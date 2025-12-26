<?php
/**
 * public/log_view.php
 * HTML wrapper for /public/api/log_get.php?log_id=...
 */

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/auth/SessionManager.php';

SessionManager::start();

$logId = trim($_GET['log_id'] ?? '');
if ($logId === '') {
    http_response_code(400);
    echo "log_id required";
    exit;
}

$apiUrl = '/php-mongo-erp/public/api/log_get.php?log_id=' . urlencode($logId);

function esc($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8" />
  <title>Log</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <style>
    body{ font-family:Arial,sans-serif; margin:16px; background:#0f1220; color:#e7eaf3; }
    .card{ background:#1b1f33; border:1px solid rgba(255,255,255,.10); border-radius:14px; padding:14px; margin-bottom:12px; }
    .muted{ color:rgba(231,234,243,.65); font-size:13px; }
    .code{ font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
    .row{ display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
    .btn{ display:inline-block; padding:8px 12px; border-radius:12px; border:1px solid rgba(255,255,255,.14); text-decoration:none; color:#e7eaf3; }
    pre{ white-space:pre-wrap; word-break:break-word; background:#101327; border:1px solid rgba(255,255,255,.10); padding:12px; border-radius:12px; }
  </style>
</head>
<body>

<div class="card">
  <div class="row">
    <h2 style="margin:0;">Log</h2>
    <span class="muted">log_id:</span>
    <span class="code"><?php echo esc($logId); ?></span>
    <a class="btn" target="_blank" href="<?php echo esc($apiUrl); ?>">JSON</a>
  </div>
</div>

<div class="card" id="data"><div class="muted">Yükleniyor…</div></div>

<script>
(function(){
  const data = document.getElementById('data');

  function esc(s){
    return String(s ?? '').replace(/[&<>"']/g, (m)=>({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[m]));
  }

  fetch(<?php echo json_encode($apiUrl); ?>, { method:'GET' })
    .then(r => r.json())
    .then(d => {
      if (!d.ok) throw new Error(d.error || 'api_error');
      data.innerHTML = `<pre class="code">${esc(JSON.stringify(d.log || d, null, 2))}</pre>`;
    })
    .catch(err => {
      data.innerHTML = `<div style="color:#ff7b7b">Hata: ${esc(err.message)}</div>`;
    });
})();
</script>

</body>
</html>
