<?php
/**
 * AuthService.php (FINAL)
 *
 * - username + password + period_oid ile login
 * - Firma bazlı dönem kontrolü (PERIOD01T _id + is_open)
 * - Session context: company_name/company_code + PERIOD01T_id + period_label
 */

require_once __DIR__ . '/SessionManager.php';
require_once __DIR__ . '/../action/ActionLogger.php';
require_once __DIR__ . '/../../app/modules/period/PERIOD01Repository.php';

final class AuthService
{
    public static function attempt(string $username, string $password, string $periodOid): bool
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

        // ✅ periodOid seçili mi?
        $periodOid = trim($periodOid);
        if ($periodOid === '' || strlen($periodOid) !== 24) {
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

        // ✅ Firma bazlı dönem açık mı? (artık _id ile)
        if (!PERIOD01Repository::isOpenById($periodOid, $companyId)) {
            ActionLogger::fail('AUTH.FAIL', [
                'reason' => 'period_closed_or_invalid_for_company',
                'source' => 'AuthService::attempt',
            ], [
                'UDEF01_id'  => (string)$user['_id'],
                'username'   => $user['username'] ?? $username,
                'CDEF01_id'  => $companyId,
                'PERIOD01T_id' => $periodOid,
                'session_id' => session_id(),
            ]);
            return false;
        }

        // ✅ Firma adı/kodu
        $companyName = $companyId;
        $companyCode = '';
        try {
            $c = MongoManager::collection('CDEF01E')->findOne([
                '_id'    => new MongoDB\BSON\ObjectId($companyId),
                'active' => true
            ]);
            if ($c instanceof MongoDB\Model\BSONDocument) $c = $c->getArrayCopy();
            if (is_array($c)) {
                $companyName = (string)($c['name'] ?? $companyName);
                $companyCode = (string)($c['code'] ?? $companyCode);
            }
        } catch (Throwable $e) {}

        // ✅ period display label
        $periodLabel = $periodOid;
        try {
            $p = PERIOD01Repository::getById($periodOid);
            if ($p) $periodLabel = (string)($p['title'] ?? ($p['period_id'] ?? $periodLabel));
        } catch (Throwable $e) {}

        $_SESSION['context'] = [
            'UDEF01_id'     => (string)$user['_id'],
            'username'      => (string)($user['username'] ?? $username),
            'CDEF01_id'     => $companyId,
            'company_name'  => $companyName,
            'company_code'  => $companyCode,

            // ✅ artık period_id yerine referans
            'PERIOD01T_id'  => $periodOid,
            'period_label'  => $periodLabel,

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
