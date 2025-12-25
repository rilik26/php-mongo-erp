<?php
/**
 * public/api/snapshot_get.php
 *
 * GET:
 * - snapshot_id
 *
 * Output:
 * - SNAP01E dokümanı (JSON)
 */

require_once __DIR__ . '/../../core/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function j($a, int $code = 200): void {
    http_response_code($code);
    echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function oid($id) {
    try {
        return new MongoDB\BSON\ObjectId((string)$id);
    } catch (Throwable $e) {
        return null;
    }
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

$snapshotId = $_GET['snapshot_id'] ?? null;
if (!$snapshotId) j(['ok'=>false,'error'=>'snapshot_id_required'], 400);

$id = oid($snapshotId);
if (!$id) j(['ok'=>false,'error'=>'invalid_snapshot_id'], 400);

$doc = MongoManager::collection('SNAP01E')->findOne(['_id'=>$id]);
if (!$doc) j(['ok'=>false,'error'=>'snapshot_not_found'], 404);

j(['ok'=>true, 'snapshot'=>bson_to_array($doc)]);
