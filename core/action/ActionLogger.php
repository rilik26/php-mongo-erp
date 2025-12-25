<?php
/**
 * core/action/ActionLogger.php
 *
 * LOG STANDARD (V1)
 * - action_code: string (örn AUTH.LOGIN, PERM.DENY, I18N.ADMIN.VIEW)
 * - result: success|fail|deny|info
 * - context: user/company/period/facility/session
 * - target: doc target (module/doc_type/doc_id/doc_no/doc_date)
 * - meta: client info (ip, user_agent, request_id)
 * - payload: any extra details
 *
 * Geriye uyumluluk:
 * - Üst seviyede UDEF01_id, username, CDEF01_id, period_id, role, session_id alanları da tutulur.
 */

use MongoDB\BSON\UTCDateTime;

final class ActionLogger
{
    /**
     * Ana log metodu
     * @return string insertedId
     */
    public static function log(
        string $actionCode,
        array $payload = [],
        array $overrideContext = [],
        array $target = [],
        string $result = 'info',
        array $metaOverride = []
    ): string {
        // Context topla (Context::get veya session fallback)
        $context = self::resolveContext();

        // Override (login/fail/deny gibi durumlar)
        if (!empty($overrideContext)) {
            $context = array_merge($context, $overrideContext);

            // override sonrası tekrar whitelist uygula
            $context = self::normalizeContext($context);
        }

        // Meta (client)
        $meta = [
            'ip'         => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'request_id' => self::requestId(),
        ];
        if (!empty($metaOverride)) {
            $meta = array_merge($meta, $metaOverride);
        }

        // Target normalize
        $target = self::normalizeTarget($target);

        $doc = [
            // Standard
            'action_code' => $actionCode,
            'result'      => $result,
            'created_at'  => new UTCDateTime(),

            'context'     => $context,
            'target'      => $target ?: null,
            'meta'        => $meta,

            'payload'     => $payload,

            // Backward compatible flat fields
            'UDEF01_id'   => $context['UDEF01_id'] ?? null,
            'username'    => $context['username'] ?? null,
            'CDEF01_id'   => $context['CDEF01_id'] ?? null,
            'period_id'   => $context['period_id'] ?? null,
            'facility_id' => $context['facility_id'] ?? null,
            'role'        => $context['role'] ?? null,
            'session_id'  => $context['session_id'] ?? null,

            // eski alanlar (istersen kalsın)
            'ip'          => $meta['ip'] ?? null,
            'user_agent'  => $meta['user_agent'] ?? null,
        ];

        $res = MongoManager::collection('UACT01E')->insertOne($doc);
        return (string)$res->getInsertedId();
    }

    /**
     * Kısa kullanım yardımcıları
     */
    public static function info(string $actionCode, array $payload = [], array $ctx = [], array $target = []): string
    {
        return self::log($actionCode, $payload, $ctx, $target, 'info');
    }

    public static function success(string $actionCode, array $payload = [], array $ctx = [], array $target = []): string
    {
        return self::log($actionCode, $payload, $ctx, $target, 'success');
    }

    public static function fail(string $actionCode, array $payload = [], array $ctx = [], array $target = []): string
    {
        return self::log($actionCode, $payload, $ctx, $target, 'fail');
    }

    public static function deny(string $actionCode, array $payload = [], array $ctx = [], array $target = []): string
    {
        return self::log($actionCode, $payload, $ctx, $target, 'deny');
    }

    /**
     * Context çözümleme: Context sınıfı varsa kullan, yoksa session context
     */
    private static function resolveContext(): array
    {
        $ctx = [];

        if (class_exists('Context')) {
            try {
                $ctx = Context::get();
            } catch (Throwable $e) {
                $ctx = [];
            }
        }

        if (empty($ctx) && isset($_SESSION['context']) && is_array($_SESSION['context'])) {
            $ctx = $_SESSION['context'];
        }

        return self::normalizeContext($ctx);
    }

    private static function normalizeContext(array $ctx): array
    {
        return [
            'UDEF01_id'   => $ctx['UDEF01_id'] ?? null,
            'username'    => $ctx['username'] ?? null,
            'CDEF01_id'   => $ctx['CDEF01_id'] ?? null,
            'period_id'   => $ctx['period_id'] ?? null,
            'facility_id' => $ctx['facility_id'] ?? null,
            'role'        => $ctx['role'] ?? null,
            'session_id'  => $ctx['session_id'] ?? session_id(),
        ];
    }

    /**
     * Target normalize
     */
    private static function normalizeTarget(array $t): array
    {
        if (empty($t)) return [];

        $out = [];
        if (isset($t['module']))   $out['module']   = (string)$t['module'];
        if (isset($t['doc_type'])) $out['doc_type'] = (string)$t['doc_type'];
        if (isset($t['doc_id']))   $out['doc_id']   = (string)$t['doc_id'];
        if (isset($t['doc_no']))   $out['doc_no']   = (string)$t['doc_no'];
        if (isset($t['doc_date'])) $out['doc_date'] = $t['doc_date'];

        return $out;
    }

    /**
     * request_id üretimi (V1): aynı request içinde aynı kalsın
     */
    private static function requestId(): string
    {
        if (!empty($_SERVER['HTTP_X_REQUEST_ID'])) {
            return (string)$_SERVER['HTTP_X_REQUEST_ID'];
        }

        static $rid = null;
        if ($rid) return $rid;

        $rid = bin2hex(random_bytes(8));
        return $rid;
    }
}
