<?php
/**
 * public/docs.php
 *
 * Evrak Listesi (V1)
 * - SNAP01E latest per target_key listesi (DataTables)
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

// view log
ActionLogger::info('DOCS.VIEW', [
  'source' => 'public/docs.php'
], $ctx);

function esc($s): string {
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Evrak Listesi</title>

  <!-- DataTables CDN -->
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>

  <style>
    body{ font-family: Arial, sans-serif; }
    .small{ font-size:12px; color:#666; }
    .bar{ display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin:10px 0; }
    .card{ border:1px solid #eee; padding:10px; border-radius:10px; background:#fff; }
    .code{ font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
    .btn{ padding:6px 10px; border:1px solid #ccc; background:#fff; cursor:pointer; text-decoration:none; color:#000; border-radius:6px; display:inline-block; }
    .btn-primary{ border-color:#1e88e5; background:#1e88e5; color:#fff; }
    .badge{
      display:inline-block; padding:3px 8px; border-radius:999px; font-size:12px; font-weight:600;
      border:1px solid rgba(0,0,0,0.08);
    }
    .b-edit{ background:#E3F2FD; color:#1565C0; }
    .b-view{ background:#F1F8E9; color:#2E7D32; }
    .b-app { background:#FFF3E0; color:#EF6C00; }
    .b-none{ background:#f7f7f7; color:#666; }
    table.dataTable thead th { background:#f7f7f7; }
    /* Datatables input box beyaz/okunur olsun */
    .dataTables_filter input, .dataTables_length select {
      padding:6px 8px !important;
      border:1px solid #ddd !important;
      border-radius:6px !important;
      background:#fff !important;
      color:#000 !important;
      outline:none !important;
    }
  </style>
</head>
<body>

<?php require_once __DIR__ . '/../app/views/layout/header.php'; ?>

<div class="card">
  <h3 style="margin:0;">Evrak Listesi (V1)</h3>
  <div class="small" style="margin-top:6px;">
    Kullanıcı: <strong><?php echo esc($ctx['username'] ?? ''); ?></strong>
    &nbsp;|&nbsp; Role: <strong><?php echo esc($ctx['role'] ?? ''); ?></strong>
    &nbsp;|&nbsp; Firma: <strong><?php echo esc($ctx['CDEF01_id'] ?? ''); ?></strong>
    &nbsp;|&nbsp; Dönem: <strong><?php echo esc($ctx['period_id'] ?? ''); ?></strong>
    <div class="small" style="margin-top:6px;">
      Not: Şu an sadece LANG01T için snapshot aldığımızdan listede az satır görmen normal.
    </div>
  </div>

  <div class="bar">
    <a class="btn" href="/php-mongo-erp/public/locks.php" target="_blank">Locks</a>
    <a class="btn" href="/php-mongo-erp/public/timeline.php" target="_blank">Timeline</a>
    <a class="btn" href="/php-mongo-erp/public/audit_view.php" target="_blank">Audit View</a>
  </div>

  <table id="docsTable" class="display" style="width:100%">
    <thead>
      <tr>
        <th>Lock</th>
        <th>Module</th>
        <th>Doc Type</th>
        <th>Doc No</th>
        <th>Doc Id</th>
        <th>Snapshot</th>
        <th>Son İşlem</th>
        <th>Links</th>
      </tr>
    </thead>
    <tbody>
      <tr><td colspan="8" class="small">Yükleniyor…</td></tr>
    </tbody>
  </table>
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
    }catch(e){
      return String(iso);
    }
  }

  function lockBadge(lock){
    if (!lock) return `<span class="badge b-none">🔓 none</span>`;
    const st = (lock.status || 'editing').toLowerCase();
    const who = lock.username ? ` – ${esc(lock.username)}` : '';
    const exp = lock.expires_at ? ` (bitiş: ${esc(fmtTR(lock.expires_at))})` : '';
    if (st === 'viewing')   return `<span class="badge b-view">🔒 viewing${who}</span><div class="small">${exp}</div>`;
    if (st === 'approving') return `<span class="badge b-app">🔒 approving${who}</span><div class="small">${exp}</div>`;
    return `<span class="badge b-edit">🔒 editing${who}</span><div class="small">${exp}</div>`;
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
      const module = row.module || '';
      const docType = row.doc_type || '';
      const docId = row.doc_id || '';
      const docNo = row.doc_no || '';
      const tk = row.target_key || '';
      const sid = row.snapshot_id || '';
      const ver = row.version ?? '';
      const at = row.created_at || '';
      const user = row.username || '';

      const auditUrl = `/php-mongo-erp/public/audit_view.php?target_key=${encodeURIComponent(tk)}`;
      const tlUrl    = `/php-mongo-erp/public/timeline.php?target_key=${encodeURIComponent(tk)}`;
      const snapUrl  = `/php-mongo-erp/public/snapshot_get_view.php?snapshot_id=${encodeURIComponent(sid)}`;


      const links = `
        <a class="btn" target="_blank" href="${auditUrl}">Audit</a>
        <a class="btn" target="_blank" href="${tlUrl}">Timeline</a>
        <a class="btn" target="_blank" href="${snapUrl}">Snapshot</a>
      `;

      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td>${lockBadge(row.lock)}</td>
        <td><span class="code">${esc(module)}</span></td>
        <td><span class="code">${esc(docType)}</span></td>
        <td>${docNo ? `<span class="code">${esc(docNo)}</span>` : `<span class="small">-</span>`}</td>
        <td><span class="code">${esc(docId)}</span></td>
        <td><span class="code">v${esc(ver)}</span></td>
        <td class="small">${esc(fmtTR(at))} – <strong>${esc(user)}</strong></td>
        <td>${links}</td>
      `;
      tbody.appendChild(tr);
    });

    // DataTables init
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

  (async function(){
    try{
      const rows = await loadRows();
      buildTable(rows);
    }catch(e){
      const tbody = document.querySelector('#docsTable tbody');
      tbody.innerHTML = `<tr><td colspan="8" style="color:red;">Hata: ${esc(e.message)}</td></tr>`;
    }
  })();
})();
</script>

</body>
</html>
