<?php
/**
 * public/api/lock_release.php
 * GET:
 *  ?module=...&doc_type=...&doc_id=...
 *  &force=1 (admin)
 */

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/auth/SessionManager.php';

require_once __DIR__ . '/../../core/base/Context.php';
require_once __DIR__ . '/../../core/base/ContextException.php';

require_once __DIR__ . '/../../core/lock/LockManager.php';

header('Content-Type: application/json; charset=utf-8');

function j($a, int $code = 200): void {
  http_response_code($code);
  echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

SessionManager::start();

// ✅ Context boot (release’te de tenant/period düzgün olsun)
try {
  if (isset($_SESSION['context']) && is_array($_SESSION['context'])) {
    Context::bootFromSession();
  }
} catch (Throwable $e) {}

$module  = trim($_GET['module'] ?? '');
$docType = trim($_GET['doc_type'] ?? '');
$docId   = trim($_GET['doc_id'] ?? '');

if ($module === '' || $docType === '' || $docId === '') {
  j(['ok'=>false,'error'=>'module,doc_type,doc_id_required'], 400);
}

$force = (($_GET['force'] ?? '') === '1');

$res = LockManager::release([
  'module'   => $module,
  'doc_type' => $docType,
  'doc_id'   => $docId,
], $force);

j($res);
