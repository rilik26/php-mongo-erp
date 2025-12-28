<?php
/**
 * public/docs.php (FINAL)
 *
 * Evrak Listesi (V1)
 * - SNAP01E latest per target listesi (DataTables)
 * - LOCK01E aktif lock bilgisi (badge + kim)
 * - Linkler: Audit View / Timeline / Snapshot Get
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

ActionLogger::info('DOCS.VIEW', [
  'source' => 'public/docs.php'
], $ctx);

if (!function_exists('h')) {
  function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('esc')) {
  function esc($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

// ✅ Theme header include (HTML head + core css/js)
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

          <div class="row g-6">

            <div class="col-md-12">
              <div class="card card-border-shadow-primary">
                <div class="card-body">
                  <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <div>
                      <h4 class="mb-1">Evrak Listesi (V1)</h4>
                      <div class="small text-muted">
                        Kullanıcı: <b><?php echo esc($ctx['username'] ?? ''); ?></b>
                        &nbsp;|&nbsp; Role: <b><?php echo esc($ctx['role'] ?? ''); ?></b>
                        &nbsp;|&nbsp; Firma: <b><?php echo esc($ctx['CDEF01_id'] ?? ''); ?></b>
                        &nbsp;|&nbsp; Dönem: <b><?php echo esc($ctx['period_id'] ?? ''); ?></b>
                      </div>
                      <div class="small text-muted mt-2">
                        Not: Şu an sadece LANG01T için snapshot aldığımızdan listede az satır görmen normal.
                      </div>
                    </div>

                    <div class="d-flex gap-2 flex-wrap">
                      <a class="btn btn-outline-primary" href="/php-mongo-erp/public/locks.php" target="_blank">Locks</a>
                      <a class="btn btn-outline-primary" href="/php-mongo-erp/public/timeline.php" target="_blank">Timeline</a>
                      <a class="btn btn-outline-primary" href="/php-mongo-erp/public/audit_view.php" target="_blank">Audit View</a>
                    </div>
                  </div>

                  <hr class="my-4">

                  <div class="table-responsive">
                    <table id="docsTable" class="table table-bordered">
                      <thead>
                      <tr>
                        <th style="width:200px;">Lock</th>
                        <th>Module</th>
                        <th>Doc Type</th>
                        <th>Doc No</th>
                        <th>Doc Id</th>
                        <th style="width:90px;">Snapshot</th>
                        <th style="width:260px;">Son İşlem</th>
                        <th style="width:260px;">Links</th>
                      </tr>
                      </thead>
                      <tbody>
                        <tr><td colspan="8" class="text-muted">Yükleniyor…</td></tr>
                      </tbody>
                    </table>
                  </div>

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
    }catch(e){
      return String(iso);
    }
  }

  function lockBadge(lock){
    if (!lock) return `<span class="badge bg-label-secondary">🔓 none</span>`;

    const st = (lock.status || 'editing').toLowerCase();
    const who = lock.username ? ` – ${esc(lock.username)}` : '';
    const exp = lock.expires_at ? `<div class="small text-muted">(bitiş: ${esc(fmtTR(lock.expires_at))})</div>` : '';

    if (st === 'viewing')   return `<span class="badge bg-label-success">🔒 viewing${who}</span>${exp}`;
    if (st === 'approving') return `<span class="badge bg-label-warning">🔒 approving${who}</span>${exp}`;
    return `<span class="badge bg-label-primary">🔒 editing${who}</span>${exp}`;
  }

  async function loadRows(){
    const url = new URL('/php-mongo-erp/public/api/docs_list.php', window.location.origin);
    url.searchParams.set('limit', '500');

    const r = await fetch(url.toString(), { method:'GET', credentials:'same-origin' });
    const j = await r.json();
    if (!j.ok) throw new Error(j.error || 'api_error');
    return Array.isArray(j.rows) ? j.rows : [];
  }

  function buildTable(rows){
    const tbody = document.querySelector('#docsTable tbody');
    tbody.innerHTML = '';

    rows.forEach(row => {
      const module  = row.module || '';
      const docType = row.doc_type || '';
      const docId   = row.doc_id || '';
      const docNo   = row.doc_no || '';
      const sid     = row.snapshot_id || '';
      const ver     = row.version ?? '';
      const at      = row.created_at || '';
      const user    = row.username || '';

      // ✅ audit_view.php ve timeline.php SENİN KODUNDA module/doc_type/doc_id bekliyor
      const auditUrl = `/php-mongo-erp/public/audit_view.php?module=${encodeURIComponent(module)}&doc_type=${encodeURIComponent(docType)}&doc_id=${encodeURIComponent(docId)}`;
      const tlUrl    = `/php-mongo-erp/public/timeline.php?module=${encodeURIComponent(module)}&doc_type=${encodeURIComponent(docType)}&doc_id=${encodeURIComponent(docId)}`;
      const snapUrl  = `/php-mongo-erp/public/snapshot_get_view.php?snapshot_id=${encodeURIComponent(sid)}`;

      const links = `
        <a class="btn btn-sm btn-outline-primary" target="_blank" href="${auditUrl}">Audit</a>
        <a class="btn btn-sm btn-outline-primary" target="_blank" href="${tlUrl}">Timeline</a>
        <a class="btn btn-sm btn-outline-primary" target="_blank" href="${snapUrl}">Snapshot</a>
      `;

      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td>${lockBadge(row.lock)}</td>
        <td><span class="text-muted">${esc(module)}</span></td>
        <td><span class="text-muted">${esc(docType)}</span></td>
        <td>${docNo ? `<span class="text-muted">${esc(docNo)}</span>` : `<span class="text-muted">-</span>`}</td>
        <td><span class="text-muted">${esc(docId)}</span></td>
        <td><span class="text-muted">v${esc(ver)}</span></td>
        <td class="small text-muted">${esc(fmtTR(at))} – <b>${esc(user)}</b></td>
        <td>${links}</td>
      `;
      tbody.appendChild(tr);
    });

    // DataTables init (footer.php'de jQuery + DT varsa çalışır)
    if (window.jQuery && jQuery.fn && jQuery.fn.DataTable) {
      if ($.fn.DataTable.isDataTable('#docsTable')) {
        $('#docsTable').DataTable().destroy();
      }
      $('#docsTable').DataTable({
        pageLength: 25,
        order: [[6,'desc']],
        language: {
          search: "Ara:",
          lengthMenu: "Göster: _MENU_",
          info: "_TOTAL_ kayıttan _START_ - _END_",
          infoEmpty: "Kayıt yok",
          zeroRecords: "Sonuç bulunamadı"
        }
      });
    }
  }

  (async function(){
    try{
      const rows = await loadRows();
      buildTable(rows);
    }catch(e){
      const tbody = document.querySelector('#docsTable tbody');
      tbody.innerHTML = `<tr><td colspan="8" class="text-danger">Hata: ${esc(e.message)}</td></tr>`;
    }
  })();
})();
</script>

</body>
</html>
