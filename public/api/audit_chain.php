<?php
/**
 * public/api/audit_chain.php
 *
 * Audit Chain API (V1 - FINAL)
 * - Bir "target" için snapshot zincirini ve eventleri listeler.
 *
 * GET (2 kullanım):
 *  A) target_key ile:
 *     ?target_key=module|doc_type|doc_id|CDEF01_id|period_id|facility_id
 *
 *  B) target alanları ile (target_key otomatik oluşur):
 *     ?module=i18n&doc_type=LANG01T&doc_id=DICT
 *     (&tenant=auto -> session contextten CDEF01_id/period_id/facility_id alır)
 *
 * Response:
 * - snapshots: [{_id, version, created_at, context, hash, prev_hash, prev_snapshot_id, summary}]
 * - events:    [{_id, created_at, event_code, context, refs, data, target}]
 *
 * NOT:
 * - Eventleri "target" ile değil, esas olarak SNAPSHOT referansı ile bulur:
 *   refs.snapshot_id IN (snapshot_ids) OR refs.prev_snapshot_id IN (snapshot_ids)
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

// --- input ---
$targetKey = trim($_GET['target_key'] ?? '');
$module    = trim($_GET['module'] ?? '');
$docType   = trim($_GET['doc_type'] ?? '');
$docId     = trim($_GET['doc_id'] ?? '');

$limitSnap = (int)($_GET['limit_snap'] ?? 200);
if ($limitSnap < 10) $limitSnap = 10;
if ($limitSnap > 2000) $limitSnap = 2000;

$limitEvt = (int)($_GET['limit_evt'] ?? 200);
if ($limitEvt < 10) $limitEvt = 10;
if ($limitEvt > 2000) $limitEvt = 2000;

if ($targetKey === '') {
    if ($module === '' || $docType === '' || $docId === '') {
        j(['ok'=>false,'error'=>'target_key_or(module,doc_type,doc_id)_required'], 400);
    }

    // tenant (auto): session contextten al
    $cdef     = $ctx['CDEF01_id'] ?? 'null';
    $period   = $ctx['period_id'] ?? 'null';
    $facility = $ctx['facility_id'] ?? 'null';

    $targetKey = $module . '|' . $docType . '|' . $docId . '|' . $cdef . '|' . $period . '|' . $facility;
}

// target_key parçaları
$parts = explode('|', $targetKey);
$mod = $parts[0] ?? null;
$dt  = $parts[1] ?? null;
$di  = $parts[2] ?? null;
$tkCdef   = $parts[3] ?? null;
$tkPeriod = $parts[4] ?? null;

// --- 1) snapshots ---
$snapshotsCur = MongoManager::collection('SNAP01E')->find(
    ['target_key' => $targetKey],
    [
        'sort'  => ['version' => 1],
        'limit' => $limitSnap,
        'projection' => [
            'data' => 0 // zincirde data taşımıyoruz (ağır)
        ]
    ]
);

$snapshots = [];
$snapshotIds = []; // string list (EventWriter refs.snapshot_id string basıyor)

foreach ($snapshotsCur as $s) {
    // snapshot_id listesi (string)
    if (isset($s['_id'])) {
        $snapshotIds[] = (string)$s['_id'];
    }
    $snapshots[] = bson_to_array($s);
}

// --- 2) events ---
// Öncelik: snapshot referansı ile join
$events = [];

if (!empty($snapshotIds)) {
    $evtFilter = [
        '$or' => [
            ['refs.snapshot_id'      => ['$in' => $snapshotIds]],
            ['refs.prev_snapshot_id' => ['$in' => $snapshotIds]],
        ]
    ];

    // ek güvenlik: aynı tenant
    if ($tkCdef) {
        $evtFilter['context.CDEF01_id'] = $tkCdef;
    }

    // period için: hem GLOBAL hem o period (audit içinde görmek isteyebilirsin)
    if ($tkPeriod) {
        $evtFilter['$and'][] = [
            '$or' => [
                ['context.period_id' => $tkPeriod],
                ['context.period_id' => 'GLOBAL']
            ]
        ];
    }

    // target match (opsiyonel ama iyi): i18n/LANG01T/DICT gibi
    if ($mod) $evtFilter['$and'][] = ['target.module' => $mod];
    if ($dt)  $evtFilter['$and'][] = ['target.doc_type' => $dt];
    if ($di)  $evtFilter['$and'][] = ['target.doc_id' => $di];

    // $and boşsa kaldır
    if (isset($evtFilter['$and']) && empty($evtFilter['$and'])) unset($evtFilter['$and']);

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
                'refs' => 1,
                'data' => 1,
                'target' => 1,
            ]
        ]
    );

    foreach ($eventsCur as $e) {
        $events[] = bson_to_array($e);
    }
} else {
    // fallback: snapshot yoksa, eski yöntemle target+tenant üzerinden getir
    $evtFilter = [];
    if ($mod) $evtFilter['target.module'] = $mod;
    if ($dt)  $evtFilter['target.doc_type'] = $dt;
    if ($di)  $evtFilter['target.doc_id'] = $di;

    if ($tkCdef) $evtFilter['context.CDEF01_id'] = $tkCdef;

    if ($tkPeriod) {
        $evtFilter['$or'] = [
            ['context.period_id' => $tkPeriod],
            ['context.period_id' => 'GLOBAL']
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
                'refs' => 1,
                'data' => 1,
                'target' => 1,
            ]
        ]
    );

    foreach ($eventsCur as $e) {
        $events[] = bson_to_array($e);
    }
}

j([
    'ok' => true,
    'target_key' => $targetKey,
    'snapshots' => $snapshots,
    'events' => $events
]);
