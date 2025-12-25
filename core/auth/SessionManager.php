<?php
/**
 * SessionManager.php
 * Amaç: Session kontrolü ve context taşıma
 */

final class SessionManager
{
    public static function start(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    public static function setContext(array $context): void
    {
        $_SESSION['context'] = $context;
    }

    public static function destroy(): void
    {
        session_destroy();
    }
}
