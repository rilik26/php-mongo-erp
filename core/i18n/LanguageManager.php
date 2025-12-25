<?php
/**
 * LanguageManager.php
 *
 * AMAÇ:
 * - Aktif dili session üzerinden yönetmek
 * - LANG01E.version ile session cache invalidation yapmak
 * - __() helper ile çeviri döndürmek
 *
 * NOT:
 * - Firma override YOK (global)
 */

require_once __DIR__ . '/../auth/SessionManager.php';
require_once __DIR__ . '/../../app/modules/lang/LANG01ERepository.php';
require_once __DIR__ . '/../../app/modules/lang/LANG01TRepository.php';

final class LanguageManager
{
    private static string $current = 'tr';
    private static int $version = 1;

    // request içi hızlı cache
    private static array $dict = [];

    private static function cacheKey(string $langCode, int $version): string
    {
        return '__i18n_dict__' . $langCode . '__v' . $version;
    }

    public static function boot(): void
    {
        SessionManager::start();

        // 0) cookie dili (tarayıcı tercihidir)
        $cookieLang = $_COOKIE['lang'] ?? null;

        // 1) session dili
        $sessionLang = $_SESSION['lang'] ?? null;

        // cookie varsa session'a bas (tek kaynak olsun)
        if ($cookieLang) {
            $_SESSION['lang'] = $cookieLang;
            $lang = $cookieLang;
        } else {
            $lang = $sessionLang;
        }

        // 2) default dil (db)
        if (!$lang) {
            $lang = LANG01ERepository::getDefaultLangCode();
            $_SESSION['lang'] = $lang;
            setcookie('lang', $lang, time() + 31536000, '/');
        }

        // 3) aktif mi? değilse default’a düş
        if (!LANG01ERepository::isActive($lang)) {
            $lang = LANG01ERepository::getDefaultLangCode();
            $_SESSION['lang'] = $lang;
            setcookie('lang', $lang, time() + 31536000, '/');
        }

        self::$current = $lang;
        self::$version = LANG01ERepository::getVersion($lang);

        // session cache var mı?
        $ck = self::cacheKey($lang, self::$version);
        if (isset($_SESSION[$ck]) && is_array($_SESSION[$ck])) {
            self::$dict = $_SESSION[$ck];
            return;
        }

        // yoksa DB’den yükle
        $dict = LANG01TRepository::loadDictionary($lang);

        self::$dict = $dict;
        $_SESSION[$ck] = $dict;
    }

    public static function set(string $langCode): void
    {
        SessionManager::start();

        // Dil pasifse kabul etme -> default’a düş
        if (!LANG01ERepository::isActive($langCode)) {
            $langCode = LANG01ERepository::getDefaultLangCode();
        }

        $_SESSION['lang'] = $langCode;

        // yeniden boot (version + dict)
        self::$dict = [];
        self::boot();
    }

    public static function get(): string
    {
        return self::$current;
    }

    public static function t(string $key, array $replace = []): string
    {
        if (empty(self::$dict)) {
            self::boot();
        }

        $text = self::$dict[$key] ?? null;

        // fallback: default dil
        if ($text === null || $text === '') {
            $default = LANG01ERepository::getDefaultLangCode();
            if ($default !== self::$current) {
                $v2 = LANG01ERepository::getVersion($default);
                $ck2 = self::cacheKey($default, $v2);

                $dict2 = $_SESSION[$ck2] ?? null;
                if (!is_array($dict2)) {
                    $dict2 = LANG01TRepository::loadDictionary($default);
                    $_SESSION[$ck2] = $dict2;
                }

                $text = $dict2[$key] ?? null;
            }
        }

        if ($text === null || $text === '') {
            $text = $key; // en son fallback
        }

        foreach ($replace as $k => $v) {
            $text = str_replace(':' . $k, (string)$v, $text);
        }

        return $text;
    }
}
