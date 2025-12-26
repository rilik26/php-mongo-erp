<?php
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
    if ($v instanceof MongoDB\Model\BSONDocument || $v instanceof MongoDB\Model\BSONArray) $v = $v->getArrayCopy();
    if (is_array($v)) { $o=[]; foreach($v as $k=>$vv) $o[$k]=bson_to_array($vv); return $o; }
    if ($v instanceof MongoDB\BSON\UTCDateTime) return $v->toDateTime()->format('c');
    if ($v instanceof MongoDB\BSON\ObjectId) return (string)$v;
    return $v;
}

SessionManager::start();

$ctx = [];
try {
    if (isset($_SESSION['context']) && is_array($_SESSION['context'])) {
        Context::bootFromSession();
        $ctx = Context::get();
    }
} catch (Throwable $e) {
    $ctx = $_SESSION['context'] ?? [];
}

$cdef = $ctx['CDEF01_id'] ?? null;
$period = $ctx['period_id'] ?? null;
if (!$cdef) j(['ok'=>false,'error'=>'no_context'], 401);

$limit = (int)($_GET['limit'] ?? 200);
if ($limit < 10) $limit = 10;
if ($limit > 2000) $limit = 2000;

$module  = trim($_GET['module'] ?? '');
$docType = trim($_GET['doc_type'] ?? '');
$docId   = trim($_GET['doc_id'] ?? '');

$filter = [
    'context.CDEF01_id' => $cdef,
];

// period: current + GLOBAL
if ($period) {
    $filter['$or'] = [
        ['context.period_id' => $period],
        ['context.period_id' => 'GLOBAL'],
    ];
}

if ($module !== '')  $filter['target.module'] = $module;
if ($docType !== '') $filter['target.doc_type'] = $docType;
if ($docId !== '')   $filter['target.doc_id'] = $docId;

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
            'context.role' => 1,
            'target' => 1,
            'refs' => 1,
            'data' => 1,
        ]
    ]
);

$events = [];
foreach ($cur as $d) $events[] = bson_to_array($d);

j(['ok'=>true,'events'=>$events]);
