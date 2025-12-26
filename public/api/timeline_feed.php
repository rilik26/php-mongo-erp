<?php
/**
 * public/api/timeline_feed.php
 *
 * Timeline Feed API (V1 - FINAL)
 * - EVENT01E üzerinden olayları listeler
 * - tenant filtresi: context.CDEF01_id
 * - period filtresi: current + GLOBAL
 * - filtreler:
 *   - event_code
 *   - module / doc_type / doc_id
 *   - date_from / date_to (ISO 8601)
 * - cursor paging:
 *   - cursor_ts=ISO8601
 *   - cursor_id=ObjectId
 *
 * Response:
 * {
 *   ok: true,
 *   events: [...],
 *   next_cursor: { ts: "...", id: "..." } | null
 * }
 */

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/auth/SessionManager.php';
require_once __DIR__ . '/../../core/base/Context.php';
require_once __DIR__ . '/../../core/base/ContextException.php';

use MongoDB\BSON\UTCDateTime;
use MongoDB\BSON\ObjectId;

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
    if ($v instanceof UTCDateTime) return $v->toDateTime()->format('c');
    if ($v instanceof ObjectId) return (string)$v;
    return $v;
}

function parse_iso_to_utcdatetime(string $iso): ?UTCDateTime {
    try {
        $dt = new DateTime($iso);
        return new UTCDateTime($dt);
    } catch (Throwable $e) {
        return null;
    }
}

SessionManager::start();

if (!isset($_SESSION['context']) || !is_array($_SESSION['context'])) {
    j(['ok'=>false,'error'=>'unauthorized'], 401);
}

try {
    Context::bootFromSession();
} catch (ContextException $e) {
    j(['ok'=>false,'error'=>'unauthorized'], 401);
}

$ctx = Context::get();

$limit = (int)($_GET['limit'] ?? 60);
if ($limit < 10) $limit = 10;
if ($limit > 200) $limit = 200;

$eventCode = trim($_GET['event_code'] ?? '');
$module    = trim($_GET['module'] ?? '');
$docType   = trim($_GET['doc_type'] ?? '');
$docId     = trim($_GET['doc_id'] ?? '');

$dateFrom  = trim($_GET['date_from'] ?? '');
$dateTo    = trim($_GET['date_to'] ?? '');

$cursorTs  = trim($_GET['cursor_ts'] ?? '');
$cursorId  = trim($_GET['cursor_id'] ?? '');

$cdef   = $ctx['CDEF01_id'] ?? null;
$period = $ctx['period_id'] ?? null;

$filter = [];

if ($cdef) $filter['context.CDEF01_id'] = $cdef;

// period: current + GLOBAL
if ($period) {
    $filter['$or'] = [
        ['context.period_id' => $period],
        ['context.period_id' => 'GLOBAL'],
    ];
}

if ($eventCode !== '') $filter['event_code'] = $eventCode;

// target filters
if ($module !== '')  $filter['target.module'] = $module;
if ($docType !== '') $filter['target.doc_type'] = $docType;
if ($docId !== '')   $filter['target.doc_id'] = $docId;

// date range
if ($dateFrom !== '' || $dateTo !== '') {
    $range = [];
    if ($dateFrom !== '') {
        $u = parse_iso_to_utcdatetime($dateFrom);
        if (!$u) j(['ok'=>false,'error'=>'invalid_date_from'], 400);
        $range['$gte'] = $u;
    }
    if ($dateTo !== '') {
        $u = parse_iso_to_utcdatetime($dateTo);
        if (!$u) j(['ok'=>false,'error'=>'invalid_date_to'], 400);
        $range['$lte'] = $u;
    }
    $filter['created_at'] = $range;
}

// cursor paging (created_at desc, _id desc)
if ($cursorTs !== '' && $cursorId !== '') {
    $u = parse_iso_to_utcdatetime($cursorTs);
    if (!$u) j(['ok'=>false,'error'=>'invalid_cursor_ts'], 400);

    try {
        $oid = new ObjectId($cursorId);
    } catch (Throwable $e) {
        j(['ok'=>false,'error'=>'invalid_cursor_id'], 400);
    }

    // (created_at < ts) OR (created_at == ts AND _id < cursor_id)
    $filter['$and'] = array_merge($filter['$and'] ?? [], [[
        '$or' => [
            ['created_at' => ['$lt' => $u]],
            ['created_at' => $u, '_id' => ['$lt' => $oid]],
        ]
    ]]);
}

$cur = MongoManager::collection('EVENT01E')->find(
    $filter,
    [
        'sort' => ['created_at' => -1, '_id' => -1],
        'limit' => $limit,
        'projection' => [
            'event_code' => 1,
            'created_at' => 1,
            'context.username' => 1,
            'context.UDEF01_id' => 1,
            'context.period_id' => 1,
            'target' => 1,
            'refs' => 1,
            'data.summary' => 1,
            'data.source' => 1,
            'data.rows' => 1,
        ]
    ]
);

$events = [];
$last = null;

foreach ($cur as $e) {
    $arr = bson_to_array($e);
    $events[] = $arr;
    $last = $e;
}

$nextCursor = null;
if (!empty($events) && $last) {
    $ts = $last['created_at'] ?? null;
    $id = $last['_id'] ?? null;

    if ($ts instanceof UTCDateTime && $id instanceof ObjectId) {
        $nextCursor = [
            'ts' => $ts->toDateTime()->format('c'),
            'id' => (string)$id
        ];
    }
}

j([
    'ok' => true,
    'events' => $events,
    'next_cursor' => $nextCursor
]);
