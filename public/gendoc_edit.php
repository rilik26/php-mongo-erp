<?php
/**
 * public/gendoc_edit.php (FINAL)
 *
 * GENDOC Edit
 * - Header (GENDOC01E): doc_no, doc_title, status
 * - Body   (GENDOC01T): content (json/text)
 *
 * AUDIT:
 * - UACT01E log: GENDOC.EDIT.VIEW / GENDOC.EDIT.SAVE
 * - EVENT01E event: GENDOC.EDIT.VIEW / GENDOC.EDIT.SAVE
 * - SNAP01E snapshot: GENDOC01T final state
 *
 * LOCK:
 * - page load -> auto acquire (editing)
 * - page exit -> beacon ile release
 *
 * Links:
 * - Timeline / Audit / Snapshot Chain
 */

require_once __DIR__ . '/../core/bootstrap.php';

require_once __DIR__ . '/../core/auth/SessionManager.php';
require_once __DIR__ . '/../core/base/Context.php';
require_once __DIR__ . '/../core/base/ContextException.php';

require_once __DIR__ . '/../core/action/ActionLogger.php';
require_once __DIR__ . '/../core/event/EventWriter.php';
require_once __DIR__ . '/../core/snapshot/SnapshotWriter.php';

require_once __DIR__ . '/../core/auth/permission_helpers.php';

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

// permission (istersen ayrı permission açarız)
// require_perm('gendoc.manage');

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$id = trim($_GET['id'] ?? '');
if ($id === '') {
  http_response_code(400);
  echo "Missing id";
  exit;
}

// ---- VIEW log + event ----
$viewLogId = ActionLogger::info('GENDOC.EDIT.VIEW', [
  'source' => 'public/gendoc_edit.php',
  'doc_id' => $id
], $ctx);

EventWriter::emit(
  'GENDOC.EDIT.VIEW',
  ['source' => 'public/gendoc_edit.php'],
  [
    'module'   => 'gendoc',
    'doc_type' => 'GENDOC01T',
    'doc_id'   => $id,
    'doc_no'   => null
  ],
  $ctx,
  ['log_id' => $viewLogId]
);

$msg = null;
$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $docNo    = trim($_POST['doc_no'] ?? '');
  $docTitle = trim($_POST['doc_title'] ?? '');
  $status   = trim($_POST['status'] ?? 'draft');
  if (!in_array($status, ['draft','saved','approved','cancelled'], true)) $status = 'draft';

  $contentRaw = (string)($_POST['content'] ?? '');
  $content = null;

  // content json ise parse et, değilse text olarak sakla
  $try = json_decode($contentRaw, true);
  if (is_array($try)) $content = $try;
  else $content = ['text' => $contentRaw];

  try {
    // Header upsert
    GENDOC01ERepository::upsertHeader($id, [
      'doc_no' => $docNo !== '' ? $docNo : null,
      'doc_title' => $docTitle !== '' ? $docTitle : null,
      'status' => $status,
    ], $ctx);

    // Body upsert
    GENDOC01TRepository::upsertBody($id, [
      'content' => $content
    ], $ctx);

    // SAVE log
    $saveLogId = ActionLogger::success('GENDOC.EDIT.SAVE', [
      'source' => 'public/gendoc_edit.php',
      'doc_id' => $id
    ], $ctx);

    // Snapshot final state
    $header = GENDOC01ERepository::getHeader($id);
    $body   = GENDOC01TRepository::getBody($id);

    $snap = SnapshotWriter::capture(
      [
        'module'   => 'gendoc',
        'doc_type' => 'GENDOC01T',
        'doc_id'   => $id,
        'doc_no'   => $header['doc_no'] ?? null,
        'doc_title'=> $header['doc_title'] ?? null,
      ],
      [
        'header' => $header,
        'body'   => $body,
      ],
      [
        'reason' => 'gendoc_save',
        'changed_fields' => ['header','body'],
      ]
    );

    // Event (summary data içine)
    EventWriter::emit(
      'GENDOC.EDIT.SAVE',
      [
        'source' => 'public/gendoc_edit.php',
        'summary' => [
          'mode' => 'gendoc',
          'status' => $status,
        ],
      ],
      [
        'module'   => 'gendoc',
        'doc_type' => 'GENDOC01T',
        'doc_id'   => $id,
        'doc_no'   => $header['doc_no'] ?? null,
        'doc_title'=> $header['doc_title'] ?? null,
      ],
      $ctx,
      [
        'log_id'           => $saveLogId,
        'snapshot_id'      => $snap['snapshot_id'] ?? null,
        'prev_snapshot_id' => $snap['prev_snapshot_id'] ?? null,
      ]
    );

    // PRG
    header('Location: /php-mongo-erp/public/gendoc_edit.php?id=' . rawurlencode($id) . '&saved=1');
    exit;

  } catch (Throwable $e) {
    $err = 'Save error: ' . $e->getMessage();
  }
}

$header = GENDOC01ERepository::getHeader($id);
$body   = GENDOC01TRepository::getBody($id);

$savedFlag = (($_GET['saved'] ?? '') === '1');

// Lock target info (JS)
$lockModule = 'gendoc';
$lockDocType = 'GENDOC01T';
$lockDocId = $id;
$lockDocNo = (string)($header['doc_no'] ?? '');
$lockDocTitle = (string)($header['doc_title'] ?? '');

$contentForTextarea = '';
if (isset($body['content'])) {
  $contentForTextarea = json_encode($body['content'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
} else {
  $contentForTextarea = json_encode(['text'=>''], JSON_PRETTY_PRINT);
}

?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>GENDOC Edit</title>
  <style>
    body{ font-family: Arial, sans-serif; }
    .bar{ display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin:10px 0; }
    .btn{ padding:6px 10px; border:1px solid #ccc; background:#fff; cursor:pointer; text-decoration:none; color:#000; border-radius:6px; }
    .btn-primary{ border-color:#1e88e5; background:#1e88e5; color:#fff; }
    .small{ font-size:12px; color:#666; }
    .grid{ display:grid; grid-template-columns: 1fr 1fr; gap:16px; }
    @media (max-width: 1100px){ .grid{ grid-template-columns: 1fr; } }
    .card{ border:1px solid #eee; padding:12px; border-radius:8px; }
    input[type="text"], select, textarea{
      width:100%;
      box-sizing:border-box;
      padding:8px 10px;
      border:1px solid #ddd;
      border-radius:8px;
      background:#fff;
      color:#111;
    }
    textarea{ min-height: 360px; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono","Courier New", monospace; }
    .lockbar{
      display:flex; gap:10px; align-items:center; margin:10px 0; flex-wrap:wrap;
      padding:8px 10px; border:1px solid #eee; border-radius:8px; background:#fafafa;
    }
    .badge{
      display:inline-block; padding:3px 8px; border-radius:999px; font-size:12px;
      background:#E3F2FD; color:#1565C0; font-weight:600;
    }
    .msg-ok{ color: #2e7d32; }
    .msg-err{ color: #c62828; }
    .hint{ color:#666; font-size:12px; }
  </style>
</head>
<body>

<?php require_once __DIR__ . '/../app/views/layout/header.php'; ?>

<h3>GENDOC Edit</h3>
<div class="small">
  Kullanıcı: <b><?php echo h($ctx['username'] ?? ''); ?></b>
  &nbsp;|&nbsp; Firma: <b><?php echo h($ctx['CDEF01_id'] ?? ''); ?></b>
  &nbsp;|&nbsp; Dönem: <b><?php echo h($ctx['period_id'] ?? ''); ?></b>
  &nbsp;|&nbsp; doc_id: <b><?php echo h($id); ?></b>
</div>

<div class="lockbar">
  <span class="badge">LOCK: editing</span>
  <span class="small" id="lockStatusText">Lock kontrol ediliyor…</span>
  <span class="small" id="saveStatusText"></span>
</div>

<?php if ($savedFlag): ?>
  <div class="msg-ok small">Kaydedildi.</div>
<?php endif; ?>
<?php if ($err): ?>
  <div class="msg-err small"><?php echo h($err); ?></div>
<?php endif; ?>

<div class="bar">
  <a class="btn" href="/php-mongo-erp/public/gendoc_list.php">Liste</a>
  <a class="btn" target="_blank" href="/php-mongo-erp/public/timeline.php?module=gendoc&doc_type=GENDOC01T&doc_id=<?php echo rawurlencode($id); ?>">Timeline</a>
  <a class="btn" target="_blank" href="/php-mongo-erp/public/audit_view.php?module=gendoc&doc_type=GENDOC01T&doc_id=<?php echo rawurlencode($id); ?>">Audit View</a>
</div>

<form method="POST" id="docForm">
  <div class="grid">
    <div class="card">
      <h4>Header</h4>

      <div class="bar">
        <div style="flex:1; min-width:220px;">
          <div class="small">doc_no</div>
          <input type="text" name="doc_no" value="<?php echo h($header['doc_no'] ?? ''); ?>" placeholder="örn: GD-0001">
        </div>

        <div style="flex:2; min-width:260px;">
          <div class="small">doc_title</div>
          <input type="text" name="doc_title" value="<?php echo h($header['doc_title'] ?? ''); ?>" placeholder="örn: Genel Evrak Başlık">
        </div>

        <div style="width:200px;">
          <div class="small">status</div>
          <select name="status">
            <?php
              $st = (string)($header['status'] ?? 'draft');
              foreach (['draft','saved','approved','cancelled'] as $opt){
                $sel = ($st === $opt) ? 'selected' : '';
                echo '<option value="'.h($opt).'" '.$sel.'>'.h($opt).'</option>';
              }
            ?>
          </select>
        </div>
      </div>

      <div class="hint">
        Not: İçerik JSON saklanır. JSON değilse <code>{"text":"..."}</code> içine alınır.
      </div>
    </div>

    <div class="card">
      <h4>Body</h4>
      <textarea name="content"><?php echo h($contentForTextarea); ?></textarea>
    </div>
  </div>

  <div class="bar">
    <button class="btn btn-primary" type="submit">Kaydet</button>
  </div>
</form>

<script>
(function(){
  function toast(type, msg){
    if (typeof window.showToast === 'function') { window.showToast(type, msg); return; }
    if (type === 'success') console.log(msg); else alert(msg);
  }

  const lockStatusText = document.getElementById('lockStatusText');
  const saveStatusText = document.getElementById('saveStatusText');
  const form = document.getElementById('docForm');

  const module  = <?php echo json_encode($lockModule); ?>;
  const docType = <?php echo json_encode($lockDocType); ?>;
  const docId   = <?php echo json_encode($lockDocId); ?>;
  const docNo   = <?php echo json_encode($lockDocNo); ?>;
  const docTitle= <?php echo json_encode($lockDocTitle); ?>;

  let acquired = false;

  function setReadOnlyMode(isReadOnly){
    if (!form) return;
    const inputs = form.querySelectorAll('input, textarea, select, button');
    inputs.forEach(el => {
      if (el.tagName === 'BUTTON' && el.type === 'submit') el.disabled = !!isReadOnly;
      if (el.tagName !== 'BUTTON') el.disabled = !!isReadOnly;
    });
  }

  async function acquireLock(){
    const url = new URL('/php-mongo-erp/public/api/lock_acquire.php', window.location.origin);
    url.searchParams.set('module', module);
    url.searchParams.set('doc_type', docType);
    url.searchParams.set('doc_id', docId);
    url.searchParams.set('status', 'editing');
    url.searchParams.set('ttl', '900');
    url.searchParams.set('doc_no', docNo);
    url.searchParams.set('doc_title', docTitle);

    try{
      const r = await fetch(url.toString(), { method:'GET', credentials:'same-origin' });
      const j = await r.json();

      if (!j.ok) {
        acquired = false;
        setReadOnlyMode(true);
        lockStatusText.textContent = 'Lock alınamadı: ' + (j.error || 'unknown');
        return;
      }

      acquired = !!j.acquired;

      if (acquired) {
        setReadOnlyMode(false);
        lockStatusText.textContent = 'Kilit sende (editing). Çıkınca otomatik bırakılır.';
      } else {
        setReadOnlyMode(true);
        const who = j.lock?.context?.username ? (' (' + j.lock.context.username + ')') : '';
        lockStatusText.textContent = 'Kilit başka kullanıcıda' + who + '. Read-only.';
      }
    } catch(e){
      acquired = false;
      setReadOnlyMode(true);
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

  // save feedback: URL param saved=1 varsa lockbar'a yaz
  const u = new URL(window.location.href);
  if (u.searchParams.get('saved') === '1') {
    saveStatusText.textContent = '— Kaydedildi ✅';
  }

  acquireLock();
})();
</script>

</body>
</html>
