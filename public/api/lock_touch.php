<?php
/**
 * public/api/lock_touch.php
 * GET:
 *  ?module=...&doc_type=...&doc_id=...
 *  &ttl=900
 *  &status=editing|viewing|approving (opsiyonel)
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
  j(['ok'=>false,'error'=>'module,doc_type,doc_id_required'], 400);
}

$ttl = (int)($_GET['ttl'] ?? 900);
if ($ttl < 60) $ttl = 60;
if ($ttl > 7200) $ttl = 7200;

$status = trim($_GET['status'] ?? '');
if ($status !== '' && !in_array($status, ['editing','viewing','approving'], true)) $status = '';

$res = LockManager::touch([
  'module' => $module,
  'doc_type' => $docType,
  'doc_id' => $docId,
], $ttl, $status !== '' ? $status : null);

j($res);
