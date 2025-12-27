<?php
/**
 * public/lang_admin.php (FINAL)
 *
 * - TR & EN aynı tabloda yan yana
 * - Toplu kaydet (bulk upsert)
 * - Cache invalidation için LANG01E.version++ (tr/en)
 *
 * GUARD:
 * - login şart (context)
 * - permission: lang.manage
 *
 * AUDIT:
 * - UACT01E log: I18N.ADMIN.VIEW / I18N.ADMIN.SAVE
 * - EVENT01E event: I18N.ADMIN.VIEW / I18N.ADMIN.SAVE
 * - SNAP01E snapshot: LANG01T dictionary (FINAL STATE)
 *
 * LOCK:
 * - page load -> auto acquire (editing)
 * - page exit -> beacon ile release
 *
 * DATATABLES:
 * - HTML tablo DataTables (paging/sort)
 * - q değişince state reset (stateSave bug fix)
 */

require_once __DIR__ . '/../core/bootstrap.php';

require_once __DIR__ . '/../core/auth/SessionManager.php';
require_once __DIR__ . '/../core/base/Context.php';
require_once __DIR__ . '/../core/base/ContextException.php';

require_once __DIR__ . '/../core/auth/permission_helpers.php';
require_once __DIR__ . '/../core/action/ActionLogger.php';
require_once __DIR__ . '/../core/event/EventWriter.php';
require_once __DIR__ . '/../core/snapshot/SnapshotWriter.php';

require_once __DIR__ . '/../app/modules/lang/LANG01ERepository.php';
require_once __DIR__ . '/../app/modules/lang/LANG01TRepository.php';

SessionManager::start();

// login guard
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

// perm guard
require_perm('lang.manage');

// VIEW log + event
$viewLogId = ActionLogger::info('I18N.ADMIN.VIEW', [
  'source' => 'public/lang_admin.php'
], $ctx);

EventWriter::emit(
  'I18N.ADMIN.VIEW',
  ['source' => 'public/lang_admin.php'],
  ['module' => 'i18n', 'doc_type' => 'LANG01T', 'doc_id' => 'DICT', 'doc_no' => 'LANG01T-DICT'],
  $ctx,
  ['log_id' => $viewLogId]
);

$langCodes = ['tr', 'en'];

$q = trim($_GET['q'] ?? '');
$msgKey = null;
$errKey = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $rows = $_POST['rows'] ?? [];

  if (!is_array($rows) || empty($rows)) {
    $errKey = 'lang.admin.required_error';
  } else {
    // 1) DB update
    LANG01TRepository::bulkUpsertPivot($rows, $langCodes);

    // 2) cache invalidation
    LANG01ERepository::bumpVersion('tr');
    LANG01ERepository::bumpVersion('en');

    // SAVE log
    $saveLogId = ActionLogger::success('I18N.ADMIN.SAVE', [
      'source' => 'public/lang_admin.php',
      'rows'   => count($rows)
    ], $ctx);

    /**
     * SNAPSHOT: FINAL STATE (DB'den gerçek sözlük)
     */
    $dictTr = LANG01TRepository::dumpAll('tr'); // key => [module,key,text]
    $dictEn = LANG01TRepository::dumpAll('en');

    $finalRows = [];
    $keys = array_unique(array_merge(array_keys($dictTr), array_keys($dictEn)));

    foreach ($keys as $k) {
      $finalRows[$k] = [
        'module' => $dictTr[$k]['module'] ?? ($dictEn[$k]['module'] ?? 'common'),
        'key'    => $k,
        'tr'     => $dictTr[$k]['text'] ?? '',
        'en'     => $dictEn[$k]['text'] ?? '',
      ];
    }
    ksort($finalRows);

    $snap = SnapshotWriter::capture(
      [
        'module'   => 'i18n',
        'doc_type' => 'LANG01T',
        'doc_id'   => 'DICT',
        'doc_no'   => 'LANG01T-DICT',
      ],
      [
        'rows' => $finalRows
      ],
      [
        'reason'         => 'bulk_upsert',
        'changed_fields' => ['rows'],
        'rows_count'     => count($rows),
      ]
    );

    // EVENT: summary refs'e değil data'ya
    EventWriter::emit(
      'I18N.ADMIN.SAVE',
      [
        'source'  => 'public/lang_admin.php',
        'rows'    => count($rows),
        'summary' => [
          'mode' => 'lang',
          'changed_keys_count' => count($rows),
        ],
      ],
      [
        'module'   => 'i18n',
        'doc_type' => 'LANG01T',
        'doc_id'   => 'DICT',
        'doc_no'   => 'LANG01T-DICT',
      ],
      $ctx,
      [
        'log_id'           => $saveLogId,
        'snapshot_id'      => $snap['snapshot_id'],
        'prev_snapshot_id' => $snap['prev_snapshot_id'] ?? null,
      ]
    );

    $msgKey = 'lang.admin.saved';

    // PRG: refresh ile re-POST olmasın
    $redir = '/php-mongo-erp/public/lang_admin.php';
    if ($q !== '') $redir .= '?q=' . rawurlencode($q);
    header('Location: ' . $redir);
    exit;
  }
}

$pivot = LANG01TRepository::listPivot($langCodes, $q, 800);

// Lock target bilgisi (JS auto lock için)
$lockModule  = 'i18n';
$lockDocType = 'LANG01T';
$lockDocId   = 'DICT';
$lockDocNo   = 'LANG01T-DICT';
$lockDocTitle = 'Language Dictionary';

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title><?php _e('lang.admin.title'); ?></title>

  <!-- DataTables CDN -->
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>

  <style>
    body{ font-family: Arial, sans-serif; }
    table { border-collapse: collapse; width: 100%; }
    th, td { border:1px solid #ddd; padding:8px; vertical-align: top; }
    th { background:#f7f7f7; text-align:left; }
    input[type="text"]{
      width:100%; box-sizing:border-box;
      background:#fff; color:#111;
      border:1px solid #ddd; border-radius:6px;
      padding:6px 8px;
    }
    input[type="text"]:disabled{
      background:#f3f3f3; color:#777;
    }

    .small { font-size:12px; color:#666; }
    .stickybar { display:flex; gap:10px; align-items:center; margin:10px 0; flex-wrap:wrap; }
    .btn { padding:6px 10px; border:1px solid #ccc; background:#fff; cursor:pointer; border-radius:6px; }
    .btn-primary { border-color:#1e88e5; background:#1e88e5; color:#fff; }

    .lockbar{
      display:flex; gap:10px; align-items:center; margin:10px 0; flex-wrap:wrap;
      padding:8px 10px; border:1px solid #eee; border-radius:8px; background:#fafafa;
    }
    .badge{
      display:inline-block; padding:3px 8px; border-radius:999px; font-size:12px;
      background:#E3F2FD; color:#1565C0; font-weight:600;
    }

    /* DataTables küçük dokunuşlar */
    div.dataTables_wrapper .dataTables_length select,
    div.dataTables_wrapper .dataTables_filter input{
      border:1px solid #ddd !important;
      border-radius:6px !important;
      padding:4px 6px !important;
    }
  </style>
</head>
<body>

<?php require_once __DIR__ . '/../app/views/layout/header.php'; ?>

<h3><?php _e('lang.admin.title'); ?></h3>

<div class="lockbar">
  <span class="badge">LOCK: editing</span>
  <span class="small" id="lockStatusText">Lock kontrol ediliyor…</span>
</div>

<?php if ($msgKey): ?><p style="color:green;"><?php _e($msgKey); ?></p><?php endif; ?>
<?php if ($errKey): ?><p style="color:red;"><?php _e($errKey); ?></p><?php endif; ?>

<form method="GET" class="stickybar">
  <label><?php _e('lang.admin.search'); ?>:</label>
  <input type="text" name="q" value="<?php echo h($q); ?>" placeholder="key/text/module">
  <button class="btn" type="submit"><?php _e('lang.admin.fetch'); ?></button>
  <a class="btn" href="/php-mongo-erp/public/lang_admin.php"><?php _e('lang.admin.clear'); ?></a>
  <span class="small"><?php _e('lang.admin.bulk_hint'); ?></span>
</form>

<form method="POST" id="langForm">
  <div class="stickybar">
    <button class="btn btn-primary" type="submit"><?php _e('lang.admin.save'); ?></button>
    <span class="small"><?php echo (int)count($pivot); ?> <?php _e('lang.admin.rows'); ?></span>
  </div>

  <table id="langTable">
    <thead>
      <tr>
        <th style="width:140px;"><?php _e('lang.admin.module'); ?></th>
        <th style="width:240px;"><?php _e('lang.admin.key'); ?></th>
        <th><?php _e('lang.admin.tr_text'); ?></th>
        <th><?php _e('lang.admin.en_text'); ?></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($pivot as $key => $row):
        $module = (string)($row['module'] ?? 'common');
        $tr = (string)($row['tr'] ?? '');
        $en = (string)($row['en'] ?? '');
        $safeKey = h($key);
      ?>
        <tr>
          <td>
            <input type="text"
                   name="rows[<?php echo $safeKey; ?>][module]"
                   value="<?php echo h($module); ?>">
          </td>

          <td>
            <div><strong><?php echo $safeKey; ?></strong></div>
            <input type="hidden"
                   name="rows[<?php echo $safeKey; ?>][key]"
                   value="<?php echo $safeKey; ?>">
          </td>

          <td>
            <input type="text"
                   name="rows[<?php echo $safeKey; ?>][tr]"
                   value="<?php echo h($tr); ?>">
          </td>

          <td>
            <input type="text"
                   name="rows[<?php echo $safeKey; ?>][en]"
                   value="<?php echo h($en); ?>">
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div class="stickybar">
    <button class="btn btn-primary" type="submit"><?php _e('lang.admin.save'); ?></button>
  </div>
</form>

<script>
(function(){
  // showToast helper (varsa onu kullanır, yoksa alert)
  function toast(type, msg){
    if (typeof window.showToast === 'function') {
      window.showToast(type, msg);
      return;
    }
    if (type === 'success') console.log(msg);
    else alert(msg);
  }

  const lockStatusText = document.getElementById('lockStatusText');
  const form = document.getElementById('langForm');

  const module  = <?php echo json_encode($lockModule); ?>;
  const docType = <?php echo json_encode($lockDocType); ?>;
  const docId   = <?php echo json_encode($lockDocId); ?>;
  const docNo   = <?php echo json_encode($lockDocNo); ?>;
  const docTitle= <?php echo json_encode($lockDocTitle); ?>;

  let acquired = false;

  function setReadOnlyMode(isReadOnly){
    if (!form) return;

    const inputs = form.querySelectorAll('input, textarea, select');
    inputs.forEach(el => {
      const t = (el.getAttribute('type') || '').toLowerCase();
      if (t === 'hidden') return;
      el.disabled = !!isReadOnly;
    });

    const submits = form.querySelectorAll('button[type="submit"], input[type="submit"]');
    submits.forEach(b => b.disabled = !!isReadOnly);
  }

  async function acquireLock(){
    const url = new URL('/php-mongo-erp/public/api/lock_acquire.php', window.location.origin);
    url.searchParams.set('module', module);
    url.searchParams.set('doc_type', docType);
    url.searchParams.set('doc_id', docId);
    url.searchParams.set('status', 'editing');
    url.searchParams.set('ttl', '900');
    url.searchParams.set('doc_no', docNo);
    url.searchParams.set('doc_title', docTitle);

    try{
      const r = await fetch(url.toString(), { method:'GET', credentials:'same-origin' });
      const j = await r.json();

      if (!j.ok) {
        acquired = false;
        setReadOnlyMode(true);
        lockStatusText.textContent = 'Lock alınamadı: ' + (j.error || 'unknown');
        toast('error', 'Lock alınamadı: ' + (j.error || 'unknown'));
        return;
      }

      acquired = !!j.acquired;

      if (acquired) {
        setReadOnlyMode(false);
        lockStatusText.textContent = 'Kilit sende. (editing) — çıkınca otomatik bırakılacak.';
        toast('success', 'Lock alındı (editing).');
      } else {
        setReadOnlyMode(true);
        const who = j.lock?.context?.username ? (' (' + j.lock.context.username + ')') : '';
        lockStatusText.textContent = 'Kilit başka bir kullanıcıda' + who + '. Sadece görüntüleme modu.';
        toast('warning', 'Kilit başka bir kullanıcıda' + who + '. Sayfa read-only.');
      }
    } catch(e){
      acquired = false;
      setReadOnlyMode(true);
      lockStatusText.textContent = 'Lock hatası: ' + e.message;
      toast('error', 'Lock hatası: ' + e.message);
    }
  }

  function releaseBeacon(){
    if (!acquired) return;

    const url = new URL('/php-mongo-erp/public/api/lock_release_beacon.php', window.location.origin);
    const fd = new FormData();
    fd.append('module', module);
    fd.append('doc_type', docType);
    fd.append('doc_id', docId);

    try{
      navigator.sendBeacon(url.toString(), fd);
    } catch(e){
      console.warn('release beacon failed', e);
    }
  }

  // --- DataTables ---
  function initDataTable(){
    if (!window.jQuery || !jQuery.fn || !jQuery.fn.DataTable) return null;

    const currentQ = (new URLSearchParams(window.location.search).get('q') || '').trim();
    const storageKey = 'lang_admin_last_q_v1';
    const lastQ = (localStorage.getItem(storageKey) || '').trim();

    const dt = $('#langTable').DataTable({
      searching: false,       // ✅ server-side q
      pageLength: 50,
      lengthMenu: [[25, 50, 100, 250, -1], [25, 50, 100, 250, "All"]],
      order: [[1, 'asc']],    // Key column
      stateSave: true,
      autoWidth: false,
      dom: 'lrtip'
    });

    // ✅ q değiştiyse eski state'i temizle ve ilk sayfaya dön
    if (currentQ !== lastQ) {
      try { dt.state.clear(); } catch(e) {}
      try { dt.page('first').draw('page'); } catch(e) {}
      localStorage.setItem(storageKey, currentQ);
    }

    return dt;
  }

  window.addEventListener('beforeunload', releaseBeacon);

  // Start
  acquireLock();
  initDataTable();
})();
</script>

</body>
</html>
