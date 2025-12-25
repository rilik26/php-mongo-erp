<?php
/**
 * UserRepository
 *
 * UDEF01E - Kullanıcı Evrakı veri erişimi
 *
 * SORUMLULUK:
 * - Kullanıcıyı login bilgisine göre bulmak
 * - AuthManager'a ham kullanıcı verisi vermek
 */
class UDEF01Repository
{
    public static function findByCredentials(string $username, string $password): ?array
    {
        $collection = MongoManager::collection('UDEF01E');

        $user = $collection->findOne([
            'username' => $username,
            'password' => sha1($password),
            'active'   => true
        ]);

        if (!$user) {
            return null;
        }

        return [
            'UDEF01_id' => (string)$user['_id'],
            'username'  => $user['username'] ?? $username,
            'CDEF01_id' => $user['CDEF01_id'] ?? null,
            'period_id' => $user['period_id'] ?? null,
            'role'      => $user['role'] ?? null,
        ];
    }
}

