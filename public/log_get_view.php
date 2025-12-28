<?php
/**
 * public/log_get_view.php (FINAL - THEME)
 *
 * - log_id ile API'den log getirir
 * - Kart UI + TR saat formatı
 * - Snapshot/Diff/Audit/Timeline linkleri (varsa)
 * - ✅ Materialize layout (header/left/header2/footer)
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

// theme head
require_once __DIR__ . '/../app/views/layout/header.php';

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<body>
<div class="layout-wrapper layout-content-navbar">
  <div class="layout-container">

    <?php require_once __DIR__ . '/../app/views/layout/left.php'; ?>

    <div class="layout-page">
      <?php require_once __DIR__ . '/../app/views/layout/header2.php'; ?>

      <div class="content-wrapper">
        <div class="container-xxl flex-grow-1 container-p-y">

          <div class="row g-6">
            <div class="col-12">
              <div class="card">
                <div class="card-body">

                  <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <div>
                      <h4 class="mb-1">Log View</h4>
                      <div class="text-muted" style="font-size:12px;">
                        Kullanıcı: <strong><?php echo h($ctx['username'] ?? ''); ?></strong>
                        &nbsp;|&nbsp; Firma: <strong><?php echo h($ctx['CDEF01_id'] ?? ''); ?></strong>
                        &nbsp;|&nbsp; Dönem: <strong><?php echo h($ctx['period_id'] ?? ''); ?></strong>
                      </div>
                    </div>
                  </div>

                  <div class="row g-3 mt-4 align-items-end">
                    <div class="col-lg-7">
                      <label class="form-label">log_id</label>
                      <input id="lid" class="form-control" type="text"
                             value="<?php echo h($logId); ?>" placeholder="...">
                    </div>
                    <div class="col-lg-5 d-flex gap-2">
                      <button class="btn btn-primary" id="btnLoad" type="button">Getir</button>
                      <a class="btn btn-outline-primary" href="/php-mongo-erp/public/timeline.php">Timeline</a>
                    </div>
                  </div>

                  <div id="meta" class="text-muted mt-3" style="font-size:12px;">Hazır.</div>

                  <div id="links" class="d-flex flex-wrap gap-2 mt-3" style="display:none;"></div>

                  <div class="card mt-4" style="background:rgba(0,0,0,.02);">
                    <div class="card-body">
                      <h5 class="mb-3">Özet</h5>
                      <div id="summary" class="row g-2 text-muted" style="font-size:12px;">Yükleniyor…</div>
                    </div>
                  </div>

                  <h5 class="mt-4 mb-2">Log JSON (pretty)</h5>
                  <pre id="out" style="background:#0b1020;color:#eaeef7;padding:12px;border-radius:12px;overflow:auto;min-height:160px;">Yükleniyor…</pre>

                </div>
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
    return `
      <div class="col-12 col-md-6">
        <div class="text-muted" style="font-size:11px;">${esc(k)}</div>
        <div style="font-size:13px;word-break:break-word;">${esc(v)}</div>
      </div>
    `;
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
      summaryEl.innerHTML = '<div class="text-danger">log_id gerekli</div>';
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
      metaEl.innerHTML = '<span class="text-danger">Hata:</span> ' + esc(j.error || 'api_error');
      summaryEl.innerHTML = '<div class="text-danger">' + esc(j.error || 'api_error') + '</div>';
      return;
    }

    const log = j.log || {};
    const action = log.action_code || '';
    const created = log.created_at || '';
    const user = log.context?.username || log.username || '';
    const result = log.result || '';
    const requestId = log.meta?.request_id || '';

    metaEl.innerHTML =
      `<span class="badge bg-label-primary">${esc(action)}</span>` +
      ` <span class="ms-2">${esc(result)}</span>` +
      ` <span class="ms-2">${esc(fmtTR(created))}</span>` +
      (user ? ` <span class="ms-2"><strong>${esc(user)}</strong></span>` : '') +
      (requestId ? ` <span class="ms-2">request_id: <span class="font-monospace">${esc(requestId)}</span></span>` : '');

    // summary
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

    const refs = log.refs || {};
    const snapId = refs.snapshot_id || null;
    const prevSnapId = refs.prev_snapshot_id || null;

    let linksHtml = '';
    linksHtml += `<a class="btn btn-outline-primary btn-sm" target="_blank" href="${esc(jsonUrl)}">JSON</a>`;

    if (snapId){
      linksHtml += `<a class="btn btn-outline-primary btn-sm" target="_blank" href="/php-mongo-erp/public/snapshot_get_view.php?snapshot_id=${encodeURIComponent(snapId)}">Snapshot</a>`;
      linksHtml += `<a class="btn btn-outline-primary btn-sm" target="_blank" href="/php-mongo-erp/public/snapshot_diff_view.php?snapshot_id=${encodeURIComponent(snapId)}">Diff</a>`;
      if (prevSnapId){
        linksHtml += `<a class="btn btn-outline-secondary btn-sm" href="/php-mongo-erp/public/snapshot_get_view.php?snapshot_id=${encodeURIComponent(prevSnapId)}">← Önceki Snapshot</a>`;
      }
    }

    const targetKey = log.target_key || '';
    if (targetKey){
      linksHtml += `<a class="btn btn-outline-primary btn-sm" target="_blank" href="/php-mongo-erp/public/audit_view.php?target_key=${encodeURIComponent(targetKey)}">Audit</a>`;
      linksHtml += `<a class="btn btn-outline-primary btn-sm" target="_blank" href="/php-mongo-erp/public/timeline.php?target_key=${encodeURIComponent(targetKey)}">Timeline</a>`;
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
