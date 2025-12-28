<?php
/**
 * public/gendoc_admin.php (FINAL)
 *
 * - Header: doc_no, title, status
 * - Body: json textarea
 * - ✅ Versiyon seçme: ?v= (dropdown)
 * - Kaydet: HEADER update/insert (duplicate key E11000 fix) + BODY new version + snapshot + event + log
 *
 * ✅ LOCK VAR (LOCK01E)
 * ✅ AMA Body dahil hiçbir alan KİLİTLENMEZ / disable edilmez (sadece bilgi amaçlı gösterim)
 */

require_once __DIR__ . '/../core/bootstrap.php';

require_once __DIR__ . '/../core/auth/SessionManager.php';
require_once __DIR__ . '/../core/base/Context.php';
require_once __DIR__ . '/../core/base/ContextException.php';

require_once __DIR__ . '/../core/auth/permission_helpers.php';
require_once __DIR__ . '/../core/action/ActionLogger.php';
require_once __DIR__ . '/../core/event/EventWriter.php';
require_once __DIR__ . '/../core/snapshot/SnapshotWriter.php';

require_once __DIR__ . '/../app/modules/gendoc/GENDOC01ERepository.php';
require_once __DIR__ . '/../app/modules/gendoc/GENDOC01TRepository.php';

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

// Permission
$isAdmin = (($ctx['role'] ?? '') === 'admin');
if (function_exists('require_perm') && !$isAdmin) {
  require_perm('gendoc.manage');
}

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function bson_to_array($v) {
  if ($v instanceof MongoDB\Model\BSONDocument || $v instanceof MongoDB\Model\BSONArray) {
    $v = $v->getArrayCopy();
  }
  if ($v instanceof MongoDB\BSON\UTCDateTime) return $v->toDateTime()->format('c');
  if ($v instanceof MongoDB\BSON\ObjectId) return (string)$v;
  if (is_array($v)) {
    $out = [];
    foreach ($v as $k => $vv) $out[$k] = bson_to_array($vv);
    return $out;
  }
  return $v;
}

// input target
$module  = trim($_GET['module'] ?? 'gen');
$docType = trim($_GET['doc_type'] ?? 'GENDOC01T');
$docId   = trim($_GET['doc_id'] ?? 'DOC-001');

// ✅ version select param (0/empty => latest)
$selectedV = (int)($_GET['v'] ?? 0);
if ($selectedV < 0) $selectedV = 0;

if ($module === '' || $docType === '' || $docId === '') {
  http_response_code(400);
  echo "module/doc_type/doc_id required";
  exit;
}

$err = null;

// view log + event
$viewLogId = ActionLogger::info('GENDOC.ADMIN.VIEW', [
  'source'   => 'public/gendoc_admin.php',
  'module'   => $module,
  'doc_type' => $docType,
  'doc_id'   => $docId
], $ctx);

EventWriter::emit(
  'GENDOC.ADMIN.VIEW',
  ['source'=>'public/gendoc_admin.php'],
  ['module'=>$module,'doc_type'=>$docType,'doc_id'=>$docId,'doc_no'=>null],
  $ctx,
  ['log_id'=>$viewLogId]
);

$target = [
  'module'   => $module,
  'doc_type' => $docType,
  'doc_id'   => $docId,
];

// ---- header load (robust) ----
$headerDoc = null;
try {
  $headerDoc = GENDOC01ERepository::findByTarget($target, $ctx);
} catch (Throwable $e) {
  $headerDoc = null;
}

$targetKey = '';
try { $targetKey = GENDOC01ERepository::buildTargetKey($target, $ctx); }
catch(Throwable $e) { $targetKey = ''; }

if (!$headerDoc && $targetKey) {
  try {
    $tmp = MongoManager::collection('GENDOC01E')->findOne(['target_key' => $targetKey]);
    if ($tmp) $headerDoc = bson_to_array($tmp);
  } catch(Throwable $e) {}
}

if (!$headerDoc) {
  try {
    $f = [
      'target.module'   => $module,
      'target.doc_type' => $docType,
      'target.doc_id'   => $docId,
    ];
    $tmp = MongoManager::collection('GENDOC01E')->findOne($f, ['sort'=>['updated_at'=>-1, '_id'=>-1]]);
    if ($tmp) $headerDoc = bson_to_array($tmp);
  } catch(Throwable $e) {}
}

$header = [];
if ($headerDoc) {
  $hdoc = $headerDoc['header'] ?? [];
  $header = is_array($hdoc) ? $hdoc : bson_to_array($hdoc);
  if (!is_array($header)) $header = [];
}

$docNo  = (string)($header['doc_no'] ?? '');
$title  = (string)($header['title'] ?? '');
$status = (string)($header['status'] ?? 'draft');
if ($status === '') $status = 'draft';

if (!$targetKey && $headerDoc && !empty($headerDoc['target_key'])) {
  $targetKey = (string)$headerDoc['target_key'];
}

// ---- versions list + load body ----
$versions = [];          // [1,2,3...]
$latestVersion = 0;      // max
$loadedVersion = 0;      // currently loaded
$bodyLatest = null;

if ($targetKey) {
  // versions list
  try {
    $curV = MongoManager::collection('GENDOC01T')->find(
      ['target_key' => $targetKey],
      ['projection' => ['version'=>1], 'sort'=>['version'=>-1], 'limit'=>500]
    );
    $tmp = iterator_to_array($curV);
    foreach ($tmp as $d) {
      $d = bson_to_array($d);
      $v = (int)($d['version'] ?? 0);
      if ($v > 0) $versions[] = $v;
    }
    $versions = array_values(array_unique($versions));
    rsort($versions);
    $latestVersion = !empty($versions) ? (int)$versions[0] : 0;
  } catch(Throwable $e) {
    $versions = [];
    $latestVersion = 0;
  }

  // which version to load?
  $wantV = $selectedV > 0 ? $selectedV : $latestVersion;

  try {
    $q = ['target_key' => $targetKey];
    $opt = ['sort' => ['version' => -1], 'projection' => ['body'=>1,'version'=>1]];
    if ($wantV > 0) {
      $q['version'] = $wantV;
      unset($opt['sort']);
    }

    $bodyDoc = MongoManager::collection('GENDOC01T')->findOne($q, $opt);
    if ($bodyDoc) {
      $bodyDoc = bson_to_array($bodyDoc);
      $loadedVersion = (int)($bodyDoc['version'] ?? 0);
      $b = bson_to_array($bodyDoc['body'] ?? null);
      $bodyLatest = is_array($b) ? $b : null;
    } else {
      // fallback: latest
      $bodyDoc = MongoManager::collection('GENDOC01T')->findOne(
        ['target_key' => $targetKey],
        ['sort'=>['version'=>-1], 'projection'=>['body'=>1,'version'=>1]]
      );
      if ($bodyDoc) {
        $bodyDoc = bson_to_array($bodyDoc);
        $loadedVersion = (int)($bodyDoc['version'] ?? 0);
        $b = bson_to_array($bodyDoc['body'] ?? null);
        $bodyLatest = is_array($b) ? $b : null;
      }
    }
  } catch(Throwable $e) {}
}

// default example body
$bodyJson = $bodyLatest
  ? json_encode($bodyLatest, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)
  : "{\n  \"doc\": {\n    \"customer\": \"\",\n    \"lines\": [\n      {\"code\":\"A-001\",\"qty\":1,\"price\":100}\n    ]\n  },\n  \"note\": \"demo body\"\n}";

// ---- POST Save ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $docNo  = trim($_POST['doc_no'] ?? '');
  $title  = trim($_POST['title'] ?? '');
  $status = trim($_POST['status'] ?? 'draft');
  if ($status === '') $status = 'draft';

  $rawBody = trim($_POST['body_json'] ?? '{}');
  $bodyArr = json_decode($rawBody, true);

  if (!is_array($bodyArr)) {
    $err = "Body JSON geçersiz.";
  } else {
    try {
      $now = new MongoDB\BSON\UTCDateTime((int)(microtime(true) * 1000));

      // target_key garanti
      $targetKey = GENDOC01ERepository::buildTargetKey($target, $ctx);
      if ($targetKey === '') throw new RuntimeException('target_key missing');

      // HEADER update/insert (E11000 fix)
      $existing = MongoManager::collection('GENDOC01E')->findOne(['target_key' => $targetKey], ['projection'=>['_id'=>1]]);
      $existingArr = $existing ? bson_to_array($existing) : null;

      $header = [
        'doc_no' => $docNo,
        'title'  => $title,
        'status' => $status,
      ];

      $cdef     = $ctx['CDEF01_id'] ?? null;
      $period   = $ctx['period_id'] ?? null;
      $facility = $ctx['facility_id'] ?? null;

      $updateDoc = [
        '$set' => [
          'target' => [
            'module'    => $module,
            'doc_type'  => $docType,
            'doc_id'    => $docId,
            'doc_no'    => $docNo ?: null,
            'doc_title' => $title ?: null,
            'status'    => $status ?: null,
          ],
          'header'     => $header,
          'target_key' => $targetKey,
          'updated_at' => $now,
        ],
      ];

      if ($existingArr && !empty($existingArr['_id'])) {
        MongoManager::collection('GENDOC01E')->updateOne(
          ['_id' => new MongoDB\BSON\ObjectId((string)$existingArr['_id'])],
          $updateDoc
        );
      } else {
        $insert = [
          'context' => [
            'CDEF01_id'   => $cdef,
            'period_id'   => $period ?: 'GLOBAL',
            'facility_id' => ($facility === '' ? null : $facility),
          ],
          'target' => $updateDoc['$set']['target'],
          'header' => $header,
          'target_key' => $targetKey,
          'created_at' => $now,
          'updated_at' => $now,
          'last_version' => 0,
        ];
        MongoManager::collection('GENDOC01E')->insertOne($insert);
      }

      // Atomic version (her zaman yeni version)
      $newVersion = GENDOC01ERepository::nextVersion($targetKey, $ctx);

      // BODY insert
      GENDOC01TRepository::insertVersion(
        $targetKey,
        $newVersion,
        $bodyArr,
        $ctx,
        [
          'module'=>$module,'doc_type'=>$docType,'doc_id'=>$docId,
          'doc_no'=>$docNo ?: null,
          'doc_title'=>$title ?: null,
          'status'=>$status ?: null,
        ]
      );

      // SAVE log
      $saveLogId = ActionLogger::success('GENDOC.ADMIN.SAVE', [
        'source' => 'public/gendoc_admin.php',
        'target_key' => $targetKey,
        'version' => $newVersion,
      ], $ctx);

      // SNAPSHOT
      $snap = SnapshotWriter::capture(
        [
          'module'    => $module,
          'doc_type'  => $docType,
          'doc_id'    => $docId,
          'doc_no'    => $docNo ?: null,
          'doc_title' => $title ?: null,
          'status'    => $status ?: null,
        ],
        [
          'header' => $header,
          'body'   => $bodyArr,
        ],
        [
          'reason' => 'gendoc_save',
          'changed_fields' => ['header','body'],
          'version' => $newVersion,
        ]
      );

      // EVENT
      EventWriter::emit(
        'GENDOC.ADMIN.SAVE',
        [
          'source' => 'public/gendoc_admin.php',
          'summary' => [
            'doc_no' => $docNo,
            'title'  => $title,
            'status' => $status,
            'version'=> $newVersion,
            'mode'   => 'gendoc',
          ],
        ],
        [
          'module'    => $module,
          'doc_type'  => $docType,
          'doc_id'    => $docId,
          'doc_no'    => $docNo ?: null,
          'doc_title' => $title ?: null,
          'status'    => $status ?: null,
        ],
        $ctx,
        [
          'log_id'           => $saveLogId,
          'snapshot_id'      => $snap['snapshot_id'] ?? null,
          'prev_snapshot_id' => $snap['prev_snapshot_id'] ?? null,
        ]
      );

      // PRG -> save sonrası latest'e düş (v paramını kaldırıyoruz)
      $redir = '/php-mongo-erp/public/gendoc_admin.php?module=' . rawurlencode($module)
             . '&doc_type=' . rawurlencode($docType)
             . '&doc_id=' . rawurlencode($docId)
             . '&toast=save';
      header('Location: ' . $redir);
      exit;

    } catch(Throwable $e) {
      $err = "Kaydetme hatası: " . $e->getMessage();
    }
  }
}

$toast = trim($_GET['toast'] ?? '');
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>GENDOC Admin</title>
  <style>
    body{ font-family: Arial, sans-serif; }
    .bar{ display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin:10px 0; }
    .btn{ padding:6px 10px; border:1px solid #ccc; background:#fff; cursor:pointer; border-radius:6px; text-decoration:none; color:#000; }
    .btn-primary{ border-color:#1e88e5; background:#1e88e5; color:#fff; }
    .small{ font-size:12px; color:#666; }
    input[type="text"], select, textarea{
      width:100%; box-sizing:border-box;
      border:1px solid #ddd; border-radius:8px;
      padding:8px 10px;
      background:#fff; color:#111;
    }
    textarea{ min-height: 280px; font-family: ui-monospace, Menlo, Consolas, monospace; font-size:12px; }
    .grid{ display:grid; grid-template-columns: 1fr 2fr; gap:14px; }
    @media (max-width: 980px){ .grid{ grid-template-columns:1fr; } }
    .card{ border:1px solid #eee; border-radius:12px; padding:12px; }
    .code{ font-family: ui-monospace, Menlo, Consolas, monospace; }
    .lockbar{
      display:flex; gap:10px; align-items:center; flex-wrap:wrap;
      padding:8px 10px; border:1px solid #eee; border-radius:8px; background:#fafafa;
      margin:10px 0;
    }
    .badge{
      display:inline-block; padding:3px 8px; border-radius:999px; font-size:12px;
      background:#E3F2FD; color:#1565C0; font-weight:600;
    }
  </style>
</head>
<body>

<?php require_once __DIR__ . '/../app/views/layout/header.php'; ?>

<h3>GENDOC Admin</h3>
<div class="small">
  Target:
  <span class="code"><?php echo h($module); ?></span> /
  <span class="code"><?php echo h($docType); ?></span> /
  <span class="code"><?php echo h($docId); ?></span>
  <?php if ($targetKey): ?>
    &nbsp;|&nbsp; target_key: <span class="code"><?php echo h($targetKey); ?></span>
  <?php endif; ?>
  <?php if ($loadedVersion > 0): ?>
    &nbsp;|&nbsp; loaded: <span class="code">V<?php echo (int)$loadedVersion; ?></span>
  <?php endif; ?>
</div>

<div class="lockbar">
  <span class="badge">LOCK: editing</span>
  <span class="small" id="lockStatusText">Lock kontrol ediliyor…</span>
  <span class="small" id="saveStatusText"></span>
  <span class="small" style="color:#999;">(Not: Lock bilgi amaçlı; alanlar kilitlenmez.)</span>
</div>

<?php if ($toast === 'save'): ?>
  <p style="color:green;">✅ Kaydedildi.</p>
<?php endif; ?>

<?php if ($err): ?><p style="color:red;"><?php echo h($err); ?></p><?php endif; ?>

<form method="POST">
  <div class="grid">
    <div class="card">
      <div class="small"><b>Header</b></div>

      <div class="bar">
        <div style="flex:1">
          <div class="small">doc_no</div>
          <input type="text" name="doc_no" value="<?php echo h($docNo); ?>" placeholder="DOC-001">
        </div>
        <div style="flex:2">
          <div class="small">title</div>
          <input type="text" name="title" value="<?php echo h($title); ?>" placeholder="Başlık">
        </div>
      </div>

      <div class="bar">
        <div style="flex:1">
          <div class="small">status</div>
          <select name="status">
            <?php foreach (['draft','saved','approving','approved','cancelled'] as $st): ?>
              <option value="<?php echo h($st); ?>" <?php echo ($st===$status?'selected':''); ?>>
                <?php echo h($st); ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="small">Not: cancelled seçsen bile sonra tekrar başka statüye alıp kaydedebilirsin.</div>
        </div>
      </div>

      <!-- ✅ Version select -->
      <div class="bar">
        <div style="flex:1">
          <div class="small">Version (yüklemek için)</div>
          <select id="verSelect">
            <?php if (empty($versions)): ?>
              <option value="0" selected>latest</option>
            <?php else: ?>
              <option value="0" <?php echo ($selectedV<=0?'selected':''); ?>>latest (V<?php echo (int)$latestVersion; ?>)</option>
              <?php foreach ($versions as $v): ?>
                <option value="<?php echo (int)$v; ?>" <?php echo ((int)$selectedV===(int)$v ? 'selected' : ''); ?>>
                  V<?php echo (int)$v; ?>
                </option>
              <?php endforeach; ?>
            <?php endif; ?>
          </select>
          <div class="small">Seçince sayfa reload olur, body o versiyondan açılır.</div>
        </div>
      </div>

      <div class="bar">
        <button class="btn btn-primary" type="submit">Kaydet</button>

        <a class="btn" target="_blank"
           href="/php-mongo-erp/public/audit_view.php?module=<?php echo urlencode($module); ?>&doc_type=<?php echo urlencode($docType); ?>&doc_id=<?php echo urlencode($docId); ?>">
          Audit View
        </a>

        <a class="btn" target="_blank"
           href="/php-mongo-erp/public/timeline.php?module=<?php echo urlencode($module); ?>&doc_type=<?php echo urlencode($docType); ?>&doc_id=<?php echo urlencode($docId); ?>">
          Timeline
        </a>
      </div>

      <div class="small">
        Not: Kaydet her zaman yeni version üretir (V+1). Seçili versiyonu ezmez.
      </div>
    </div>

    <div class="card">
      <div class="small"><b>Body (JSON)</b></div>
      <textarea name="body_json"><?php echo h($bodyJson); ?></textarea>
      <div class="small" style="margin-top:6px;">Not: Şimdilik JSON; sonra form/fields’e böleriz.</div>
    </div>
  </div>
</form>

<script>
(function(){
  function setSaveStatus(msg){
    const el = document.getElementById('saveStatusText');
    if (el) el.textContent = msg || '';
  }

  const qs = new URLSearchParams(window.location.search);
  if ((qs.get('toast') || '') === 'save') setSaveStatus('✅ Kaydedildi.');

  // ✅ version select -> reload with v=
  const sel = document.getElementById('verSelect');
  if (sel) {
    sel.addEventListener('change', function(){
      const v = String(sel.value || '0');
      const url = new URL(window.location.href);
      if (v === '0') url.searchParams.delete('v');
      else url.searchParams.set('v', v);
      url.searchParams.delete('toast');
      window.location.href = url.toString();
    });
  }

  // LOCK info
  const lockStatusText = document.getElementById('lockStatusText');

  const module  = <?php echo json_encode($module); ?>;
  const docType = <?php echo json_encode($docType); ?>;
  const docId   = <?php echo json_encode($docId); ?>;

  const docNo   = <?php echo json_encode($docNo); ?>;
  const docTitle= <?php echo json_encode($title); ?>;

  let acquired = false;

  async function acquireLock(){
    const url = new URL('/php-mongo-erp/public/api/lock_acquire.php', window.location.origin);
    url.searchParams.set('module', module);
    url.searchParams.set('doc_type', docType);
    url.searchParams.set('doc_id', docId);
    url.searchParams.set('status', 'editing');
    url.searchParams.set('ttl', '900');
    if (docNo) url.searchParams.set('doc_no', docNo);
    if (docTitle) url.searchParams.set('doc_title', docTitle);

    try{
      const r = await fetch(url.toString(), { method:'GET', credentials:'same-origin' });
      const j = await r.json();

      if (!j.ok) {
        acquired = false;
        lockStatusText.textContent = 'Lock alınamadı: ' + (j.error || 'unknown');
        return;
      }

      acquired = !!j.acquired;

      if (acquired) {
        lockStatusText.textContent = 'Kilit sende. (editing) — alanlar kilitlenmez.';
      } else {
        const who = j.lock?.context?.username ? (' (' + j.lock.context.username + ')') : '';
        lockStatusText.textContent = 'Kilit başka kullanıcıda' + who + ' — yine de düzenleyebilirsin.';
      }
    } catch(e){
      acquired = false;
      lockStatusText.textContent = 'Lock hatası: ' + e.message;
    }
  }

  function releaseBeacon(){
    if (!acquired) return;

    const url = new URL('/php-mongo-erp/public/api/lock_release_beacon.php', window.location.origin);
    const fd = new FormData();
    fd.append('module', module);
    fd.append('doc_type', docType);
    fd.append('doc_id', docId);

    try{ navigator.sendBeacon(url.toString(), fd); } catch(e){}
  }

  window.addEventListener('beforeunload', releaseBeacon);
  acquireLock();
})();
</script>

</body>
</html>
