<?php
/**
 * PermissionManager.php
 *
 * AMAÇ:
 * - Context'teki role'a göre permission set'i üretmek
 * - Request boyunca hızlı can() kontrolü
 *
 * CACHE:
 * - şimdilik request cache (static)
 * - istersen sonra session cache / APCu ekleriz
 */

require_once __DIR__ . '/../base/Context.php';
require_once __DIR__ . '/../../app/modules/perm/PERM01TRepository.php';

final class PermissionManager
{
    private static bool $booted = false;
    private static array $permSet = []; // ['lang.manage'=>true, ...]

    public static function boot(): void
    {
        if (self::$booted) return;

        $ctx = [];
        try {
            $ctx = Context::get();
        } catch (Throwable $e) {
            self::$booted = true;
            self::$permSet = [];
            return;
        }

        $role = (string)($ctx['role'] ?? '');
        if ($role === '') {
            self::$booted = true;
            self::$permSet = [];
            return;
        }

        $perms = PERM01TRepository::listAllowedPerms($role);

        $set = [];
        foreach ($perms as $p) {
            $set[$p] = true;
        }

        self::$permSet = $set;
        self::$booted = true;
    }

    public static function can(string $perm): bool
    {
        if (!self::$booted) self::boot();
        return isset(self::$permSet[$perm]) && self::$permSet[$perm] === true;
    }
}
