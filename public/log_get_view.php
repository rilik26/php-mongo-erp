<?php
/**
 * public/log_get_view.php
 *
 * Log HTML View (V1)
 * - log_id ile API'den log getirir
 * - Kart UI + TR saat formatı
 * - Snapshot/Diff/Audit/Timeline linkleri (varsa)
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

ActionLogger::info('LOG.VIEW', [
  'source' => 'public/log_get_view.php'
], $ctx);

$logId = trim($_GET['log_id'] ?? '');
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Log</title>
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
    .kv{ display:grid; grid-template-columns: 160px 1fr; gap:8px; }
    .kv div{ padding:4px 0; }
    .k{ color:#666; font-size:12px; }
    .v{ font-size:13px; }
  </style>
</head>
<body>

<?php require_once __DIR__ . '/../app/views/layout/header.php'; ?>

<h3>Log View</h3>

<div class="card">
  <div class="bar">
    <label class="small">log_id</label>
    <input id="lid" type="text" style="width:520px" value="<?php echo htmlspecialchars($logId, ENT_QUOTES, 'UTF-8'); ?>" placeholder="...">
    <button class="btn btn-primary" id="btnLoad">Getir</button>
    <a class="btn" href="/php-mongo-erp/public/timeline.php">Timeline</a>
  </div>

  <div id="meta" class="small">Hazır.</div>

  <div id="links" class="bar" style="display:none;"></div>

  <div class="card" style="background:#fafafa;">
    <h4 style="margin:0 0 8px;">Özet</h4>
    <div id="summary" class="kv small">Yükleniyor…</div>
  </div>

  <h4 style="margin:10px 0 6px;">Log JSON (pretty)</h4>
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
  function kvRow(k, v){
    return `<div class="k">${esc(k)}</div><div class="v">${esc(v)}</div>`;
  }

  const lidEl = document.getElementById('lid');
  const outEl = document.getElementById('out');
  const metaEl = document.getElementById('meta');
  const linksEl = document.getElementById('links');
  const summaryEl = document.getElementById('summary');
  const btn = document.getElementById('btnLoad');

  async function load(){
    const logId = (lidEl.value || '').trim();
    if (!logId){
      outEl.textContent = 'log_id gerekli';
      summaryEl.innerHTML = 'log_id gerekli';
      return;
    }

    const api = new URL('/php-mongo-erp/public/api/log_get.php', window.location.origin);
    api.searchParams.set('log_id', logId);

    outEl.textContent = 'Yükleniyor…';
    metaEl.textContent = '...';
    summaryEl.innerHTML = 'Yükleniyor…';
    linksEl.style.display = 'none';
    linksEl.innerHTML = '';

    const r = await fetch(api.toString(), { method:'GET', credentials:'same-origin' });
    const j = await r.json();

    if (!j.ok){
      outEl.textContent = JSON.stringify(j, null, 2);
      metaEl.innerHTML = '<span style="color:red">Hata:</span> ' + esc(j.error || 'api_error');
      summaryEl.innerHTML = '<span style="color:red">' + esc(j.error || 'api_error') + '</span>';
      return;
    }

    const log = j.log || {};
    const action = log.action_code || '';
    const created = log.created_at || '';
    const user = log.context?.username || log.username || '';
    const result = log.result || '';
    const requestId = log.meta?.request_id || '';

    metaEl.innerHTML =
      `<span class="code"><strong>${esc(action)}</strong></span>` +
      ` — ${esc(result)}` +
      ` — ${esc(fmtTR(created))}` +
      (user ? ` — <strong>${esc(user)}</strong>` : '') +
      (requestId ? ` — request_id: <span class="code">${esc(requestId)}</span>` : '');

    // summary (readable)
    const c = log.context || {};
    const t = log.target || {};
    summaryEl.innerHTML = '';
    summaryEl.innerHTML += kvRow('action_code', action);
    summaryEl.innerHTML += kvRow('result', result);
    summaryEl.innerHTML += kvRow('created_at', fmtTR(created));
    summaryEl.innerHTML += kvRow('username', c.username || log.username || '');
    summaryEl.innerHTML += kvRow('role', c.role || log.role || '');
    summaryEl.innerHTML += kvRow('company', c.CDEF01_id || log.CDEF01_id || '');
    summaryEl.innerHTML += kvRow('period', c.period_id || log.period_id || '');
    summaryEl.innerHTML += kvRow('facility', (c.facility_id ?? log.facility_id) ?? '');
    summaryEl.innerHTML += kvRow('session_id', c.session_id || log.session_id || '');
    if (t && typeof t === 'object'){
      summaryEl.innerHTML += kvRow('target.module', t.module || '');
      summaryEl.innerHTML += kvRow('target.doc_type', t.doc_type || '');
      summaryEl.innerHTML += kvRow('target.doc_id', t.doc_id || '');
      summaryEl.innerHTML += kvRow('target.doc_no', t.doc_no || '');
    }

    // links
    const jsonUrl = '/php-mongo-erp/public/api/log_get.php?log_id=' + encodeURIComponent(logId);

    // refs -> snapshot/diff/audit/timeline
    const refs = log.refs || {}; // bazı sistemlerde log’da refs olmayabilir; varsa kullan
    const snapId = refs.snapshot_id || null;
    const prevSnapId = refs.prev_snapshot_id || null;

    let linksHtml = '';
    linksHtml += `<a class="btn" target="_blank" href="${esc(jsonUrl)}">JSON</a>`;

    if (snapId){
      linksHtml += `<a class="btn" target="_blank" href="/php-mongo-erp/public/snapshot_get_view.php?snapshot_id=${encodeURIComponent(snapId)}">Snapshot</a>`;
      linksHtml += `<a class="btn" target="_blank" href="/php-mongo-erp/public/snapshot_diff_view.php?snapshot_id=${encodeURIComponent(snapId)}">Diff</a>`;
      if (prevSnapId){
        linksHtml += `<a class="btn" href="/php-mongo-erp/public/snapshot_get_view.php?snapshot_id=${encodeURIComponent(prevSnapId)}">← Önceki Snapshot</a>`;
      }
    }

    // target_key varsa audit/timeline'a bağla
    const targetKey = log.target_key || '';
    if (targetKey){
      linksHtml += `<a class="btn" target="_blank" href="/php-mongo-erp/public/audit_view.php?target_key=${encodeURIComponent(targetKey)}">Audit</a>`;
      linksHtml += `<a class="btn" target="_blank" href="/php-mongo-erp/public/timeline.php?target_key=${encodeURIComponent(targetKey)}">Timeline</a>`;
    }

    linksEl.innerHTML = linksHtml;
    linksEl.style.display = 'flex';

    outEl.textContent = JSON.stringify(j, null, 2);
  }

  btn.addEventListener('click', load);
  load();
})();
</script>

</body>
</html>
