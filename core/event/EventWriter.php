<?php
/**
 * core/event/EventWriter.php
 *
 * EVENT STANDARD (V1)
 * - event_code: string (örn DOC.UPDATED, DOC.CREATED, DOC.STATUS_CHANGED)
 * - created_at: UTCDateTime (server time)
 * - context: iş bağlamı (user/company/period/facility/session)
 * - target: hedef evrak (module/doc_type/doc_id/doc_no/doc_date)
 * - refs: log_id / snapshot_id / prev_snapshot_id / request_id
 * - data: event'e özel payload (changed_fields, reason vs.)
 *
 * NOT:
 * - Firma override YOK (global context)
 * - Event "iş olayıdır", audit log değildir. Ama refs.log_id ile bağlanabilir.
 */

use MongoDB\BSON\UTCDateTime;

final class EventWriter
{
    /**
     * Basit emit:
     * Event'i yaz ve event_id döndür.
     *
     * @param string $eventCode   Örn: DOC.UPDATED
     * @param array  $data        Event datası (işsel)
     * @param array  $target      module/doc_type/doc_id/doc_no/doc_date
     * @param array  $ctxOverride Context override (login fail gibi özel durumlar)
     * @param array  $refs        ['log_id'=>..., 'snapshot_id'=>..., 'prev_snapshot_id'=>...]
     */
    public static function emit(
        string $eventCode,
        array $data = [],
        array $target = [],
        array $ctxOverride = [],
        array $refs = []
    ): string {
        // Context (ActionLogger ile aynı prensip)
        $context = self::resolveContext();
        if (!empty($ctxOverride)) {
            $context = array_merge($context, $ctxOverride);
            $context = self::normalizeContext($context);
        }

        // Target
        $target = self::normalizeTarget($target);

        // Refs (request_id otomatik)
        $refs = array_merge([
            'request_id' => self::requestId(),
        ], $refs);

        // null temizliği
        $refs = self::cleanNulls($refs);

        $doc = [
            'event_code'  => $eventCode,
            'created_at'  => new UTCDateTime(),

            'context'     => $context,
            'target'      => $target ?: null,
            'refs'        => !empty($refs) ? $refs : null,

            'data'        => $data,
        ];

        $res = MongoManager::collection('EVENT01E')->insertOne($doc);

        return (string)$res->getInsertedId();
    }

    /**
     * Context çözümleme: Context sınıfı varsa kullan, yoksa session context
     * (ip/user_agent burada yok; onlar log meta'sıydı)
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
     * Target normalize:
     * - module, doc_type, doc_id, doc_no, doc_date
     */
    private static function normalizeTarget(array $t): array
    {
        if (empty($t)) return [];

        $out = [];
        if (isset($t['module']))   $out['module']   = (string)$t['module'];
        if (isset($t['doc_type'])) $out['doc_type'] = (string)$t['doc_type'];
        if (isset($t['doc_id']))   $out['doc_id']   = (string)$t['doc_id'];
        if (isset($t['doc_no']))   $out['doc_no']   = (string)$t['doc_no'];

        // doc_date: "YYYY-MM-DD" veya UTCDateTime olabilir
        if (isset($t['doc_date'])) $out['doc_date'] = $t['doc_date'];

        return $out;
    }

    /**
     * request_id üretimi: aynı request içinde sabit
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

    /**
     * null değerleri temizle (refs/target içinde boşluk olmasın)
     */
    private static function cleanNulls(array $a): array
    {
        foreach ($a as $k => $v) {
            if ($v === null) unset($a[$k]);
        }
        return $a;
    }
}
