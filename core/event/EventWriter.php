<?php
/**
 * core/event/EventWriter.php
 *
 * EVENT STANDARD (V1 - FINAL)
 * - event_code
 * - created_at
 * - context
 * - target
 * - refs: log_id / snapshot_id / prev_snapshot_id / request_id
 * - data: event'e özel payload (summary dahil burada)
 */

use MongoDB\BSON\UTCDateTime;

final class EventWriter
{
    public static function emit(
        string $eventCode,
        array $data = [],
        array $target = [],
        array $ctxOverride = [],
        array $refs = []
    ): string {
        $context = self::resolveContext();
        if (!empty($ctxOverride)) {
            $context = array_merge($context, $ctxOverride);
            $context = self::normalizeContext($context);
        }

        $target = self::normalizeTarget($target);

        // request_id otomatik
        $refs = array_merge(['request_id' => self::requestId()], $refs);
        // refs içindeki null temizle
        $refs = self::cleanNulls($refs);

        // refs içine summary koyma (kaza ile geldiyse sil)
        if (isset($refs['summary'])) unset($refs['summary']);
        if (isset($refs['diff'])) unset($refs['diff']);

        $doc = [
            'event_code'  => $eventCode,
            'created_at'  => new UTCDateTime(),
            'context'     => $context,
            'target'      => $target ?: null,
            'refs'        => !empty($refs) ? $refs : null,
            'data'        => $data ?: null,
        ];

        $res = MongoManager::collection('EVENT01E')->insertOne($doc);
        return (string)$res->getInsertedId();
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

    private static function cleanNulls(array $a): array
    {
        foreach ($a as $k => $v) {
            if ($v === null) unset($a[$k]);
        }
        return $a;
    }
}
