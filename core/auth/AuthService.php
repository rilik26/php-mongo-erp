<?php
/**
 * AuthService.php (FINAL)
 *
 * - username + password + period ile login
 * - PERIOD01T firma bazlı open period kontrolü
 * - Session context: company_name / company_code mutlaka set edilir
 */

require_once __DIR__ . '/SessionManager.php';
require_once __DIR__ . '/../action/ActionLogger.php';
require_once __DIR__ . '/../../app/modules/period/PERIOD01Repository.php';

final class AuthService
{
    public static function attempt(string $username, string $password, string $periodId): bool
    {
        SessionManager::start();

        $user = MongoManager::collection('UDEF01E')->findOne([
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

        $companyId = (string)($user['CDEF01_id'] ?? '');
        if ($companyId === '' || strlen($companyId) !== 24) {
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

        // ✅ Firma bilgisini çek (active filtresi opsiyonel)
        $companyName = $companyId;
        $companyCode = '';

        try {
            $c = MongoManager::collection('CDEF01E')->findOne([
                '_id' => new MongoDB\BSON\ObjectId($companyId),
            ]);

            if ($c instanceof MongoDB\Model\BSONDocument) $c = $c->getArrayCopy();
            if (is_array($c)) {
                $companyName = trim((string)($c['name'] ?? $companyName));
                $companyCode = trim((string)($c['code'] ?? $companyCode));
                if ($companyName === '') $companyName = $companyId;
            }
        } catch (Throwable $e) {
            // fallback: companyName stays companyId
        }

        $_SESSION['context'] = [
            'UDEF01_id'     => (string)$user['_id'],
            'username'      => (string)($user['username'] ?? $username),

            'CDEF01_id'     => $companyId,
            'company_name'  => $companyName,
            'company_code'  => $companyCode,

            'period_id'     => $periodId,
            'role'          => $user['role'] ?? null,
            'session_id'    => session_id(),

            'facility_id'   => null,

            'ip'            => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent'    => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ];

        ActionLogger::success('AUTH.LOGIN', [
            'source' => 'public/login.php',
        ], $_SESSION['context']);

        return true;
    }

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
