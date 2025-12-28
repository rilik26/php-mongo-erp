<?php
/**
 * public/audit_view.php (FINAL)
 *
 * - Audit ekranı
 * - Filtre: module/doc_type/doc_id
 * - ✅ GENDOC zinciri: SNAP01E üzerinden V1→V2→V3 (tüm versiyonlar)
 * - Snapshot / Diff / Timeline linkleri
 * - BSONDocument -> array stabil
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

ActionLogger::info('AUDIT.VIEW', [
  'source' => 'public/audit_view.php',
], $ctx);

function esc($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

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

// ---- input ----
$module  = trim($_GET['module'] ?? '');
$docType = trim($_GET['doc_type'] ?? '');
$docId   = trim($_GET['doc_id'] ?? '');

$limitEvents = (int)($_GET['limit'] ?? 50);
if ($limitEvents < 10) $limitEvents = 10;
if ($limitEvents > 200) $limitEvents = 200;

// ---- tenant scope ----
$cdef     = $ctx['CDEF01_id'] ?? null;
$period   = $ctx['period_id'] ?? null;
$facility = $ctx['facility_id'] ?? null;

/**
 * facility filtre stabil:
 * - facility yoksa filtreleme
 * - facility varsa: hem eşit olanları hem de field missing/null olanları göster
 */
function apply_facility_filter(array &$filter, $facility): void {
  if ($facility === null || $facility === '') return;

  // zaten $or varsa genişlet
  if (isset($filter['$and']) && is_array($filter['$and'])) {
    $filter['$and'][] = [
      '$or' => [
        ['context.facility_id' => $facility],
        ['context.facility_id' => null],
        ['context.facility_id' => ['$exists' => false]],
      ]
    ];
    return;
  }

  // $and yoksa oluştur
  $filter['$and'] = [
    [
      '$or' => [
        ['context.facility_id' => $facility],
        ['context.facility_id' => null],
        ['context.facility_id' => ['$exists' => false]],
      ]
    ]
  ];
}

// ---- snapshot list (chain) ----
$snapshots = [];
$snapErr = null;

if ($module !== '' && $docType !== '' && $docId !== '') {
  $snapFilter = [
    'target.module'   => $module,
    'target.doc_type' => $docType,
    'target.doc_id'   => $docId,
  ];

  if ($cdef) $snapFilter['context.CDEF01_id'] = $cdef;

  // period: current + GLOBAL
  if ($period) {
    $snapFilter['$or'] = [
      ['context.period_id' => $period],
      ['context.period_id' => 'GLOBAL'],
    ];
  }

  // facility stabil
  apply_facility_filter($snapFilter, $facility);

  try {
    $cur = MongoManager::collection('SNAP01E')->find(
      $snapFilter,
      [
        'sort' => ['version' => 1, 'created_at' => 1],
        'projection' => [
          '_id' => 1,
          'version' => 1,
          'created_at' => 1,
          'hash' => 1,
          'prev_snapshot_id' => 1,
          'target' => 1,
          'context.username' => 1,
        ],
        'limit' => 500,
      ]
    );
    $snapshots = array_map('bson_to_array', iterator_to_array($cur));
  } catch(Throwable $e) {
    $snapErr = $e->getMessage();
    $snapshots = [];
  }
}

// ---- events (optional list) ----
$events = [];
$evErr = null;

if ($module !== '' && $docType !== '' && $docId !== '') {
  $evFilter = [
    'target.module'   => $module,
    'target.doc_type' => $docType,
    'target.doc_id'   => $docId,
  ];

  if ($cdef) $evFilter['context.CDEF01_id'] = $cdef;

  if ($period) {
    $evFilter['$or'] = [
      ['context.period_id' => $period],
      ['context.period_id' => 'GLOBAL'],
    ];
  }

  // facility stabil
  apply_facility_filter($evFilter, $facility);

  try {
    $curE = MongoManager::collection('EVENT01E')->find(
      $evFilter,
      [
        'sort' => ['created_at' => -1],
        'limit' => $limitEvents,
        'projection' => [
          '_id' => 1,
          'event_code' => 1,
          'created_at' => 1,
          'context.username' => 1,
          'data.summary' => 1,
          'refs' => 1,
          'target' => 1,
        ]
      ]
    );
    $events = array_map('bson_to_array', iterator_to_array($curE));
  } catch(Throwable $e) {
    $evErr = $e->getMessage();
    $events = [];
  }
}

function pick_target_meta(array $snapshots): array {
  foreach ($snapshots as $s) {
    $t = $s['target'] ?? [];
    if (!is_array($t)) continue;
    $docNo = (string)($t['doc_no'] ?? '');
    $title = (string)($t['doc_title'] ?? '');
    $status= (string)($t['status'] ?? '');
    if ($docNo !== '' || $title !== '' || $status !== '') {
      return ['doc_no'=>$docNo, 'doc_title'=>$title, 'status'=>$status];
    }
  }
  return ['doc_no'=>'', 'doc_title'=>'', 'status'=>''];
}

$meta = pick_target_meta($snapshots);

?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Audit View</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <style>
    :root{
      --bg:#0f1220;
      --panel:#171a2c;
      --panel2:#1d2140;
      --text:#e8ebf6;
      --muted:rgba(232,235,246,.65);
      --border:rgba(255,255,255,.12);
      --primary:#5865f2;
      --chip:#2a2f55;
    }
    body{ margin:0; background:var(--bg); color:var(--text); font-family: Arial, sans-serif; }
    .wrap{ max-width:1200px; margin:0 auto; padding:16px; }
    h1{ margin:0 0 10px; font-size:28px; }
    .sub{ color:var(--muted); font-size:13px; margin-bottom:12px; }
    .bar{
      display:grid;
      grid-template-columns: 1fr 1fr 1fr auto auto;
      gap:10px;
      align-items:center;
      margin: 10px 0 14px;
    }
    @media (max-width: 980px){ .bar{ grid-template-columns:1fr 1fr; } }
    .in{
      width:100%; height:42px; box-sizing:border-box;
      background:var(--panel);
      color:var(--text);
      border:1px solid var(--border);
      border-radius:12px;
      padding:0 12px;
      outline:none;
    }
    .btn{
      height:42px;
      padding:0 14px;
      border-radius:12px;
      border:1px solid var(--border);
      background:transparent;
      color:var(--text);
      cursor:pointer;
      text-decoration:none;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      white-space:nowrap;
      gap:8px;
    }
    .btn-primary{ background:var(--primary); border-color:transparent; color:#fff; }
    .card{
      background:var(--panel);
      border:1px solid var(--border);
      border-radius:16px;
      padding:14px;
      margin-top:12px;
    }
    .chips{ display:flex; gap:8px; flex-wrap:wrap; margin-top:8px; }
    .chip{
      background:var(--chip);
      border:1px solid var(--border);
      padding:6px 10px;
      border-radius:999px;
      font-size:12px;
      color:rgba(232,235,246,.95);
    }
    .code{
      font-family: ui-monospace, Menlo, Consolas, monospace;
      background:rgba(0,0,0,.25);
      border:1px solid var(--border);
      padding:2px 8px;
      border-radius:999px;
      font-size:12px;
      color:#fff;
    }
    .muted{ color:var(--muted); font-size:13px; }
    .chain{
      display:flex;
      flex-wrap:wrap;
      gap:10px;
      align-items:center;
      line-height:1.8;
    }
    .chain a{ color:#fff; text-decoration:none; }
    .chain a:hover{ text-decoration:underline; }
    .node{
      display:inline-flex;
      align-items:center;
      gap:8px;
      padding:6px 10px;
      border-radius:999px;
      border:1px solid var(--border);
      background:rgba(255,255,255,.04);
      font-size:12px;
    }
    .arrow{ color:rgba(232,235,246,.55); font-size:12px; display:inline-flex; align-items:center; gap:6px; }
    table{ border-collapse:collapse; width:100%; }
    th,td{ border:1px solid var(--border); padding:8px; vertical-align:top; }
    th{ background:rgba(255,255,255,.06); text-align:left; }
    .small{ font-size:12px; color:rgba(232,235,246,.75); }
  </style>
</head>
<body>
<div class="wrap">

  <h1>Audit View</h1>
  <div class="sub">
    Firma: <b><?php echo esc($ctx['CDEF01_id'] ?? ''); ?></b>
    &nbsp;|&nbsp; Dönem: <b><?php echo esc($ctx['period_id'] ?? ''); ?></b>
    &nbsp;|&nbsp; Kullanıcı: <b><?php echo esc($ctx['username'] ?? ''); ?></b>
  </div>

  <form method="GET" class="bar">
    <input class="in" name="module"  value="<?php echo esc($module); ?>" placeholder="module (örn: gen)">
    <input class="in" name="doc_type"value="<?php echo esc($docType); ?>" placeholder="doc_type (örn: GENDOC01T)">
    <input class="in" name="doc_id"  value="<?php echo esc($docId); ?>" placeholder="doc_id (örn: DOC-001)">
    <button class="btn btn-primary" type="submit">Getir</button>
    <a class="btn" href="/php-mongo-erp/public/audit_view.php">Sıfırla</a>
  </form>

  <?php if ($module === '' || $docType === '' || $docId === ''): ?>
    <div class="card">
      <div class="muted">module / doc_type / doc_id girip “Getir” de.</div>
    </div>
  <?php else: ?>

    <div class="card">
      <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
        <div><b>Target</b>: <span class="code"><?php echo esc($module); ?></span> / <span class="code"><?php echo esc($docType); ?></span> / <span class="code"><?php echo esc($docId); ?></span></div>
        <span style="flex:1"></span>
        <a class="btn" target="_blank" href="/php-mongo-erp/public/timeline.php?module=<?php echo urlencode($module); ?>&doc_type=<?php echo urlencode($docType); ?>&doc_id=<?php echo urlencode($docId); ?>">Timeline</a>
      </div>

      <div class="chips">
        <?php if (($meta['doc_no'] ?? '') !== ''): ?>
          <span class="chip">doc_no: <b><?php echo esc($meta['doc_no']); ?></b></span>
        <?php endif; ?>
        <?php if (($meta['doc_title'] ?? '') !== ''): ?>
          <span class="chip">title: <b><?php echo esc($meta['doc_title']); ?></b></span>
        <?php endif; ?>
        <?php if (($meta['status'] ?? '') !== ''): ?>
          <span class="chip">status: <b><?php echo esc($meta['status']); ?></b></span>
        <?php endif; ?>
        <span class="chip">snapshots: <b><?php echo (int)count($snapshots); ?></b></span>
      </div>
    </div>

    <div class="card">
      <h3 style="margin:0 0 10px;">GENDOC Zinciri (V1 → V2 → V3)</h3>

      <?php if ($snapErr): ?>
        <div class="small">Snapshot query error: <?php echo esc($snapErr); ?></div>
      <?php elseif (empty($snapshots)): ?>
        <div class="small">Snapshot bulunamadı.</div>
      <?php else: ?>

        <div class="chain">
          <?php
            $prevSnapId = null;
            $prevVer = null;

            foreach ($snapshots as $i => $s):
              $sid = (string)($s['_id'] ?? '');
              $ver2 = (int)($s['version'] ?? ($i+1));
              $created = fmt_tr($s['created_at'] ?? '');
              $who = (string)($s['context']['username'] ?? '-');

              $snapHref = '/php-mongo-erp/public/snapshot_view.php?snapshot_id=' . rawurlencode($sid);

              if ($prevSnapId && $sid) {
                $diffHref = '/php-mongo-erp/public/snapshot_diff_view.php?snapshot_id=' . rawurlencode($sid);
                echo '<span class="arrow">→ <a class="small" target="_blank" href="'.esc($diffHref).'">Diff (v'.(int)$prevVer.'→v'.(int)$ver2.')</a></span>';
              }
          ?>
              <span class="node">
                <a target="_blank" href="<?php echo esc($snapHref); ?>">
                  <b>V<?php echo (int)$ver2; ?></b>
                </a>
                <span class="small"><?php echo esc($created); ?></span>
                <span class="small">— <?php echo esc($who); ?></span>
              </span>
          <?php
              $prevSnapId = $sid;
              $prevVer = $ver2;
            endforeach;
          ?>
        </div>

        <div class="small" style="margin-top:10px;">
          Not: Okların üstündeki “Diff” linki, sonraki snapshot’ın diff ekranını açar. (v2 linki v1→v2 diff’idir)
        </div>

      <?php endif; ?>
    </div>

    <div class="card">
      <h3 style="margin:0 0 10px;">Son Event’ler</h3>

      <?php if ($evErr): ?>
        <div class="small">Event query error: <?php echo esc($evErr); ?></div>
      <?php elseif (empty($events)): ?>
        <div class="small">Event bulunamadı.</div>
      <?php else: ?>
        <table>
          <tr>
            <th style="width:220px;">Zaman</th>
            <th style="width:220px;">Kullanıcı</th>
            <th style="width:260px;">event_code</th>
            <th>Özet</th>
            <th style="width:240px;">Link</th>
          </tr>
          <?php foreach ($events as $ev):
            $t = fmt_tr($ev['created_at'] ?? '');
            $u = (string)($ev['context']['username'] ?? '-');
            $code = (string)($ev['event_code'] ?? '');
            $sum = $ev['data']['summary'] ?? null;
            $sumTxt = '';
            if (is_array($sum)) {
              $sumTxt = json_encode($sum, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            } elseif ($sum !== null) {
              $sumTxt = (string)$sum;
            }

            $refs = (array)($ev['refs'] ?? []);
            $logId = (string)($refs['log_id'] ?? '');
            $snapId = (string)($refs['snapshot_id'] ?? '');
          ?>
            <tr>
              <td class="small"><?php echo esc($t); ?></td>
              <td class="small"><?php echo esc($u); ?></td>
              <td><span class="code"><?php echo esc($code); ?></span></td>
              <td class="small"><?php echo esc($sumTxt ?: '-'); ?></td>
              <td class="small">
                <?php if ($logId): ?>
                  <a target="_blank" href="/php-mongo-erp/public/log_view.php?log_id=<?php echo esc(urlencode($logId)); ?>">LOG</a>
                <?php else: ?>
                  LOG -
                <?php endif; ?>
                &nbsp;|&nbsp;
                <?php if ($snapId): ?>
                  <a target="_blank" href="/php-mongo-erp/public/snapshot_view.php?snapshot_id=<?php echo esc(urlencode($snapId)); ?>">SNAP</a>
                <?php else: ?>
                  SNAP -
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </table>
      <?php endif; ?>
    </div>

  <?php endif; ?>

</div>
</body>
</html>
