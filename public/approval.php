<?php
/**
 * public/approval.php (FINAL)
 *
 * Approval View (V1)
 * - Tek evrak için approve/reject
 * - Workflow status göster
 * - Timeline linkleri
 */

require_once __DIR__ . '/../app/views/layout/header.php';

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

ActionLogger::info('APPROVAL.VIEW', ['source' => 'public/approval.php'], $ctx);

$module  = trim($_GET['module'] ?? 'i18n');
$docType = trim($_GET['doc_type'] ?? 'LANG01T');
$docId   = trim($_GET['doc_id'] ?? 'DICT');

if (!function_exists('esc')) {
  function esc($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
?>

<body>
<div class="layout-wrapper layout-content-navbar">
  <div class="layout-container">

    <?php require_once __DIR__ . '/../app/views/layout/left.php'; ?>

    <div class="layout-page">

      <?php require_once __DIR__ . '/../app/views/layout/header2.php'; ?>

      <div class="content-wrapper">
        <div class="container-xxl flex-grow-1 container-p-y">

          <style>
            .cardx{ border:1px solid #eee; padding:12px; border-radius:10px; max-width:900px; margin:14px auto; }
            .rowx{ display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
            .btnx{ padding:8px 12px; border-radius:8px; border:1px solid #ccc; background:#fff; cursor:pointer; }
            .btnx-ok{ background:#2e7d32; color:#fff; border-color:#2e7d32; }
            .btnx-no{ background:#e53935; color:#fff; border-color:#e53935; }
            .mutedx{ color:#777; font-size:12px; }
            .codex{ font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
          </style>

          <div class="cardx">
            <h3>Approval (V1)</h3>

            <div class="mutedx">
              user: <b><?php echo esc($ctx['username'] ?? ''); ?></b>
              &nbsp;|&nbsp; role: <b><?php echo esc($ctx['role'] ?? ''); ?></b>
            </div>

            <div style="margin-top:10px">
              Target:
              <span class="codex"><?php echo esc($module); ?></span> /
              <span class="codex"><?php echo esc($docType); ?></span> /
              <span class="codex"><?php echo esc($docId); ?></span>
            </div>

            <div class="rowx" style="margin-top:12px">
              <button class="btnx btnx-ok" onclick="setStatus('approved')">Approve</button>
              <button class="btnx btnx-no" onclick="setStatus('rejected')">Reject</button>
              <button class="btnx" onclick="setStatus('approving')">Mark Approving</button>
              <button class="btnx" onclick="setStatus('editing')">Back to Editing</button>
            </div>

            <div style="margin-top:14px" class="rowx">
              <a class="btnx" href="/php-mongo-erp/public/timeline.php?module=<?php echo urlencode($module); ?>&doc_type=<?php echo urlencode($docType); ?>&doc_id=<?php echo urlencode($docId); ?>">Open Timeline</a>
              <a class="btnx" href="/php-mongo-erp/public/audit_view.php?module=<?php echo urlencode($module); ?>&doc_type=<?php echo urlencode($docType); ?>&doc_id=<?php echo urlencode($docId); ?>">Open Audit</a>
            </div>

            <div style="margin-top:14px">
              <h4>Workflow</h4>
              <pre id="wfBox" style="background:#fafafa;border:1px solid #eee;border-radius:10px;padding:10px;white-space:pre-wrap;"></pre>
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

<script>
function toast(type,msg){
  if (typeof window.showToast === 'function') return window.showToast(type,msg);
  alert(msg);
}

const module_  = <?php echo json_encode($module); ?>;
const docType_ = <?php echo json_encode($docType); ?>;
const docId_   = <?php echo json_encode($docId); ?>;

async function refreshWF(){
  const url = new URL('/php-mongo-erp/public/api/workflow_get.php', window.location.origin);
  url.searchParams.set('module', module_);
  url.searchParams.set('doc_type', docType_);
  url.searchParams.set('doc_id', docId_);

  const r = await fetch(url.toString(), { credentials:'same-origin' });
  const j = await r.json();
  document.getElementById('wfBox').textContent = JSON.stringify(j, null, 2);
}

async function setStatus(st){
  const url = new URL('/php-mongo-erp/public/api/workflow_set.php', window.location.origin);
  const fd = new FormData();
  fd.append('module', module_);
  fd.append('doc_type', docType_);
  fd.append('doc_id', docId_);
  fd.append('status', st);

  const r = await fetch(url.toString(), { method:'POST', body: fd, credentials:'same-origin' });
  const j = await r.json();
  if (!j.ok) {
    toast('error', 'Workflow set error: ' + (j.error || 'unknown'));
  } else {
    toast('success', 'Workflow status: ' + st);
  }
  refreshWF();
}

refreshWF();
</script>

<?php require_once __DIR__ . '/../app/views/layout/footer.php'; ?>
</body>
</html>
