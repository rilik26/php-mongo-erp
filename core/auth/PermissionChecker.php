<?php
/**
 * PermissionChecker.php
 *
 * AMAÇ:
 * - Bir işlem yapılmadan önce yetki kontrolü yapmak
 *
 * SORUMLULUK:
 * - Context içinden role okumak
 * - PermissionRegistry ile allow/deny kararı vermek
 *
 * YAPMAZ:
 * - Session yönetmez
 * - Context üretmez
 * - Controller mantığı içermez
 */

require_once __DIR__ . '/../base/Context.php';
require_once __DIR__ . '/../registry/permissions/PermissionRegistry.php';

final class PermissionChecker
{
    /**
     * Yetki yoksa exception fırlatır
     */
    public static function check(string $document, string $action): void
    {
        $context = Context::get();

        $role = $context['role'] ?? null;
        if (!$role) {
            throw new Exception('Role not found in context');
        }

        $allowed = PermissionRegistry::can($role, $document, $action);

        if (!$allowed) {
            throw new Exception('Yetkisiz işlem');
        }
    }

    /**
     * Boolean kontrol (bazı yerlerde lazım olur)
     */
    public static function can(string $document, string $action): bool
    {
        $context = Context::get();
        $role = $context['role'] ?? null;

        if (!$role) {
            return false;
        }

        return PermissionRegistry::can($role, $document, $action);
    }
}
