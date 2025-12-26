<?php
/**
 * public/api/lock_refresh.php
 *
 * Amaç:
 * - Sayfa açıkken lock TTL uzatmak (renew)
 *
 * GET/POST:
 * - module, doc_type, doc_id (zorunlu)
 * - status (opsiyonel: editing|viewing|approving) default editing
 * - ttl (opsiyonel) default 900
 * - doc_no, doc_title (opsiyonel)
 */

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/auth/SessionManager.php';
require_once __DIR__ . '/../../core/base/Context.php';
require_once __DIR__ . '/../../core/base/ContextException.php';

require_once __DIR__ . '/../../core/lock/LockRepository.php';
require_once __DIR__ . '/../../core/lock/LockManager.php';

header('Content-Type: application/json; charset=utf-8');

function j($a, int $code = 200): void {
  http_response_code($code);
  echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

SessionManager::start();

// login şart
try {
  if (isset($_SESSION['context']) && is_array($_SESSION['context'])) {
    Context::bootFromSession();
  } else {
    j(['ok'=>false,'error'=>'not_logged_in'], 401);
  }
} catch (Throwable $e) {
  j(['ok'=>false,'error'=>'not_logged_in'], 401);
}

$src = array_merge($_GET, $_POST);

$module  = trim($src['module'] ?? '');
$docType = trim($src['doc_type'] ?? '');
$docId   = trim($src['doc_id'] ?? '');

if ($module === '' || $docType === '' || $docId === '') {
  j(['ok'=>false,'error'=>'module_doc_type_doc_id_required'], 400);
}

$status = trim($src['status'] ?? 'editing');
if (!in_array($status, ['editing','viewing','approving'], true)) $status = 'editing';

$ttl = (int)($src['ttl'] ?? 900);
if ($ttl < 60) $ttl = 60;
if ($ttl > 7200) $ttl = 7200;

$target = [
  'module'   => $module,
  'doc_type' => $docType,
  'doc_id'   => $docId,
];

if (!empty($src['doc_no'])) $target['doc_no'] = (string)$src['doc_no'];
if (!empty($src['doc_title'])) $target['doc_title'] = (string)$src['doc_title'];

$res = LockManager::acquire($target, $ttl, $status); // acquire zaten "aynı session ise renew" yapıyor

j([
  'ok'        => true,
  'acquired'  => (bool)($res['acquired'] ?? false),
  'target_key'=> $res['target_key'] ?? null,
  'reason'    => $res['reason'] ?? null,
  'lock'      => $res['lock'] ?? null,
]);
