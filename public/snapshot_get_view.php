<?php
/**
 * public/snapshot_get_view.php
 *
 * Snapshot HTML View (V1)
 * - snapshot_id ile API'den snapshot getirir
 * - Kart UI ile gösterir
 * - JSON linki + Diff linki + Audit/Timeline linki verir
 *
 * Guard:
 * - login şart
 */

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/auth/SessionManager.php';
require_once __DIR__ . '/../core/base/Context.php';
require_once __DIR__ . '/../core/base/ContextException.php';
require_once __DIR__ . '/../core/action/ActionLogger.php';

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

ActionLogger::info('SNAPSHOT.VIEW', [
  'source' => 'public/snapshot_get_view.php'
], $ctx);

$snapshotId = trim($_GET['snapshot_id'] ?? '');
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Snapshot</title>
  <style>
    body{ font-family: Arial, sans-serif; }
    .card{ border:1px solid #eee; padding:12px; border-radius:12px; background:#fff; margin:10px 0; }
    .bar{ display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin:10px 0; }
    .btn{ padding:6px 10px; border:1px solid #ccc; background:#fff; cursor:pointer; text-decoration:none; color:#000; border-radius:6px; display:inline-block; }
    .btn-primary{ border-color:#1e88e5; background:#1e88e5; color:#fff; }
    .small{ font-size:12px; color:#666; }
    .code{ font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
    pre{ background:#0b1020; color:#eaeef7; padding:12px; border-radius:10px; overflow:auto; }
    input{ padding:6px 8px; border:1px solid #ddd; border-radius:8px; background:#fff; color:#000; }
  </style>
</head>
<body>

<?php require_once __DIR__ . '/../app/views/layout/header.php'; ?>

<h3>Snapshot View</h3>

<div class="card">
  <div class="bar">
    <label class="small">snapshot_id</label>
    <input id="sid" type="text" style="width:520px" value="<?php echo htmlspecialchars($snapshotId, ENT_QUOTES, 'UTF-8'); ?>" placeholder="...">
    <button class="btn btn-primary" id="btnLoad">Getir</button>
    <a class="btn" href="/php-mongo-erp/public/docs.php">Evrak Listesi</a>
  </div>

  <div id="meta" class="small">Hazır.</div>
  <div id="links" class="bar" style="display:none;"></div>

  <h4 style="margin:10px 0 6px;">Snapshot JSON (pretty)</h4>
  <pre id="out">Yükleniyor…</pre>
</div>

<script>
(function(){
  function esc(s){
    return String(s ?? '').replace(/[&<>"']/g, (m)=>( {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m] ));
  }
  function fmtTR(iso){
    if (!iso) return '';
    try{
      const d = new Date(iso);
      if (isNaN(d.getTime())) return String(iso);
      return new Intl.DateTimeFormat('tr-TR', {
        year:'numeric', month:'2-digit', day:'2-digit',
        hour:'2-digit', minute:'2-digit', second:'2-digit'
      }).format(d);
    }catch(e){ return String(iso); }
  }

  const sidEl = document.getElementById('sid');
  const outEl = document.getElementById('out');
  const metaEl = document.getElementById('meta');
  const linksEl = document.getElementById('links');
  const btn = document.getElementById('btnLoad');

  async function load(){
    const sid = (sidEl.value || '').trim();
    if (!sid){
      outEl.textContent = 'snapshot_id gerekli';
      return;
    }

    const api = new URL('/php-mongo-erp/public/api/snapshot_get.php', window.location.origin);
    api.searchParams.set('snapshot_id', sid);

    outEl.textContent = 'Yükleniyor…';
    metaEl.textContent = '...';

    const r = await fetch(api.toString(), { method:'GET', credentials:'same-origin' });
    const j = await r.json();

    if (!j.ok){
      outEl.textContent = JSON.stringify(j, null, 2);
      metaEl.innerHTML = '<span style="color:red">Hata:</span> ' + esc(j.error || 'api_error');
      linksEl.style.display = 'none';
      return;
    }

    const s = j.snapshot || {};
    const targetKey = s.target_key || '';
    const ver = s.version ?? '';
    const created = s.created_at || '';
    const user = s.context?.username || '';
    const prevId = s.prev_snapshot_id || null;

    metaEl.innerHTML =
      '<span class="code">v' + esc(ver) + '</span>' +
      ' — ' + esc(fmtTR(created)) +
      ' — <strong>' + esc(user) + '</strong>' +
      (targetKey ? ' — target_key: <span class="code">' + esc(targetKey) + '</span>' : '');

    const jsonUrl = '/php-mongo-erp/public/api/snapshot_get.php?snapshot_id=' + encodeURIComponent(sid);
    const diffUrl = '/php-mongo-erp/public/snapshot_diff_view.php?snapshot_id=' + encodeURIComponent(sid);
    const auditUrl = targetKey ? ('/php-mongo-erp/public/audit_view.php?target_key=' + encodeURIComponent(targetKey)) : '#';
    const tlUrl    = targetKey ? ('/php-mongo-erp/public/timeline.php?target_key=' + encodeURIComponent(targetKey)) : '#';

    let linksHtml = '';
    linksHtml += '<a class="btn" target="_blank" href="' + esc(jsonUrl) + '">JSON</a>';
    linksHtml += '<a class="btn" target="_blank" href="' + esc(diffUrl) + '">Diff</a>';
    if (targetKey){
      linksHtml += '<a class="btn" target="_blank" href="' + esc(auditUrl) + '">Audit</a>';
      linksHtml += '<a class="btn" target="_blank" href="' + esc(tlUrl) + '">Timeline</a>';
    }
    if (prevId){
      linksHtml += '<a class="btn" href="/php-mongo-erp/public/snapshot_get_view.php?snapshot_id=' + encodeURIComponent(prevId) + '">← Önceki Versiyon</a>';
    }

    linksEl.innerHTML = linksHtml;
    linksEl.style.display = 'flex';

    outEl.textContent = JSON.stringify(j, null, 2);
  }

  btn.addEventListener('click', load);

  // page load auto
  load();
})();
</script>

</body>
</html>
