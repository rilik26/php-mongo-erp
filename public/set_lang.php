<?php
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/auth/SessionManager.php';
require_once __DIR__ . '/../core/i18n/LanguageManager.php';

SessionManager::start();

$lang = strtolower(trim($_GET['lang'] ?? ''));
if ($lang !== '') {
  LanguageManager::set($lang);
}

$next = trim($_GET['next'] ?? '');
if ($next !== '' && strpos($next, '/php-mongo-erp/') === 0) {
  header('Location: ' . $next);
  exit;
}

$ref = $_SERVER['HTTP_REFERER'] ?? '';
if ($ref && strpos($ref, '/php-mongo-erp/') !== false) {
  header('Location: ' . $ref);
  exit;
}

header('Location: /php-mongo-erp/public/index.php');
exit;
