<?php
/**
 * public/api/log_get.php
 *
 * GET:
 *  - ?log_id=<UACT01E _id>
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

$logId = trim($_GET['log_id'] ?? '');
$view  = (string)($_GET['view'] ?? '') === '1';

if ($logId === '') {
  if ($view) {
    http_response_code(400);
    echo "log_id gerekli";
    exit;
  }
  j(['ok' => false, 'error' => 'log_id_required'], 400);
}

try {
  $doc = MongoManager::collection('UACT01E')->findOne(['_id' => new MongoDB\BSON\ObjectId($logId)]);
} catch (Throwable $e) {
  if ($view) {
    http_response_code(400);
    echo "Geçersiz log_id";
    exit;
  }
  j(['ok'=>false,'error'=>'invalid_log_id'], 400);
}

if (!$doc) {
  if ($view) {
    http_response_code(404);
    echo "Log bulunamadı";
    exit;
  }
  j(['ok'=>false,'error'=>'not_found'], 404);
}

$log = bson_to_array($doc);

if (!$view) {
  j(['ok'=>true, 'log'=>$log]);
}

// ---- HTML VIEW ----
header('Content-Type: text/html; charset=utf-8');

$created = fmt_tr($log['created_at'] ?? null);
$ctx = $log['context'] ?? [];
$target = $log['target'] ?? null;
$meta = $log['meta'] ?? [];
$payload = $log['payload'] ?? [];

?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>LOG VIEW</title>
  <style>
    body{font-family:Arial,sans-serif;margin:16px;}
    .card{border:1px solid #eee;border-radius:10px;padding:12px;margin-bottom:12px;}
    .h{display:flex;gap:10px;flex-wrap:wrap;align-items:center;}
    .pill{padding:4px 8px;border-radius:999px;background:#f3f3f3;font-size:12px;}
    pre{background:#0b1020;color:#d7e2ff;padding:10px;border-radius:10px;overflow:auto;}
    .k{color:#666;font-size:12px;}
    table{border-collapse:collapse;width:100%;}
    td{border:1px solid #eee;padding:8px;vertical-align:top;}
  </style>
</head>
<body>

<div class="card">
  <div class="h">
    <span class="pill"><strong><?php echo esc($log['action_code'] ?? ''); ?></strong></span>
    <span class="pill">result: <?php echo esc($log['result'] ?? ''); ?></span>
    <span class="pill"><?php echo esc($created); ?></span>
  </div>
  <div style="margin-top:8px;">
    <div class="k">Kullanıcı</div>
    <div><strong><?php echo esc($ctx['username'] ?? $log['username'] ?? ''); ?></strong> (<?php echo esc($ctx['role'] ?? $log['role'] ?? ''); ?>)</div>
    <div class="k">Firma / Dönem</div>
    <div><?php echo esc($ctx['CDEF01_id'] ?? $log['CDEF01_id'] ?? ''); ?> / <?php echo esc($ctx['period_id'] ?? $log['period_id'] ?? ''); ?></div>
  </div>
</div>

<div class="card">
  <h3 style="margin:0 0 8px 0;">Context</h3>
  <pre><?php echo esc(json_encode($ctx, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)); ?></pre>
</div>

<?php if ($target): ?>
<div class="card">
  <h3 style="margin:0 0 8px 0;">Target</h3>
  <pre><?php echo esc(json_encode($target, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)); ?></pre>
</div>
<?php endif; ?>

<div class="card">
  <h3 style="margin:0 0 8px 0;">Meta</h3>
  <pre><?php echo esc(json_encode($meta, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)); ?></pre>
</div>

<div class="card">
  <h3 style="margin:0 0 8px 0;">Payload</h3>
  <pre><?php echo esc(json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)); ?></pre>
</div>

</body>
</html>
