<?php
require_once __DIR__ . '/LanguageManager.php';

if (!function_exists('_t')) {
  function _t(string $key, array $params = []): string {
    return LanguageManager::t($key, $params);
  }
}

if (!function_exists('_e')) {
  function _e(string $key, array $params = []): void {
    echo htmlspecialchars(LanguageManager::t($key, $params), ENT_QUOTES, 'UTF-8');
  }
}
