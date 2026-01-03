<?php
/**
 * public/salesorder/edit.php (FINAL)
 *
 * - Edit açılışında editing lock alır (TTL=300)
 * - Başkasında ise readOnly + uyarı
 * - Kaydetmeden önce lock refresh (editing)
 * - ✅ Approve Lock butonu (status=approving) -> sadece lock alır, sayfayı yeniler
 * - ✅ Approve (SAVED -> APPROVED): sadece SAVED iken çalışır, approving lock alır, sonra APPROVED kaydeder
 * - ✅ APPROVED olunca: her koşulda readOnly (lock alınmaz, save/delete/approve çalışmaz)
 * - ✅ Auto keep-alive: 90 sn’de bir editing refresh (readOnly ise çalışmaz)
 * - Timeline VIEW + LOCK dedupe (30sn)
 * - ✅ NEW: Approve -> Timeline APPROVE event
 * - ✅ NEW: Approve -> SNAP01E snapshot + summary (prev chain)
 * - ✅ UI: APPROVED iken status select yerine sabit text
 */

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/auth/SessionManager.php';
require_once __DIR__ . '/../../core/base/Context.php';
require_once __DIR__ . '/../../core/base/ContextException.php';

require_once __DIR__ . '/../../core/lock/LockManager.php';
require_once __DIR__ . '/../../core/timeline/TimelineService.php';

require_once __DIR__ . '/../../app/modules/salesorder/SORDRepository.php';

SessionManager::start();

try { Context::bootFromSession(); }
catch (Throwable $e) { header('Location: /php-mongo-erp/public/login.php'); exit; }

$ctx = Context::get();
if (!is_array($ctx)) $ctx = [];
$isAdmin = ((string)($ctx['role'] ?? '') === 'admin');

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function lock_owner_text($lock): string {
  $u = (string)($lock['context']['username'] ?? '');
  $st = (string)($lock['status'] ?? '');
  if ($u === '') $u = 'unknown';
  if ($st === '') $st = 'editing';
  return $u . ' (' . $st . ')';
}

// ---------- Snapshot helpers (Approve sırasında kullanılıyor) ----------
function snap_target_key(string $module, string $docType, string $docId): string {
  // basit ve stabil key
  return strtolower($module) . '|' . strtoupper($docType) . '|' . $docId;
}

function json_hash($arr): string {
  $j = json_encode($arr, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  return sha1((string)$j);
}

function create_snapshot_safe(array $target, array $data, array $summary, array $ctx, array $refs = []): void {
  // fail-safe: snapshot atılamazsa approve akışı bozulmasın
  try {
    $module = strtolower((string)($target['module'] ?? ''));
    $docType = strtoupper((string)($target['doc_type'] ?? ''));
    $docId = (string)($target['doc_id'] ?? '');
    if ($module === '' || $docType === '' || $docId === '' || strlen($docId) !== 24) return;

    $targetKey = snap_target_key($module, $docType, $docId);

    $prev = MongoManager::collection('SNAP01E')->findOne(
      ['target_key' => $targetKey],
      ['sort' => ['version' => -1]]
    );

    $prevId = null;
    $prevHash = null;
    $ver = 1;

    if ($prev) {
      if ($prev instanceof MongoDB\Model\BSONDocument) $prev = $prev->getArrayCopy();
      $ver = (int)($prev['version'] ?? 0) + 1;
      $prevHash = (string)($prev['hash'] ?? '');
      if (!empty($prev['_id'])) $prevId = (string)$prev['_id'];
    }

    $hash = json_hash($data);

    $doc = [
      'target_key' => $targetKey,
      'version' => $ver,
      'hash' => $hash,
      'prev_hash' => ($prevHash ?: null),

      'target' => [
        'module' => $module,
        'doc_type' => $docType,
        'doc_id' => $docId,
        'doc_no' => (string)($target['doc_no'] ?? ''),
        'doc_title' => (string)($target['doc_title'] ?? ''),
        'doc_status' => (string)($target['doc_status'] ?? ''),
      ],

      'context' => [
        'username' => (string)($ctx['username'] ?? ''),
        'role' => (string)($ctx['role'] ?? ''),
        'session_id' => (string)($ctx['session_id'] ?? session_id()),
        'CDEF01_id' => (string)($ctx['CDEF01_id'] ?? ''),
        'PERIOD01T_id' => (string)($ctx['PERIOD01T_id'] ?? ($ctx['period_id'] ?? '')),
      ],

      'data' => $data,
      'summary' => $summary,

      'refs' => array_merge(
        $refs,
        $prevId ? ['prev_snapshot_id' => $prevId] : []
      ),

      'created_at' => new MongoDB\BSON\UTCDateTime((int) floor(microtime(true) * 1000)),
    ];

    MongoManager::collection('SNAP01E')->insertOne($doc);

  } catch (Throwable $e) {
    // swallow
  }
}
// -------------------------------------------------------------------

$id = trim((string)($_GET['id'] ?? ''));

$model = ['header'=>['evrakno'=>'','customer'=>'','status'=>'DRAFT'], 'lines'=>[], 'version'=>0];
$isEdit = ($id !== '' && strlen($id) === 24);

$readOnly = false;
$lockMsg = null;
$error = null;

// ✅ APPROVED olunca readOnly flag
$forceReadOnlyApproved = false;

// ====== GET: load + editing lock try ======
if ($isEdit) {
  $tmp = SORDRepository::dumpFull($id);
  if (!empty($tmp)) $model = $tmp;

  $evrakno   = (string)($model['header']['evrakno'] ?? $id);
  $customer  = (string)($model['header']['customer'] ?? '');
  $docStatus = (string)($model['header']['status'] ?? '');

  // ✅ APPROVED ise: lock alma, full readOnly
  if (strtoupper($docStatus) === 'APPROVED') {
    $readOnly = true;
    $forceReadOnlyApproved = true;
    $lockMsg = 'Bu evrak APPROVED durumda. Sadece görüntüleme yapılabilir.';
  }

  $lockRes = null;
  if (!$forceReadOnlyApproved) {
    $lockRes = LockManager::acquire([
      'module' => 'salesorder',
      'doc_type' => 'SORD01E',
      'doc_id' => $id,
      'doc_no' => ($evrakno !== '' ? $evrakno : null),
      'doc_title' => ($customer !== '' ? $customer : null),
      'doc_status' => ($docStatus !== '' ? $docStatus : null),
    ], 300, 'editing');

    if (is_array($lockRes) && ($lockRes['ok'] ?? false) && !($lockRes['acquired'] ?? false)) {
      $readOnly = true;
      $lockMsg = 'Bu evrak şu an başka kullanıcıda kilitli: ' . lock_owner_text($lockRes['lock'] ?? []);
    }
  }

  // Timeline dedupe
  $k = 'SORD01E:' . $id;
  $now = time();
  if (!isset($_SESSION['tl_seen'])) $_SESSION['tl_seen'] = [];
  $last = (int)($_SESSION['tl_seen'][$k] ?? 0);
  if (($now - $last) >= 30) {
    TimelineService::log('VIEW', 'SORD01E', $id, $evrakno, $ctx, ['page'=>'salesorder/edit']);
    $_SESSION['tl_seen'][$k] = $now;
  }

  if (!$forceReadOnlyApproved && is_array($lockRes) && ($lockRes['ok'] ?? false) && ($lockRes['acquired'] ?? false)) {
    $lk = 'LOCK:' . $k;
    $ll = (int)($_SESSION['tl_seen'][$lk] ?? 0);
    if (($now - $ll) >= 30) {
      TimelineService::log('LOCK', 'SORD01E', $id, $evrakno, $ctx, ['ttl'=>300,'status'=>'editing']);
      $_SESSION['tl_seen'][$lk] = $now;
    }
  }
}

// ====== POST ======
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {

    // helper: fresh model + meta
    $loadFresh = function() use (&$model, $id) {
      $fresh = SORDRepository::dumpFull($id);
      if (!empty($fresh)) $model = $fresh;

      $evrakno   = (string)($model['header']['evrakno'] ?? $id);
      $customer  = (string)($model['header']['customer'] ?? '');
      $docStatus = (string)($model['header']['status'] ?? '');

      $lines     = $model['lines'] ?? [];
      if (!is_array($lines)) $lines = [];

      return [$evrakno, $customer, $docStatus, $lines];
    };

    // ✅ APPROVED ise: hiçbir write action çalışmasın (backend garanti)
    if ($isEdit) {
      [$evraknoX, $customerX, $docStatusX] = $loadFresh();
      if (strtoupper($docStatusX) === 'APPROVED') {
        throw new RuntimeException('approved_is_readonly');
      }
    }

    // 1) Approve Lock (sadece lock al)
    if (!empty($_POST['_action']) && $_POST['_action'] === 'approve_lock') {
      if (!$isEdit) throw new RuntimeException('approve_lock_requires_id');
      if ($readOnly) throw new RuntimeException('locked_by_other_user: ' . ($lockMsg ?: 'locked'));

      [$evrakno, $customer, $docStatus] = $loadFresh();

      $lk = LockManager::acquire([
        'module' => 'salesorder',
        'doc_type' => 'SORD01E',
        'doc_id' => $id,
        'doc_no' => ($evrakno !== '' ? $evrakno : null),
        'doc_title' => ($customer !== '' ? $customer : null),
        'doc_status' => ($docStatus !== '' ? $docStatus : null),
      ], 300, 'approving');

      if (is_array($lk) && ($lk['ok'] ?? false) && !($lk['acquired'] ?? false)) {
        throw new RuntimeException('locked_by_other_user: ' . lock_owner_text($lk['lock'] ?? []));
      }

      // timeline lock (approve) - dedupe
      $k = 'SORD01E:' . $id;
      $now = time();
      if (!isset($_SESSION['tl_seen'])) $_SESSION['tl_seen'] = [];
      $lkSeen = 'LOCK:APPROVE:' . $k;
      $ll = (int)($_SESSION['tl_seen'][$lkSeen] ?? 0);
      if (($now - $ll) >= 30) {
        TimelineService::log('LOCK', 'SORD01E', $id, $evrakno, $ctx, ['ttl'=>300,'status'=>'approving']);
        $_SESSION['tl_seen'][$lkSeen] = $now;
      }

      header('Location: /php-mongo-erp/public/salesorder/edit.php?id=' . urlencode($id) . '&locked=approving');
      exit;
    }

    // 2) ✅ Approve (SAVED -> APPROVED)
    if (!empty($_POST['_action']) && $_POST['_action'] === 'approve_doc') {
      if (!$isEdit) throw new RuntimeException('approve_requires_id');
      if ($readOnly) throw new RuntimeException('locked_by_other_user: ' . ($lockMsg ?: 'locked'));

      [$evrakno, $customer, $docStatus, $lines] = $loadFresh();

      if (strtoupper($docStatus) !== 'SAVED') {
        throw new RuntimeException('approve_requires_saved_status');
      }

      // approving lock al
      $lk = LockManager::acquire([
        'module' => 'salesorder',
        'doc_type' => 'SORD01E',
        'doc_id' => $id,
        'doc_no' => ($evrakno !== '' ? $evrakno : null),
        'doc_title' => ($customer !== '' ? $customer : null),
        'doc_status' => ($docStatus !== '' ? $docStatus : null),
      ], 300, 'approving');

      if (is_array($lk) && ($lk['ok'] ?? false) && !($lk['acquired'] ?? false)) {
        throw new RuntimeException('locked_by_other_user: ' . lock_owner_text($lk['lock'] ?? []));
      }

      // save with APPROVED (aynı lines)
      $header = [
        'evrakno'  => trim((string)($model['header']['evrakno'] ?? '')),
        'customer' => trim((string)($model['header']['customer'] ?? '')),
        'status'   => 'APPROVED',
      ];

      $res = SORDRepository::save($header, $lines, $ctx, $id);
      $newId = (string)($res['SORD01_id'] ?? $id);

      // ✅ Timeline APPROVE event (dedupe 30sn)
      $k = 'SORD01E:' . $newId;
      $now = time();
      if (!isset($_SESSION['tl_seen'])) $_SESSION['tl_seen'] = [];
      $apSeen = 'APPROVE:' . $k;
      $last = (int)($_SESSION['tl_seen'][$apSeen] ?? 0);
      if (($now - $last) >= 30) {
        TimelineService::log('APPROVE', 'SORD01E', $newId, $evrakno, $ctx, [
          'from' => 'SAVED',
          'to'   => 'APPROVED',
          'page' => 'salesorder/edit',
        ]);
        $_SESSION['tl_seen'][$apSeen] = $now;
      }

      // ✅ SNAP01E snapshot + summary (fail-safe)
      $approvedModel = SORDRepository::dumpFull($newId);
      if (empty($approvedModel)) {
        // minimum data fallback
        $approvedModel = [
          'header' => $header,
          'lines'  => $lines,
        ];
      }

      create_snapshot_safe(
        [
          'module' => 'salesorder',
          'doc_type' => 'SORD01E',
          'doc_id' => $newId,
          'doc_no' => $evrakno,
          'doc_title' => $customer,
          'doc_status' => 'APPROVED',
        ],
        $approvedModel,
        [
          'event' => 'APPROVE',
          'status_from' => 'SAVED',
          'status_to' => 'APPROVED',
          'note' => 'Approved via salesorder/edit.php',
        ],
        $ctx,
        [
          'action' => 'approve_doc',
          'source' => 'public/salesorder/edit.php',
        ]
      );

      // approve bitince lock bırak
      LockManager::release([
        'module' => 'salesorder',
        'doc_type' => 'SORD01E',
        'doc_id' => $newId,
      ], false);

      header('Location: /php-mongo-erp/public/salesorder/edit.php?id=' . urlencode($newId) . '&approved=1');
      exit;
    }

    // edit ise: lock refresh (editing) — save/delete için
    if ($isEdit) {
      [$evrakno, $customer, $docStatus] = $loadFresh();

      $lockResPost = LockManager::acquire([
        'module' => 'salesorder',
        'doc_type' => 'SORD01E',
        'doc_id' => $id,
        'doc_no' => ($evrakno !== '' ? $evrakno : null),
        'doc_title' => ($customer !== '' ? $customer : null),
        'doc_status' => ($docStatus !== '' ? $docStatus : null),
      ], 300, 'editing');

      if (is_array($lockResPost) && ($lockResPost['ok'] ?? false) && !($lockResPost['acquired'] ?? false)) {
        throw new RuntimeException('locked_by_other_user: ' . lock_owner_text($lockResPost['lock'] ?? []));
      }
    }

    // DELETE
    if (!empty($_POST['delete']) && $_POST['delete'] === '1') {
      if (!$isEdit) throw new RuntimeException('delete_requires_id');

      LockManager::release([
        'module' => 'salesorder',
        'doc_type' => 'SORD01E',
        'doc_id' => $id,
      ], $isAdmin);

      SORDRepository::deleteHard($id, $ctx);
      header('Location: /php-mongo-erp/public/salesorder/index.php');
      exit;
    }

    // SAVE
    $header = [
      'evrakno'  => trim((string)($_POST['evrakno'] ?? '')),
      'customer' => trim((string)($_POST['customer'] ?? '')),
      'status'   => trim((string)($_POST['status'] ?? 'DRAFT')),
    ];

    $lines = [];
    $names  = $_POST['item_name'] ?? [];
    $qtys   = $_POST['qty'] ?? [];
    $prices = $_POST['price'] ?? [];

    $n = max(count($names), count($qtys), count($prices));
    for ($i=0; $i<$n; $i++) {
      $name = trim((string)($names[$i] ?? ''));
      if ($name === '') continue;
      $lines[] = [
        'line_no'   => $i+1,
        'item_name' => $name,
        'qty'       => (float)($qtys[$i] ?? 0),
        'price'     => (float)($prices[$i] ?? 0),
      ];
    }

    $res = SORDRepository::save($header, $lines, $ctx, ($isEdit ? $id : null));
    $newId = (string)($res['SORD01_id'] ?? '');

    // save sonrası lock refresh (editing)
    if ($newId !== '' && strlen($newId) === 24) {
      LockManager::acquire([
        'module' => 'salesorder',
        'doc_type' => 'SORD01E',
        'doc_id' => $newId,
        'doc_no' => (string)($res['evrakno'] ?? ''),
        'doc_title' => (string)($header['customer'] ?? ''),
        'doc_status' => (string)($header['status'] ?? ''),
      ], 300, 'editing');
    }

    header('Location: /php-mongo-erp/public/salesorder/edit.php?id=' . urlencode($newId));
    exit;

  } catch (Throwable $e) {
    $error = $e->getMessage();
    $readOnly = $readOnly || (strpos($error, 'locked_by_other_user:') === 0);
    if ($readOnly && $lockMsg === null) $lockMsg = $error;

    if ($error === 'approve_requires_saved_status') {
      $error = 'Approve için evrak durumu SAVED olmalı.';
    }
    if ($error === 'approved_is_readonly') {
      $error = 'Bu evrak APPROVED durumda. Değişiklik yapılamaz.';
      $readOnly = true;
      if ($lockMsg === null) $lockMsg = $error;
    }
  }
}

// UI params
$lockedParam   = (string)($_GET['locked'] ?? '');
$approvedParam = (string)($_GET['approved'] ?? '');

$currStatus = (string)($model['header']['status'] ?? 'DRAFT');
$canApprove = ($isEdit && !$readOnly && strtoupper($currStatus) === 'SAVED');

require_once BASE_PATH . '/app/views/layout/header.php';
?>
<body>
<div class="layout-wrapper layout-content-navbar">
  <div class="layout-container">
    <?php require_once BASE_PATH . '/app/views/layout/left.php'; ?>
    <div class="layout-page">
      <?php require_once BASE_PATH . '/app/views/layout/header2.php'; ?>

      <div class="content-wrapper">
        <div class="container-xxl flex-grow-1 container-p-y">

          <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="mb-0">Satış Siparişi</h4>
            <div class="d-flex gap-2">
              <a class="btn btn-outline-secondary" href="/php-mongo-erp/public/salesorder/index.php">Liste</a>

              <?php if ($isEdit): ?>
                <form method="POST" style="display:inline;">
                  <input type="hidden" name="_action" value="approve_lock">
                  <button class="btn btn-outline-warning" type="submit"
                          onclick="return confirm('Approving lock alınsın mı?');"
                          <?php echo $readOnly ? 'disabled' : ''; ?>>
                    Approve Lock
                  </button>
                </form>
              <?php endif; ?>

              <?php if ($isEdit): ?>
                <form method="POST" style="display:inline;">
                  <input type="hidden" name="_action" value="approve_doc">
                  <button class="btn btn-warning" type="submit"
                          title="<?php echo $canApprove ? '' : 'Approve için evrak durumu SAVED olmalı.'; ?>"
                          onclick="return <?php echo $canApprove ? "confirm('Evrak SAVED ise APPROVED yapılacak. Devam edilsin mi?');" : 'false'; ?>;"
                          <?php echo $canApprove ? '' : 'disabled'; ?>>
                    Approve (SAVED → APPROVED)
                  </button>
                </form>
              <?php endif; ?>

              <?php if ($isEdit): ?>
                <form method="POST" onsubmit="return confirm('Silinsin mi? (hard delete)');" style="display:inline;">
                  <input type="hidden" name="delete" value="1">
                  <button class="btn btn-outline-danger" type="submit" <?php echo $readOnly ? 'disabled' : ''; ?>>Sil</button>
                </form>
              <?php endif; ?>
            </div>
          </div>

          <?php if ($lockedParam === 'approving'): ?>
            <div class="alert alert-success">Approving lock alındı.</div>
          <?php endif; ?>

          <?php if ($approvedParam === '1'): ?>
            <div class="alert alert-success">Evrak APPROVED yapıldı.</div>
          <?php endif; ?>

          <?php if ($lockMsg): ?>
            <div class="alert alert-warning"><?php echo h($lockMsg); ?></div>
          <?php endif; ?>

          <?php if ($error && !$lockMsg): ?>
            <div class="alert alert-danger"><?php echo h($error); ?></div>
          <?php endif; ?>

          <form method="POST">
            <div class="card mb-3">
              <div class="card-body">

                <div class="row g-3">
                  <div class="col-md-4">
                    <label class="form-label">Evrak No</label>
                    <input class="form-control" name="evrakno"
                           value="<?php echo h($model['header']['evrakno'] ?? ''); ?>"
                           placeholder="Yeni kayıtta ilk kayıtta otomatik verilir"
                           <?php echo $readOnly ? 'disabled' : ''; ?>>
                  </div>

                  <div class="col-md-5">
                    <label class="form-label">Müşteri</label>
                    <input class="form-control" name="customer"
                           value="<?php echo h($model['header']['customer'] ?? ''); ?>"
                           <?php echo $readOnly ? 'disabled' : ''; ?>>
                  </div>

                  <div class="col-md-3">
                    <label class="form-label">Durum</label>

                    <?php $st = (string)($model['header']['status'] ?? 'DRAFT'); ?>

                    <?php if (strtoupper($st) === 'APPROVED'): ?>
                      <!-- ✅ APPROVED iken sabit text -->
                      <div class="form-control" style="background:#f8f9fa;display:flex;align-items:center;">
                        <span style="font-weight:700;">APPROVED</span>
                      </div>
                      <div style="font-size:12px;opacity:.65;margin-top:6px;">
                        Not: APPROVED evraklar değiştirilemez.
                      </div>
                    <?php else: ?>
                      <select class="form-control" name="status" <?php echo $readOnly ? 'disabled' : ''; ?>>
                        <option value="DRAFT" <?php echo $st==='DRAFT'?'selected':''; ?>>DRAFT</option>
                        <option value="SAVED" <?php echo $st==='SAVED'?'selected':''; ?>>SAVED</option>
                        <option value="APPROVED" <?php echo $st==='APPROVED'?'selected':''; ?>>APPROVED</option>
                      </select>
                      <div style="font-size:12px;opacity:.65;margin-top:6px;">
                        Not: Approve butonu sadece SAVED iken aktif olur.
                      </div>
                    <?php endif; ?>
                  </div>
                </div>

              </div>
            </div>

            <div class="card">
              <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0">Satırlar</h6>
                <button type="button" class="btn btn-sm btn-outline-primary" onclick="addRow()" <?php echo $readOnly ? 'disabled' : ''; ?>>Satır Ekle</button>
              </div>

              <div class="card-body">
                <div class="table-responsive">
                  <table class="table" id="linesTbl">
                    <thead>
                      <tr>
                        <th>Ürün</th>
                        <th style="width:120px;">Miktar</th>
                        <th style="width:160px;">Fiyat</th>
                        <th style="width:60px;"></th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach (($model['lines'] ?? []) as $ln): ?>
                        <tr>
                          <td><input class="form-control" name="item_name[]" value="<?php echo h($ln['item_name'] ?? ''); ?>" <?php echo $readOnly ? 'disabled' : ''; ?>></td>
                          <td><input class="form-control" name="qty[]" type="number" step="0.01" value="<?php echo h($ln['qty'] ?? 0); ?>" <?php echo $readOnly ? 'disabled' : ''; ?>></td>
                          <td><input class="form-control" name="price[]" type="number" step="0.01" value="<?php echo h($ln['price'] ?? 0); ?>" <?php echo $readOnly ? 'disabled' : ''; ?>></td>
                          <td class="text-end">
                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove()" <?php echo $readOnly ? 'disabled' : ''; ?>>X</button>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>

                <div class="mt-3 text-end">
                  <button class="btn btn-primary" type="submit" <?php echo $readOnly ? 'disabled' : ''; ?>>Kaydet</button>
                </div>

              </div>
            </div>
          </form>

        </div>
        <div class="content-backdrop fade"></div>
      </div>

    </div>
  </div>
</div>

<script>
function addRow(){
  const tb = document.querySelector('#linesTbl tbody');
  const tr = document.createElement('tr');
  tr.innerHTML = `
    <td><input class="form-control" name="item_name[]" /></td>
    <td><input class="form-control" name="qty[]" type="number" step="0.01" value="1" /></td>
    <td><input class="form-control" name="price[]" type="number" step="0.01" value="0" /></td>
    <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove()">X</button></td>
  `;
  tb.appendChild(tr);
}

// === lock keep-alive (edit + not readonly) ===
(function(){
  const isEdit = <?php echo $isEdit ? 'true' : 'false'; ?>;
  const readOnly = <?php echo $readOnly ? 'true' : 'false'; ?>;
  const docId = <?php echo json_encode($isEdit ? $id : ''); ?>;

  if (!isEdit || readOnly || !docId) return;

  const module = 'salesorder';
  const docType = 'SORD01E';

  function getMeta(){
    const evrakno = (document.querySelector('[name="evrakno"]')?.value || '').trim();
    const customer = (document.querySelector('[name="customer"]')?.value || '').trim();
    const status = (document.querySelector('[name="status"]')?.value || '').trim();
    return { evrakno, customer, status };
  }

  async function ping(){
    try{
      const m = getMeta();
      const u = new URL('/php-mongo-erp/public/api/lock_acquire.php', window.location.origin);
      u.searchParams.set('module', module);
      u.searchParams.set('doc_type', docType);
      u.searchParams.set('doc_id', docId);
      u.searchParams.set('status', 'editing');
      u.searchParams.set('ttl', '300');
      if (m.evrakno) u.searchParams.set('doc_no', m.evrakno);
      if (m.customer) u.searchParams.set('doc_title', m.customer);
      if (m.status) u.searchParams.set('doc_status', m.status);

      await fetch(u.toString(), { method:'GET', credentials:'same-origin' });
    } catch(e){}
  }

  setInterval(ping, 90000);
})();
</script>

<?php require_once BASE_PATH . '/app/views/layout/footer.php'; ?>
</body>
</html>
