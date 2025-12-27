<?php
/**
 * public/gendoc_admin.php (FINAL)
 *
 * Basit GENDOC admin ekranı:
 * - Header: doc_no, title, status
 * - Body: json textarea
 *
 * Kaydet:
 *  - Header upsert (GENDOC01E)
 *  - Body version insert (GENDOC01T V1→V2→V3)
 *  - Snapshot (FINAL STATE)
 *  - Event + Log
 *  - Lock auto acquire/release (editing)
 */

require_once __DIR__ . '/../core/bootstrap.php';

require_once __DIR__ . '/../core/auth/SessionManager.php';
require_once __DIR__ . '/../core/base/Context.php';
require_once __DIR__ . '/../core/base/ContextException.php';

require_once __DIR__ . '/../core/auth/permission_helpers.php';
require_once __DIR__ . '/../core/action/ActionLogger.php';
require_once __DIR__ . '/../core/event/EventWriter.php';
require_once __DIR__ . '/../core/snapshot/SnapshotWriter.php';

require_once __DIR__ . '/../app/modules/gendoc/GENDOC01ERepository.php';
require_once __DIR__ . '/../app/modules/gendoc/GENDOC01TRepository.php';

SessionManager::start();

if (!isset($_SESSION['context']) || !is_array($_SESSION['context'])) {
  header('Location: /php-mongo-erp/public/login.php');
  exit;
}

try {
  Context::bootFromSession();
} catch (ContextException $e) {
  header('Location: /php-mongo-erp/public/login.php');
  exit;
}

$ctx = Context::get();

// Permission (admin geçer, diğerleri require_perm)
$isAdmin = (($ctx['role'] ?? '') === 'admin');
if (function_exists('require_perm') && !$isAdmin) {
  require_perm('gendoc.manage'); // 403 olabilir => normal
}

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// input target
$module  = trim($_GET['module'] ?? 'gen');
$docType = trim($_GET['doc_type'] ?? 'GENDOC01T');
$docId   = trim($_GET['doc_id'] ?? 'DOC-001');

if ($module === '' || $docType === '' || $docId === '') {
  http_response_code(400);
  echo "module/doc_type/doc_id required";
  exit;
}

$msg = null;
$err = null;

// view log + event
$viewLogId = ActionLogger::info('GENDOC.ADMIN.VIEW', [
  'source' => 'public/gendoc_admin.php',
  'module' => $module,
  'doc_type' => $docType,
  'doc_id' => $docId
], $ctx);

EventWriter::emit(
  'GENDOC.ADMIN.VIEW',
  ['source'=>'public/gendoc_admin.php'],
  ['module'=>$module,'doc_type'=>$docType,'doc_id'=>$docId,'doc_no'=>null],
  $ctx,
  ['log_id'=>$viewLogId]
);

$target = [
  'module'   => $module,
  'doc_type' => $docType,
  'doc_id'   => $docId,
];

$headerDoc = GENDOC01ERepository::findByTarget($target, $ctx);
$header = [];
if ($headerDoc) {
  $hdoc = $headerDoc['header'] ?? [];
  if ($hdoc instanceof MongoDB\Model\BSONDocument) $hdoc = $hdoc->getArrayCopy();
  $header = is_array($hdoc) ? $hdoc : [];
}

$docNo  = (string)($header['doc_no'] ?? '');
$title  = (string)($header['title'] ?? '');
$status = (string)($header['status'] ?? 'draft');

$targetKey = '';
try {
  $targetKey = GENDOC01ERepository::buildTargetKey($target, $ctx);
} catch(Throwable $e) {}

$latestBody = $targetKey ? GENDOC01TRepository::latestByTargetKey($targetKey) : null;
$bodyLatest = null;
if ($latestBody) {
  $b = $latestBody['body'] ?? null;
  if ($b instanceof MongoDB\Model\BSONDocument) $b = $b->getArrayCopy();
  $bodyLatest = is_array($b) ? $b : null;
}
$bodyJson = $bodyLatest ? json_encode($bodyLatest, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT) : "{}";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $docNo  = trim($_POST['doc_no'] ?? '');
  $title  = trim($_POST['title'] ?? '');
  $status = trim($_POST['status'] ?? 'draft');
  if ($status === '') $status = 'draft';

  $rawBody = trim($_POST['body_json'] ?? '{}');
  $bodyArr = json_decode($rawBody, true);

  if (!is_array($bodyArr)) {
    $err = "Body JSON geçersiz.";
  } else {
    try {
      // 1) HEADER upsert
      $header = [
        'doc_no' => $docNo,
        'title'  => $title,
        'status' => $status,
      ];

      $hdr = GENDOC01ERepository::upsertHeader(
        [
          'module'=>$module,'doc_type'=>$docType,'doc_id'=>$docId,
          'doc_no'=>$docNo ?: null,
          'doc_title'=>$title ?: null,
          'status'=>$status ?: null,
        ],
        $header,
        $ctx
      );

      $targetKey = (string)($hdr['target_key'] ?? '');
      if ($targetKey === '') throw new RuntimeException('target_key missing');

      // 2) Atomic version (V1→V2→V3)
      $newVersion = GENDOC01ERepository::nextVersion($targetKey, $ctx);

      // 3) BODY insert
      $ins = GENDOC01TRepository::insertVersion(
        $targetKey,
        $newVersion,
        $bodyArr,
        $ctx,
        [
          'module'=>$module,'doc_type'=>$docType,'doc_id'=>$docId,
          'doc_no'=>$docNo ?: null,
          'doc_title'=>$title ?: null,
          'status'=>$status ?: null,
        ]
      );

      // 4) SAVE log
      $saveLogId = ActionLogger::success('GENDOC.ADMIN.SAVE', [
        'source' => 'public/gendoc_admin.php',
        'target_key' => $targetKey,
        'version' => $newVersion,
      ], $ctx);

      // 5) SNAPSHOT final state (header+body)
      $snap = SnapshotWriter::capture(
        [
          'module'    => $module,
          'doc_type'  => $docType,
          'doc_id'    => $docId,
          'doc_no'    => $docNo ?: null,
          'doc_title' => $title ?: null,
          'status'    => $status ?: null,
        ],
        [
          'header' => $header,
          'body'   => $bodyArr,
        ],
        [
          'reason' => 'gendoc_save',
          'changed_fields' => ['header','body'],
          'version' => $newVersion,
        ]
      );

      // 6) EVENT (summary data içine)
      EventWriter::emit(
        'GENDOC.ADMIN.SAVE',
        [
          'source' => 'public/gendoc_admin.php',
          'summary' => [
            'doc_no' => $docNo,
            'title'  => $title,
            'status' => $status,
            'version'=> $newVersion,
            'mode'   => 'gendoc',
          ],
        ],
        [
          'module'    => $module,
          'doc_type'  => $docType,
          'doc_id'    => $docId,
          'doc_no'    => $docNo ?: null,
          'doc_title' => $title ?: null,
          'status'    => $status ?: null,
        ],
        $ctx,
        [
          'log_id'           => $saveLogId,
          'snapshot_id'      => $snap['snapshot_id'] ?? null,
          'prev_snapshot_id' => $snap['prev_snapshot_id'] ?? null,
        ]
      );

      // PRG
      $redir = '/php-mongo-erp/public/gendoc_admin.php?module=' . rawurlencode($module)
             . '&doc_type=' . rawurlencode($docType)
             . '&doc_id=' . rawurlencode($docId)
             . '&toast=save';
      header('Location: ' . $redir);
      exit;

    } catch(Throwable $e) {
      $err = "Kaydetme hatası: " . $e->getMessage();
    }
  }
}

$toast = trim($_GET['toast'] ?? '');
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>GENDOC Admin</title>
  <style>
    body{ font-family: Arial, sans-serif; }
    .bar{ display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin:10px 0; }
    .btn{ padding:6px 10px; border:1px solid #ccc; background:#fff; cursor:pointer; border-radius:6px; text-decoration:none; color:#000; }
    .btn-primary{ border-color:#1e88e5; background:#1e88e5; color:#fff; }
    .small{ font-size:12px; color:#666; }
    input[type="text"], select, textarea{
      width:100%; box-sizing:border-box;
      border:1px solid #ddd; border-radius:8px;
      padding:8px 10px;
      background:#fff; color:#111;
    }
    textarea{ min-height: 280px; font-family: ui-monospace, Menlo, Consolas, monospace; font-size:12px; }
    .grid{ display:grid; grid-template-columns: 1fr 2fr; gap:14px; }
    @media (max-width: 980px){ .grid{ grid-template-columns:1fr; } }
    .card{ border:1px solid #eee; border-radius:12px; padding:12px; }
    .code{ font-family: ui-monospace, Menlo, Consolas, monospace; }
    .lockbar{
      display:flex; gap:10px; align-items:center; margin:10px 0; flex-wrap:wrap;
      padding:8px 10px; border:1px solid #eee; border-radius:8px; background:#fafafa;
    }
    .badge{
      display:inline-block; padding:3px 8px; border-radius:999px; font-size:12px;
      background:#E3F2FD; color:#1565C0; font-weight:600;
    }
  </style>
</head>
<body>

<?php require_once __DIR__ . '/../app/views/layout/header.php'; ?>

<h3>GENDOC Admin</h3>
<div class="small">
  Target:
  <span class="code"><?php echo h($module); ?></span> /
  <span class="code"><?php echo h($docType); ?></span> /
  <span class="code"><?php echo h($docId); ?></span>
  <?php if ($targetKey): ?>
    &nbsp;|&nbsp; target_key: <span class="code"><?php echo h($targetKey); ?></span>
  <?php endif; ?>
</div>

<div class="lockbar">
  <span class="badge">LOCK: editing</span>
  <span class="small" id="lockStatusText">Lock kontrol ediliyor…</span>
  <span class="small" id="saveStatusText"></span>
</div>

<?php if ($err): ?><p style="color:red;"><?php echo h($err); ?></p><?php endif; ?>

<form method="POST">
  <div class="grid">
    <div class="card">
      <div class="small"><b>Header</b></div>

      <div class="bar">
        <div style="flex:1">
          <div class="small">doc_no</div>
          <input type="text" name="doc_no" value="<?php echo h($docNo); ?>" placeholder="DOC-001">
        </div>
        <div style="flex:2">
          <div class="small">title</div>
          <input type="text" name="title" value="<?php echo h($title); ?>" placeholder="Başlık">
        </div>
      </div>

      <div class="bar">
        <div style="flex:1">
          <div class="small">status</div>
          <select name="status">
            <?php foreach (['draft','saved','approving','approved','cancelled'] as $st): ?>
              <option value="<?php echo h($st); ?>" <?php echo ($st===$status?'selected':''); ?>>
                <?php echo h($st); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="bar">
        <button class="btn btn-primary" type="submit">Kaydet</button>
        <a class="btn" target="_blank" href="/php-mongo-erp/public/audit_view.php?module=<?php echo urlencode($module); ?>&doc_type=<?php echo urlencode($docType); ?>&doc_id=<?php echo urlencode($docId); ?>">Audit View</a>
        <a class="btn" target="_blank" href="/php-mongo-erp/public/timeline.php?module=<?php echo urlencode($module); ?>&doc_type=<?php echo urlencode($docType); ?>&doc_id=<?php echo urlencode($docId); ?>">Timeline</a>
      </div>

      <div class="small">
        Not: Kaydet → GENDOC01E header + GENDOC01T version + Snapshot + Event
      </div>
    </div>

    <div class="card">
      <div class="small"><b>Body (JSON)</b></div>
      <textarea name="body_json"><?php echo h($bodyJson); ?></textarea>
      <div class="small" style="margin-top:6px;">Not: Şimdilik JSON; sonra form/fields’e böleriz.</div>
    </div>
  </div>
</form>

<script>
(function(){
  function setSaveStatus(msg){
    const el = document.getElementById('saveStatusText');
    if (el) el.textContent = msg || '';
  }

  // PRG sonrası lockbar içinde göster
  const qs = new URLSearchParams(window.location.search);
  if ((qs.get('toast') || '') === 'save') setSaveStatus('✅ Kaydedildi.');

  const lockStatusText = document.getElementById('lockStatusText');

  const module  = <?php echo json_encode($module); ?>;
  const docType = <?php echo json_encode($docType); ?>;
  const docId   = <?php echo json_encode($docId); ?>;

  // doc_no/title status değişebilir; lock acquire için initial değer yeterli
  const docNo   = <?php echo json_encode($docNo); ?>;
  const docTitle= <?php echo json_encode($title); ?>;

  let acquired = false;

  async function acquireLock(){
    const url = new URL('/php-mongo-erp/public/api/lock_acquire.php', window.location.origin);
    url.searchParams.set('module', module);
    url.searchParams.set('doc_type', docType);
    url.searchParams.set('doc_id', docId);
    url.searchParams.set('status', 'editing');
    url.searchParams.set('ttl', '900');
    if (docNo) url.searchParams.set('doc_no', docNo);
    if (docTitle) url.searchParams.set('doc_title', docTitle);

    try{
      const r = await fetch(url.toString(), { method:'GET', credentials:'same-origin' });
      const j = await r.json();

      if (!j.ok) {
        acquired = false;
        lockStatusText.textContent = 'Lock alınamadı: ' + (j.error || 'unknown');
        return;
      }

      acquired = !!j.acquired;

      if (acquired) {
        lockStatusText.textContent = 'Kilit sende. (editing) — çıkınca otomatik bırakılacak.';
      } else {
        const who = j.lock?.context?.username ? (' (' + j.lock.context.username + ')') : '';
        lockStatusText.textContent = 'Kilit başka bir kullanıcıda' + who + '.';
      }
    } catch(e){
      acquired = false;
      lockStatusText.textContent = 'Lock hatası: ' + e.message;
    }
  }

  function releaseBeacon(){
    if (!acquired) return;

    const url = new URL('/php-mongo-erp/public/api/lock_release_beacon.php', window.location.origin);
    const fd = new FormData();
    fd.append('module', module);
    fd.append('doc_type', docType);
    fd.append('doc_id', docId);

    try{ navigator.sendBeacon(url.toString(), fd); } catch(e){}
  }

  window.addEventListener('beforeunload', releaseBeacon);
  acquireLock();
})();
</script>

</body>
</html>
