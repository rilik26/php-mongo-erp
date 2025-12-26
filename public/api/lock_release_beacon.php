<?php
/**
 * public/api/lock_release_beacon.php
 *
 * POST (FormData):
 *  module, doc_type, doc_id
 *
 * Amaç:
 * - beforeunload sırasında navigator.sendBeacon ile release
 * - JSON döndürmeye çalışır ama beacon için body önemli değil (ok yeter)
 */

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/auth/SessionManager.php';
require_once __DIR__ . '/../../core/lock/LockManager.php';

header('Content-Type: application/json; charset=utf-8');

function j($a, int $code = 200): void {
  http_response_code($code);
  echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

SessionManager::start();

$module  = trim($_POST['module'] ?? '');
$docType = trim($_POST['doc_type'] ?? '');
$docId   = trim($_POST['doc_id'] ?? '');

if ($module === '' || $docType === '' || $docId === '') {
  j(['ok'=>false,'error'=>'module,doc_type,doc_id_required'], 400);
}

$res = LockManager::release([
  'module' => $module,
  'doc_type' => $docType,
  'doc_id' => $docId,
], false);

j($res);
