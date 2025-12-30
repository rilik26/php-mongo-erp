<?php
/**
 * core/base/Context.php (FINAL)
 *
 * AMAÇ:
 * - Session context'i güvenli şekilde uygulama context'ine almak
 * - Whitelist yüzünden yeni alanların kaybolmasını engellemek
 * - company_name / company_code gibi ekstra alanları KORUMAK
 */

final class Context
{
    private static array $ctx = [];

    public static function bootFromSession(): void
    {
        if (!isset($_SESSION['context']) || !is_array($_SESSION['context'])) {
            throw new ContextException('context_not_found_in_session');
        }

        // ✅ Session'daki context'i komple al (yeni alanlar kaybolmasın)
        $c = $_SESSION['context'];

        // ✅ Minimum alanları garanti et
        $c['username']   = (string)($c['username'] ?? '');
        $c['CDEF01_id']  = (string)($c['CDEF01_id'] ?? '');
        $c['period_id']  = (string)($c['period_id'] ?? '');
        $c['role']       = $c['role'] ?? null;

        // ✅ Opsiyonel alanları normalize et
        $c['company_name'] = (string)($c['company_name'] ?? '');
        $c['company_code'] = (string)($c['company_code'] ?? '');
        $c['facility_id']  = $c['facility_id'] ?? null;

        self::$ctx = $c;
    }

    public static function get(): array
    {
        if (empty(self::$ctx)) {
            // bazı sayfalarda boot unutulursa session'dan okumaya çalış
            if (isset($_SESSION['context']) && is_array($_SESSION['context'])) {
                self::$ctx = $_SESSION['context'];
            }
        }
        return self::$ctx;
    }

    public static function set(array $ctx): void
    {
        self::$ctx = $ctx;
    }
}
