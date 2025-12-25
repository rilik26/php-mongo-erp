<?php
/**
 * PERM01TRepository.php
 *
 * AMAÇ:
 * - role_code'a göre izinleri çekmek
 */

final class PERM01TRepository
{
    public static function listAllowedPerms(string $roleCode): array
    {
        $cursor = MongoManager::collection('PERM01T')->find(
            ['role_code' => $roleCode, 'allow' => true],
            ['projection' => ['perm' => 1]]
        );

        $perms = [];
        foreach ($cursor as $doc) {
            $d = (array)$doc;
            $p = (string)($d['perm'] ?? '');
            if ($p !== '') $perms[] = $p;
        }

        return array_values(array_unique($perms));
    }
}
