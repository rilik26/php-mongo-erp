<?php
/**
 * public/gendoc_admin.php (FINAL)
 *
 * - Header: doc_no, title, status
 * - Body: json textarea
 * - ✅ Versiyon seçme: ?v= (dropdown)
 * - Kaydet: HEADER update/insert + BODY new version + snapshot + event + log
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

if (!function_exists('h')) {
  function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

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

      $targetKey = GENDOC01ERepository::buildTargetKey($target, $ctx);
      if ($targetKey === '') throw new RuntimeException('target_key missing');

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

      $newVersion = GENDOC01ERepository::nextVersion($targetKey, $ctx);

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

      $saveLogId = ActionLogger::success('GENDOC.ADMIN.SAVE', [
        'source' => 'public/gendoc_admin.php',
        'target_key' => $targetKey,
        'version' => $newVersion,
      ], $ctx);

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
                      <h4 class="mb-1">GENDOC Admin</h4>
                      <div class="small text-muted">
                        Target:
                        <span class="text-muted"><?php echo h($module); ?></span> /
                        <span class="text-muted"><?php echo h($docType); ?></span> /
                        <span class="text-muted"><?php echo h($docId); ?></span>
                        <?php if ($targetKey): ?>
                          &nbsp;|&nbsp; target_key: <span class="text-muted"><?php echo h($targetKey); ?></span>
                        <?php endif; ?>
                        <?php if ($loadedVersion > 0): ?>
                          &nbsp;|&nbsp; loaded: <span class="text-muted">V<?php echo (int)$loadedVersion; ?></span>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>

                  <!-- LOCK BAR -->
                  <div class="alert alert-outline-primary d-flex align-items-center flex-wrap row-gap-2 mt-4" role="alert">
                    <span class="alert-icon rounded">
                      <i class="icon-base ri ri-information-line icon-md"></i>
                    </span>
                    <span class="ms-2"><b>LOCK:</b> editing</span>
                    <span class="statusline ms-2" id="lockStatusText">Lock kontrol ediliyor…</span>
                    <span class="statusline ms-2" id="saveStatusText"></span>
                    <span class="statusline ms-2 text-muted">(Not: Lock bilgi amaçlı; alanlar kilitlenmez.)</span>
                  </div>

                  <?php if ($toast === 'save'): ?>
                    <div class="alert alert-outline-success d-flex align-items-center flex-wrap row-gap-2" role="alert">
                      <span class="alert-icon rounded">
                        <i class="icon-base ri ri-check-line icon-md"></i>
                      </span>
                      <span class="ms-2">✅ Kaydedildi.</span>
                    </div>
                  <?php endif; ?>

                  <?php if ($err): ?>
                    <div class="alert alert-outline-danger d-flex align-items-center flex-wrap row-gap-2" role="alert">
                      <span class="alert-icon rounded">
                        <i class="icon-base ri ri-alert-line icon-md"></i>
                      </span>
                      <span class="ms-2"><?php echo h($err); ?></span>
                    </div>
                  <?php endif; ?>

                  <form method="POST" class="mt-4">
                    <div class="row g-4">

                      <div class="col-lg-4">
                        <div class="card">
                          <div class="card-body">

                            <h6 class="mb-3">Header</h6>

                            <div class="mb-3">
                              <label class="form-label">doc_no</label>
                              <input type="text" class="form-control" name="doc_no" value="<?php echo h($docNo); ?>" placeholder="DOC-001">
                            </div>

                            <div class="mb-3">
                              <label class="form-label">title</label>
                              <input type="text" class="form-control" name="title" value="<?php echo h($title); ?>" placeholder="Başlık">
                            </div>

                            <div class="mb-3">
                              <label class="form-label">status</label>
                              <select class="form-select" name="status">
                                <?php foreach (['draft','saved','approving','approved','cancelled'] as $st): ?>
                                  <option value="<?php echo h($st); ?>" <?php echo ($st===$status?'selected':''); ?>>
                                    <?php echo h($st); ?>
                                  </option>
                                <?php endforeach; ?>
                              </select>
                              <div class="text-muted mt-1" style="font-size:12px;">
                                Not: cancelled seçsen bile sonra başka statüyle kaydedebilirsin.
                              </div>
                            </div>

                            <!-- ✅ Version select -->
                            <div class="mb-3">
                              <label class="form-label">Version (yüklemek için)</label>
                              <select class="form-select" id="verSelect">
                                <?php if (empty($versions)): ?>
                                  <option value="0" selected>latest</option>
                                <?php else: ?>
                                  <option value="0" <?php echo ($selectedV<=0?'selected':''); ?>>
                                    latest (V<?php echo (int)$latestVersion; ?>)
                                  </option>
                                  <?php foreach ($versions as $v): ?>
                                    <option value="<?php echo (int)$v; ?>" <?php echo ((int)$selectedV===(int)$v ? 'selected' : ''); ?>>
                                      V<?php echo (int)$v; ?>
                                    </option>
                                  <?php endforeach; ?>
                                <?php endif; ?>
                              </select>
                              <div class="text-muted mt-1" style="font-size:12px;">
                                Seçince sayfa reload olur, body o versiyondan açılır.
                              </div>
                            </div>

                            <div class="d-flex gap-2 flex-wrap">
                              <button class="btn btn-primary" type="submit">Kaydet</button>

                              <a class="btn btn-outline-primary" target="_blank"
                                 href="/php-mongo-erp/public/audit_view.php?module=<?php echo urlencode($module); ?>&doc_type=<?php echo urlencode($docType); ?>&doc_id=<?php echo urlencode($docId); ?>">
                                Audit View
                              </a>

                              <a class="btn btn-outline-primary" target="_blank"
                                 href="/php-mongo-erp/public/timeline.php?module=<?php echo urlencode($module); ?>&doc_type=<?php echo urlencode($docType); ?>&doc_id=<?php echo urlencode($docId); ?>">
                                Timeline
                              </a>
                            </div>

                            <div class="text-muted mt-3" style="font-size:12px;">
                              Not: Kaydet her zaman yeni version üretir (V+1). Seçili versiyonu ezmez.
                            </div>

                          </div>
                        </div>
                      </div>

                      <div class="col-lg-8">
                        <div class="card">
                          <div class="card-body">
                            <h6 class="mb-3">Body (JSON)</h6>
                            <textarea class="form-control" name="body_json" style="min-height:360px; font-family: ui-monospace, Menlo, Consolas, monospace; font-size:12px;"><?php echo h($bodyJson); ?></textarea>
                            <div class="text-muted mt-2" style="font-size:12px;">
                              Not: Şimdilik JSON; sonra form/fields’e böleriz.
                            </div>
                          </div>
                        </div>
                      </div>

                    </div>
                  </form>

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
