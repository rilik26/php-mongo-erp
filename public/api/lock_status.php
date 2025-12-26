<?php
/**
 * public/api/lock_status.php
 *
 * GET:
 *  A) ?target_key=...
 *  B) ?module=...&doc_type=...&doc_id=...   (tenant auto session context)
 *
 * Response:
 *  {
 *    ok: true,
 *    target_key: "...",
 *    now: "ISO",
 *    lock: { ... } | null,
 *    active: true|false
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

// context (tenant auto)
$ctx = [];
try {
  if (isset($_SESSION['context']) && is_array($_SESSION['context'])) {
    Context::bootFromSession();
    $ctx = Context::get();
  }
} catch (Throwable $e) {
  $ctx = $_SESSION['context'] ?? [];
}

$targetKey = trim($_GET['target_key'] ?? '');
$module    = trim($_GET['module'] ?? '');
$docType   = trim($_GET['doc_type'] ?? '');
$docId     = trim($_GET['doc_id'] ?? '');

if ($targetKey === '') {
  if ($module === '' || $docType === '' || $docId === '') {
    j(['ok'=>false,'error'=>'target_key_or(module,doc_type,doc_id)_required'], 400);
  }

  $cdef     = $ctx['CDEF01_id'] ?? 'null';
  $period   = $ctx['period_id'] ?? 'null';
  $facility = $ctx['facility_id'] ?? 'null';

  $targetKey = $module . '|' . $docType . '|' . $docId . '|' . $cdef . '|' . $period . '|' . $facility;
}

$nowMs = (int) floor(microtime(true) * 1000);
$nowUtc = new MongoDB\BSON\UTCDateTime($nowMs);

$lock = MongoManager::collection('LOCK01E')->findOne([
  'target_key' => $targetKey
]);

$lockArr = $lock ? bson_to_array($lock) : null;

// active?
$active = false;
if ($lock) {
  $expiresAt = $lock['expires_at'] ?? null;
  if ($expiresAt instanceof MongoDB\BSON\UTCDateTime) {
    $active = ($expiresAt->toDateTime()->getTimestamp() * 1000) > $nowMs;
  } else {
    $tmp = strtotime((string)$expiresAt);
    if ($tmp !== false) $active = ($tmp * 1000) > $nowMs;
  }
}

j([
  'ok' => true,
  'target_key' => $targetKey,
  'now' => $nowUtc->toDateTime()->format('c'),
  'lock' => $lockArr,
  'active' => $active
]);
