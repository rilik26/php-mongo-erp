<?php
/**
 * public/snapshot_view.php (FINAL)
 *
 * - Snapshot JSON değil, HTML kart UI
 * - Prev / Next snapshot navigation
 * - Diff linki (prev varsa)
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

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function bson_to_array($v) {
  if ($v instanceof MongoDB\Model\BSONDocument || $v instanceof MongoDB\Model\BSONArray) {
    $v = $v->getArrayCopy();
  }
  if (is_array($v)) {
    $out = [];
    foreach ($v as $k => $vv) $out[$k] = bson_to_array($vv);
    return $out;
  }
  if ($v instanceof MongoDB\BSON\UTCDateTime) return $v->toDateTime()->format('c');
  if ($v instanceof MongoDB\BSON\ObjectId) return (string)$v;
  return $v;
}

function fmt_tr_dt($iso): string {
  if (!$iso) return '-';
  $ts = strtotime((string)$iso);
  if ($ts === false) return (string)$iso;
  date_default_timezone_set('Europe/Istanbul');
  return date('d.m.Y H:i:s', $ts);
}

$snapshotId = trim($_GET['snapshot_id'] ?? '');
if ($snapshotId === '') {
  http_response_code(400);
  echo "snapshot_id_required";
  exit;
}

ActionLogger::info('SNAPSHOT.VIEW', [
  'source' => 'public/snapshot_view.php',
  'snapshot_id' => $snapshotId
], $ctx);

// --- load snapshot ---
$snap = MongoManager::collection('SNAP01E')->findOne(
  ['_id' => new MongoDB\BSON\ObjectId($snapshotId)]
);

if (!$snap) {
  http_response_code(404);
  echo "snapshot_not_found";
  exit;
}

$snap = bson_to_array($snap);

$targetKey = (string)($snap['target_key'] ?? '');
$ver = $snap['version'] ?? null;
$prevId = $snap['prev_snapshot_id'] ?? null;

// --- prev snapshot doc (if exists) ---
$prev = null;
if ($prevId) {
  try {
    $prevDoc = MongoManager::collection('SNAP01E')->findOne(['_id' => new MongoDB\BSON\ObjectId((string)$prevId)]);
    if ($prevDoc) $prev = bson_to_array($prevDoc);
  } catch (Throwable $e) {
    $prev = null;
  }
}

// --- next snapshot doc ---
$next = null;
$nextId = null;

// 1) version+1
if ($targetKey !== '' && $ver !== null && is_numeric($ver)) {
  $nv = (int)$ver + 1;
  $nextDoc = MongoManager::collection('SNAP01E')->findOne(
    ['target_key' => $targetKey, 'version' => $nv],
    ['sort' => ['version' => 1]]
  );
  if ($nextDoc) $next = bson_to_array($nextDoc);
}
// 2) fallback by prev_snapshot_id
if (!$next) {
  $nextDoc = MongoManager::collection('SNAP01E')->findOne(
    ['prev_snapshot_id' => (string)($snap['_id'] ?? $snapshotId)],
    ['sort' => ['version' => 1]]
  );
  if ($nextDoc) $next = bson_to_array($nextDoc);
}

if ($next && isset($next['_id'])) $nextId = (string)$next['_id'];

// urls
$currJsonUrl = '/php-mongo-erp/public/api/snapshot_get.php?snapshot_id=' . rawurlencode((string)$snapshotId);
$diffUrl = ($prevId) ? ('/php-mongo-erp/public/snapshot_diff_view.php?snapshot_id=' . rawurlencode((string)$snapshotId)) : null;

$prevUrl = ($prevId) ? ('/php-mongo-erp/public/snapshot_view.php?snapshot_id=' . rawurlencode((string)$prevId)) : null;
$nextUrl = ($nextId) ? ('/php-mongo-erp/public/snapshot_view.php?snapshot_id=' . rawurlencode((string)$nextId)) : null;

$target = (array)($snap['target'] ?? []);
$ctxSnap = (array)($snap['context'] ?? []);

$createdTr = fmt_tr_dt($snap['created_at'] ?? '');
$user = (string)($ctxSnap['username'] ?? '-');

function pretty_json($arr): string {
  return json_encode($arr, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
}

// data ağır olabilir, ama HTML view için göstereceğiz
$data = $snap['data'] ?? [];
$summary = $snap['summary'] ?? null;

?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Snapshot View</title>
  <style>
    body{ font-family: Arial, sans-serif; background:#0f1220; color:#e7eaf3; margin:0; }
    .wrap{ max-width:1200px; margin:0 auto; padding:16px; }
    .top{ display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap; }
    .h1{ font-size:22px; font-weight:700; margin:0; }
    .small{ font-size:12px; color:#a7adc3; }
    .btn{
      padding:8px 12px; border:1px solid rgba(255,255,255,.14);
      background:transparent; color:#e7eaf3; border-radius:10px;
      text-decoration:none; display:inline-flex; align-items:center; gap:8px;
      white-space:nowrap;
    }
    .btn:hover{ filter:brightness(1.08); }
    .btn-primary{ background:#5865f2; border-color:transparent; color:#fff; }
    .btn-disabled{ opacity:.45; pointer-events:none; }
    .bar{ display:flex; gap:8px; flex-wrap:wrap; margin-top:8px; }
    .card{
      background:#272b40; border:1px solid rgba(255,255,255,.10);
      border-radius:14px; padding:12px; margin-top:12px;
    }
    .ttl{ font-weight:700; margin-bottom:8px; }
    .grid2{ display:grid; grid-template-columns: 1fr 1fr; gap:10px; }
    @media (max-width: 1000px){ .grid2{ grid-template-columns:1fr; } }
    .box{
      background:rgba(0,0,0,.14);
      border:1px solid rgba(255,255,255,.10);
      border-radius:12px;
      padding:10px;
    }
    .kv{ font-size:12px; color:#a7adc3; line-height:1.65; }
    .kv b{ color:#e7eaf3; font-weight:600; }
    .code{ font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono","Courier New", monospace; }
    .pre{
      white-space:pre-wrap; word-break:break-word; margin:0;
      background:rgba(0,0,0,.22); border:1px solid rgba(255,255,255,.12);
      padding:12px; border-radius:12px;
      color:rgba(231,234,243,.95);
    }
  </style>
</head>
<body>

<div class="wrap">
  <?php require_once __DIR__ . '/../app/views/layout/header.php'; ?>

  <div class="top">
    <div>
      <div class="h1">Snapshot View</div>
      <div class="small">
        version: <b>v<?php echo h((string)($snap['version'] ?? '-')); ?></b>
        &nbsp;|&nbsp; time: <b><?php echo h($createdTr); ?></b>
        &nbsp;|&nbsp; user: <b><?php echo h($user); ?></b>
      </div>

      <div class="bar">
        <?php if ($prevUrl): ?>
          <a class="btn" href="<?php echo h($prevUrl); ?>">← Prev Snapshot</a>
        <?php else: ?>
          <span class="btn btn-disabled">← Prev Snapshot</span>
        <?php endif; ?>

        <?php if ($nextUrl): ?>
          <a class="btn" href="<?php echo h($nextUrl); ?>">Next Snapshot →</a>
        <?php else: ?>
          <span class="btn btn-disabled">Next Snapshot →</span>
        <?php endif; ?>

        <?php if ($diffUrl): ?>
          <a class="btn btn-primary" href="<?php echo h($diffUrl); ?>">Diff</a>
        <?php else: ?>
          <span class="btn btn-disabled">Diff</span>
        <?php endif; ?>

        <a class="btn" href="<?php echo h($currJsonUrl); ?>" target="_blank">JSON</a>
      </div>
    </div>

    <div class="small">
      target_key:<br>
      <span class="code"><?php echo h($targetKey); ?></span>
    </div>
  </div>

  <div class="card">
    <div class="ttl">Target</div>
    <div class="grid2">
      <div class="box">
        <div class="kv">
          <div><b>module</b>: <span class="code"><?php echo h($target['module'] ?? '-'); ?></span></div>
          <div><b>doc_type</b>: <span class="code"><?php echo h($target['doc_type'] ?? '-'); ?></span></div>
          <div><b>doc_id</b>: <span class="code"><?php echo h($target['doc_id'] ?? '-'); ?></span></div>
          <div><b>doc_no</b>: <span class="code"><?php echo h($target['doc_no'] ?? '-'); ?></span></div>
        </div>
      </div>
      <div class="box">
        <div class="kv">
          <div><b>hash</b>: <span class="code"><?php echo h($snap['hash'] ?? '-'); ?></span></div>
          <div><b>prev_hash</b>: <span class="code"><?php echo h($snap['prev_hash'] ?? '-'); ?></span></div>
          <div><b>prev_snapshot_id</b>: <span class="code"><?php echo h($prevId ?: '-'); ?></span></div>
        </div>
      </div>
    </div>
  </div>

  <?php if (is_array($summary) && !empty($summary)): ?>
    <div class="card">
      <div class="ttl">Summary</div>
      <pre class="pre"><?php echo h(pretty_json($summary)); ?></pre>
    </div>
  <?php endif; ?>

  <div class="card">
    <div class="ttl">Data</div>
    <pre class="pre"><?php echo h(pretty_json($data)); ?></pre>
  </div>

</div>

</body>
</html>
