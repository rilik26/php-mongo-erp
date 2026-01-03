<?php
/**
 * public/locks.php (FINAL)
 *
 * - Aktif lockları listeler
 * - status badge (editing/viewing/approving)
 * - doc_no / doc_title alanlarını UI’da gösterir
 * - Acquire/Release fetch + toast
 * - SORD01E için doc_no/doc_title/status boşsa SORD01E koleksiyonundan resolve eder
 * - Acquire (editing) + Approve Lock (approving)
 * - Force release: admin only
 *
 * FIX:
 * - Checkbox filtreleri unchecked olsa da değer gönderir (hidden input ile)
 *
 * NEW:
 * - ✅ approving lock satırı highlight
 * - ✅ approving lock için "APPROVE PENDING" chip
 */

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/auth/SessionManager.php';
require_once __DIR__ . '/../core/base/Context.php';
require_once __DIR__ . '/../core/base/ContextException.php';
require_once __DIR__ . '/../core/action/ActionLogger.php';

SessionManager::start();

if (!isset($_SESSION['context']) || !is_array($_SESSION['context'])) {
  header('Location: /php-mongo-erp/public/login.php'); exit;
}

try { Context::bootFromSession(); }
catch (ContextException $e) {
  header('Location: /php-mongo-erp/public/login.php'); exit;
}

$ctx = Context::get();
$isAdmin = ((string)($ctx['role'] ?? '') === 'admin');

ActionLogger::info('LOCKS.VIEW', ['source' => 'public/locks.php'], $ctx);

date_default_timezone_set('Europe/Istanbul');

function esc($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function fmt_tr_dt($isoOrAnything): string {
  $s = (string)$isoOrAnything;
  $ts = strtotime($s);
  if ($ts === false) return $s;
  return date('d.m.Y H:i:s', $ts);
}

function badge_html(string $status): string {
  $status = $status ?: 'editing';
  $map = [
    'editing'   => ['#E3F2FD', '#1565C0', 'EDITING'],
    'viewing'   => ['#F1F8E9', '#2E7D32', 'VIEWING'],
    'approving' => ['#FFF3E0', '#EF6C00', 'APPROVING'],
  ];
  $cfg = $map[$status] ?? $map['editing'];
  [$bg, $fg, $label] = $cfg;
  return '<span style="display:inline-block;padding:3px 8px;border-radius:999px;font-size:12px;background:' . $bg . ';color:' . $fg . ';font-weight:600;">' . $label . '</span>';
}

function chip_html(string $label, string $value): string {
  $label = esc($label);
  $value = esc($value);
  return '<span style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:12px;background:rgba(0,0,0,.03);border:1px solid rgba(0,0,0,.10);margin-right:6px;margin-top:4px;"><b>' . $label . ':</b> ' . $value . '</span>';
}

function doc_url(array $t): ?string {
  $module = strtolower((string)($t['module'] ?? ''));
  $dt = strtoupper((string)($t['doc_type'] ?? ''));
  $di = (string)($t['doc_id'] ?? '');

  if ($di === '' && !empty($t['maybe_doc_id'])) {
    $di = (string)$t['maybe_doc_id'];
  }

  if ($dt === 'SORD01E' && $di !== '' && strlen($di) === 24) {
    return '/php-mongo-erp/public/salesorder/edit.php?id=' . rawurlencode($di);
  }

  if ($module === 'salesorder' && $dt === 'SORD01E' && $di !== '' && strlen($di) === 24) {
    return '/php-mongo-erp/public/salesorder/edit.php?id=' . rawurlencode($di);
  }

  if ($module === 'i18n' && $dt === 'LANG01T' && $di === 'DICT') {
    return '/php-mongo-erp/public/lang_admin.php';
  }

  if ($module === 'gendoc' && $dt === 'GENDOC01T' && $di !== '') {
    return '/php-mongo-erp/public/gendoc_edit.php?id=' . rawurlencode($di);
  }

  if ($module !== '' && $dt !== '' && $di !== '') {
    return '/php-mongo-erp/public/timeline.php?module=' . rawurlencode($module) . '&doc_type=' . rawurlencode($dt) . '&doc_id=' . rawurlencode($di);
  }

  return null;
}

function resolve_sord_meta(string $docId): array {
  if ($docId === '' || strlen($docId) !== 24) return ['doc_no'=>'','doc_title'=>'','status'=>''];

  try { $oid = new MongoDB\BSON\ObjectId($docId); }
  catch (Throwable $e) { return ['doc_no'=>'','doc_title'=>'','status'=>'']; }

  $d = MongoManager::collection('SORD01E')->findOne(['_id' => $oid], [
    'projection' => ['header.evrakno'=>1,'header.customer'=>1,'header.status'=>1]
  ]);
  if (!$d) return ['doc_no'=>'','doc_title'=>'','status'=>''];

  if ($d instanceof MongoDB\Model\BSONDocument) $d = $d->getArrayCopy();
  $h = (array)($d['header'] ?? []);

  return [
    'doc_no'    => (string)($h['evrakno'] ?? ''),
    'doc_title' => (string)($h['customer'] ?? ''),
    'status'    => (string)($h['status'] ?? ''),
  ];
}

// --- filters ---
$q = trim((string)($_GET['q'] ?? ''));

// Hidden input ile 0 geliyor, checkbox işaretliyse 1 gelir.
$onlyMine   = ((string)($_GET['mine'] ?? '0') === '1');
$onlyActive = ((string)($_GET['active'] ?? '1') === '1'); // default: aktif

$nowMs = (int) floor(microtime(true) * 1000);

// Mongo filter
$filter = [];
if ($onlyActive) {
  $filter['expires_at'] = ['$gt' => new MongoDB\BSON\UTCDateTime($nowMs)];
}
if ($onlyMine) {
  $filter['context.session_id'] = $ctx['session_id'] ?? session_id();
}
if ($q !== '') {
  $regex = new MongoDB\BSON\Regex(preg_quote($q), 'i');
  $filter['$or'] = [
    ['target_key' => $regex],
    ['context.username' => $regex],
    ['target.module' => $regex],
    ['target.doc_type' => $regex],
    ['target.doc_id' => $regex],
    ['target.doc_no' => $regex],
    ['target.doc_title' => $regex],
    ['target.doc_status' => $regex],
    ['status' => $regex],
  ];
}

$locksCur = MongoManager::collection('LOCK01E')->find(
  $filter,
  ['sort' => ['locked_at' => -1], 'limit' => 1000]
);

$locks = [];
foreach ($locksCur as $l) {
  if ($l instanceof MongoDB\Model\BSONDocument) $l = $l->getArrayCopy();
  $locks[] = $l;
}

require_once __DIR__ . '/../app/views/layout/header.php';
?>

<style>
  .code{ font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
  .muted{ color:#888; }
  .small{ font-size:12px; color:#666; }

  /* ✅ approving lock satır vurgusu */
  tr.lock-approving td{
    background: rgba(255, 193, 7, .08);
  }
</style>

<body>
<div class="layout-wrapper layout-content-navbar">
  <div class="layout-container">

    <?php require_once __DIR__ . '/../app/views/layout/left.php'; ?>

    <div class="layout-page">
      <?php require_once __DIR__ . '/../app/views/layout/header2.php'; ?>

      <div class="content-wrapper">
        <div class="container-xxl flex-grow-1 container-p-y">

          <div class="row g-6">
            <div class="col-12">
              <div class="card">
                <div class="card-body">

                  <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <div>
                      <h4 class="mb-1">Locks</h4>
                      <div class="text-muted" style="font-size:12px;">
                        Kullanıcı: <strong><?php echo esc($ctx['username'] ?? ''); ?></strong>
                        &nbsp;|&nbsp; Role: <strong><?php echo esc($ctx['role'] ?? ''); ?></strong>
                        &nbsp;|&nbsp; Firma: <strong><?php echo esc($ctx['CDEF01_id'] ?? ''); ?></strong>
                        &nbsp;|&nbsp; Dönem: <strong><?php echo esc($ctx['PERIOD01T_id'] ?? ($ctx['period_id'] ?? '')); ?></strong>
                      </div>
                    </div>
                  </div>

                  <form method="GET" class="row g-3 mt-4" id="filterForm">
                    <div class="col-md-5">
                      <label class="form-label">Search</label>
                      <input class="form-control" type="text" name="q" value="<?php echo esc($q); ?>" placeholder="user/module/doc_no/doc_title/status/target_key">
                    </div>

                    <div class="col-md-2 d-flex align-items-end">
                      <input type="hidden" name="mine" value="0">
                      <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="chkMine" name="mine" value="1" <?php echo $onlyMine ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="chkMine">Sadece benim</label>
                      </div>
                    </div>

                    <div class="col-md-2 d-flex align-items-end">
                      <input type="hidden" name="active" value="0">
                      <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="chkActive" name="active" value="1" <?php echo $onlyActive ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="chkActive">Sadece aktif</label>
                      </div>
                    </div>

                    <div class="col-md-3 d-flex align-items-end gap-2">
                      <button class="btn btn-primary" type="submit">Getir</button>
                      <a class="btn btn-outline-primary" href="/php-mongo-erp/public/locks.php">Sıfırla</a>
                    </div>
                  </form>

                  <div class="table-responsive mt-4">
                    <table class="table table-bordered">
                      <thead style="background:rgba(0,0,0,.03);">
                        <tr>
                          <th style="width:120px;">Status</th>
                          <th style="width:170px;">Kilit Zamanı</th>
                          <th style="width:170px;">Bitiş (TTL)</th>
                          <th style="width:170px;">Kullanıcı</th>
                          <th style="width:360px;">Target</th>
                          <th>Target Key</th>
                          <th style="width:320px;">Actions</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php if (count($locks) === 0): ?>
                          <tr><td colspan="7" class="text-muted" style="font-size:12px;">Lock bulunamadı.</td></tr>
                        <?php else: ?>
                          <?php foreach ($locks as $l):
                            $status = (string)($l['status'] ?? 'editing');

                            $lockedAt = $l['locked_at'] ?? null;
                            $expiresAt = $l['expires_at'] ?? null;

                            $lockedIso = ($lockedAt instanceof MongoDB\BSON\UTCDateTime)
                              ? $lockedAt->toDateTime()->format('c')
                              : (string)$lockedAt;

                            $expiresIso = '';
                            $expiresMs = null;
                            if ($expiresAt instanceof MongoDB\BSON\UTCDateTime) {
                              $dt = $expiresAt->toDateTime();
                              $expiresIso = $dt->format('c');
                              $expiresMs = (int)$dt->getTimestamp() * 1000;
                            } else {
                              $expiresIso = (string)$expiresAt;
                              $tmp = strtotime($expiresIso);
                              if ($tmp !== false) $expiresMs = (int)$tmp * 1000;
                            }

                            $ttlLeft = '';
                            if ($expiresMs !== null) {
                              $diff = $expiresMs - $nowMs;
                              if ($diff <= 0) $ttlLeft = '<span class="muted">expired</span>';
                              else {
                                $sec = (int) floor($diff / 1000);
                                $min = (int) floor($sec / 60);
                                $rem = $sec % 60;
                                $ttlLeft = $min . 'm ' . $rem . 's';
                              }
                            }

                            $c = $l['context'] ?? [];
                            if ($c instanceof MongoDB\Model\BSONDocument) $c = $c->getArrayCopy();

                            $user = $c['username'] ?? '';
                            $sess = $c['session_id'] ?? '';
                            $isMine = ($sess !== '' && ($ctx['session_id'] ?? session_id()) === $sess);

                            $t = $l['target'] ?? [];
                            if ($t instanceof MongoDB\Model\BSONDocument) $t = $t->getArrayCopy();

                            $tModule  = (string)($t['module'] ?? '');
                            $tDocType = (string)($t['doc_type'] ?? '');
                            $tDocId   = (string)($t['doc_id'] ?? '');

                            $targetKey = (string)($l['target_key'] ?? '');
                            if ($tDocId === '' && preg_match('/\|([a-f0-9]{24})\|/i', $targetKey, $m)) {
                              $tDocId = $m[1];
                            }

                            if ($tDocType === '' && strtoupper($tModule) === 'SORD01E') {
                              $tDocType = 'SORD01E';
                              $tModule = 'salesorder';
                            }

                            $tDocNo = (string)($t['doc_no'] ?? '');
                            $tTitle = (string)($t['doc_title'] ?? '');
                            $tDocStatus = (string)($t['doc_status'] ?? '');

                            if (strtoupper($tDocType) === 'SORD01E' && $tDocId !== '' && ($tDocNo === '' || $tTitle === '' || $tDocStatus === '')) {
                              $m = resolve_sord_meta($tDocId);
                              if ($tDocNo === '') $tDocNo = (string)($m['doc_no'] ?? '');
                              if ($tTitle === '') $tTitle = (string)($m['doc_title'] ?? '');
                              if ($tDocStatus === '') $tDocStatus = (string)($m['status'] ?? '');
                            }

                            $docUrl = doc_url([
                              'module' => $tModule,
                              'doc_type' => $tDocType,
                              'doc_id' => $tDocId,
                            ]);

                            $baseAcq = '/php-mongo-erp/public/api/lock_acquire.php?module=' . rawurlencode($tModule ?: 'salesorder')
                                   . '&doc_type=' . rawurlencode($tDocType ?: 'SORD01E')
                                   . '&doc_id=' . rawurlencode($tDocId);

                            $acqEditUrl = $baseAcq . '&status=editing';
                            $acqApprUrl = $baseAcq . '&status=approving';

                            $relUrl = '/php-mongo-erp/public/api/lock_release.php?module=' . rawurlencode($tModule ?: 'salesorder')
                                   . '&doc_type=' . rawurlencode($tDocType ?: 'SORD01E')
                                   . '&doc_id=' . rawurlencode($tDocId);
                            $forceUrl = $relUrl . '&force=1';
                          ?>
                            <tr
                              class="<?php echo ($status === 'approving') ? 'lock-approving' : ''; ?>"
                              data-doc-no="<?php echo esc($tDocNo); ?>"
                              data-doc-title="<?php echo esc($tTitle); ?>"
                              data-doc-status="<?php echo esc($tDocStatus); ?>"
                            >
                              <td><?php echo badge_html($status); ?></td>

                              <td class="text-muted" style="font-size:12px;"><?php echo esc(fmt_tr_dt($lockedIso)); ?></td>

                              <td class="text-muted" style="font-size:12px;">
                                <?php echo esc(fmt_tr_dt($expiresIso)); ?>
                                <div class="small muted">TTL: <?php echo $ttlLeft; ?></div>
                              </td>

                              <td>
                                <div><strong><?php echo esc($user); ?></strong></div>
                                <div class="small muted">session: <span class="code"><?php echo esc($sess); ?></span></div>
                              </td>

                              <td class="text-muted" style="font-size:12px;">
                                <div><span class="code"><?php echo esc($tModule ?: '-'); ?></span> / <span class="code"><?php echo esc($tDocType ?: '-'); ?></span></div>
                                <div>doc_id: <span class="code"><?php echo esc($tDocId ?: '-'); ?></span></div>

                                <div style="margin-top:6px;">
                                  <?php if ($tDocNo !== '') echo chip_html('doc_no', $tDocNo); ?>
                                  <?php if ($tDocStatus !== '') echo chip_html('doc_status', $tDocStatus); ?>
                                </div>

                                <?php if ($status === 'approving'): ?>
                                  <div style="margin-top:6px;">
                                    <?php echo chip_html('lock', 'APPROVE PENDING'); ?>
                                  </div>
                                <?php endif; ?>

                                <?php if ($tTitle !== ''): ?>
                                  <div style="margin-top:6px;">title: <?php echo esc($tTitle); ?></div>
                                <?php endif; ?>
                              </td>

                              <td class="text-muted" style="font-size:12px;"><span class="code"><?php echo esc($targetKey); ?></span></td>

                              <td class="d-flex flex-wrap gap-2">
                                <?php if ($docUrl): ?>
                                  <a class="btn btn-outline-primary btn-sm" href="<?php echo esc($docUrl); ?>" target="_blank">Evraka Git</a>
                                <?php else: ?>
                                  <span class="btn btn-outline-secondary btn-sm disabled">Evraka Git</span>
                                <?php endif; ?>

                                <button class="btn btn-outline-primary btn-sm js-acquire" type="button" data-url="<?php echo esc($acqEditUrl); ?>">
                                  Acquire
                                </button>

                                <button class="btn btn-outline-warning btn-sm js-approve" type="button" data-url="<?php echo esc($acqApprUrl); ?>">
                                  Approve Lock
                                </button>

                                <?php if ($isMine): ?>
                                  <button class="btn btn-danger btn-sm js-release" type="button" data-url="<?php echo esc($relUrl); ?>">Release</button>
                                <?php elseif ($isAdmin): ?>
                                  <button class="btn btn-danger btn-sm js-release" type="button" data-url="<?php echo esc($forceUrl); ?>">Force</button>
                                <?php else: ?>
                                  <span class="text-muted small">-</span>
                                <?php endif; ?>
                              </td>
                            </tr>
                          <?php endforeach; ?>
                        <?php endif; ?>
                      </tbody>
                    </table>
                  </div>

                  <div class="text-muted mt-3" style="font-size:12px;">
                    Notlar:
                    <ul class="mb-0">
                      <li>Acquire TTL yeniler (aynı session ise refresh gibi).</li>
                      <li>Approve Lock = status=approving ile lock alır.</li>
                      <li>SORD01E için doc_no/title/status boşsa header’dan resolve edilir.</li>
                    </ul>
                  </div>

                </div>
              </div>
            </div>
          </div>

        </div>

        <div class="content-backdrop fade"></div>
      </div>
    </div>
  </div>

  <div class="layout-overlay layout-menu-toggle"></div>
  <div class="drag-target"></div>
</div>

<?php require_once __DIR__ . '/../app/views/layout/footer.php'; ?>

<script>
(function(){
  function toast(type, msg){
    if (typeof window.showToast === 'function') return window.showToast(type, msg);
    if (type === 'success') console.log(msg);
    else alert(msg);
  }

  async function callJson(url){
    const r = await fetch(url, { method:'GET', credentials:'same-origin' });
    let j = null;
    try { j = await r.json(); } catch(e){}
    return { ok: r.ok, json: j };
  }

  function reloadSoon(){ window.location.reload(); }

  function withMeta(btn){
    const tr = btn.closest('tr');
    const base = btn.getAttribute('data-url') || '';
    if (!base) return '';

    try{
      const u = new URL(base, window.location.origin);
      if (tr) {
        const docNo = (tr.getAttribute('data-doc-no') || '').trim();
        const docTitle = (tr.getAttribute('data-doc-title') || '').trim();
        const docStatus = (tr.getAttribute('data-doc-status') || '').trim();
        if (docNo) u.searchParams.set('doc_no', docNo);
        if (docTitle) u.searchParams.set('doc_title', docTitle);
        if (docStatus) u.searchParams.set('doc_status', docStatus);
      }
      return u.toString();
    }catch(e){
      return base;
    }
  }

  document.querySelectorAll('.js-acquire').forEach(btn => {
    btn.addEventListener('click', async () => {
      const url = withMeta(btn);
      if (!url) return;

      btn.disabled = true;
      try{
        const res = await callJson(url);
        const j = res.json || {};
        if (!res.ok || !j.ok) {
          toast('error', 'Acquire: ' + (j.error || 'exception'));
        } else {
          if (j.acquired) toast('success', 'Lock alındı.');
          else toast('warning', 'Lock başka kullanıcıda: ' + (j.lock?.context?.username || 'unknown'));
          reloadSoon();
        }
      } catch(e){
        toast('error', 'Acquire exception: ' + e.message);
      } finally {
        btn.disabled = false;
      }
    });
  });

  document.querySelectorAll('.js-approve').forEach(btn => {
    btn.addEventListener('click', async () => {
      if (!confirm('Approving lock alınsın mı?')) return;

      const url = withMeta(btn);
      if (!url) return;

      btn.disabled = true;
      try{
        const res = await callJson(url);
        const j = res.json || {};
        if (!res.ok || !j.ok) {
          toast('error', 'Approve: ' + (j.error || 'exception'));
        } else {
          if (j.acquired) toast('success', 'Approving lock alındı.');
          else toast('warning', 'Lock başka kullanıcıda: ' + (j.lock?.context?.username || 'unknown'));
          reloadSoon();
        }
      } catch(e){
        toast('error', 'Approve exception: ' + e.message);
      } finally {
        btn.disabled = false;
      }
    });
  });

  document.querySelectorAll('.js-release').forEach(btn => {
    btn.addEventListener('click', async () => {
      const url = btn.getAttribute('data-url');
      if (!url) return;

      btn.disabled = true;
      try{
        const res = await callJson(url);
        const j = res.json || {};
        if (!res.ok || !j.ok) {
          toast('error', 'Release: ' + (j.error || 'exception'));
        } else {
          toast('success', 'Lock bırakıldı.');
          reloadSoon();
        }
      } catch(e){
        toast('error', 'Release exception: ' + e.message);
      } finally {
        btn.disabled = false;
      }
    });
  });
})();
</script>

</body>
</html>
