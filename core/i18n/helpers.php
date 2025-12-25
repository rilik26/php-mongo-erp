<?php
/**
 * i18n helpers
 *
 * __()  : çeviri döndürür
 * _e()  : çeviri + htmlspecialchars + echo
 * _t()  : sadece çeviri döndürür (alias)
 */

require_once __DIR__ . '/LanguageManager.php';

/**
 * Translation getter
 */
function __(string $key, array $replace = []): string
{
    return LanguageManager::t($key, $replace);
}

/**
 * Echo + HTML escape (view için EN İDEAL)
 */
function _e(string $key, array $replace = []): void
{
    echo htmlspecialchars(LanguageManager::t($key, $replace), ENT_QUOTES, 'UTF-8');
}

/**
 * Alias (okunurluk için)
 */
function _t(string $key, array $replace = []): string
{
    return LanguageManager::t($key, $replace);
}
