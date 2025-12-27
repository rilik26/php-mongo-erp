<?php
/**
 * app/modules/gendoc/GENDOC01ERepository.php (FINAL)
 *
 * GENDOC Header Store
 * - unique: (context.CDEF01_id, context.period_id, context.facility_id, target.module, target.doc_type, target.doc_id)
 * - upsertHeader: insertOne değil, updateOne(upsert) / findOneAndUpdate ile çalışır
 * - nextVersion: header doc üzerinde atomic $inc ile version üretir (V1→V2→V3)
 */

final class GENDOC01ERepository
{
    public static function collectionName(): string { return 'GENDOC01E'; }

    private static function ctxParts(array $ctx): array
    {
        return [
            'CDEF01_id'   => $ctx['CDEF01_id'] ?? null,
            'period_id'   => $ctx['period_id'] ?? null,
            'facility_id' => $ctx['facility_id'] ?? null,
        ];
    }

    public static function buildTargetKey(array $target, array $ctx): string
    {
        $module  = (string)($target['module'] ?? '');
        $docType = (string)($target['doc_type'] ?? '');
        $docId   = (string)($target['doc_id'] ?? '');

        if ($module === '' || $docType === '' || $docId === '') {
            throw new InvalidArgumentException('target.module/doc_type/doc_id required');
        }

        $c = self::ctxParts($ctx);

        // null’ları stringe çevir (senin sistemde bu target_key standardı vardı)
        $cdef = $c['CDEF01_id']   !== null ? (string)$c['CDEF01_id'] : 'null';
        $per  = $c['period_id']   !== null ? (string)$c['period_id'] : 'null';
        $fac  = $c['facility_id'] !== null ? (string)$c['facility_id'] : 'null';

        return $module . '|' . $docType . '|' . $docId . '|' . $cdef . '|' . $per . '|' . $fac;
    }

    public static function findByTarget(array $target, array $ctx): ?array
    {
        $module  = (string)($target['module'] ?? '');
        $docType = (string)($target['doc_type'] ?? '');
        $docId   = (string)($target['doc_id'] ?? '');

        if ($module === '' || $docType === '' || $docId === '') return null;

        $c = self::ctxParts($ctx);

        $doc = MongoManager::collection(self::collectionName())->findOne([
            'context.CDEF01_id'   => $c['CDEF01_id'],
            'context.period_id'   => $c['period_id'],
            'context.facility_id' => $c['facility_id'],
            'target.module'       => $module,
            'target.doc_type'     => $docType,
            'target.doc_id'       => $docId,
        ]);

        if (!$doc) return null;
        if ($doc instanceof MongoDB\Model\BSONDocument) $doc = $doc->getArrayCopy();
        return $doc;
    }

    /**
     * Header upsert.
     * $targetMeta: module/doc_type/doc_id + optional doc_no/doc_title/status
     * $header: UI header (doc_no/title/status)
     */
    public static function upsertHeader(array $targetMeta, array $header, array $ctx): array
    {
        $module  = trim((string)($targetMeta['module'] ?? ''));
        $docType = trim((string)($targetMeta['doc_type'] ?? ''));
        $docId   = trim((string)($targetMeta['doc_id'] ?? ''));

        if ($module === '' || $docType === '' || $docId === '') {
            throw new InvalidArgumentException('module/doc_type/doc_id required');
        }

        $c = self::ctxParts($ctx);

        $target = [
            'module'   => $module,
            'doc_type' => $docType,
            'doc_id'   => $docId,
        ];

        $targetKey = self::buildTargetKey($target, $ctx);
        $now = new MongoDB\BSON\UTCDateTime((int)floor(microtime(true) * 1000));

        $filter = [
            'context.CDEF01_id'   => $c['CDEF01_id'],
            'context.period_id'   => $c['period_id'],
            'context.facility_id' => $c['facility_id'],
            'target.module'       => $module,
            'target.doc_type'     => $docType,
            'target.doc_id'       => $docId,
        ];

        $set = [
            'target_key' => $targetKey,
            'target' => [
                'module'    => $module,
                'doc_type'  => $docType,
                'doc_id'    => $docId,
                'doc_no'    => $targetMeta['doc_no'] ?? null,
                'doc_title' => $targetMeta['doc_title'] ?? null,
                'status'    => $targetMeta['status'] ?? null,
            ],
            'header' => [
                'doc_no' => $header['doc_no'] ?? null,
                'title'  => $header['title'] ?? null,
                'status' => $header['status'] ?? null,
            ],
            'updated_at' => $now,
        ];

        $setOnInsert = [
            'context' => [
                'CDEF01_id'   => $c['CDEF01_id'],
                'period_id'   => $c['period_id'],
                'facility_id' => $c['facility_id'],
            ],
            'created_at' => $now,
            'version' => 0, // version counter (atomic inc ile)
        ];

        $res = MongoManager::collection(self::collectionName())->findOneAndUpdate(
            $filter,
            [
                '$set' => $set,
                '$setOnInsert' => $setOnInsert,
            ],
            [
                'upsert' => true,
                'returnDocument' => MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER,
            ]
        );

        if ($res instanceof MongoDB\Model\BSONDocument) $res = $res->getArrayCopy();

        return [
            'ok' => true,
            'target_key' => $targetKey,
            'doc' => $res,
        ];
    }

    /**
     * Header doc üstünde atomic versiyon üretir.
     * - lock olsa bile race riskini sıfırlamak için en sağlam yöntem bu.
     */
    public static function nextVersion(string $targetKey, array $ctx): int
    {
        if ($targetKey === '') throw new InvalidArgumentException('target_key required');

        $c = self::ctxParts($ctx);

        $filter = [
            'target_key'          => $targetKey,
            'context.CDEF01_id'   => $c['CDEF01_id'],
            'context.period_id'   => $c['period_id'],
            'context.facility_id' => $c['facility_id'],
        ];

        $now = new MongoDB\BSON\UTCDateTime((int)floor(microtime(true) * 1000));

        $doc = MongoManager::collection(self::collectionName())->findOneAndUpdate(
            $filter,
            [
                '$inc' => ['version' => 1],
                '$set' => ['updated_at' => $now],
                '$setOnInsert' => [
                    'created_at' => $now,
                    'version' => 1,
                ],
            ],
            [
                'upsert' => true,
                'returnDocument' => MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER,
                'projection' => ['version' => 1],
            ]
        );

        if ($doc instanceof MongoDB\Model\BSONDocument) $doc = $doc->getArrayCopy();
        $v = (int)($doc['version'] ?? 0);
        return $v > 0 ? $v : 1;
    }
}
