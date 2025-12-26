<?php
/**
 * public/api/event_get.php
 *
 * Tek event gösterir.
 * GET:
 * - ?event_id=...
 * - &format=json|html (default json)
 */

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/auth/SessionManager.php';
require_once __DIR__ . '/../../core/base/Context.php';

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

SessionManager::start();

function bson_to_array($v) {
  if ($v instanceof MongoDB\Model\BSONDocument || $v instanceof MongoDB\Model\BSONArray) {
    $v = $v->getArrayCopy();
  }
  if (is_array($v)) {
    $out = [];
    foreach ($v as $k => $vv) $out[$k] = bson_to_array($vv);
    return $out;
  }
  if ($v instanceof UTCDateTime) return $v->toDateTime()->format('c');
  if ($v instanceof ObjectId) return (string)$v;
  return $v;
}

function j($a, int $code = 200): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

$eventId = trim($_GET['event_id'] ?? '');
$format  = trim($_GET['format'] ?? 'json');

if ($eventId === '') j(['ok'=>false,'error'=>'event_id_required'], 400);

$ctx = [];
if (isset($_SESSION['context']) && is_array($_SESSION['context'])) {
  try { Context::bootFromSession(); $ctx = Context::get(); } catch (Throwable $e) { $ctx = $_SESSION['context'] ?? []; }
}

$cdef = $ctx['CDEF01_id'] ?? null;

try {
  $oid = new ObjectId($eventId);
} catch (Throwable $e) {
  j(['ok'=>false,'error'=>'invalid_event_id'], 400);
}

$filter = ['_id' => $oid];
if ($cdef) $filter['context.CDEF01_id'] = $cdef; // tenant safety

$ev = MongoManager::collection('EVENT01E')->findOne($filter);
if (!$ev) j(['ok'=>false,'error'=>'event_not_found'], 404);

$evArr = bson_to_array($ev);

if ($format === 'html') {
  header('Content-Type: text/html; charset=utf-8');
  $pretty = htmlspecialchars(json_encode($evArr, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
  echo "<pre style='white-space:pre-wrap; font-family: ui-monospace, Menlo, Consolas, monospace;'>".$pretty."</pre>";
  exit;
}

j(['ok'=>true,'event'=>$evArr]);
