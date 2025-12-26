<?php
/**
 * public/api/lock_acquire.php
 * GET:
 *  ?module=...&doc_type=...&doc_id=...
 *  &status=editing|viewing|approving
 *  &ttl=900
 *  &doc_no=...&doc_title=...
 *
 * Response standard (V1):
 *  ok: bool
 *  acquired: bool
 *  target_key: string
 *  lock: object|null
 *  reason?: string
 *  message?: string
 *  error?: string
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

$module  = trim($_GET['module'] ?? '');
$docType = trim($_GET['doc_type'] ?? '');
$docId   = trim($_GET['doc_id'] ?? '');

if ($module === '' || $docType === '' || $docId === '') {
  j([
    'ok' => false,
    'error' => 'module,doc_type,doc_id_required',
    'reason' => 'bad_request',
    'message' => 'module/doc_type/doc_id zorunlu'
  ], 400);
}

$status = trim($_GET['status'] ?? 'editing');
if (!in_array($status, ['editing','viewing','approving'], true)) $status = 'editing';

$ttl = (int)($_GET['ttl'] ?? 900);
if ($ttl < 60) $ttl = 60;
if ($ttl > 7200) $ttl = 7200;

$docNo = trim($_GET['doc_no'] ?? '');
$docTitle = trim($_GET['doc_title'] ?? '');

try {
  $res = LockManager::acquire([
    'module'    => $module,
    'doc_type'  => $docType,
    'doc_id'    => $docId,
    'doc_no'    => $docNo !== '' ? $docNo : null,
    'doc_title' => $docTitle !== '' ? $docTitle : null,
  ], $ttl, $status);

  // burada LockManager zaten {ok:true/false,...} döndürüyor varsayıyoruz
  // ama olası eksik alanları normalize edelim:
  if (!is_array($res)) {
    j(['ok'=>false,'error'=>'invalid_response','reason'=>'server_error'], 500);
  }

  // acquired false ise kullanıcıya daha iyi mesaj
  if (($res['ok'] ?? false) && (($res['acquired'] ?? false) === false)) {
    if (empty($res['reason'])) $res['reason'] = 'already_locked';
    if (empty($res['message'])) $res['message'] = 'Evrak başka bir kullanıcı tarafından kilitli.';
  }

  j($res, 200);

} catch (Throwable $e) {
  j([
    'ok' => false,
    'error' => 'exception',
    'reason' => 'server_error',
    'message' => $e->getMessage(),
  ], 500);
}
