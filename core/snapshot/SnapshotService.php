<?php
/**
 * core/snapshot/SnapshotService.php (FINAL)
 *
 * Koleksiyon: SNAP01E
 * Unique index varsayımı: { target_key: 1, version: 1 } unique
 *
 * - target_key ASLA null olmaz.
 * - version sayı (int) gibi gider.
 * - prev_snapshot_id döner.
 */

final class SnapshotService
{
    private static function nowMs(): int {
        return (int) floor(microtime(true) * 1000);
    }

    private static function ctx_min(array $ctx): array {
        return [
            'UDEF01_id'    => (string)($ctx['UDEF01_id'] ?? ''),
            'username'     => (string)($ctx['username'] ?? ''),
            'CDEF01_id'    => (string)($ctx['CDEF01_id'] ?? ''),
            'PERIOD01T_id' => (string)($ctx['PERIOD01T_id'] ?? ($ctx['period_id'] ?? '')),
            'role'         => (string)($ctx['role'] ?? ''),
            'session_id'   => (string)($ctx['session_id'] ?? session_id()),
        ];
    }

    public static function targetKey(string $module, string $docType, string $docId, array $ctx): string
    {
        $module  = strtolower(trim($module));
        $docType = strtoupper(trim($docType));
        $docId   = trim($docId);

        $c = (string)($ctx['CDEF01_id'] ?? '');
        $p = (string)($ctx['PERIOD01T_id'] ?? ($ctx['period_id'] ?? ''));

        // target_key ASLA boş olmasın
        return strtoupper($module) . '|' . $docType . '|' . $docId . '|' . $c . '|' . $p;
    }

    /**
     * Snapshot create
     * @return array ['snapshot_id'=>..., 'prev_snapshot_id'=>..., 'version'=>int, 'target_key'=>...]
     */
    public static function create(
        string $module,
        string $docType,
        string $docId,
        ?string $docNo,
        ?string $docTitle,
        ?string $status,
        array $ctx,
        array $payload
    ): array {
        $ctxMin = self::ctx_min($ctx);

        $docType = strtoupper(trim($docType));
        $module  = strtolower(trim($module));
        $docId   = trim($docId);

        if ($module === '' || $docType === '' || $docId === '') {
            throw new RuntimeException('snapshot_target_required');
        }

        $tkey = self::targetKey($module, $docType, $docId, $ctxMin);
        if ($tkey === '' || str_contains($tkey, '||')) {
            throw new RuntimeException('snapshot_target_key_invalid');
        }

        $col = MongoManager::collection('SNAP01E');

        // last snapshot
        $last = $col->findOne(['target_key' => $tkey], ['sort' => ['version' => -1]]);
        if ($last instanceof MongoDB\Model\BSONDocument) $last = $last->getArrayCopy();

        $prevId = null;
        $nextVersion = 1;

        if (is_array($last) && !empty($last)) {
            $prevId = (string)($last['_id'] ?? '');
            $v = $last['version'] ?? 0;
            $nextVersion = (int)$v + 1;
        }

        $nowMs = self::nowMs();

        $ins = [
            'target_key' => $tkey,
            'version'    => $nextVersion, // int
            'created_at' => new MongoDB\BSON\UTCDateTime($nowMs),

            'context'    => $ctxMin,

            'target' => [
                'module'    => $module,
                'doc_type'  => $docType,
                'doc_id'    => $docId,
                'doc_no'    => $docNo ?: null,
                'doc_title' => $docTitle ?: null,
                'status'    => $status ?: null,
            ],

            'refs' => [
                'prev_snapshot_id' => $prevId ?: null,
            ],

            'payload' => $payload,
        ];

        $r = $col->insertOne($ins);

        return [
            'snapshot_id'      => (string)$r->getInsertedId(),
            'prev_snapshot_id' => $prevId,
            'version'          => $nextVersion,
            'target_key'       => $tkey,
        ];
    }
}
