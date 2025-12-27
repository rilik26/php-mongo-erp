<?php
/**
 * public/api/lock_get.php
 *
 * GET:
 *  ?module=...&doc_type=...&doc_id=...
 *
 * Response:
 *  {
 *    ok: true,
 *    target_key: "...",
 *    locked: true|false,
 *    lock: {...} | null
 *  }
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

// Context (varsa)
$ctx = [];
try {
  if (isset($_SESSION['context']) && is_array($_SESSION['context'])) {
    Context::bootFromSession();
    $ctx = Context::get();
  }
} catch (Throwable $e) {
  $ctx = $_SESSION['context'] ?? [];
}

$module  = trim($_GET['module'] ?? '');
$docType = trim($_GET['doc_type'] ?? '');
$docId   = trim($_GET['doc_id'] ?? '');

if ($module === '' || $docType === '' || $docId === '') {
  j(['ok'=>false,'error'=>'module,doc_type,doc_id_required'], 400);
}

// target_key tenant bazlı
$cdef     = $ctx['CDEF01_id'] ?? 'null';
$period   = $ctx['period_id'] ?? 'null';
$facility = $ctx['facility_id'] ?? 'null';

$targetKey = $module . '|' . $docType . '|' . $docId . '|' . $cdef . '|' . $period . '|' . $facility;

// aktif lock: expires_at > now
$nowMs = (int) floor(microtime(true) * 1000);

$lock = MongoManager::collection('LOCK01E')->findOne([
  'target_key' => $targetKey,
  'expires_at' => ['$gt' => new MongoDB\BSON\UTCDateTime($nowMs)]
]);

if ($lock) {
  $lockArr = bson_to_array($lock);
  j([
    'ok' => true,
    'target_key' => $targetKey,
    'locked' => true,
    'lock' => $lockArr
  ]);
}

j([
  'ok' => true,
  'target_key' => $targetKey,
  'locked' => false,
  'lock' => null
]);
