<?php
/**
 * public/api/doc_transition.php (FINAL)
 *
 * POST:
 * - doc_type
 * - doc_id
 * - action
 * - payload (optional JSON)
 */

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/auth/SessionManager.php';
require_once __DIR__ . '/../../core/base/Context.php';
require_once __DIR__ . '/../../core/base/ContextException.php';
require_once __DIR__ . '/../../app/services/DocumentStateService.php';

SessionManager::start();
header('Content-Type: application/json; charset=utf-8');

try {
  Context::bootFromSession();
} catch (Throwable $e) {
  echo json_encode(['ok'=>false,'error'=>'unauthorized']); exit;
}

$ctx = Context::get();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  echo json_encode(['ok'=>false,'error'=>'method_not_allowed']); exit;
}

$docType = trim((string)($_POST['doc_type'] ?? ''));
$docId   = trim((string)($_POST['doc_id'] ?? ''));
$action  = trim((string)($_POST['action'] ?? ''));

$payload = [];
if (!empty($_POST['payload'])) {
  $tmp = json_decode((string)$_POST['payload'], true);
  if (is_array($tmp)) $payload = $tmp;
}

if ($docType==='' || $docId==='' || $action==='') {
  echo json_encode(['ok'=>false,'error'=>'missing_params']); exit;
}

$res = DocumentStateService::transition($docType, $docId, $action, $payload, $ctx);
echo json_encode($res);
