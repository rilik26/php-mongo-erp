<?php
/**
 * public/api/lock_release.php
 * GET:
 *  ?module=...&doc_type=...&doc_id=...
 *  &force=1 (admin)
 *
 * Response standard (V1):
 *  ok: bool
 *  released: bool
 *  target_key?: string
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

$force = (($_GET['force'] ?? '') === '1');

try {
  $res = LockManager::release([
    'module'   => $module,
    'doc_type' => $docType,
    'doc_id'   => $docId,
  ], $force);

  if (!is_array($res)) {
    j(['ok'=>false,'error'=>'invalid_response','reason'=>'server_error'], 500);
  }

  // normalize
  if (($res['ok'] ?? false) && !isset($res['released'])) {
    $res['released'] = true; // bazı implementasyonlar sadece ok döndürür
  }

  if (($res['ok'] ?? false) && (($res['released'] ?? false) === false)) {
    if (empty($res['reason'])) $res['reason'] = 'not_owner';
    if (empty($res['message'])) $res['message'] = 'Lock size ait değil (force gerekiyorsa admin kullan).';
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
