<?php
/**
 * public/api/lock_release.php (FINAL)
 * GET:
 *  ?module=...&doc_type=...&doc_id=...
 *  &force=1 (admin)
 *
 * IMPORTANT:
 * - Always return JSON (even on warnings/fatal)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/auth/SessionManager.php';
require_once __DIR__ . '/../../core/base/Context.php';
require_once __DIR__ . '/../../core/lock/LockManager.php';

header('Content-Type: application/json; charset=utf-8');

function j($a, int $code = 200): void {
  http_response_code($code);
  echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

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

try {
  SessionManager::start();

  try {
    if (class_exists('Context') && isset($_SESSION['context']) && is_array($_SESSION['context'])) {
      Context::bootFromSession();
    }
  } catch (Throwable $e) {}

  $module  = trim((string)($_GET['module'] ?? ''));
  $docType = trim((string)($_GET['doc_type'] ?? ''));
  $docId   = trim((string)($_GET['doc_id'] ?? ''));

  if ($module === '' || $docType === '' || $docId === '') {
    j(['ok'=>false,'error'=>'module,doc_type,doc_id_required'], 400);
  }

  $force = (($_GET['force'] ?? '') === '1');

  $res = LockManager::release([
    'module' => $module,
    'doc_type' => $docType,
    'doc_id' => $docId,
  ], $force);

  j($res);

} catch (Throwable $e) {
  j([
    'ok' => false,
    'error' => 'exception',
    'error_detail' => $e->getMessage(),
  ], 500);
}
