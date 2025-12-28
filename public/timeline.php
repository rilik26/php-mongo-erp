<?php
/**
 * public/timeline.php (FINAL)
 *
 * - Event listesi kart UI
 * - TR tarih/saat formatı
 * - Filtre: event_code / username / module / doc_type / doc_id
 * - Butonlar: LOG / SNAPSHOT / DIFF
 *
 * ✅ GENDOC kartlarında doc_no/title/status otomatik dolsun:
 *    target -> summary -> snapshot.target fallback
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

// View log
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

function esc($s): string {
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

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

/**
 * ✅ snapshot'tan target meta çek (cache'li)
 */
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

/**
 * ✅ event için doc meta çöz (target -> summary -> snapshot.target)
 */
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

?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Timeline</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <style>
    :root{
      --bg:#1f2233;
      --panel:#272b40;
      --panel2:#2d3250;
      --text:#e7eaf3;
      --muted:#a7adc3;
      --border:rgba(255,255,255,.10);
      --primary:#5865f2;
      --chip:#3b4163;
    }
    body{ margin:0; background:var(--bg); color:var(--text); font-family: Arial, sans-serif; }
    .wrap{ max-width:1200px; margin:0 auto; padding:18px; }

    h1{ margin:0 0 12px; font-size:34px; letter-spacing:.2px; }
    .sub{ color:var(--muted); font-size:13px; margin-bottom:10px; }

    .bar{
      display:grid;
      grid-template-columns: 1fr 1fr 1fr 1fr 1fr auto auto;
      gap:12px;
      align-items:center;
      margin: 10px 0 14px;
    }
    @media (max-width: 980px){
      .bar{ grid-template-columns: 1fr 1fr; }
    }

    .in{
      width:100%;
      height:44px;
      box-sizing:border-box;
      background:var(--panel);
      color:var(--text);
      border:1px solid var(--border);
      border-radius:12px;
      padding:0 12px;
      outline:none;
    }
    .in::placeholder{ color:rgba(231,234,243,.55); }
    .in:focus{ border-color: rgba(88,101,242,.65); box-shadow: 0 0 0 3px rgba(88,101,242,.15); }

    .btn{
      height:44px;
      padding:0 16px;
      border-radius:12px;
      border:1px solid var(--border);
      background:transparent;
      color:var(--text);
      cursor:pointer;
      text-decoration:none;
      display:inline-flex;
      align-items:center;
      gap:8px;
      justify-content:center;
      white-space:nowrap;
    }
    .btn-primary{
      background:var(--primary);
      border-color: transparent;
      color:#fff;
    }
    .btn:hover{ filter: brightness(1.05); }
    .btn:active{ transform: translateY(1px); }

    .cards{ display:flex; flex-direction:column; gap:12px; margin-top:12px; }

    .card{
      background:var(--panel2);
      border:1px solid var(--border);
      border-radius:16px;
      padding:14px 14px 12px;
    }

    .row1{
      display:flex;
      gap:10px;
      align-items:center;
      justify-content:space-between;
      flex-wrap:wrap;
      margin-bottom:8px;
    }

    .title{
      font-size:16px;
      font-weight:bold;
      display:flex;
      gap:10px;
      align-items:center;
      flex-wrap:wrap;
    }

    .code{
      font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
      background:rgba(0,0,0,.22);
      padding:3px 8px;
      border-radius:10px;
      border:1px solid var(--border);
      color:rgba(231,234,243,.95);
      font-size:12px;
    }

    .meta{
      color:var(--muted);
      font-size:13px;
      display:flex;
      gap:10px;
      align-items:center;
      flex-wrap:wrap;
    }

    .chips{ display:flex; gap:8px; flex-wrap:wrap; margin-top:8px; }
    .chip{
      background:var(--chip);
      border:1px solid var(--border);
      padding:5px 10px;
      border-radius:999px;
      font-size:12px;
      color:rgba(231,234,243,.95);
    }

    .grid2{
      display:grid;
      grid-template-columns: 1fr 1fr;
      gap:10px;
      margin-top:10px;
    }
    @media (max-width: 980px){ .grid2{ grid-template-columns: 1fr; } }

    .box{
      background:rgba(0,0,0,.14);
      border:1px solid var(--border);
      border-radius:14px;
      padding:10px;
      min-height:52px;
    }
    .box h4{ margin:0 0 8px; font-size:13px; color:rgba(231,234,243,.9); }
    .kv{ font-size:12px; color:var(--muted); line-height:1.55; }
    .kv b{ color:rgba(231,234,243,.95); font-weight:600; }

    .actions{
      display:flex;
      gap:8px;
      flex-wrap:wrap;
      margin-top:10px;
    }
  </style>
</head>
<body>

<div class="wrap">
  <h1>Timeline</h1>

  <div class="sub">
    Firma: <b><?php echo esc($ctx['CDEF01_id'] ?? ''); ?></b>
    &nbsp;|&nbsp; Dönem: <b><?php echo esc($ctx['period_id'] ?? ''); ?></b>
    &nbsp;|&nbsp; Kullanıcı: <b><?php echo esc($ctx['username'] ?? ''); ?></b>
  </div>

  <form method="GET" class="bar">
    <input class="in" name="event"    value="<?php echo esc($eventCode); ?>" placeholder="event_code (örn: GENDOC.ADMIN.SAVE)">
    <input class="in" name="user"     value="<?php echo esc($username); ?>"  placeholder="username (örn: admin)">
    <input class="in" name="module"   value="<?php echo esc($module); ?>"    placeholder="module (örn: gen)">
    <input class="in" name="doc_type" value="<?php echo esc($docType); ?>"   placeholder="doc_type (örn: GENDOC01T)">
    <input class="in" name="doc_id"   value="<?php echo esc($docId); ?>"     placeholder="doc_id (örn: DOC-001)">
    <button class="btn btn-primary" type="submit">Getir</button>
    <a class="btn" href="/php-mongo-erp/public/timeline.php">Sıfırla</a>
    <input type="hidden" name="limit" value="<?php echo (int)$limit; ?>">
  </form>

  <div class="cards">
    <?php if (empty($events)): ?>
      <div class="card">
        <div class="meta">Kayıt bulunamadı.</div>
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

      // ✅ doc meta auto-fill (target -> summary -> snapshot.target)
      [$docNoAuto, $titleAuto, $statusAuto] = resolve_doc_meta($ev);

      // summary chips (version vs)
      $sumVersion = (is_array($sum) ? (string)($sum['version'] ?? '') : '');
    ?>
      <div class="card">
        <div class="row1">
          <div class="title">
            <?php echo esc(event_title($code)); ?>
            <span class="code"><?php echo esc($code); ?></span>
          </div>
          <div class="meta">
            <span><?php echo esc($tTr); ?></span>
            <span>—</span>
            <b><?php echo esc($user ?: '-'); ?></b>
          </div>
        </div>

        <div class="chips">
          <span class="chip">module: <b><?php echo esc($tMod); ?></b></span>
          <span class="chip">doc_type: <b><?php echo esc($tDt); ?></b></span>
          <span class="chip">doc_id: <b><?php echo esc($tDi); ?></b></span>

          <?php if ($docNoAuto !== ''): ?>
            <span class="chip">doc_no: <b><?php echo esc($docNoAuto); ?></b></span>
          <?php endif; ?>
          <?php if ($titleAuto !== ''): ?>
            <span class="chip">title: <b><?php echo esc($titleAuto); ?></b></span>
          <?php endif; ?>
          <?php if ($statusAuto !== ''): ?>
            <span class="chip">status: <b><?php echo esc($statusAuto); ?></b></span>
          <?php endif; ?>
          <?php if ($sumVersion !== ''): ?>
            <span class="chip">version: <b><?php echo esc($sumVersion); ?></b></span>
          <?php endif; ?>
        </div>

        <div class="grid2">
          <div class="box">
            <h4>Özet</h4>
            <div class="kv">
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

          <div class="box">
            <h4>Refs</h4>
            <div class="kv">
              <div><b>log_id</b>: <?php echo esc($logId ?: '-'); ?></div>
              <div><b>snapshot_id</b>: <?php echo esc($snapId ?: '-'); ?></div>
              <div><b>prev_snapshot_id</b>: <?php echo esc($prevId ?: '-'); ?></div>
              <div><b>request_id</b>: <?php echo esc($refs['request_id'] ?? '-'); ?></div>
            </div>
          </div>
        </div>

        <div class="actions">
          <?php if ($logId): ?>
            <a class="btn" target="_blank" href="/php-mongo-erp/public/log_view.php?log_id=<?php echo urlencode($logId); ?>">LOG</a>
          <?php else: ?>
            <span class="btn" style="opacity:.45; cursor:default;">LOG</span>
          <?php endif; ?>

          <?php if ($snapId): ?>
            <a class="btn" target="_blank" href="/php-mongo-erp/public/snapshot_view.php?snapshot_id=<?php echo urlencode($snapId); ?>">SNAPSHOT</a>
          <?php else: ?>
            <span class="btn" style="opacity:.45; cursor:default;">SNAPSHOT</span>
          <?php endif; ?>

          <?php if ($snapId && $prevId): ?>
            <a class="btn" target="_blank" href="/php-mongo-erp/public/snapshot_diff_view.php?snapshot_id=<?php echo urlencode($snapId); ?>">DIFF</a>
          <?php else: ?>
            <span class="btn" style="opacity:.45; cursor:default;">DIFF</span>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

</body>
</html>
