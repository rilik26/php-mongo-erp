<?php
/**
 * PermissionRegistry.php
 *
 * AMAÇ:
 * - Rol → Evrak → Aksiyon yetkilerini merkezi olarak tutmak
 *
 * SORUMLULUK:
 * - load(): config'ten yetkileri yüklemek
 * - can(): rol için document/action izni var mı?
 *
 * YAPMAZ:
 * - Context bilmez
 * - Session bilmez
 * - HTTP bilmez
 */

final class PermissionRegistry
{
    private static array $roles = [];

    public static function load(array $config): void
    {
        self::$roles = $config;
    }

    public static function can(string $role, string $document, string $action): bool
    {
        if (!isset(self::$roles[$role])) {
            return false;
        }

        $permissions = self::$roles[$role];

        // global wildcard: admin
        if (isset($permissions['*']) && in_array('*', $permissions['*'], true)) {
            return true;
        }

        // document wildcard
        if (isset($permissions['*']) && in_array($action, $permissions['*'], true)) {
            return true;
        }

        if (!isset($permissions[$document])) {
            return false;
        }

        $allowedActions = $permissions[$document];

        return in_array('*', $allowedActions, true) || in_array($action, $allowedActions, true);
    }
}
