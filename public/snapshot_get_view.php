<?php
/**
 * public/snapshot_get_view.php (FINAL - THEME)
 *
 * - snapshot_id ile API'den snapshot getirir
 * - Kart UI ile gösterir
 * - JSON linki + Diff linki + Audit/Timeline linki verir
 *
 * Guard: login şart
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

try { Context::bootFromSession(); } catch (ContextException $e) {
  header('Location: /php-mongo-erp/public/login.php');
  exit;
}

$ctx = Context::get();

ActionLogger::info('SNAPSHOT.VIEW', [
  'source' => 'public/snapshot_get_view.php'
], $ctx);

$snapshotId = trim($_GET['snapshot_id'] ?? '');

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
              <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                <div>
                  <h4 class="mb-1">Snapshot View</h4>
                  <div class="text-muted" style="font-size:12px;">
                    Kullanıcı: <strong><?php echo h($ctx['username'] ?? ''); ?></strong>
                    &nbsp;|&nbsp; Firma: <strong><?php echo h($ctx['CDEF01_id'] ?? ''); ?></strong>
                    &nbsp;|&nbsp; Dönem: <strong><?php echo h($ctx['period_id'] ?? ''); ?></strong>
                  </div>
                </div>
              </div>

              <div class="row g-3 mt-4 align-items-end">
                <div class="col-lg-7">
                  <label class="form-label">snapshot_id</label>
                  <input id="sid" class="form-control" type="text"
                         value="<?php echo h($snapshotId); ?>" placeholder="...">
                </div>
                <div class="col-lg-5 d-flex gap-2">
                  <button class="btn btn-primary" id="btnLoad" type="button">Getir</button>
                  <a class="btn btn-outline-primary" href="/php-mongo-erp/public/docs.php">Evrak Listesi</a>
                </div>
              </div>

              <div id="meta" class="text-muted mt-3" style="font-size:12px;">Hazır.</div>
              <div id="links" class="d-flex flex-wrap gap-2 mt-3" style="display:none;"></div>

              <h5 class="mt-4 mb-2">Snapshot JSON (pretty)</h5>
              <pre id="out" style="background:#0b1020;color:#eaeef7;padding:12px;border-radius:12px;overflow:auto;min-height:160px;">Yükleniyor…</pre>

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
    linksEl.style.display = 'none';
    linksEl.innerHTML = '';

    const r = await fetch(api.toString(), { method:'GET', credentials:'same-origin' });
    const j = await r.json();

    if (!j.ok){
      outEl.textContent = JSON.stringify(j, null, 2);
      metaEl.innerHTML = '<span class="text-danger">Hata:</span> ' + esc(j.error || 'api_error');
      return;
    }

    const s = j.snapshot || {};
    const targetKey = s.target_key || '';
    const ver = s.version ?? '';
    const created = s.created_at || '';
    const user = s.context?.username || '';
    const prevId = s.prev_snapshot_id || null;

    metaEl.innerHTML =
      `<span class="badge bg-label-primary">v${esc(ver)}</span>` +
      ` <span class="ms-2">${esc(fmtTR(created))}</span>` +
      ` <span class="ms-2"><strong>${esc(user)}</strong></span>` +
      (targetKey ? ` <span class="ms-2">target_key: <span class="font-monospace">${esc(targetKey)}</span></span>` : '');

    const jsonUrl = '/php-mongo-erp/public/api/snapshot_get.php?snapshot_id=' + encodeURIComponent(sid);
    const diffUrl = '/php-mongo-erp/public/snapshot_diff_view.php?snapshot_id=' + encodeURIComponent(sid);
    const auditUrl = targetKey ? ('/php-mongo-erp/public/audit_view.php?target_key=' + encodeURIComponent(targetKey)) : '#';
    const tlUrl    = targetKey ? ('/php-mongo-erp/public/timeline.php?target_key=' + encodeURIComponent(targetKey)) : '#';

    let linksHtml = '';
    linksHtml += `<a class="btn btn-outline-primary btn-sm" target="_blank" href="${esc(jsonUrl)}">JSON</a>`;
    linksHtml += `<a class="btn btn-outline-primary btn-sm" target="_blank" href="${esc(diffUrl)}">Diff</a>`;
    if (targetKey){
      linksHtml += `<a class="btn btn-outline-primary btn-sm" target="_blank" href="${esc(auditUrl)}">Audit</a>`;
      linksHtml += `<a class="btn btn-outline-primary btn-sm" target="_blank" href="${esc(tlUrl)}">Timeline</a>`;
    }
    if (prevId){
      linksHtml += `<a class="btn btn-outline-secondary btn-sm" href="/php-mongo-erp/public/snapshot_get_view.php?snapshot_id=${encodeURIComponent(prevId)}">← Önceki Versiyon</a>`;
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
