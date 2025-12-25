<?php
/**
 * set_lang.php
 *
 * ?lang=tr|en ile dili değiştirir, referer’a döner
 * - session'a yazar
 * - cookie'ye yazar (tarayıcıda kalıcı olsun)
 */

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/i18n/LanguageManager.php';

$lang = $_GET['lang'] ?? 'tr';

// 1 yıl, tüm site için
setcookie('lang', $lang, time() + 31536000, '/');

LanguageManager::set($lang);

$back = $_SERVER['HTTP_REFERER'] ?? '/php-mongo-erp/public/index.php';
header('Location: ' . $back);
exit;
