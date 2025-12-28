<?php
// public/set_lang.php

require_once __DIR__ . '/../core/auth/SessionManager.php';
SessionManager::start(); // ✅ önce session

require_once __DIR__ . '/../core/bootstrap.php'; // ✅ bootstrap sonra (i18n boot artık session varken çalışır)
require_once __DIR__ . '/../core/i18n/LanguageManager.php';

$lang = strtolower(trim($_GET['lang'] ?? ''));

if ($lang !== '') {
  try {
    LanguageManager::set($lang);
  } catch (Throwable $e) {
    // ignore
  }
  // garanti
  $_SESSION['lang'] = $lang;
}

$next = trim($_GET['next'] ?? '');

// sadece kendi app path’ine izin ver (open redirect engeli)
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
