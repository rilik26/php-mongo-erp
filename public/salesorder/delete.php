<?php
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/auth/SessionManager.php';
require_once __DIR__ . '/../../core/base/Context.php';
require_once __DIR__ . '/../../app/modules/salesorder/SORDRepository.php';
require_once __DIR__ . '/../../core/lock/LockService.php';

SessionManager::start();
try { Context::bootFromSession(); } catch (Throwable $e) {
  header('Location: /php-mongo-erp/public/login.php'); exit;
}

$ctx = Context::get();
$id = trim((string)($_GET['id'] ?? ''));

if ($id === '' || strlen($id) !== 24) {
  header('Location: /php-mongo-erp/public/salesorder/index.php'); exit;
}

// sadece POST ile sil
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: /php-mongo-erp/public/salesorder/edit.php?id=' . urlencode($id)); exit;
}

// lock release (best effort)
LockService::release('SORD01E', $id);

try {
  SORDRepository::softDelete($id, $ctx);
} catch (Throwable $e) {
  // hata olsa bile edit'e dön
  header('Location: /php-mongo-erp/public/salesorder/edit.php?id=' . urlencode($id) . '&err=' . urlencode($e->getMessage()));
  exit;
}

header('Location: /php-mongo-erp/public/salesorder/index.php');
exit;
