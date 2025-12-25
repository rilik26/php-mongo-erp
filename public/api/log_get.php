<?php
/**
 * public/api/log_get.php
 *
 * ?log_id=... ile UACT01E log kaydını döndürür.
 */

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/auth/SessionManager.php';

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Model\BSONDocument;
use MongoDB\Model\BSONArray;

SessionManager::start();

header('Content-Type: application/json; charset=utf-8');

$logId = trim($_GET['log_id'] ?? '');

if ($logId === '') {
    echo json_encode(['ok' => false, 'error' => 'log_id_required'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $oid = new ObjectId($logId);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'invalid_log_id'], JSON_UNESCAPED_UNICODE);
    exit;
}

$doc = MongoManager::collection('UACT01E')->findOne(['_id' => $oid]);

if (!$doc) {
    echo json_encode(['ok' => false, 'error' => 'log_not_found'], JSON_UNESCAPED_UNICODE);
    exit;
}

function normalize_bson($v) {
    if ($v instanceof ObjectId) {
        return (string)$v;
    }
    if ($v instanceof UTCDateTime) {
        // ISO string
        return $v->toDateTime()->format('c');
    }
    if ($v instanceof BSONDocument || $v instanceof BSONArray) {
        $v = $v->getArrayCopy();
    }
    if (is_array($v)) {
        $out = [];
        foreach ($v as $k => $vv) {
            $out[$k] = normalize_bson($vv);
        }
        return $out;
    }
    return $v;
}

echo json_encode([
    'ok'  => true,
    'log' => normalize_bson($doc)
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
