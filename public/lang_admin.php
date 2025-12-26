<?php
/**
 * public/lang_admin.php (FINAL)
 *
 * - TR/EN pivot edit
 * - bulk upsert
 * - bump version (tr/en)
 *
 * AUDIT:
 * - UACT01E log: I18N.ADMIN.VIEW / I18N.ADMIN.SAVE
 * - EVENT01E event: I18N.ADMIN.VIEW / I18N.ADMIN.SAVE
 * - SNAP01E snapshot: LANG01T dictionary (FINAL STATE)
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

require_once __DIR__ . '/../core/snapshot/SnapshotRepository.php';
require_once __DIR__ . '/../core/snapshot/SnapshotDiff.php';

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
require_perm('lang.manage');

require_once __DIR__ . '/../core/lock/LockRepository.php';
require_once __DIR__ . '/../core/lock/LockManager.php';

$lockRes = LockManager::acquire(
    [
        'module' => 'i18n',
        'doc_type' => 'LANG01T',
        'doc_id' => 'DICT',
        'doc_no' => 'LANG01T-DICT',
        'doc_title' => 'Language Dictionary',
    ],
    900,
    'editing'
);

$lockDenied = (isset($lockRes['acquired']) && $lockRes['acquired'] === false);
$lockInfo = $lockRes['lock'] ?? null;


// VIEW log + event
$viewLogId = ActionLogger::info('I18N.ADMIN.VIEW', [
    'source' => 'public/lang_admin.php'
], $ctx);

EventWriter::emit(
    'I18N.ADMIN.VIEW',
    ['source' => 'public/lang_admin.php'],
    ['module' => 'i18n', 'doc_type' => 'LANG01T', 'doc_id' => 'DICT'],
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

        // ✅ Snapshot FINAL STATE (DB’den dump)
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

        // ✅ Diff summary (Event.data.summary)
        $diff = null;
        $summary = ['note' => 'no_prev_snapshot'];

        if (!empty($snap['prev_snapshot_id'])) {
            $prevSnap = SnapshotRepository::findById($snap['prev_snapshot_id']);
            if ($prevSnap) {
                $oldRows = (array)($prevSnap['data']['rows'] ?? []);
                $newRows = (array)($finalRows ?? []);
                $diff = SnapshotDiff::diffLangRows($oldRows, $newRows);
                $summary = SnapshotDiff::summarizeLangDiff($diff, 8);
                $summary['mode'] = 'lang';
            }
        }

        // ✅ Event: refs temiz, summary data’da
        EventWriter::emit(
            'I18N.ADMIN.SAVE',
            [
                'source'  => 'public/lang_admin.php',
                'rows'    => count($rows),
                'summary' => $summary,
                // debug istersen:
                // 'diff' => $diff,
            ],
            [
                'module'   => 'i18n',
                'doc_type' => 'LANG01T',
                'doc_id'   => 'DICT'
            ],
            $ctx,
            [
                'log_id'           => $saveLogId,
                'snapshot_id'      => $snap['snapshot_id'],
                'prev_snapshot_id' => $snap['prev_snapshot_id'] ?? null,
            ]
        );

        $msgKey = 'lang.admin.saved';
    }
}

$pivot = LANG01TRepository::listPivot($langCodes, $q, 800);
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title><?php _e('lang.admin.title'); ?></title>
  <style>
    table { border-collapse: collapse; width: 100%; }
    th, td { border:1px solid #ddd; padding:8px; vertical-align: top; }
    th { background:#f7f7f7; text-align:left; }
    input[type="text"]{ width:100%; box-sizing:border-box; }
    .small { font-size:12px; color:#666; }
    .stickybar { display:flex; gap:10px; align-items:center; margin:10px 0; flex-wrap:wrap; }
    .btn { padding:6px 10px; border:1px solid #ccc; background:#fff; cursor:pointer; }
    .btn-primary { border-color:#1e88e5; background:#1e88e5; color:#fff; }
  </style>
</head>
<body>

<?php require_once __DIR__ . '/../app/views/layout/header.php'; ?>

<h3><?php _e('lang.admin.title'); ?></h3>
<?php if (!empty($lockDenied)): ?>
  <div style="padding:10px; border:1px solid #f5c2c7; background:#f8d7da; color:#842029; border-radius:8px; margin:10px 0;">
    Bu ekran şu an başka bir kullanıcı tarafından kilitli:
    <strong><?php echo htmlspecialchars($lockInfo['context']['username'] ?? 'unknown'); ?></strong>
    (<?php echo htmlspecialchars($lockInfo['status'] ?? 'editing'); ?>)
  </div>
<?php endif; ?>
<?php if ($msgKey): ?><p style="color:green;"><?php _e($msgKey); ?></p><?php endif; ?>
<?php if ($errKey): ?><p style="color:red;"><?php _e($errKey); ?></p><?php endif; ?>

<form method="GET" class="stickybar">
  <label><?php _e('lang.admin.search'); ?>:</label>
  <input type="text" name="q"
         value="<?php echo htmlspecialchars($q, ENT_QUOTES, 'UTF-8'); ?>"
         placeholder="key/text/module">
  <button class="btn" type="submit"><?php _e('lang.admin.fetch'); ?></button>
  <span class="small"><?php _e('lang.admin.bulk_hint'); ?></span>
</form>

<form method="POST">
  <div class="stickybar">
    <button class="btn btn-primary" type="submit"><?php _e('lang.admin.save'); ?></button>
    <span class="small"><?php echo (int)count($pivot); ?> <?php _e('lang.admin.rows'); ?></span>
  </div>

  <table>
    <tr>
      <th style="width:140px;"><?php _e('lang.admin.module'); ?></th>
      <th style="width:240px;"><?php _e('lang.admin.key'); ?></th>
      <th><?php _e('lang.admin.tr_text'); ?></th>
      <th><?php _e('lang.admin.en_text'); ?></th>
    </tr>

    <?php foreach ($pivot as $key => $row):
      $module = (string)($row['module'] ?? 'common');
      $tr = (string)($row['tr'] ?? '');
      $en = (string)($row['en'] ?? '');
      $safeKey = htmlspecialchars($key, ENT_QUOTES, 'UTF-8');
    ?>
      <tr>
        <td>
          <input type="text"
                 name="rows[<?php echo $safeKey; ?>][module]"
                 value="<?php echo htmlspecialchars($module, ENT_QUOTES, 'UTF-8'); ?>">
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
                 value="<?php echo htmlspecialchars($tr, ENT_QUOTES, 'UTF-8'); ?>">
        </td>

        <td>
          <input type="text"
                 name="rows[<?php echo $safeKey; ?>][en]"
                 value="<?php echo htmlspecialchars($en, ENT_QUOTES, 'UTF-8'); ?>">
        </td>
      </tr>
    <?php endforeach; ?>
  </table>

  <div class="stickybar">
    <button class="btn btn-primary" type="submit"><?php _e('lang.admin.save'); ?></button>
  </div>
</form>
<script>
(function(){
  // 60sn'de bir TTL uzat (renew)
  const EVERY_MS = 60 * 1000;

  function refreshLock(){
    const params = new URLSearchParams();
    params.set('module','i18n');
    params.set('doc_type','LANG01T');
    params.set('doc_id','DICT');
    params.set('status','editing');
    params.set('ttl','900');
    params.set('doc_no','LANG01T-DICT');
    params.set('doc_title','Language Dictionary');

    fetch('/php-mongo-erp/public/api/lock_refresh.php', {
      method: 'POST',
      headers: {'Content-Type':'application/x-www-form-urlencoded'},
      body: params.toString()
    }).then(r=>r.json()).then(j=>{
      // Eğer acquired false dönerse, lock başka kullanıcıya geçmiş demektir (nadir).
      // İstersen burada ekrana uyarı basarız.
      // console.log('refresh', j);
    }).catch(()=>{});
  }

  // ilk açılışta 1 kere, sonra interval
  refreshLock();
  setInterval(refreshLock, EVERY_MS);
})();
</script>

<script>
(function(){
  function releaseLock(){
    try{
      const params = new URLSearchParams();
      params.set('module','i18n');
      params.set('doc_type','LANG01T');
      params.set('doc_id','DICT');

      // sendBeacon POST yapar
      navigator.sendBeacon('/php-mongo-erp/public/api/lock_release.php', params);
    }catch(e){}
  }

  window.addEventListener('beforeunload', releaseLock);
})();
</script>
<script>
(function(){
  const module = "i18n";
  const docType = "LANG01T";
  const docId = "DICT";

  const acquireUrl = `/php-mongo-erp/public/api/lock_acquire.php?module=${encodeURIComponent(module)}&doc_type=${encodeURIComponent(docType)}&doc_id=${encodeURIComponent(docId)}&status=editing&ttl=900&doc_no=${encodeURIComponent("LANG01T-DICT")}&doc_title=${encodeURIComponent("Language Dictionary")}`;
  const touchUrl   = `/php-mongo-erp/public/api/lock_touch.php?module=${encodeURIComponent(module)}&doc_type=${encodeURIComponent(docType)}&doc_id=${encodeURIComponent(docId)}&ttl=900&status=editing`;
  const releaseUrl = `/php-mongo-erp/public/api/lock_release.php?module=${encodeURIComponent(module)}&doc_type=${encodeURIComponent(docType)}&doc_id=${encodeURIComponent(docId)}`;

  let acquired = false;
  let touchTimer = null;

  function stopTouch(){
    if (touchTimer) clearInterval(touchTimer);
    touchTimer = null;
  }

  function startTouch(){
    stopTouch();
    touchTimer = setInterval(() => {
      fetch(touchUrl, { method:'GET', credentials:'same-origin' }).catch(()=>{});
    }, 30000);
  }

  function showLockedBy(lock){
    const u = lock?.context?.username || 'unknown';
    const st = lock?.status || 'editing';
   const until = lock?.expires_at?.tr || lock?.expires_at?.iso || '';
    alert(`Bu evrak şu anda kilitli.\nKişi: ${u}\nDurum: ${st}\nBitiş: ${until}`);
  }

  fetch(acquireUrl, { method:'GET', credentials:'same-origin' })
    .then(r => r.json())
    .then(res => {
      if (!res.ok) throw new Error(res.error || 'lock_acquire_failed');

      if (res.acquired) {
        acquired = true;
        startTouch();
        return;
      }

      // acquired değilse: kilitli
      showLockedBy(res.lock);
      // Bu sayfada kalmasını istemiyorsan:
      // window.location.href = '/php-mongo-erp/public/index.php';
    })
    .catch(err => {
      console.warn('lock acquire error', err);
    });

  // Sayfa kapanırken release (sendBeacon)
  window.addEventListener('beforeunload', function(){
    stopTouch();
    if (!acquired) return;

    try {
      if (navigator.sendBeacon) {
        navigator.sendBeacon(releaseUrl);
      } else {
        // fallback (best-effort)
        fetch(releaseUrl, { method:'GET', keepalive:true, credentials:'same-origin' }).catch(()=>{});
      }
    } catch (e) {}
  });
})();
</script>
// bu sayfanın target_key’i
$targetKey = 'i18n|LANG01T|DICT|'
  . ($ctx['CDEF01_id'] ?? 'null') . '|'
  . ($ctx['period_id'] ?? 'null') . '|'
  . ($ctx['facility_id'] ?? 'null');
?>

<script>
(function(){
  const TARGET_KEY = <?= json_encode($targetKey) ?>;
  if(!TARGET_KEY) return;

  window.addEventListener('storage', function(ev){
    if(ev.key === 'lock_release:'+TARGET_KEY){
      alert('Bu evrağın kilidi bırakıldı. Güvenli sayfaya yönlendiriliyorsunuz.');
      window.location.href = '/php-mongo-erp/public/index.php';
    }
  });
})();
</script>
</body>
</html>
