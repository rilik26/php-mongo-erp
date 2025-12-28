<?php
/**
 * public/gendoc_list.php (FINAL)
 *
 * - GENDOC header listesi (GENDOC01E)
 * - Filtre: module, doc_type, q
 * - Lock ikonları: lock_status.php (bulk)
 * - Linkler: gendoc_admin / timeline / audit_view / latest snapshot
 * - ✅ Yeni modal (doc_id input)
 *
 * ✅ Theme layout uyumlu: header + left + header2 + footer
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

ActionLogger::info('GENDOC.LIST.VIEW', [
  'source' => 'public/gendoc_list.php',
], $ctx);

if (!function_exists('h')) {
  function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

function bson_to_array($v) {
  if ($v instanceof MongoDB\Model\BSONDocument || $v instanceof MongoDB\Model\BSONArray) $v = $v->getArrayCopy();
  if ($v instanceof MongoDB\BSON\UTCDateTime) return $v->toDateTime()->format('c');
  if ($v instanceof MongoDB\BSON\ObjectId) return (string)$v;
  if (is_array($v)) {
    $out = [];
    foreach ($v as $k => $vv) $out[$k] = bson_to_array($vv);
    return $out;
  }
  return $v;
}

function fmt_tr($iso): string {
  if (!$iso) return '-';
  try {
    $dt = new DateTime((string)$iso);
    $dt->setTimezone(new DateTimeZone('Europe/Istanbul'));
    return $dt->format('d.m.Y H:i:s');
  } catch(Throwable $e) {
    return (string)$iso;
  }
}

// filters
$module  = trim($_GET['module'] ?? 'gen');
$docType = trim($_GET['doc_type'] ?? 'GENDOC01T');
$q       = trim($_GET['q'] ?? '');

$limit = (int)($_GET['limit'] ?? 120);
if ($limit < 20) $limit = 20;
if ($limit > 500) $limit = 500;

// tenant scope
$cdef     = $ctx['CDEF01_id'] ?? null;
$period   = $ctx['period_id'] ?? null;
$facility = $ctx['facility_id'] ?? null;

// query build
$filter = [
  'target.module'   => $module,
  'target.doc_type' => $docType,
];

if ($cdef) $filter['context.CDEF01_id'] = $cdef;

if ($period) {
  $filter['$or'] = [
    ['context.period_id' => (string)$period],
    ['context.period_id' => 'GLOBAL'],
  ];
}

// facility null ise null/missing
if ($facility === null || $facility === '') {
  $filter['$and'][] = [
    '$or' => [
      ['context.facility_id' => null],
      ['context.facility_id' => ['$exists' => false]],
    ]
  ];
} else {
  $filter['context.facility_id'] = $facility;
}

if ($q !== '') {
  $rx = new MongoDB\BSON\Regex(preg_quote($q), 'i');
  $filter['$and'][] = [
    '$or' => [
      ['target.doc_id'    => $rx],
      ['header.doc_no'    => $rx],
      ['header.title'     => $rx],
      ['header.status'    => $rx],
      ['target.doc_no'    => $rx],
      ['target.doc_title' => $rx],
      ['target.status'    => $rx],
    ]
  ];
}

$cur = MongoManager::collection('GENDOC01E')->find(
  $filter,
  [
    'sort' => ['updated_at' => -1, '_id' => -1],
    'limit' => $limit,
    'projection' => [
      '_id' => 1,
      'target' => 1,
      'header' => 1,
      'updated_at' => 1,
      'created_at' => 1,
      'target_key' => 1,
    ]
  ]
);

$rows = array_map('bson_to_array', iterator_to_array($cur));

// latest snapshot id helper
function find_latest_snapshot_id(string $module, string $docType, string $docId, array $ctx): ?string {
  $cdef     = $ctx['CDEF01_id'] ?? null;
  $period   = $ctx['period_id'] ?? null;
  $facility = $ctx['facility_id'] ?? null;

  $f = [
    'target.module'   => $module,
    'target.doc_type' => $docType,
    'target.doc_id'   => $docId,
  ];
  if ($cdef) $f['context.CDEF01_id'] = $cdef;

  if ($period) {
    $f['$or'] = [
      ['context.period_id' => (string)$period],
      ['context.period_id' => 'GLOBAL'],
    ];
  }

  if ($facility === null || $facility === '') {
    $f['$and'][] = [
      '$or' => [
        ['context.facility_id' => null],
        ['context.facility_id' => ['$exists' => false]],
      ]
    ];
  } else {
    $f['context.facility_id'] = $facility;
  }

  $doc = MongoManager::collection('SNAP01E')->findOne(
    $f,
    ['sort' => ['version' => -1, 'created_at' => -1], 'projection' => ['_id' => 1]]
  );
  if (!$doc) return null;
  $doc = bson_to_array($doc);
  return !empty($doc['_id']) ? (string)$doc['_id'] : null;
}

// snapshot id attach
foreach ($rows as &$r) {
  $t = (array)($r['target'] ?? []);
  $did = (string)($t['doc_id'] ?? '');
  $r['_latest_snapshot_id'] = $did !== '' ? find_latest_snapshot_id($module, $docType, $did, $ctx) : null;
}
unset($r);

// ✅ Theme header include (HTML head + core css/js)
require_once __DIR__ . '/../app/views/layout/header.php';
?>

<!-- DataTables CDN (kalsın) -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>

<style>
  /* sadece gerekli ufak dokunuşlar */
  .code { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 12px; }
  .pill { display:inline-block; padding:3px 8px; border-radius:999px; font-size:12px; border:1px solid rgba(0,0,0,.08); background:rgba(0,0,0,.02); }
  .lock-cell{ text-align:center; width:44px; }
  .actions{ display:flex; gap:8px; flex-wrap:wrap; }
  table.dataTable thead th { background: rgba(0,0,0,.03); }
</style>

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
                      <h4 class="mb-1">GENDOC List</h4>
                      <div class="text-muted" style="font-size:12px;">
                        Tenant: <b><?php echo h($ctx['CDEF01_id'] ?? ''); ?></b>
                        &nbsp;|&nbsp; Period: <b><?php echo h($ctx['period_id'] ?? ''); ?></b>
                        &nbsp;|&nbsp; User: <b><?php echo h($ctx['username'] ?? ''); ?></b>
                      </div>
                    </div>
                  </div>

                  <form method="GET" class="row g-3 mt-4">
                    <div class="col-md-2">
                      <label class="form-label">module</label>
                      <select class="form-select" name="module">
                        <?php foreach (['gen','i18n','stock','inv'] as $m): ?>
                          <option value="<?php echo h($m); ?>" <?php echo ($m===$module?'selected':''); ?>>
                            <?php echo h($m); ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>

                    <div class="col-md-3">
                      <label class="form-label">doc_type</label>
                      <input class="form-control" name="doc_type" value="<?php echo h($docType); ?>" placeholder="örn: GENDOC01T">
                    </div>

                    <div class="col-md-4">
                      <label class="form-label">q</label>
                      <input class="form-control" name="q" value="<?php echo h($q); ?>" placeholder="Ara: doc_id / doc_no / title / status">
                    </div>

                    <div class="col-md-2">
                      <label class="form-label">limit</label>
                      <select class="form-select" name="limit">
                        <?php foreach ([50,120,200,300,500] as $l): ?>
                          <option value="<?php echo (int)$l; ?>" <?php echo ((int)$l===$limit?'selected':''); ?>>
                            <?php echo (int)$l; ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>

                    <div class="col-md-1 d-flex gap-2 align-items-end">
                      <button class="btn btn-primary" type="submit">Getir</button>
                    </div>

                    <div class="col-md-12 d-flex gap-2 flex-wrap">
                      <a class="btn btn-outline-primary" href="/php-mongo-erp/public/gendoc_list.php">Sıfırla</a>
                      <button class="btn btn-primary" type="button" id="btnNewDoc">+ Yeni</button>
                      <span class="text-muted" style="font-size:12px; align-self:center;"><?php echo (int)count($rows); ?> kayıt</span>
                    </div>
                  </form>

                  <div class="table-responsive mt-4">
                    <table id="gendocTable" class="table table-bordered">
                      <thead>
                        <tr>
                          <th style="width:44px;">🔒</th>
                          <th style="width:220px;">doc_id</th>
                          <th style="width:180px;">doc_no</th>
                          <th>title</th>
                          <th style="width:120px;">status</th>
                          <th style="width:180px;">updated_at</th>
                          <th style="width:420px;">links</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($rows as $r):
                          $t  = (array)($r['target'] ?? []);
                          $h0 = (array)($r['header'] ?? []);

                          $did = (string)($t['doc_id'] ?? '');

                          $docNo = (string)($h0['doc_no'] ?? ($t['doc_no'] ?? ''));
                          $title = (string)($h0['title'] ?? ($t['doc_title'] ?? ''));
                          $status= (string)($h0['status'] ?? ($t['status'] ?? ''));

                          $upd = (string)($r['updated_at'] ?? ($r['created_at'] ?? ''));
                          $snapId = (string)($r['_latest_snapshot_id'] ?? '');
                        ?>
                          <tr data-doc-id="<?php echo h($did); ?>">
                            <td class="lock-cell">🔓</td>
                            <td><span class="code"><?php echo h($did); ?></span></td>
                            <td><?php echo h($docNo); ?></td>
                            <td><?php echo h($title); ?></td>
                            <td><span class="pill"><?php echo h($status); ?></span></td>
                            <td class="text-muted" style="font-size:12px;"><?php echo h(fmt_tr($upd)); ?></td>
                            <td>
                              <div class="actions">
                                <a class="btn btn-outline-primary btn-sm" target="_blank"
                                   href="/php-mongo-erp/public/gendoc_admin.php?module=<?php echo urlencode($module); ?>&doc_type=<?php echo urlencode($docType); ?>&doc_id=<?php echo urlencode($did); ?>">
                                  Edit
                                </a>

                                <a class="btn btn-outline-primary btn-sm" target="_blank"
                                   href="/php-mongo-erp/public/timeline.php?module=<?php echo urlencode($module); ?>&doc_type=<?php echo urlencode($docType); ?>&doc_id=<?php echo urlencode($did); ?>">
                                  Timeline
                                </a>

                                <a class="btn btn-outline-primary btn-sm" target="_blank"
                                   href="/php-mongo-erp/public/audit_view.php?module=<?php echo urlencode($module); ?>&doc_type=<?php echo urlencode($docType); ?>&doc_id=<?php echo urlencode($did); ?>">
                                  Audit
                                </a>

                                <?php if ($snapId): ?>
                                  <a class="btn btn-outline-primary btn-sm" target="_blank"
                                     href="/php-mongo-erp/public/snapshot_view.php?snapshot_id=<?php echo urlencode($snapId); ?>">
                                    Snapshot
                                  </a>
                                <?php else: ?>
                                  <span class="btn btn-outline-secondary btn-sm disabled">Snapshot</span>
                                <?php endif; ?>
                              </div>
                            </td>
                          </tr>
                        <?php endforeach; ?>
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

<!-- New Doc Modal (Theme'e uygun) -->
<div id="newDocModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.35); z-index:9999;">
  <div class="card" style="max-width:560px; margin:12vh auto; border-radius:14px;">
    <div class="card-body">
      <div class="d-flex align-items-center gap-2">
        <div class="fw-bold">Yeni GENDOC</div>
        <span class="flex-grow-1"></span>
        <button class="btn btn-outline-primary btn-sm" type="button" id="btnNewClose">Kapat</button>
      </div>

      <div class="text-muted mt-3" style="font-size:12px;">
        module / doc_type: <b><?php echo h($module); ?></b> / <b><?php echo h($docType); ?></b>
      </div>

      <div class="mt-3">
        <label class="form-label">doc_id</label>
        <input class="form-control" id="new_doc_id" placeholder="örn: DEMO-2">
      </div>

      <div class="mt-4 d-flex gap-2 justify-content-end">
        <button class="btn btn-outline-primary" type="button" id="btnNewCancel">Vazgeç</button>
        <button class="btn btn-primary" type="button" id="btnNewGo">Oluştur</button>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  const module  = <?php echo json_encode($module); ?>;
  const docType = <?php echo json_encode($docType); ?>;

  function escAttr(s){ return String(s ?? '').replace(/"/g, '&quot;'); }

  function renderLockCell(cell, lock){
    if (!cell) return;
    if (!lock) { cell.innerHTML = `<span title="Kilit yok">🔓</span>`; return; }

    const u = lock.username || 'unknown';
    const st = lock.status || 'editing';
    const ttl = (typeof lock.ttl_left_sec === 'number') ? `TTL: ${lock.ttl_left_sec}s` : '';
    const exp = lock.expires_at || '';
    const title = `Kilitli: ${u} (${st})\n${ttl}\nexpires: ${exp}`;

    cell.innerHTML = `<span title="${escAttr(title)}">🔒</span>`;
  }

  async function refreshVisibleLocks(dt){
    if (!dt) return;
    const nodes = dt.rows({ page: 'current' }).nodes().toArray();
    const docIds = [];
    nodes.forEach(n => {
      const did = n && n.dataset ? (n.dataset.docId || '') : '';
      if (did) docIds.push(String(did));
    });

    const uniq = Array.from(new Set(docIds));
    if (uniq.length === 0) return;

    try{
      const url = new URL('/php-mongo-erp/public/api/lock_status.php', window.location.origin);
      const r = await fetch(url.toString(), {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type':'application/json' },
        body: JSON.stringify({ module: module, doc_type: docType, doc_ids: uniq })
      });
      const j = await r.json();
      if (!j.ok) return;

      nodes.forEach(n => {
        const did = n && n.dataset ? (n.dataset.docId || '') : '';
        const cell = n ? n.querySelector('.lock-cell') : null;
        const lock = (did && j.locks) ? (j.locks[did] || null) : null;
        renderLockCell(cell, lock);
      });
    } catch(e){
      console.warn('lock_status failed', e);
    }
  }

  function initDataTable(){
    if (!window.jQuery || !jQuery.fn || !jQuery.fn.DataTable) return null;

    const dt = jQuery('#gendocTable').DataTable({
      searching: false,
      pageLength: 50,
      lengthMenu: [[25,50,100,250,-1],[25,50,100,250,"All"]],
      order: [[5,'desc']],
      columnDefs: [{ orderable:false, targets:[0,6] }],
      stateSave: true,
      autoWidth: false,
      dom: 'lrtip'
    });

    dt.on('draw', function(){ refreshVisibleLocks(dt); });
    refreshVisibleLocks(dt);
    return dt;
  }

  const dt = initDataTable();

  // --- NEW DOC MODAL ---
  const modal = document.getElementById('newDocModal');
  const btnOpen = document.getElementById('btnNewDoc');
  const btnClose = document.getElementById('btnNewClose');
  const btnCancel = document.getElementById('btnNewCancel');
  const btnGo = document.getElementById('btnNewGo');
  const inp = document.getElementById('new_doc_id');

  function openModal(){
    if (!modal) return;
    modal.style.display = 'block';
    setTimeout(() => { if (inp) inp.focus(); }, 0);
  }
  function closeModal(){
    if (!modal) return;
    modal.style.display = 'none';
    if (inp) inp.value = '';
  }
  function goCreate(){
    const did = (inp?.value || '').trim();
    if (!did) { alert('doc_id zorunlu'); return; }

    const url = new URL('/php-mongo-erp/public/gendoc_admin.php', window.location.origin);
    url.searchParams.set('module', module);
    url.searchParams.set('doc_type', docType);
    url.searchParams.set('doc_id', did);

    window.open(url.toString(), '_blank');
    closeModal();
  }

  if (btnOpen) btnOpen.addEventListener('click', openModal);
  if (btnClose) btnClose.addEventListener('click', closeModal);
  if (btnCancel) btnCancel.addEventListener('click', closeModal);
  if (btnGo) btnGo.addEventListener('click', goCreate);

  if (modal) {
    modal.addEventListener('click', function(e){ if (e.target === modal) closeModal(); });
  }
  if (inp) {
    inp.addEventListener('keydown', function(e){
      if (e.key === 'Enter') goCreate();
      if (e.key === 'Escape') closeModal();
    });
  }
})();
</script>

</body>
</html>
