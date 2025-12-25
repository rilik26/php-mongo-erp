<?php
/**
 * AuthService.php
 *
 * AMAÇ:
 * - username + password + period ile tek ekranda login
 * - Firma bazlı period kontrolü (PERIOD01T)
 * - Session context yazma
 * - AUTH.LOGIN / AUTH.FAIL / AUTH.LOGOUT logları
 *
 * NOT:
 * - Şifre şeması: sha1 (mevcut sistemin)
 * - Period seçilmeden login tamamlanmaz
 */

require_once __DIR__ . '/SessionManager.php';
require_once __DIR__ . '/../action/ActionLogger.php';
require_once __DIR__ . '/../../app/modules/period/PERIOD01Repository.php';

final class AuthService
{
    /**
     * Tek adım login:
     * - user doğrula
     * - period doğrula (firma bazlı açık mı)
     * - context yaz
     * - logla
     */
    public static function attempt(string $username, string $password, string $periodId): bool
    {
        SessionManager::start();

        $collection = MongoManager::collection('UDEF01E');

        // Kullanıcıyı username + active ile bul (fail loglarında firma vs görebilmek için)
        $user = $collection->findOne([
            'username' => $username,
            'active'   => true
        ]);

        if (!$user) {
            ActionLogger::fail('AUTH.FAIL', [
                'reason' => 'user_not_found_or_inactive',
                'source' => 'AuthService::attempt',
            ], [
                'username'   => $username,
                'session_id' => session_id(),
            ]);
            return false;
        }

        // Şifre doğrula (mevcut: sha1)
        $expected = $user['password'] ?? null;
        if (!$expected || $expected !== sha1($password)) {
            ActionLogger::fail('AUTH.FAIL', [
                'reason' => 'wrong_password',
                'source' => 'AuthService::attempt',
            ], [
                'UDEF01_id'  => (string)$user['_id'],
                'username'   => $user['username'] ?? $username,
                'CDEF01_id'  => $user['CDEF01_id'] ?? null,
                'session_id' => session_id(),
            ]);
            return false;
        }

        // period seçili mi?
        if ($periodId === '') {
            ActionLogger::fail('AUTH.FAIL', [
                'reason' => 'period_not_selected',
                'source' => 'AuthService::attempt',
            ], [
                'UDEF01_id'  => (string)$user['_id'],
                'username'   => $user['username'] ?? $username,
                'CDEF01_id'  => $user['CDEF01_id'] ?? null,
                'session_id' => session_id(),
            ]);
            return false;
        }

        $companyId = $user['CDEF01_id'] ?? null;
        if (!$companyId) {
            ActionLogger::fail('AUTH.FAIL', [
                'reason' => 'company_not_found_on_user',
                'source' => 'AuthService::attempt',
            ], [
                'UDEF01_id'  => (string)$user['_id'],
                'username'   => $user['username'] ?? $username,
                'session_id' => session_id(),
            ]);
            return false;
        }

        // Firma bazlı dönem açık mı?
        if (!PERIOD01Repository::isOpen($periodId, $companyId)) {
            ActionLogger::fail('AUTH.FAIL', [
                'reason' => 'period_closed_or_invalid_for_company',
                'source' => 'AuthService::attempt',
            ], [
                'UDEF01_id'  => (string)$user['_id'],
                'username'   => $user['username'] ?? $username,
                'CDEF01_id'  => $companyId,
                'period_id'  => $periodId,
                'session_id' => session_id(),
            ]);
            return false;
        }

        // ✅ CONTEXT (Session’a yazacağımız standart alanlar)
        $_SESSION['context'] = [
            'UDEF01_id'  => (string)$user['_id'],
            'username'   => $user['username'] ?? $username,
            'CDEF01_id'  => $companyId,
            'period_id'  => $periodId,
            'role'       => $user['role'] ?? null,
            'session_id' => session_id(),

            // yakında eklenecek:
            'facility_id' => null,

            // meta
            'ip'         => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ];

        ActionLogger::success('AUTH.LOGIN', [
            'source' => 'public/login.php',
        ], $_SESSION['context']);

        return true;
    }

    /**
     * Logout
     */
    public static function logout(): void
    {
        SessionManager::start();

        $context = (isset($_SESSION['context']) && is_array($_SESSION['context']))
            ? $_SESSION['context']
            : [];

        ActionLogger::success('AUTH.LOGOUT', [
            'source' => 'public/logout.php',
        ], array_merge($context, [
            'session_id' => session_id(),
        ]));

        SessionManager::destroy();
    }
}
