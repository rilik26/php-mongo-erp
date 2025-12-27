<?php
/**
 * public/api/lock_acquire.php
 * GET:
 *  ?module=...&doc_type=...&doc_id=...
 *  &status=editing|viewing|approving
 *  &ttl=900
 *  &doc_no=...&doc_title=...
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

// ✅ Context’i session’dan boot et (tenant/period dolsun)
try {
  if (isset($_SESSION['context']) && is_array($_SESSION['context'])) {
    Context::bootFromSession();
  }
} catch (Throwable $e) {
  // lock endpointinde redirect istemiyoruz; context boş kalsa da ok
}

$module  = trim($_GET['module'] ?? '');
$docType = trim($_GET['doc_type'] ?? '');
$docId   = trim($_GET['doc_id'] ?? '');

if ($module === '' || $docType === '' || $docId === '') {
  j(['ok'=>false,'error'=>'module,doc_type,doc_id_required'], 400);
}

$status = trim($_GET['status'] ?? 'editing');
if (!in_array($status, ['editing','viewing','approving'], true)) $status = 'editing';

$ttl = (int)($_GET['ttl'] ?? 900);
if ($ttl < 60) $ttl = 60;
if ($ttl > 7200) $ttl = 7200;

$docNo = trim($_GET['doc_no'] ?? '');
$docTitle = trim($_GET['doc_title'] ?? '');

$res = LockManager::acquire([
  'module'    => $module,
  'doc_type'  => $docType,
  'doc_id'    => $docId,
  'doc_no'    => ($docNo !== '' ? $docNo : null),
  'doc_title' => ($docTitle !== '' ? $docTitle : null),
], $ttl, $status);

j($res);
