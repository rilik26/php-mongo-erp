<?php
/**
 * public/api/snapshot_get.php (FINAL)
 *
 * GET:
 *  - ?snapshot_id=<SNAP01E _id>
 *  - &view=1 -> redirect to /public/snapshot_view.php?snapshot_id=...
 *
 * IMPORTANT:
 * - Always return JSON (even on warnings/fatal)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/auth/SessionManager.php';

SessionManager::start();

function j($a, int $code = 200): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

/** Fatal/Warn/Notice -> JSON */
ini_set('display_errors', '0');
error_reporting(E_ALL);

set_error_handler(function($severity, $message, $file, $line) {
  throw new ErrorException($message, 0, $severity, $file, $line);
});

register_shutdown_function(function() {
  $e = error_get_last();
  if (!$e) return;
  if (headers_sent()) return;

  header('Content-Type: application/json; charset=utf-8');
  http_response_code(500);
  echo json_encode([
    'ok' => false,
    'error' => 'fatal_error',
    'error_detail' => $e['message'] ?? 'unknown',
    'file' => $e['file'] ?? null,
    'line' => $e['line'] ?? null,
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
});

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

function try_oid(string $id): ?MongoDB\BSON\ObjectId {
  if ($id === '' || strlen($id) !== 24) return null;
  try { return new MongoDB\BSON\ObjectId($id); }
  catch (Throwable $e) { return null; }
}

try {
  $snapshotId = trim((string)($_GET['snapshot_id'] ?? ''));
  $view = ((string)($_GET['view'] ?? '') === '1');

  if ($snapshotId === '') {
    j(['ok'=>false,'error'=>'snapshot_id_required'], 400);
  }

  // Geriye uyumluluk: view=1 ise theme sayfaya yönlendir
  if ($view) {
    header('Location: /php-mongo-erp/public/snapshot_view.php?snapshot_id=' . rawurlencode($snapshotId));
    exit;
  }

  $oid = try_oid($snapshotId);
  if (!$oid) {
    j(['ok'=>false,'error'=>'invalid_snapshot_id'], 400);
  }

  $doc = MongoManager::collection('SNAP01E')->findOne(['_id' => $oid]);
  if (!$doc) {
    j(['ok'=>false,'error'=>'not_found'], 404);
  }

  $snap = bson_to_array($doc);

  j(['ok'=>true,'snapshot'=>$snap]);

} catch (Throwable $e) {
  j([
    'ok' => false,
    'error' => 'exception',
    'error_detail' => $e->getMessage(),
  ], 500);
}
