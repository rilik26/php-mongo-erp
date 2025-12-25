<?php
/**
 * public/audit_view.php
 *
 * Audit View (V1)
 * - Tek hedef evrak için:
 *   - Snapshot zinciri (V1 -> V2 -> V3)
 *   - Event listesi
 *   - Snapshot Diff modal (human + raw + copy)
 *   - Event log modal (log_get ile)
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
    // default: i18n dict
    $module  = $module ?: 'i18n';
    $docType = $docType ?: 'LANG01T';
    $docId   = $docId ?: 'DICT';
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Audit View</title>
  <style>
    body{ font-family: Arial, sans-serif; margin:0; padding:0; }
    .container{ padding:16px; }
    table { border-collapse: collapse; width: 100%; }
    th, td { border:1px solid #ddd; padding:8px; vertical-align: top; }
    th { background:#f7f7f7; text-align:left; }
    .bar{ display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin:10px 0; }
    .btn{
      padding:6px 10px; border:1px solid #ccc; background:#fff; cursor:pointer;
      text-decoration:none; color:#000; border-radius:6px; display:inline-block;
    }
    .btn-primary{ border-color:#1e88e5; background:#1e88e5; color:#fff; }
    .btn-success{ border-color:#2e7d32; background:#2e7d32; color:#fff; }
    .btn-danger{ border-color:#c62828; background:#c62828; color:#fff; }
    .small{ font-size:12px; color:#666; }
    .code, .mono{
      font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono","Courier New", monospace;
    }
    .grid{ display:grid; grid-template-columns: 1fr 1fr; gap:16px; }
    @media (max-width: 1100px){ .grid{ grid-template-columns: 1fr; } }
    .card{ border:1px solid #eee; padding:10px; border-radius:10px; background:#fff; }
    pre.json{
      background:#0b1020; color:#dbe2ff; padding:10px; border-radius:8px; overflow:auto;
      font-size:12px; line-height:1.35;
    }
    details{ margin-top:10px; }
    details > summary{ cursor:pointer; user-select:none; }
    .pill{
      display:inline-block; padding:2px 8px; border-radius:999px;
      background:#eef2ff; color:#1e3a8a; font-size:12px;
      border:1px solid #e5e7eb;
    }
    .kv{
      display:grid; grid-template-columns: 60px 1fr; gap:10px; margin-top:6px;
      align-items:start;
    }
    .ok{ color:#2e7d32; }
    .danger{ color:#c62828; }

    /* Modal */
    .backdrop{
      position:fixed; inset:0; background:rgba(0,0,0,.35);
      display:none; align-items:center; justify-content:center; padding:20px; z-index:9999;
    }
    .modal{
      width:min(1100px, 96vw);
      max-height: 90vh;
      background:#fff;
      border-radius:12px;
      border:1px solid #e5e7eb;
      overflow:hidden;
      box-shadow: 0 12px 40px rgba(0,0,0,.25);
      display:flex; flex-direction:column;
    }
    .modal-header{
      display:flex; align-items:center; justify-content:space-between;
      gap:10px; padding:10px 12px; border-bottom:1px solid #eee;
      background:#fafafa;
    }
    .modal-title{ font-weight:700; }
    .modal-body{ padding:12px; overflow:auto; }
    .modal-actions{ display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
    .meta-line{ margin-top:4px; }
    .muted{ color:#777; }
  </style>
</head>
<body>

<?php require_once __DIR__ . '/../app/views/layout/header.php'; ?>

<div class="container">
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
        <tr><td class="small">Yükleniyor…</td></tr>
      </table>
    </div>

    <div class="card">
      <h4>Event Listesi</h4>
      <div class="small">Bu target için son eventler (event_code + summary + refs).</div>
      <table id="evtTable">
        <tr><td class="small">Yükleniyor…</td></tr>
      </table>
    </div>
  </div>
</div>

<!-- ===================== DIFF MODAL ===================== -->
<div class="backdrop" id="diffBackdrop">
  <div class="modal">
    <div class="modal-header">
      <div>
        <div class="modal-title">Snapshot Diff</div>
        <div class="small meta-line"><span id="diffMeta" class="code muted"></span></div>
        <div class="small meta-line"><span id="diffMeta2" class="code muted"></span></div>
      </div>
      <div class="modal-actions">
        <a id="diffOpenNew" class="btn" href="#" target="_blank">Open JSON</a>
        <button id="diffCopyBtn" class="btn btn-success" type="button">Copy Diff JSON</button>
        <button class="btn btn-danger" type="button" id="diffClose">Kapat</button>
      </div>
    </div>
    <div class="modal-body" id="diffBody"></div>
  </div>
</div>

<!-- ===================== LOG MODAL ===================== -->
<div class="backdrop" id="logBackdrop">
  <div class="modal">
    <div class="modal-header">
      <div>
        <div class="modal-title">Log (UACT01E)</div>
        <div class="small meta-line"><span id="logMeta" class="code muted"></span></div>
      </div>
      <div class="modal-actions">
        <a id="logOpenNew" class="btn" href="#" target="_blank">Open JSON</a>
        <button id="logCopyBtn" class="btn btn-success" type="button">Copy Log JSON</button>
        <button class="btn btn-danger" type="button" id="logClose">Kapat</button>
      </div>
    </div>
    <div class="modal-body" id="logBody"></div>
  </div>
</div>

<script>
(function(){
  const snapTable = document.getElementById('snapTable');
  const evtTable  = document.getElementById('evtTable');

  // --- Diff modal els ---
  const diffBackdrop = document.getElementById('diffBackdrop');
  const diffBody = document.getElementById('diffBody');
  const diffMeta = document.getElementById('diffMeta');
  const diffMeta2 = document.getElementById('diffMeta2');
  const diffClose = document.getElementById('diffClose');
  const diffOpenNew = document.getElementById('diffOpenNew');
  const diffCopyBtn = document.getElementById('diffCopyBtn');
  let currentDiffJsonText = '';

  // --- Log modal els ---
  const logBackdrop = document.getElementById('logBackdrop');
  const logBody = document.getElementById('logBody');
  const logMeta = document.getElementById('logMeta');
  const logClose = document.getElementById('logClose');
  const logOpenNew = document.getElementById('logOpenNew');
  const logCopyBtn = document.getElementById('logCopyBtn');
  let currentLogJsonText = '';

  function esc(s){
    return String(s ?? '').replace(/[&<>"']/g, (m)=>({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[m]));
  }

  function openBackdrop(el){ el.style.display = 'flex'; }
  function closeBackdrop(el){ el.style.display = 'none'; }

  diffClose.addEventListener('click', ()=> closeBackdrop(diffBackdrop));
  logClose.addEventListener('click', ()=> closeBackdrop(logBackdrop));

  // backdrops click outside
  diffBackdrop.addEventListener('click', (e)=>{ if(e.target === diffBackdrop) closeBackdrop(diffBackdrop); });
  logBackdrop.addEventListener('click', (e)=>{ if(e.target === logBackdrop) closeBackdrop(logBackdrop); });

  async function copyToClipboard(text){
    if (navigator.clipboard && window.isSecureContext) {
      await navigator.clipboard.writeText(text);
      return true;
    }
    // fallback
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.focus(); ta.select();
    const ok = document.execCommand('copy');
    document.body.removeChild(ta);
    return ok;
  }

  // Copy Diff JSON
  diffCopyBtn.addEventListener('click', async () => {
    if (!currentDiffJsonText) return;
    const old = diffCopyBtn.textContent;
    diffCopyBtn.disabled = true;

    try {
      const ok = await copyToClipboard(currentDiffJsonText);
      diffCopyBtn.textContent = ok ? 'Kopyalandı ✅' : 'Kopyalanamadı ❌';
    } catch (e) {
      diffCopyBtn.textContent = 'Kopyalanamadı ❌';
    }
    setTimeout(()=>{ diffCopyBtn.textContent = old; diffCopyBtn.disabled = false; }, 1800);
  });

  // Copy Log JSON
  logCopyBtn.addEventListener('click', async () => {
    if (!currentLogJsonText) return;
    const old = logCopyBtn.textContent;
    logCopyBtn.disabled = true;

    try {
      const ok = await copyToClipboard(currentLogJsonText);
      logCopyBtn.textContent = ok ? 'Kopyalandı ✅' : 'Kopyalanamadı ❌';
    } catch (e) {
      logCopyBtn.textContent = 'Kopyalanamadı ❌';
    }
    setTimeout(()=>{ logCopyBtn.textContent = old; logCopyBtn.disabled = false; }, 1800);
  });

  function pillLine(k,v){
    return `<div class="small"><span class="pill">${esc(k)}</span> ${esc(v)}</div>`;
  }

  function renderLangDiff(diff){
    const added = Array.isArray(diff.added_keys) ? diff.added_keys : [];
    const removed = Array.isArray(diff.removed_keys) ? diff.removed_keys : [];
    const changed = diff.changed_keys || {};
    const changedKeys = Object.keys(changed);

    let html = '';
    html += pillLine('added_keys_count', added.length);
    html += pillLine('removed_keys_count', removed.length);
    html += pillLine('changed_keys_count', changedKeys.length);

    if (changedKeys.length) {
      html += `<details open><summary>Changed Keys</summary>`;
      changedKeys.forEach(key=>{
        const e = changed[key] || {};
        html += `
          <div class="card" style="margin:10px 0;">
            <div><strong class="mono">${esc(key)}</strong></div>
            ${e.tr ? `
              <div class="kv">
                <div><span class="pill">tr</span></div>
                <div class="mono"><span class="danger">${esc(e.tr.from)}</span> → <span class="ok">${esc(e.tr.to)}</span></div>
              </div>` : ''
            }
            ${e.en ? `
              <div class="kv">
                <div><span class="pill">en</span></div>
                <div class="mono"><span class="danger">${esc(e.en.from)}</span> → <span class="ok">${esc(e.en.to)}</span></div>
              </div>` : ''
            }
          </div>
        `;
      });
      html += `</details>`;
    } else {
      html += `<div class="small" style="margin-top:10px;">Değişiklik yok.</div>`;
    }

    html += `<details ${added.length ? 'open':''}><summary>Added Keys (${added.length})</summary><pre class="json">${esc(JSON.stringify(added, null, 2))}</pre></details>`;
    html += `<details ${removed.length ? 'open':''}><summary>Removed Keys (${removed.length})</summary><pre class="json">${esc(JSON.stringify(removed, null, 2))}</pre></details>`;

    return html;
  }

  function renderGenericDiff(diff){
    const added = diff.added || {};
    const removed = diff.removed || {};
    const changed = diff.changed || {};

    const addedKeys = Object.keys(added);
    const removedKeys = Object.keys(removed);
    const changedKeys = Object.keys(changed);

    let html = '';
    html += pillLine('added_count', addedKeys.length);
    html += pillLine('removed_count', removedKeys.length);
    html += pillLine('changed_count', changedKeys.length);

    html += `<details ${addedKeys.length ? 'open':''}>
      <summary>Added (${addedKeys.length})</summary>
      <pre class="json">${esc(JSON.stringify(added, null, 2))}</pre>
    </details>`;

    html += `<details ${removedKeys.length ? 'open':''}>
      <summary>Removed (${removedKeys.length})</summary>
      <pre class="json">${esc(JSON.stringify(removed, null, 2))}</pre>
    </details>`;

    html += `<details open>
      <summary>Changed (${changedKeys.length})</summary>`;

    if (!changedKeys.length) {
      html += `<div class="small" style="margin-top:8px;">Değişiklik yok.</div>`;
    } else {
      changedKeys.forEach(p => {
        const c = changed[p];
        html += `
          <div class="card" style="margin:10px 0; border:1px solid #eee;">
            <div><strong class="mono">${esc(p)}</strong></div>
            <div class="kv">
              <div><span class="pill">from</span></div>
              <div class="mono danger">${esc(JSON.stringify(c.from))}</div>
            </div>
            <div class="kv">
              <div><span class="pill">to</span></div>
              <div class="mono ok">${esc(JSON.stringify(c.to))}</div>
            </div>
          </div>
        `;
      });
    }

    html += `</details>`;
    return html;
  }

  async function showDiff(snapshotId){
    const url = `/php-mongo-erp/public/api/snapshot_diff.php?snapshot_id=${encodeURIComponent(snapshotId)}`;
    diffOpenNew.href = url;

    diffMeta.textContent = `snapshot_id=${snapshotId}`;
    diffMeta2.textContent = '';
    openBackdrop(diffBackdrop);
    diffBody.innerHTML = '<div class="small">Yükleniyor…</div>';

    currentDiffJsonText = '';
    diffCopyBtn.disabled = true;

    try {
      const res = await fetch(url, { method:'GET' });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'diff_error');

      currentDiffJsonText = JSON.stringify(data, null, 2);
      diffCopyBtn.disabled = false;

      const prevV = data.prev?.version ?? '-';
      const lastV = data.latest?.version ?? '-';
      diffMeta.textContent  = `target_key=${data.target_key || ''}`;
      diffMeta2.textContent = `prev=v${prevV}  ->  latest=v${lastV}  |  mode=${data.mode || 'generic'}`;

      let human = '';
      if (data.mode === 'lang') human = renderLangDiff(data.diff || {});
      else human = renderGenericDiff(data.diff || {});

      const raw = `<details>
        <summary>Raw JSON</summary>
        <pre class="json">${esc(JSON.stringify(data, null, 2))}</pre>
      </details>`;

      diffBody.innerHTML = human + raw;

    } catch (err) {
      diffBody.innerHTML = `<p style="color:red">Hata: ${esc(err.message)}</p>`;
    }
  }

  async function showLog(logId){
    const url = `/php-mongo-erp/public/api/log_get.php?log_id=${encodeURIComponent(logId)}`;
    logOpenNew.href = url;

    logMeta.textContent = `log_id=${logId}`;
    openBackdrop(logBackdrop);
    logBody.innerHTML = '<div class="small">Yükleniyor…</div>';

    currentLogJsonText = '';
    logCopyBtn.disabled = true;

    try {
      const res = await fetch(url, { method:'GET' });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'log_error');

      currentLogJsonText = JSON.stringify(data, null, 2);
      logCopyBtn.disabled = false;

      logBody.innerHTML = `
        <details open>
          <summary>Log JSON</summary>
          <pre class="json">${esc(JSON.stringify(data, null, 2))}</pre>
        </details>
      `;
    } catch (err) {
      logBody.innerHTML = `<p style="color:red">Hata: ${esc(err.message)}</p>`;
    }
  }

  // ------------------ load audit chain ------------------
  const qs = new URLSearchParams(window.location.search);

  const api = new URL('/php-mongo-erp/public/api/audit_chain.php', window.location.origin);
  if (qs.get('target_key')) {
    api.searchParams.set('target_key', qs.get('target_key'));
  } else {
    api.searchParams.set('module', qs.get('module') || 'i18n');
    api.searchParams.set('doc_type', qs.get('doc_type') || 'LANG01T');
    api.searchParams.set('doc_id', qs.get('doc_id') || 'DICT');
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
          <th style="width:260px;">Links</th>
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

          sHtml += `
            <tr>
              <td><strong>${esc(ver)}</strong></td>
              <td class="small">${esc(t)}</td>
              <td>${esc(user)}</td>
              <td class="small"><span class="code">${esc(hash)}</span></td>
              <td class="small">
                <a class="btn" target="_blank" href="/php-mongo-erp/public/api/snapshot_get.php?snapshot_id=${encodeURIComponent(id)}">Snapshot</a>
                ${prevId ? `<button class="btn btn-primary jsDiff" data-sid="${esc(id)}" type="button">Diff</button>` : `<span class="small">-</span>`}
              </td>
            </tr>
          `;
        });
      }
      snapTable.innerHTML = sHtml;

      // attach diff click
      document.querySelectorAll('.jsDiff').forEach(btn=>{
        btn.addEventListener('click', ()=>{
          const sid = btn.getAttribute('data-sid');
          showDiff(sid);
        });
      });

      // --- events ---
      const evts = Array.isArray(data.events) ? data.events : [];
      let eHtml = `
        <tr>
          <th style="width:170px;">Zaman</th>
          <th style="width:220px;">Event</th>
          <th style="width:140px;">Kullanıcı</th>
          <th>Özet / Refs</th>
        </tr>
      `;

      if (evts.length === 0) {
        eHtml += `<tr><td colspan="4" class="small">Event bulunamadı.</td></tr>`;
      } else {
        evts.forEach(ev => {
          const t = ev.created_at ?? '';
          const code = ev.event_code ?? '';
          const user = ev.context?.username ?? '';
          const refs = ev.refs ?? {};
          const sum = refs.summary ?? ev.data?.summary ?? null;

          // summary render
          let sumHtml = '-';
          if (sum && typeof sum === 'object') {
            sumHtml = `<div class="small">`;
            Object.keys(sum).forEach(k => {
              sumHtml += `<div><span class="pill">${esc(k)}</span> ${esc(typeof sum[k] === 'object' ? JSON.stringify(sum[k]) : sum[k])}</div>`;
            });
            sumHtml += `</div>`;
          }

          // log link
          let logHtml = '';
          if (refs.log_id) {
            logHtml = ` <button type="button" class="btn jsLog" data-lid="${esc(refs.log_id)}">Log</button>`;
          }

          eHtml += `
            <tr>
              <td class="small">${esc(t)}</td>
              <td><span class="code"><strong>${esc(code)}</strong></span></td>
              <td>${esc(user)}</td>
              <td class="small">
                ${sumHtml}
                <div style="margin-top:6px;">
                  ${refs.snapshot_id ? `<span class="pill">snapshot_id</span> <span class="code">${esc(refs.snapshot_id)}</span>` : ''}
                </div>
                <div style="margin-top:6px;">${logHtml}</div>
              </td>
            </tr>
          `;
        });
      }
      evtTable.innerHTML = eHtml;

      // attach log click
      document.querySelectorAll('.jsLog').forEach(btn=>{
        btn.addEventListener('click', ()=>{
          const lid = btn.getAttribute('data-lid');
          showLog(lid);
        });
      });
    })
    .catch(err => {
      snapTable.innerHTML = `<tr><td colspan="5" style="color:red">Hata: ${esc(err.message)}</td></tr>`;
      evtTable.innerHTML = `<tr><td colspan="4" style="color:red">Hata: ${esc(err.message)}</td></tr>`;
    });
})();
</script>

</body>
</html>
