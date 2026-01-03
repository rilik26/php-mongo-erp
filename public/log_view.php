<?php
/**
 * public/log_view.php (FINAL - THEME)
 * HTML wrapper for /public/api/log_get.php?log_id=...
 *
 * - log_id required + basic validate (24 char ObjectId)
 * - Theme layout + JSON render
 */

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/auth/SessionManager.php';
require_once __DIR__ . '/../core/base/Context.php';
require_once __DIR__ . '/../core/base/ContextException.php';

SessionManager::start();

if (!isset($_SESSION['context']) || !is_array($_SESSION['context'])) {
  header('Location: /php-mongo-erp/public/login.php');
  exit;
}

try { Context::bootFromSession(); }
catch (Throwable $e) {
  header('Location: /php-mongo-erp/public/login.php');
  exit;
}

$logId = trim((string)($_GET['log_id'] ?? ''));
if ($logId === '') {
  http_response_code(400);
  echo "log_id_required";
  exit;
}

if (strlen($logId) !== 24 || !preg_match('/^[a-f0-9]{24}$/i', $logId)) {
  http_response_code(400);
  echo "log_id_invalid";
  exit;
}

$apiUrl = '/php-mongo-erp/public/api/log_get.php?log_id=' . urlencode($logId);

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

require_once __DIR__ . '/../app/views/layout/header.php';
?>
<body>
<div class="layout-wrapper layout-content-navbar">
  <div class="layout-container">
    <?php require_once __DIR__ . '/../app/views/layout/left.php'; ?>
    <div class="layout-page">
      <?php require_once __DIR__ . '/../app/views/layout/header2.php'; ?>

      <div class="content-wrapper">
        <div class="container-xxl flex-grow-1 container-p-y">

          <div class="card">
            <div class="card-body">
              <div class="d-flex align-items-center flex-wrap gap-2">
                <h4 class="mb-0">Log</h4>
                <span class="text-muted">log_id:</span>
                <span class="font-monospace"><?php echo h($logId); ?></span>
                <span style="flex:1"></span>
                <a class="btn btn-outline-primary btn-sm" target="_blank" href="<?php echo h($apiUrl); ?>">JSON</a>
              </div>
            </div>
          </div>

          <div class="card mt-4">
            <div class="card-body">
              <pre id="data" style="background:#0b1020;color:#eaeef7;padding:12px;border-radius:12px;overflow:auto;min-height:220px;">Yükleniyor…</pre>
              <div class="text-muted mt-2" style="font-size:12px;">
                Not: Bu ekran, API’den gelen log JSON’unu gösterir.
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
  const pre = document.getElementById('data');

  fetch(<?php echo json_encode($apiUrl); ?>, { method:'GET', credentials:'same-origin' })
    .then(r => r.json())
    .then(d => {
      if (!d || !d.ok) throw new Error((d && (d.error_detail || d.error)) || 'api_error');
      pre.textContent = JSON.stringify(d.log || d, null, 2);
    })
    .catch(err => {
      pre.textContent = 'Hata: ' + (err && err.message ? err.message : 'unknown');
    });
})();
</script>

</body>
</html>
