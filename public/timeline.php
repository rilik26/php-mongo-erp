<?php
/**
 * public/timeline.php (FINAL - THEME)
 *
 * - Event listesi kart UI
 * - TR tarih/saat formatı
 * - Filtre: event_code / username / module / doc_type / doc_id
 * - Butonlar: LOG / SNAPSHOT / DIFF
 *
 * ✅ GENDOC kartlarında doc_no/title/status otomatik dolsun:
 *    target -> summary -> snapshot.target fallback
 *
 * ✅ Theme Layout: header / left / header2 / footer
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

ActionLogger::info('TIMELINE.VIEW', [
  'source' => 'public/timeline.php'
], $ctx);

// ---- Filters ----
$eventCode = trim($_GET['event'] ?? '');
$username  = trim($_GET['user'] ?? '');
$module    = trim($_GET['module'] ?? '');
$docType   = trim($_GET['doc_type'] ?? '');
$docId     = trim($_GET['doc_id'] ?? '');

$limit = (int)($_GET['limit'] ?? 80);
if ($limit < 10) $limit = 10;
if ($limit > 300) $limit = 300;

// Tenant scope
$cdef     = $ctx['CDEF01_id'] ?? null;
$period   = $ctx['period_id'] ?? null;
$facility = $ctx['facility_id'] ?? null;

// Filter build
$filter = [];
if ($cdef) $filter['context.CDEF01_id'] = $cdef;

// Period: current + GLOBAL göster
if ($period) {
  $filter['$or'] = [
    ['context.period_id' => $period],
    ['context.period_id' => 'GLOBAL'],
  ];
}

if ($username !== '') $filter['context.username'] = $username;
if ($eventCode !== '') $filter['event_code'] = $eventCode;

if ($module !== '') $filter['target.module'] = $module;
if ($docType !== '') $filter['target.doc_type'] = $docType;
if ($docId !== '') $filter['target.doc_id'] = $docId;

$cur = MongoManager::collection('EVENT01E')->find(
  $filter,
  [
    'sort'  => ['created_at' => -1],
    'limit' => $limit,
    'projection' => [
      'event_code' => 1,
      'created_at' => 1,
      'context.username' => 1,
      'context.UDEF01_id' => 1,
      'target' => 1,
      'refs' => 1,
      'data' => 1,
    ]
  ]
);

$events = iterator_to_array($cur);

function esc($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function fmt_tr($iso): string {
  if (!$iso) return '-';
  try {
    $dt = new DateTime((string)$iso);
    $dt->setTimezone(new DateTimeZone('Europe/Istanbul'));
    return $dt->format('d.m.Y H:i:s');
  } catch (Throwable $e) {
    return (string)$iso;
  }
}

function bson2arr($v) {
  if ($v instanceof MongoDB\Model\BSONDocument || $v instanceof MongoDB\Model\BSONArray) {
    $v = $v->getArrayCopy();
  }
  if (is_array($v)) {
    $out = [];
    foreach ($v as $k => $vv) $out[$k] = bson2arr($vv);
    return $out;
  }
  if ($v instanceof MongoDB\BSON\UTCDateTime) return $v->toDateTime()->format('c');
  if ($v instanceof MongoDB\BSON\ObjectId) return (string)$v;
  return $v;
}

$events = array_map('bson2arr', $events);

function event_title(string $code): string {
  $map = [
    'I18N.ADMIN.SAVE'  => 'Dil Yönetimi: Kaydet',
    'I18N.ADMIN.VIEW'  => 'Dil Yönetimi: Görüntüle',
    'AUDIT.VIEW'       => 'Audit View: Görüntüle',
    'TIMELINE.VIEW'    => 'Timeline: Görüntüle',

    'GENDOC.ADMIN.VIEW' => 'GENDOC: Görüntüle',
    'GENDOC.ADMIN.SAVE' => 'GENDOC: Kaydet',
    'GENDOC.SAVE'       => 'GENDOC: Kaydet',
  ];
  return $map[$code] ?? $code;
}

// ✅ snapshot'tan target meta çek (cache'li)
$snapMetaCache = []; // snapshot_id => ['doc_no'=>..,'doc_title'=>..,'status'=>..,'version'=>..]
function snapshot_target_meta(?string $snapshotId) {
  global $snapMetaCache;

  $snapshotId = (string)($snapshotId ?? '');
  if ($snapshotId === '') return null;
  if (array_key_exists($snapshotId, $snapMetaCache)) return $snapMetaCache[$snapshotId];

  try {
    $oid = new MongoDB\BSON\ObjectId($snapshotId);
  } catch (Throwable $e) {
    $snapMetaCache[$snapshotId] = null;
    return null;
  }

  $doc = MongoManager::collection('SNAP01E')->findOne(
    ['_id' => $oid],
    ['projection' => ['target.doc_no'=>1,'target.doc_title'=>1,'target.status'=>1,'version'=>1]]
  );
  if (!$doc) {
    $snapMetaCache[$snapshotId] = null;
    return null;
  }
  if ($doc instanceof MongoDB\Model\BSONDocument) $doc = $doc->getArrayCopy();
  $doc = bson2arr($doc);

  $t = (array)($doc['target'] ?? []);
  $out = [
    'doc_no'    => (string)($t['doc_no'] ?? ''),
    'doc_title' => (string)($t['doc_title'] ?? ''),
    'status'    => (string)($t['status'] ?? ''),
    'version'   => (string)($doc['version'] ?? ''),
  ];

  $snapMetaCache[$snapshotId] = $out;
  return $out;
}

// ✅ event için doc meta çöz (target -> summary -> snapshot.target)
function resolve_doc_meta(array $ev): array
{
  $target = (array)($ev['target'] ?? []);
  $refs   = (array)($ev['refs'] ?? []);
  $data   = (array)($ev['data'] ?? []);
  $sum    = $data['summary'] ?? null;

  $docNo   = (string)($target['doc_no'] ?? '');
  $docTitle= (string)($target['doc_title'] ?? '');
  $status  = (string)($target['status'] ?? '');

  // summary fallback
  if (($docNo === '' || $docTitle === '' || $status === '') && is_array($sum)) {
    if ($docNo === '')    $docNo    = (string)($sum['doc_no'] ?? '');
    if ($docTitle === '') $docTitle = (string)($sum['title'] ?? '');
    if ($status === '')   $status   = (string)($sum['status'] ?? '');
  }

  // snapshot fallback
  if (($docNo === '' || $docTitle === '' || $status === '') && !empty($refs['snapshot_id'])) {
    $m = snapshot_target_meta((string)$refs['snapshot_id']);
    if (is_array($m)) {
      if ($docNo === '')    $docNo    = (string)($m['doc_no'] ?? '');
      if ($docTitle === '') $docTitle = (string)($m['doc_title'] ?? '');
      if ($status === '')   $status   = (string)($m['status'] ?? '');
    }
  }

  return [$docNo, $docTitle, $status];
}

/** ✅ THEME HEAD */
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

          <style>
            .tl-wrap{ max-width:1200px; margin:0 auto; }
            .tl-title{ font-size:22px; font-weight:800; margin:0 0 8px; }
            .tl-sub{ font-size:12px; color:rgba(0,0,0,.55); margin-bottom:10px; }

            .tl-form{
              display:grid;
              grid-template-columns: 1fr 1fr 1fr 1fr 1fr auto auto;
              gap:10px;
              align-items:center;
              margin: 10px 0 14px;
            }
            @media (max-width: 980px){
              .tl-form{ grid-template-columns: 1fr 1fr; }
            }

            .tl-in{
              width:100%;
              height:42px;
              box-sizing:border-box;
              background:#fff;
              color:#111;
              border:1px solid rgba(0,0,0,.12);
              border-radius:12px;
              padding:0 12px;
              outline:none;
            }
            .tl-in::placeholder{ color:rgba(0,0,0,.40); }
            .tl-in:focus{ border-color: rgba(30,136,229,.55); box-shadow: 0 0 0 3px rgba(30,136,229,.12); }

            .tl-btn{
              height:42px;
              padding:0 14px;
              border-radius:12px;
              border:1px solid rgba(0,0,0,.12);
              background:#fff;
              color:#111;
              cursor:pointer;
              text-decoration:none;
              display:inline-flex;
              align-items:center;
              justify-content:center;
              white-space:nowrap;
              gap:8px;
            }
            .tl-btn-primary{ background:#1e88e5; border-color:#1e88e5; color:#fff; }
            .tl-btn:hover{ filter:brightness(.98); }

            .tl-cards{ display:flex; flex-direction:column; gap:12px; margin-top:12px; }
            .tl-card{
              background:#fff;
              border:1px solid rgba(0,0,0,.10);
              border-radius:16px;
              padding:14px;
            }

            .tl-row1{
              display:flex; gap:10px; align-items:center; justify-content:space-between;
              flex-wrap:wrap; margin-bottom:8px;
            }
            .tl-evtitle{
              font-size:15px;
              font-weight:800;
              display:flex;
              gap:10px;
              align-items:center;
              flex-wrap:wrap;
            }

            .tl-code{
              font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
              background:rgba(0,0,0,.05);
              padding:3px 8px;
              border-radius:999px;
              border:1px solid rgba(0,0,0,.10);
              color:#111;
              font-size:12px;
            }

            .tl-meta{
              color:rgba(0,0,0,.55);
              font-size:12px;
              display:flex;
              gap:10px;
              align-items:center;
              flex-wrap:wrap;
            }

            .tl-chips{ display:flex; gap:8px; flex-wrap:wrap; margin-top:8px; }
            .tl-chip{
              background:rgba(0,0,0,.03);
              border:1px solid rgba(0,0,0,.10);
              padding:5px 10px;
              border-radius:999px;
              font-size:12px;
              color:rgba(0,0,0,.80);
            }
            .tl-chip b{ color:#111; }

            .tl-grid2{
              display:grid;
              grid-template-columns: 1fr 1fr;
              gap:10px;
              margin-top:10px;
            }
            @media (max-width: 980px){ .tl-grid2{ grid-template-columns: 1fr; } }

            .tl-box{
              background:rgba(0,0,0,.03);
              border:1px solid rgba(0,0,0,.10);
              border-radius:14px;
              padding:10px;
              min-height:52px;
            }
            .tl-box h4{ margin:0 0 8px; font-size:13px; color:#111; font-weight:800; }
            .tl-kv{ font-size:12px; color:rgba(0,0,0,.65); line-height:1.55; }
            .tl-kv b{ color:#111; font-weight:700; }

            .tl-actions{ display:flex; gap:8px; flex-wrap:wrap; margin-top:10px; }
            .tl-dim{ opacity:.45; cursor:default; }
          </style>

          <div class="tl-wrap">
            <div class="tl-title">Timeline</div>

            <div class="tl-sub">
              Firma: <b><?php echo esc($ctx['CDEF01_id'] ?? ''); ?></b>
              &nbsp;|&nbsp; Dönem: <b><?php echo esc($ctx['period_id'] ?? ''); ?></b>
              &nbsp;|&nbsp; Kullanıcı: <b><?php echo esc($ctx['username'] ?? ''); ?></b>
            </div>

            <form method="GET" class="tl-form">
              <input class="tl-in" name="event"    value="<?php echo esc($eventCode); ?>" placeholder="event_code (örn: GENDOC.ADMIN.SAVE)">
              <input class="tl-in" name="user"     value="<?php echo esc($username); ?>"  placeholder="username (örn: admin)">
              <input class="tl-in" name="module"   value="<?php echo esc($module); ?>"    placeholder="module (örn: gen)">
              <input class="tl-in" name="doc_type" value="<?php echo esc($docType); ?>"   placeholder="doc_type (örn: GENDOC01T)">
              <input class="tl-in" name="doc_id"   value="<?php echo esc($docId); ?>"     placeholder="doc_id (örn: DOC-001)">
              <button class="tl-btn tl-btn-primary" type="submit">Getir</button>
              <a class="tl-btn" href="/php-mongo-erp/public/timeline.php">Sıfırla</a>
              <input type="hidden" name="limit" value="<?php echo (int)$limit; ?>">
            </form>

            <div class="tl-cards">
              <?php if (empty($events)): ?>
                <div class="tl-card">
                  <div class="tl-meta">Kayıt bulunamadı.</div>
                </div>
              <?php endif; ?>

              <?php foreach ($events as $ev):
                $code = (string)($ev['event_code'] ?? '');
                $tIso = (string)($ev['created_at'] ?? '');
                $tTr  = fmt_tr($tIso);
                $user = (string)($ev['context']['username'] ?? '');
                $target = (array)($ev['target'] ?? []);
                $refs   = (array)($ev['refs'] ?? []);
                $data   = (array)($ev['data'] ?? []);

                $sum = $data['summary'] ?? null;

                $logId  = $refs['log_id'] ?? null;
                $snapId = $refs['snapshot_id'] ?? null;
                $prevId = $refs['prev_snapshot_id'] ?? null;

                $tMod = (string)($target['module'] ?? '-');
                $tDt  = (string)($target['doc_type'] ?? '-');
                $tDi  = (string)($target['doc_id'] ?? '-');

                [$docNoAuto, $titleAuto, $statusAuto] = resolve_doc_meta($ev);
                $sumVersion = (is_array($sum) ? (string)($sum['version'] ?? '') : '');
              ?>
                <div class="tl-card">
                  <div class="tl-row1">
                    <div class="tl-evtitle">
                      <?php echo esc(event_title($code)); ?>
                      <span class="tl-code"><?php echo esc($code); ?></span>
                    </div>
                    <div class="tl-meta">
                      <span><?php echo esc($tTr); ?></span>
                      <span>—</span>
                      <b><?php echo esc($user ?: '-'); ?></b>
                    </div>
                  </div>

                  <div class="tl-chips">
                    <span class="tl-chip">module: <b><?php echo esc($tMod); ?></b></span>
                    <span class="tl-chip">doc_type: <b><?php echo esc($tDt); ?></b></span>
                    <span class="tl-chip">doc_id: <b><?php echo esc($tDi); ?></b></span>

                    <?php if ($docNoAuto !== ''): ?>
                      <span class="tl-chip">doc_no: <b><?php echo esc($docNoAuto); ?></b></span>
                    <?php endif; ?>
                    <?php if ($titleAuto !== ''): ?>
                      <span class="tl-chip">title: <b><?php echo esc($titleAuto); ?></b></span>
                    <?php endif; ?>
                    <?php if ($statusAuto !== ''): ?>
                      <span class="tl-chip">status: <b><?php echo esc($statusAuto); ?></b></span>
                    <?php endif; ?>
                    <?php if ($sumVersion !== ''): ?>
                      <span class="tl-chip">version: <b><?php echo esc($sumVersion); ?></b></span>
                    <?php endif; ?>
                  </div>

                  <div class="tl-grid2">
                    <div class="tl-box">
                      <h4>Özet</h4>
                      <div class="tl-kv">
                        <?php if (is_array($sum) && !empty($sum)): ?>
                          <?php foreach ($sum as $k => $v):
                            $vv = is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : (string)$v;
                          ?>
                            <div><b><?php echo esc($k); ?></b>: <?php echo esc($vv); ?></div>
                          <?php endforeach; ?>
                        <?php else: ?>
                          <span>-</span>
                        <?php endif; ?>
                      </div>
                    </div>

                    <div class="tl-box">
                      <h4>Refs</h4>
                      <div class="tl-kv">
                        <div><b>log_id</b>: <?php echo esc($logId ?: '-'); ?></div>
                        <div><b>snapshot_id</b>: <?php echo esc($snapId ?: '-'); ?></div>
                        <div><b>prev_snapshot_id</b>: <?php echo esc($prevId ?: '-'); ?></div>
                        <div><b>request_id</b>: <?php echo esc($refs['request_id'] ?? '-'); ?></div>
                      </div>
                    </div>
                  </div>

                  <div class="tl-actions">
                    <?php if ($logId): ?>
                      <a class="tl-btn" target="_blank" href="/php-mongo-erp/public/log_view.php?log_id=<?php echo urlencode($logId); ?>">LOG</a>
                    <?php else: ?>
                      <span class="tl-btn tl-dim">LOG</span>
                    <?php endif; ?>

                    <?php if ($snapId): ?>
                      <a class="tl-btn" target="_blank" href="/php-mongo-erp/public/snapshot_view.php?snapshot_id=<?php echo urlencode($snapId); ?>">SNAPSHOT</a>
                    <?php else: ?>
                      <span class="tl-btn tl-dim">SNAPSHOT</span>
                    <?php endif; ?>

                    <?php if ($snapId && $prevId): ?>
                      <a class="tl-btn" target="_blank" href="/php-mongo-erp/public/snapshot_diff_view.php?snapshot_id=<?php echo urlencode($snapId); ?>">DIFF</a>
                    <?php else: ?>
                      <span class="tl-btn tl-dim">DIFF</span>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
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

</body>
</html>
