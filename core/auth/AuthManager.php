<?php
/**
 * AuthManager
 *
 * SORUMLULUK:
 * - Kullanıcı doğrulamak (login attempt)
 *
 * YAPMAZ:
 * - static context tutmaz
 * - bind() yapmaz
 * - session'a doğrudan yazmaz (SessionManager üzerinden yazar)
 */

require_once __DIR__ . '/SessionManager.php';
require_once __DIR__ . '/../base/ContextFactory.php';
require_once __DIR__ . '/../../app/modules/udef/UDEF01Repository.php';
require_once __DIR__ . '/../action/ActionLogger.php';

final class AuthManager
{
    /**
     * Login attempt:
     * - kullanıcıyı doğrular
     * - başarılıysa context üretir ve session'a yazar
     * - log atar (AUTH.LOGIN / AUTH.FAIL)
     */
    public static function login(string $username, string $password): bool
    {
        SessionManager::start();

        $user = UDEF01Repository::findByCredentials($username, $password);

        if (!$user) {
            // ❗ context yok; overrideContext ile minimum alanları doldur
            ActionLogger::fail('AUTH.FAIL', [
                'reason'   => 'invalid_credentials',
                'source'   => 'AuthManager::login',
            ], [
                'username'   => $username,
                'session_id' => session_id(),
            ]);

            return false;
        }

        // context üret (Factory artık username'ı da dolduruyor)
        $context = ContextFactory::create($user);

        // session'a yaz
        SessionManager::setContext($context);

        // başarılı login logu
        ActionLogger::success('AUTH.LOGIN', [
            'source' => 'AuthManager::login',
        ], $context);

        return true;
    }

    /**
     * Logout
     */
    public static function logout(): void
    {
        SessionManager::start();

        // session context varsa al
        $context = (isset($_SESSION['context']) && is_array($_SESSION['context']))
            ? $_SESSION['context']
            : [];

        // Context sınıfı varsa daha sağlam şekilde çek (varsa)
        if (class_exists('Context')) {
            try {
                // projende standart get() ise:
                $context = Context::get();
            } catch (Throwable $e) {
                // fallback olarak session context kalır
            }
        }

        // logout logu
        ActionLogger::success('AUTH.LOGOUT', [
            'source' => 'AuthManager::logout',
        ], array_merge($context, [
            'session_id' => session_id(),
        ]));

        SessionManager::destroy();
    }
}
