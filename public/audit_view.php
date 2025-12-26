<?php
/**
 * public/audit_view.php
 *
 * Audit View (V1) - FINAL
 * - Tek hedef evrak için:
 *   - Snapshot zinciri (V1 -> V2 -> V3)
 *   - Event listesi
 *   - Her snapshot satırında Diff linki
 *
 * Guard:
 * - login şart
 * (permission'i V2'de audit.view olarak ekleriz)
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

ActionLogger::info('AUDIT.VIEW', [
    'source' => 'public/audit_view.php'
], $ctx);

// input
$targetKey = trim($_GET['target_key'] ?? '');
$module    = trim($_GET['module'] ?? '');
$docType   = trim($_GET['doc_type'] ?? '');
$docId     = trim($_GET['doc_id'] ?? '');

if ($targetKey === '' && ($module === '' || $docType === '' || $docId === '')) {
    $module = $module ?: 'i18n';
    $docType = $docType ?: 'LANG01T';
    $docId = $docId ?: 'DICT';
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Audit View</title>
  <style>
    body{ font-family: Arial, sans-serif; }
    table { border-collapse: collapse; width: 100%; }
    th, td { border:1px solid #ddd; padding:8px; vertical-align: top; }
    th { background:#f7f7f7; text-align:left; }
    .bar{ display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin:10px 0; }
    .btn{ padding:6px 10px; border:1px solid #ccc; background:#fff; cursor:pointer; text-decoration:none; color:#000; }
    .btn-primary{ border-color:#1e88e5; background:#1e88e5; color:#fff; }
    .small{ font-size:12px; color:#666; }
    .code{ font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
    .grid{ display:grid; grid-template-columns: 1fr 1fr; gap:16px; }
    @media (max-width: 1100px){ .grid{ grid-template-columns: 1fr; } }
    .card{ border:1px solid #eee; padding:10px; border-radius:8px; }
  </style>
</head>
<body>

<?php require_once __DIR__ . '/../app/views/layout/header.php'; ?>

<h3>Audit View (V1)</h3>
<div class="small">
  Firma: <strong><?php echo htmlspecialchars($ctx['CDEF01_id'] ?? ''); ?></strong>
  &nbsp;|&nbsp; Dönem: <strong><?php echo htmlspecialchars($ctx['period_id'] ?? ''); ?></strong>
  &nbsp;|&nbsp; Kullanıcı: <strong><?php echo htmlspecialchars($ctx['username'] ?? ''); ?></strong>
</div>

<form method="GET" class="bar">
  <label class="small">module</label>
  <input type="text" name="module" value="<?php echo htmlspecialchars($module, ENT_QUOTES, 'UTF-8'); ?>" placeholder="i18n">

  <label class="small">doc_type</label>
  <input type="text" name="doc_type" value="<?php echo htmlspecialchars($docType, ENT_QUOTES, 'UTF-8'); ?>" placeholder="LANG01T">

  <label class="small">doc_id</label>
  <input type="text" name="doc_id" value="<?php echo htmlspecialchars($docId, ENT_QUOTES, 'UTF-8'); ?>" placeholder="DICT">

  <label class="small">target_key</label>
  <input type="text" name="target_key" style="width:520px"
         value="<?php echo htmlspecialchars($targetKey, ENT_QUOTES, 'UTF-8'); ?>"
         placeholder="(opsiyonel) module|doc_type|doc_id|CDEF01_id|period_id|facility_id">

  <button class="btn btn-primary" type="submit">Getir</button>
  <a class="btn" href="/php-mongo-erp/public/audit_view.php">Sıfırla</a>
</form>

<div class="grid">
  <div class="card">
    <h4>Snapshot Zinciri</h4>
    <div class="small">V1→V2→V3… listelenir. “Diff” her versiyonu bir öncekiyle kıyaslar.</div>
    <table id="snapTable">
      <tr><td colspan="5" class="small">Yükleniyor…</td></tr>
    </table>
  </div>

  <div class="card">
    <h4>Event Listesi</h4>
    <div class="small">Bu target için son eventler (event_code + data.summary + refs).</div>
    <table id="evtTable">
      <tr><td colspan="4" class="small">Yükleniyor…</td></tr>
    </table>
  </div>
</div>

<script>
(function(){
  const snapTable = document.getElementById('snapTable');
  const evtTable  = document.getElementById('evtTable');
  const qs = new URLSearchParams(window.location.search);

  const api = new URL('/php-mongo-erp/public/api/audit_chain.php', window.location.origin);
  if (qs.get('target_key')) {
    api.searchParams.set('target_key', qs.get('target_key'));
  } else {
    api.searchParams.set('module', qs.get('module') || 'i18n');
    api.searchParams.set('doc_type', qs.get('doc_type') || 'LANG01T');
    api.searchParams.set('doc_id', qs.get('doc_id') || 'DICT');
  }

  function esc(s){
    return String(s ?? '').replace(/[&<>"']/g, (m)=>({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[m]));
  }

  function pill(k,v){
    if (v === null || v === undefined) return '';
    let txt = (typeof v === 'object') ? JSON.stringify(v) : String(v);
    return `<div class="small"><span class="code">${esc(k)}</span>: ${esc(txt)}</div>`;
  }

  fetch(api.toString(), { method:'GET' })
    .then(r => r.json())
    .then(data => {
      if (!data.ok) throw new Error(data.error || 'api_error');

      // --- snapshots ---
      const snaps = Array.isArray(data.snapshots) ? data.snapshots : [];
      let sHtml = `
        <tr>
          <th style="width:70px;">Ver</th>
          <th style="width:170px;">Zaman</th>
          <th style="width:140px;">Kullanıcı</th>
          <th>Hash</th>
          <th style="width:220px;">Links</th>
        </tr>
      `;

      if (snaps.length === 0) {
        sHtml += `<tr><td colspan="5" class="small">Snapshot bulunamadı.</td></tr>`;
      } else {
        snaps.forEach(s => {
          const id = s._id;
          const ver = s.version ?? '';
          const t = s.created_at ?? '';
          const user = s.context?.username ?? '';
          const hash = s.hash ?? '';
          const prevId = s.prev_snapshot_id ?? null;

          const diffLink = prevId
            ? `/php-mongo-erp/public/api/snapshot_diff.php?snapshot_id=${encodeURIComponent(id)}`
            : '';

          sHtml += `
            <tr>
              <td><strong>${esc(ver)}</strong></td>
              <td class="small">${esc(t)}</td>
              <td>${esc(user)}</td>
              <td class="small"><span class="code">${esc(hash)}</span></td>
              <td class="small">
                <a class="btn" target="_blank" href="/php-mongo-erp/public/api/snapshot_get.php?snapshot_id=${encodeURIComponent(id)}">Snapshot</a>
                ${diffLink ? `<a class="btn" target="_blank" href="${diffLink}">Diff</a>` : `<span class="small">-</span>`}
              </td>
            </tr>
          `;
        });
      }
      snapTable.innerHTML = sHtml;

      // --- events ---
      const evts = Array.isArray(data.events) ? data.events : [];
      let eHtml = `
        <tr>
          <th style="width:170px;">Zaman</th>
          <th style="width:210px;">Event</th>
          <th style="width:140px;">Kullanıcı</th>
          <th>Özet</th>
        </tr>
      `;

      if (evts.length === 0) {
        eHtml += `<tr><td colspan="4" class="small">Event bulunamadı.</td></tr>`;
      } else {
        evts.forEach(ev => {
          const t = ev.created_at ?? '';
          const code = ev.event_code ?? '';
          const user = ev.context?.username ?? '';
          const sum = ev.data?.summary ?? null; // ✅ refs.summary YOK

          let sumHtml = '-';
          if (sum && typeof sum === 'object') {
            sumHtml = '';
            Object.keys(sum).forEach(k => { sumHtml += pill(k, sum[k]); });
          }

          eHtml += `
            <tr>
              <td class="small">${esc(t)}</td>
              <td><span class="code"><strong>${esc(code)}</strong></span></td>
              <td>${esc(user)}</td>
              <td class="small">${sumHtml}</td>
            </tr>
          `;
        });
      }
      evtTable.innerHTML = eHtml;

    })
    .catch(err => {
      snapTable.innerHTML = `<tr><td colspan="5" style="color:red">Hata: ${esc(err.message)}</td></tr>`;
      evtTable.innerHTML = `<tr><td colspan="4" style="color:red">Hata: ${esc(err.message)}</td></tr>`;
    });
})();
</script>

</body>
</html>
