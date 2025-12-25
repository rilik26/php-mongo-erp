<?php
/**
 * Context.php
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
 * - session'a yazmaz
 */

final class Context
{
    private static ?array $current = null;

    public static function bootFromSession(): void
    {
        if (!isset($_SESSION['context']) || !is_array($_SESSION['context'])) {
            throw new ContextException('Context not found in session');
        }

        self::$current = $_SESSION['context'];
    }

    public static function get(): array
    {
        if (self::$current === null) {
            throw new ContextException('Context not initialized');
        }

        return self::$current;
    }
}
