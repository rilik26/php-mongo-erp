<?php
/**
 * core/base/Context.php (FINAL)
 *
 * AMAÇ:
 * - Session context'i güvenli şekilde uygulama context'ine almak
 * - Yeni alanlar (company_name/company_code/PERIOD01T_id/period_label) kaybolmasın
 */

final class Context
{
    private static array $ctx = [];

    public static function bootFromSession(): void
    {
        if (!isset($_SESSION['context']) || !is_array($_SESSION['context'])) {
            throw new ContextException('context_not_found_in_session');
        }

        $c = $_SESSION['context'];

        // minimum
        $c['username']   = (string)($c['username'] ?? '');
        $c['CDEF01_id']  = (string)($c['CDEF01_id'] ?? '');
        $c['role']       = $c['role'] ?? null;

        // ✅ yeni period şeması
        $c['PERIOD01T_id'] = (string)($c['PERIOD01T_id'] ?? '');
        $c['period_label'] = (string)($c['period_label'] ?? '');

        // company
        $c['company_name'] = (string)($c['company_name'] ?? '');
        $c['company_code'] = (string)($c['company_code'] ?? '');

        // future
        $c['facility_id']  = $c['facility_id'] ?? null;

        self::$ctx = $c;
    }

    public static function get(): array
    {
        if (empty(self::$ctx) && isset($_SESSION['context']) && is_array($_SESSION['context'])) {
            self::$ctx = $_SESSION['context'];
        }
        return self::$ctx;
    }

    public static function set(array $ctx): void
    {
        self::$ctx = $ctx;
    }
}
