<?php
/**
 * public/api/snapshot_get.php
 *
 * GET:
 *  - ?snapshot_id=<SNAP01E _id>
 *  - &view=1 -> HTML view
 */

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/auth/SessionManager.php';

SessionManager::start();

function j($a, int $code = 200): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

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

function esc($s): string {
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function fmt_tr($isoOrNull): string {
  if (!$isoOrNull) return '';
  try {
    $dt = new DateTime($isoOrNull, new DateTimeZone('UTC'));
    $dt->setTimezone(new DateTimeZone('Europe/Istanbul'));
    return $dt->format('d.m.Y H:i:s');
  } catch (Throwable $e) {
    return (string)$isoOrNull;
  }
}

$snapshotId = trim($_GET['snapshot_id'] ?? '');
$view = (string)($_GET['view'] ?? '') === '1';

if ($snapshotId === '') {
  if ($view) { http_response_code(400); echo "snapshot_id gerekli"; exit; }
  j(['ok'=>false,'error'=>'snapshot_id_required'], 400);
}

try {
  $doc = MongoManager::collection('SNAP01E')->findOne(['_id' => new MongoDB\BSON\ObjectId($snapshotId)]);
} catch (Throwable $e) {
  if ($view) { http_response_code(400); echo "Geçersiz snapshot_id"; exit; }
  j(['ok'=>false,'error'=>'invalid_snapshot_id'], 400);
}

if (!$doc) {
  if ($view) { http_response_code(404); echo "Snapshot bulunamadı"; exit; }
  j(['ok'=>false,'error'=>'not_found'], 404);
}

$snap = bson_to_array($doc);

if (!$view) {
  j(['ok'=>true,'snapshot'=>$snap]);
}

// ---- HTML VIEW ----
header('Content-Type: text/html; charset=utf-8');

$created = fmt_tr($snap['created_at'] ?? null);
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>SNAPSHOT VIEW</title>
  <style>
    body{font-family:Arial,sans-serif;margin:16px;}
    .card{border:1px solid #eee;border-radius:10px;padding:12px;margin-bottom:12px;}
    .h{display:flex;gap:10px;flex-wrap:wrap;align-items:center;}
    .pill{padding:4px 8px;border-radius:999px;background:#f3f3f3;font-size:12px;}
    pre{background:#0b1020;color:#d7e2ff;padding:10px;border-radius:10px;overflow:auto;}
    .k{color:#666;font-size:12px;}
  </style>
</head>
<body>

<div class="card">
  <div class="h">
    <span class="pill"><strong>SNAP v<?php echo esc($snap['version'] ?? ''); ?></strong></span>
    <span class="pill"><?php echo esc($created); ?></span>
    <span class="pill">target_key: <?php echo esc($snap['target_key'] ?? ''); ?></span>
  </div>
  <div style="margin-top:8px;">
    <div class="k">Hash / Prev</div>
    <div style="font-size:12px;">
      <div><strong><?php echo esc($snap['hash'] ?? ''); ?></strong></div>
      <div class="k"><?php echo esc($snap['prev_hash'] ?? ''); ?></div>
    </div>
  </div>
</div>

<div class="card">
  <h3 style="margin:0 0 8px 0;">Target</h3>
  <pre><?php echo esc(json_encode($snap['target'] ?? null, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)); ?></pre>
</div>

<div class="card">
  <h3 style="margin:0 0 8px 0;">Context</h3>
  <pre><?php echo esc(json_encode($snap['context'] ?? null, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)); ?></pre>
</div>

<div class="card">
  <h3 style="margin:0 0 8px 0;">Summary</h3>
  <pre><?php echo esc(json_encode($snap['summary'] ?? null, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)); ?></pre>
</div>

<div class="card">
  <h3 style="margin:0 0 8px 0;">Data</h3>
  <pre><?php echo esc(json_encode($snap['data'] ?? null, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)); ?></pre>
</div>

</body>
</html>
