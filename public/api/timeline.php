<?php
/**
 * public/api/timeline.php
 *
 * Timeline API (V1 - FINAL)
 */

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/auth/SessionManager.php';
require_once __DIR__ . '/../../core/base/Context.php';

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
$ctx = $_SESSION['context'] ?? [];

$targetKey = trim($_GET['target_key'] ?? '');
$module    = trim($_GET['module'] ?? '');
$docType   = trim($_GET['doc_type'] ?? '');
$docId     = trim($_GET['doc_id'] ?? '');

if ($targetKey === '') {
    if ($module === '' || $docType === '' || $docId === '') {
        j(['ok'=>false,'error'=>'target_required'], 400);
    }

    $cdef     = $ctx['CDEF01_id'] ?? 'null';
    $period   = $ctx['period_id'] ?? 'null';
    $facility = $ctx['facility_id'] ?? 'null';

    $targetKey = $module.'|'.$docType.'|'.$docId.'|'.$cdef.'|'.$period.'|'.$facility;
}

// target parçala
[$mod,$dt,$di,$cdef,$period] = array_pad(explode('|', $targetKey), 6, null);

// Eventler
$evtFilter = [
    'target.module'   => $mod,
    'target.doc_type' => $dt,
    'target.doc_id'   => $di,
    'context.CDEF01_id' => $cdef,
    '$or' => [
        ['context.period_id' => $period],
        ['context.period_id' => 'GLOBAL']
    ]
];

$eventsCur = MongoManager::collection('EVENT01E')->find(
    $evtFilter,
    ['sort' => ['created_at' => -1], 'limit' => 300]
);

$timeline = [];

foreach ($eventsCur as $e) {
    $e = bson_to_array($e);

    $item = [
        'type' => 'event',
        'time' => $e['created_at'],
        'event_code' => $e['event_code'],
        'user' => $e['context']['username'] ?? null,
        'summary' => $e['data']['summary'] ?? null,
        'refs' => $e['refs'] ?? [],
    ];

    // log bağla
    if (!empty($e['refs']['log_id'])) {
        $log = MongoManager::collection('UACT01E')->findOne([
            '_id' => new MongoDB\BSON\ObjectId($e['refs']['log_id'])
        ]);
        if ($log) {
            $item['log'] = bson_to_array($log);
        }
    }

    // snapshot bağla
    if (!empty($e['refs']['snapshot_id'])) {
        $snap = MongoManager::collection('SNAP01E')->findOne([
            '_id' => new MongoDB\BSON\ObjectId($e['refs']['snapshot_id'])
        ]);
        if ($snap) {
            $item['snapshot'] = bson_to_array($snap);
        }
    }

    $timeline[] = $item;
}

j([
    'ok' => true,
    'target_key' => $targetKey,
    'timeline' => $timeline
]);
