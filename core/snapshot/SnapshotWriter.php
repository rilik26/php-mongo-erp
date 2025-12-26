<?php
/**
 * core/snapshot/SnapshotWriter.php
 *
 * SNAPSHOT STANDARD (V1)
 * - SNAP01E: evrakın tam hali (versioned)
 * - SNAPSEQ01E: atomic version counter
 * - hash chain: prev_hash + data => sha256
 *
 * ÖNEMLİ:
 * - Üst seviye module/doc_type/doc_id alanları eklendi (index + query kolaylığı)
 * - prev_snapshot_id alana yazılır
 */

use MongoDB\BSON\UTCDateTime;

final class SnapshotWriter
{
    public static function capture(
        array $target,
        array $data,
        array $summary = [],
        array $ctxOverride = []
    ): array {
        // context
        $ctx = self::resolveContext();
        if (!empty($ctxOverride)) {
            $ctx = array_merge($ctx, $ctxOverride);
            $ctx = self::normalizeContext($ctx);
        }

        // target + key
        $target = self::normalizeTarget($target);
        $targetKey = self::targetKey($target, $ctx);

        // prev snapshot (hash chain)
        $prev = MongoManager::collection('SNAP01E')->findOne(
            ['target_key' => $targetKey],
            [
                'sort' => ['version' => -1],
                'projection' => ['_id' => 1, 'hash' => 1, 'version' => 1]
            ]
        );

        $prevHash = $prev['hash'] ?? null;
        $prevSnapshotId = isset($prev['_id']) ? (string)$prev['_id'] : null;

        // version (atomic)
        $version = self::nextVersion($targetKey);

        // hash
        $hash = self::computeHash($prevHash, $targetKey, $version, $data);

        $doc = [
            'target_key' => $targetKey,
            'target'     => $target,

            // ✅ flat alanlar (index uyumu + hızlı filtre)
            'module'     => $target['module'] ?? null,
            'doc_type'   => $target['doc_type'] ?? null,
            'doc_id'     => $target['doc_id'] ?? null,

            'version'    => (int)$version,

            'hash'       => $hash,
            'prev_hash'  => $prevHash,
            'prev_snapshot_id' => $prevSnapshotId,

            'context'    => $ctx,
            'created_at' => new UTCDateTime(),

            'data'       => $data,
            'summary'    => $summary ?: null,
        ];

        $res = MongoManager::collection('SNAP01E')->insertOne($doc);

        return [
            'snapshot_id'      => (string)$res->getInsertedId(),
            'target_key'       => $targetKey,
            'version'          => (int)$version,
            'hash'             => $hash,
            'prev_hash'        => $prevHash,
            'prev_snapshot_id' => $prevSnapshotId,
        ];
    }

    private static function nextVersion(string $targetKey): int
    {
        $res = MongoManager::collection('SNAPSEQ01E')->findOneAndUpdate(
            ['_id' => $targetKey],
            ['$inc' => ['seq' => 1], '$set' => ['updated_at' => new UTCDateTime()]],
            ['upsert' => true, 'returnDocument' => MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER]
        );

        $seq = $res['seq'] ?? 1;
        return (int)$seq;
    }

    private static function computeHash(?string $prevHash, string $targetKey, int $version, array $data): string
    {
        $payload = [
            'prev_hash'  => $prevHash,
            'target_key' => $targetKey,
            'version'    => $version,
            'data'       => $data,
        ];

        self::ksortRecursive($payload);

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return hash('sha256', $json ?: '');
    }

    private static function ksortRecursive(array &$arr): void
    {
        foreach ($arr as &$v) {
            if (is_array($v)) self::ksortRecursive($v);
        }
        ksort($arr);
    }

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

    private static function normalizeTarget(array $t): array
    {
        $out = [];
        if (isset($t['module']))   $out['module']   = (string)$t['module'];
        if (isset($t['doc_type'])) $out['doc_type'] = (string)$t['doc_type'];
        if (isset($t['doc_id']))   $out['doc_id']   = (string)$t['doc_id'];
        if (isset($t['doc_no']))   $out['doc_no']   = (string)$t['doc_no'];
        if (isset($t['doc_date'])) $out['doc_date'] = $t['doc_date'];
        return $out;
    }

    /**
     * module|doc_type|doc_id|CDEF01_id|period_id|facility_id
     */
    private static function targetKey(array $target, array $ctx): string
    {
        $module   = $target['module'] ?? 'unknown';
        $docType  = $target['doc_type'] ?? 'unknown';
        $docId    = $target['doc_id'] ?? 'unknown';

        $cdef     = $ctx['CDEF01_id'] ?? 'null';
        $period   = $ctx['period_id'] ?? 'null';
        $facility = $ctx['facility_id'] ?? 'null';

        return $module . '|' . $docType . '|' . $docId . '|' . $cdef . '|' . $period . '|' . $facility;
    }
}
