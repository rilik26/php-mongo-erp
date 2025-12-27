<?php
/**
 * public/api/log_get.php
 *
 * Log Get API (V1)
 * - UACT01E içinden log döner
 *
 * GET:
 *  ?log_id=xxxxxxxxxxxxxxxxxxxxxxxx  (Mongo ObjectId string)
 *
 * Response:
 *  { ok:true, log:{...} }
 */

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/auth/SessionManager.php';
require_once __DIR__ . '/../../core/base/Context.php';
require_once __DIR__ . '/../../core/base/ContextException.php';

header('Content-Type: application/json; charset=utf-8');

function j($a, int $code = 200): void {
  http_response_code($code);
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

SessionManager::start();

// login guard (API de korunsun)
if (!isset($_SESSION['context']) || !is_array($_SESSION['context'])) {
  j(['ok'=>false,'error'=>'login_required'], 401);
}

try {
  Context::bootFromSession();
} catch (ContextException $e) {
  j(['ok'=>false,'error'=>'login_required'], 401);
}

$logId = trim($_GET['log_id'] ?? '');
if ($logId === '') j(['ok'=>false,'error'=>'log_id_required'], 400);

try {
  $oid = new MongoDB\BSON\ObjectId($logId);
} catch (Throwable $e) {
  j(['ok'=>false,'error'=>'invalid_log_id'], 400);
}

$doc = MongoManager::collection('UACT01E')->findOne(['_id' => $oid]);
if (!$doc) j(['ok'=>false,'error'=>'log_not_found'], 404);

j([
  'ok' => true,
  'log' => bson_to_array($doc),
]);
