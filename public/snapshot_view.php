<?php
/**
 * public/snapshot_view.php
 * HTML wrapper for /public/api/snapshot_get.php?snapshot_id=...
 */

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/auth/SessionManager.php';

SessionManager::start();

$snapshotId = trim($_GET['snapshot_id'] ?? '');
if ($snapshotId === '') {
    http_response_code(400);
    echo "snapshot_id required";
    exit;
}

$apiUrl = '/php-mongo-erp/public/api/snapshot_get.php?snapshot_id=' . urlencode($snapshotId);

function esc($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8" />
  <title>Snapshot</title>
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
    <h2 style="margin:0;">Snapshot</h2>
    <span class="muted">snapshot_id:</span>
    <span class="code"><?php echo esc($snapshotId); ?></span>
    <a class="btn" target="_blank" href="<?php echo esc($apiUrl); ?>">JSON</a>
  </div>
  <div class="muted">Snapshot içeriği JSON olarak aşağıda gösterilir (okunur).</div>
</div>

<div class="card" id="meta"><div class="muted">Yükleniyor…</div></div>
<div class="card" id="data"><div class="muted">Yükleniyor…</div></div>

<script>
(function(){
  const meta = document.getElementById('meta');
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

      const s = d.snapshot || {};
      meta.innerHTML = `
        <div class="row">
          <div class="muted">target_key:</div>
          <div class="code">${esc(s.target_key || '-')}</div>
        </div>
        <div class="row">
          <div class="muted">version:</div><div class="code">${esc(s.version || '-')}</div>
          <div class="muted">created_at:</div><div class="code">${esc(s.created_at || '-')}</div>
          <div class="muted">user:</div><div class="code">${esc(s.context?.username || '-')}</div>
        </div>
      `;

      data.innerHTML = `<pre class="code">${esc(JSON.stringify(s, null, 2))}</pre>`;
    })
    .catch(err => {
      meta.innerHTML = `<div style="color:#ff7b7b">Hata: ${esc(err.message)}</div>`;
      data.innerHTML = `<div class="muted">Detay için JSON butonuna bas.</div>`;
    });
})();
</script>

</body>
</html>
