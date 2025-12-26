<?php
/**
 * public/api/audit_chain.php
 *
 * Audit Chain API (V1 - FINAL)
 * - target_key veya module/doc_type/doc_id ile:
 *   - SNAP01E zinciri (version asc)
 *   - EVENT01E listesi (created_at desc)
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

$ctx = [];
try {
    if (isset($_SESSION['context']) && is_array($_SESSION['context'])) {
        Context::bootFromSession();
        $ctx = Context::get();
    }
} catch (Throwable $e) {
    $ctx = $_SESSION['context'] ?? [];
}

// input
$targetKey = trim($_GET['target_key'] ?? '');
$module    = trim($_GET['module'] ?? '');
$docType   = trim($_GET['doc_type'] ?? '');
$docId     = trim($_GET['doc_id'] ?? '');

$limitSnap = (int)($_GET['limit_snap'] ?? 300);
if ($limitSnap < 10) $limitSnap = 10;
if ($limitSnap > 2000) $limitSnap = 2000;

$limitEvt = (int)($_GET['limit_evt'] ?? 200);
if ($limitEvt < 10) $limitEvt = 10;
if ($limitEvt > 2000) $limitEvt = 2000;

if ($targetKey === '') {
    if ($module === '' || $docType === '' || $docId === '') {
        j(['ok'=>false,'error'=>'target_key_or(module,doc_type,doc_id)_required'], 400);
    }

    $cdef     = $ctx['CDEF01_id'] ?? 'null';
    $period   = $ctx['period_id'] ?? 'null';
    $facility = $ctx['facility_id'] ?? 'null';

    $targetKey = $module . '|' . $docType . '|' . $docId . '|' . $cdef . '|' . $period . '|' . $facility;
}

// --- snapshots ---
$snapshotsCur = MongoManager::collection('SNAP01E')->find(
    ['target_key' => $targetKey],
    [
        'sort'  => ['version' => 1],
        'limit' => $limitSnap,
        'projection' => [
            'data' => 0
        ]
    ]
);

$snapshots = [];
foreach ($snapshotsCur as $s) $snapshots[] = bson_to_array($s);

// --- events ---
$parts = explode('|', $targetKey);
$mod = $parts[0] ?? null;
$dt  = $parts[1] ?? null;
$di  = $parts[2] ?? null;

$tkCdef   = $parts[3] ?? null;
$tkPeriod = $parts[4] ?? null;

$evtFilter = [];

// target filter (en kritik)
if ($mod) $evtFilter['target.module'] = $mod;
if ($dt)  $evtFilter['target.doc_type'] = $dt;
if ($di)  $evtFilter['target.doc_id'] = $di;

// tenant guard
if ($tkCdef && $tkCdef !== 'null') {
    $evtFilter['context.CDEF01_id'] = $tkCdef;
}

// period: current + GLOBAL (timeline/audit uyumu için)
if ($tkPeriod && $tkPeriod !== 'null') {
    $evtFilter['$or'] = [
        ['context.period_id' => $tkPeriod],
        ['context.period_id' => 'GLOBAL'],
    ];
}

$eventsCur = MongoManager::collection('EVENT01E')->find(
    $evtFilter,
    [
        'sort'  => ['created_at' => -1],
        'limit' => $limitEvt,
        'projection' => [
            'event_code' => 1,
            'created_at' => 1,
            'context.username' => 1,
            'context.UDEF01_id' => 1,
            'context.period_id' => 1,
            'refs' => 1,
            'data.summary' => 1,
            'data.source' => 1,
            'target' => 1,
        ]
    ]
);

$events = [];
foreach ($eventsCur as $e) $events[] = bson_to_array($e);

j([
    'ok' => true,
    'target_key' => $targetKey,
    'snapshots' => $snapshots,
    'events' => $events
]);
