<?php
/**
 * public/api/lock_list.php
 *
 * Aktif lock listesini döner.
 * - Default: sadece current tenant (CDEF01_id + period_id + facility_id)
 * - Optional filtre:
 *   ?module=&doc_type=&doc_id=
 * - Optional:
 *   ?include_global=1  => period_id GLOBAL olanları da dahil eder
 *   ?limit=200
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

// login guard
if (!isset($_SESSION['context']) || !is_array($_SESSION['context'])) {
  j(['ok'=>false,'error'=>'not_logged_in'], 401);
}

try {
  Context::bootFromSession();
} catch (Throwable $e) {
  j(['ok'=>false,'error'=>'context_boot_failed'], 401);
}

$ctx = Context::get();

$module  = trim($_GET['module'] ?? '');
$docType = trim($_GET['doc_type'] ?? '');
$docId   = trim($_GET['doc_id'] ?? '');

$includeGlobal = (int)($_GET['include_global'] ?? 0) === 1;

$limit = (int)($_GET['limit'] ?? 200);
if ($limit < 10) $limit = 10;
if ($limit > 2000) $limit = 2000;

// tenant pieces
$cdef     = $ctx['CDEF01_id'] ?? 'null';
$period   = $ctx['period_id'] ?? 'null';
$facility = $ctx['facility_id'] ?? 'null';

// aktif lock: expires_at > now
$now = new MongoDB\BSON\UTCDateTime();

$filter = [
  'context.CDEF01_id' => $cdef,
  'expires_at' => ['$gt' => $now],
];

// facility filtresi: sadece facility doluysa uygula
if ($facility !== null && $facility !== '' && $facility !== 'null') {
  $filter['context.facility_id'] = $facility;
} else {
  // facility null veya field yoksa ikisini de kapsa
  $filter['$or'][] = ['context.facility_id' => null];
  $filter['$or'][] = ['context.facility_id' => ['$exists' => false]];
}

// period filter (GLOBAL opsiyon)
if ($includeGlobal) {
  $orPeriod = [
    ['context.period_id' => $period],
    ['context.period_id' => 'GLOBAL'],
  ];

  if (!isset($filter['$or'])) $filter['$or'] = [];
  $filter['$or'] = array_merge($filter['$or'], $orPeriod);
} else {
  $filter['context.period_id'] = $period;
}

// optional target filter
if ($module !== '')  $filter['target.module']   = $module;
if ($docType !== '') $filter['target.doc_type'] = $docType;
if ($docId !== '')   $filter['target.doc_id']   = $docId;

$cur = MongoManager::collection('LOCK01E')->find(
  $filter,
  [
    'sort' => ['locked_at' => -1],
    'limit' => $limit,
    'projection' => [
      'target_key' => 1,
      'target' => 1,
      'status' => 1,
      'locked_at' => 1,
      'expires_at' => 1,
      'context.username' => 1,
      'context.UDEF01_id' => 1,
      'context.session_id' => 1,
      'context.role' => 1,
      'context.ip' => 1,
      'context.user_agent' => 1,
    ]
  ]
);

$locks = [];
foreach ($cur as $doc) {
  $locks[] = bson_to_array($doc);
}

j([
  'ok' => true,
  'count' => count($locks),
  'tenant' => [
    'CDEF01_id' => $cdef,
    'period_id' => $period,
    'facility_id' => $facility,
    'include_global' => $includeGlobal,
  ],
  'locks' => $locks,
]);
