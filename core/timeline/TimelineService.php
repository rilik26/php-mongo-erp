<?php
/**
 * core/timeline/TimelineService.php (FINAL)
 *
 * ✅ Dual-write:
 * - TIMELINE01T (geri uyumluluk)
 * - EVENT01E    (public/timeline.php bununla çalışıyor)
 */

final class TimelineService
{
    /**
     * action: VIEW / SAVE / DELETE / LOCK ...
     * docType: SORD01E gibi
     * docId: 24 char string
     * evrakno: boşsa docId
     *
     * extra:
     *  - title, status, version, snapshot_id, prev_snapshot_id, log_id, request_id ...
     */
    public static function log(
        string $action,
        string $docType,
        string $docId,
        string $evrakno,
        array $ctx,
        array $extra = []
    ): void {
        $now = new MongoDB\BSON\UTCDateTime((int)floor(microtime(true) * 1000));

        $action = strtoupper(trim($action));
        $docType = strtoupper(trim($docType));
        $docId = trim((string)$docId);
        $evrakno = trim((string)$evrakno);
        if ($evrakno === '') $evrakno = $docId;

        // ---- 1) TIMELINE01T (legacy) ----
        MongoManager::collection('TIMELINE01T')->insertOne([
            'action'       => $action,
            'doc_type'     => $docType,
            'doc_id'       => $docId,
            'evrakno'      => $evrakno,
            'username'     => (string)($ctx['username'] ?? ''),
            'session_id'   => (string)($ctx['session_id'] ?? session_id()),
            'CDEF01_id'    => (string)($ctx['CDEF01_id'] ?? ''),
            'PERIOD01T_id' => (string)($ctx['PERIOD01T_id'] ?? ''),
            'created_at'   => $now,
            'extra'        => $extra,
        ]);

        // ---- 2) EVENT01E (public/timeline.php source) ----
        // timeline.php şunları filtreliyor:
        // - context.CDEF01_id
        // - context.period_id (senin context’te PERIOD01T_id var; burada hem period_id hem PERIOD01T_id koyuyoruz)
        // - target.module / target.doc_type / target.doc_id

        $module = (string)($extra['module'] ?? 'salesorder');
        $title  = (string)($extra['title'] ?? ($extra['doc_title'] ?? ''));
        $status = (string)($extra['status'] ?? '');
        $version= $extra['version'] ?? null;

        // Event code standardı (timeline.php map’inde yoksa da görünür, code olarak listeler)
        $eventCode = $extra['event_code'] ?? ('SORD.' . $action);

        $refs = [
            'snapshot_id'       => $extra['snapshot_id'] ?? null,
            'prev_snapshot_id'  => $extra['prev_snapshot_id'] ?? null,
            'log_id'            => $extra['log_id'] ?? null,
            'request_id'        => $extra['request_id'] ?? null,
        ];

        // boş refs’leri temizle
        foreach ($refs as $k => $v) {
            if ($v === null || $v === '') unset($refs[$k]);
        }

        $summary = [
            'doc_no'  => $evrakno,
            'title'   => $title,
            'status'  => $status,
        ];
        if ($version !== null && $version !== '') $summary['version'] = $version;

        MongoManager::collection('EVENT01E')->insertOne([
            'event_code' => (string)$eventCode,
            'created_at' => $now,

            'context' => [
                'username'     => (string)($ctx['username'] ?? ''),
                'UDEF01_id'    => (string)($ctx['UDEF01_id'] ?? ''),
                'CDEF01_id'    => (string)($ctx['CDEF01_id'] ?? ''),
                // timeline.php period_id bekliyor; biz hem period_id hem PERIOD01T_id veriyoruz
                'period_id'    => (string)($ctx['period_id'] ?? ($ctx['PERIOD01T_id'] ?? '')),
                'PERIOD01T_id' => (string)($ctx['PERIOD01T_id'] ?? ''),
                'facility_id'  => (string)($ctx['facility_id'] ?? ''),
                'role'         => (string)($ctx['role'] ?? ''),
                'session_id'   => (string)($ctx['session_id'] ?? session_id()),
            ],

            'target' => [
                'module'    => $module,
                'doc_type'  => $docType,
                'doc_id'    => $docId,
                'doc_no'    => $evrakno,
                'doc_title' => $title,
                'status'    => $status,
            ],

            'refs' => $refs,

            'data' => [
                'summary' => $summary,
                'extra'   => $extra,
            ],
        ]);
    }
}
