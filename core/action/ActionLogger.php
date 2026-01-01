<?php
/**
 * core/action/ActionLogger.php (FINAL)
 *
 * LOG STANDARD (V1)
 * - action_code: string
 * - result: success|fail|deny|info
 * - context: user/company/period/facility/session
 * - target: doc target (module/doc_type/doc_id/doc_no/doc_date)
 * - meta: client info (ip, user_agent, request_id)
 * - payload: any extra details
 *
 * ✅ FINAL:
 * - UACT01E (audit) + EVENT01E (timeline) birlikte yazılır
 * - EVENT01E.refs.log_id = UACT01E insertedId
 */

use MongoDB\BSON\UTCDateTime;

final class ActionLogger
{
    public static function log(
        string $actionCode,
        array $payload = [],
        array $overrideContext = [],
        array $target = [],
        string $result = 'info',
        array $metaOverride = []
    ): string {
        $context = self::resolveContext();

        if (!empty($overrideContext)) {
            $context = array_merge($context, $overrideContext);
            $context = self::normalizeContext($context);
        }

        $meta = [
            'ip'         => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'request_id' => self::requestId(),
        ];
        if (!empty($metaOverride)) {
            $meta = array_merge($meta, $metaOverride);
        }

        $target = self::normalizeTarget($target);

        $now = new UTCDateTime();

        // --- UACT01E (audit) ---
        $doc = [
            'action_code' => $actionCode,
            'result'      => $result,
            'created_at'  => $now,

            'context'     => $context,
            'target'      => $target ?: null,
            'meta'        => $meta,

            'payload'     => $payload,

            // backward compatible flat fields
            'UDEF01_id'   => $context['UDEF01_id'] ?? null,
            'username'    => $context['username'] ?? null,
            'CDEF01_id'   => $context['CDEF01_id'] ?? null,
            'period_id'   => $context['period_id'] ?? null,
            'facility_id' => $context['facility_id'] ?? null,
            'role'        => $context['role'] ?? null,
            'session_id'  => $context['session_id'] ?? null,

            'ip'          => $meta['ip'] ?? null,
            'user_agent'  => $meta['user_agent'] ?? null,
        ];

        $res = MongoManager::collection('UACT01E')->insertOne($doc);
        $logId = (string)$res->getInsertedId();

        // --- EVENT01E (timeline) ---
        // timeline.php bu koleksiyonu okuyor
        try {
            $summary = null;

            // kullanıcı payload içinde summary gönderirse onu aynen kullan
            if (isset($payload['summary']) && is_array($payload['summary'])) {
                $summary = $payload['summary'];
            } else {
                // default summary üret
                $summary = [
                    'result' => $result,
                ];
                if (!empty($target['doc_no']))   $summary['doc_no'] = (string)$target['doc_no'];
                if (!empty($target['doc_id']))   $summary['doc_id'] = (string)$target['doc_id'];
                if (!empty($target['doc_type'])) $summary['doc_type'] = (string)$target['doc_type'];
                if (!empty($target['module']))   $summary['module'] = (string)$target['module'];
            }

            $eventDoc = [
                'event_code' => $actionCode,
                'created_at' => $now,

                'context' => $context,
                'target'  => $target ?: null,

                'refs' => [
                    'log_id'     => $logId,
                    'request_id' => $meta['request_id'] ?? null,
                ],

                // timeline UI: data.summary bekliyor
                'data' => [
                    'summary' => $summary,
                    'payload' => $payload, // debug için; istersen sonra kural koyarız
                ],
            ];

            MongoManager::collection('EVENT01E')->insertOne($eventDoc);
        } catch (Throwable $e) {
            // timeline insert fail olsa da audit log kaydı bozulmasın
        }

        return $logId;
    }

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

    private static function resolveContext(): array
    {
        $ctx = [];

        if (class_exists('Context')) {
            try { $ctx = Context::get(); } catch (Throwable $e) { $ctx = []; }
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

    private static function normalizeTarget(array $t): array
    {
        if (empty($t)) return [];

        $out = [];
        if (isset($t['module']))   $out['module']   = (string)$t['module'];
        if (isset($t['doc_type'])) $out['doc_type'] = (string)$t['doc_type'];
        if (isset($t['doc_id']))   $out['doc_id']   = (string)$t['doc_id'];
        if (isset($t['doc_no']))   $out['doc_no']   = (string)$t['doc_no'];
        if (isset($t['doc_date'])) $out['doc_date'] = $t['doc_date'];
        if (isset($t['doc_title'])) $out['doc_title'] = (string)$t['doc_title']; // ✅ ek fayda
        if (isset($t['status']))    $out['status']    = (string)$t['status'];    // ✅ ek fayda

        return $out;
    }

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
