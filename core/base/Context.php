<?php
/**
 * Context.php (FINAL)
 *
 * AMAÇ:
 * - Aktif request'in çalışma bağlamını (session içindeki context) tek yerden vermek
 *
 * SORUMLULUK:
 * - bootFromSession(): session'dan context'i yükler
 * - get(): aktif context'i döner
 *
 * YAPMAZ:
 * - login yapmaz
 * - context'i session'a yazmaz
 */

final class Context
{
    private static ?array $current = null;

    public static function bootFromSession(): void
    {
        // Session başlamadıysa güvenli şekilde başlat
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (!isset($_SESSION['context']) || !is_array($_SESSION['context'])) {
            throw new ContextException('Context not found in session');
        }

        // Shallow copy: referans karışıklığı olmasın
        self::$current = $_SESSION['context'];
    }

    public static function get(): array
    {
        if (self::$current === null) {
            throw new ContextException('Context not initialized');
        }

        return self::$current;
    }

    /**
     * Context var mı? (guard yazmak kolaylaşır)
     */
    public static function has(): bool
    {
        return is_array(self::$current);
    }

    /**
     * Patlamasın istersen: exception yerine [] dönsün
     */
    public static function tryGet(): array
    {
        return is_array(self::$current) ? self::$current : [];
    }
}
