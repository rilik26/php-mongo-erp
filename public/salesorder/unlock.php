<?php
/**
 * public/salesorder/unlock.php (FINAL)
 *
 * - mevcut oturumla SORD01E lock kaldırır
 * - JS beacon/fetch ile çağrılır
 */
define('SKIP_I18N_BOOT', true);

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/auth/SessionManager.php';
require_once __DIR__ . '/../../core/base/Context.php';
require_once __DIR__ . '/../../app/modules/salesorder/SORDRepository.php';

SessionManager::start();

header('Content-Type: application/json; charset=utf-8');

try { Context::bootFromSession(); }
catch (Throwable $e) {
  echo json_encode(['ok'=>false,'err'=>'not_logged_in']);
  exit;
}

$ctx = Context::get();
$id = trim((string)($_POST['id'] ?? ($_GET['id'] ?? '')));

if ($id === '' || strlen($id) !== 24) {
  echo json_encode(['ok'=>false,'err'=>'id_invalid']);
  exit;
}

try {
  SORDRepository::unlock($id, $ctx);
  echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
  echo json_encode(['ok'=>false,'err'=>$e->getMessage()]);
}
