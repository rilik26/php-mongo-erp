<?php
/**
 * app/modules/gendoc/GENDOC01TRepository.php
 *
 * Body / Payload için.
 * İster versionlı tut, ister latest-only. Biz versionlı tutuyoruz.
 */

require_once __DIR__ . '/../../../core/doc/TargetKey.php';

final class GENDOC01TRepository
{
    public static function insertVersion(array $ctx, array $target, array $data): array
    {
        $targetKey = TargetKey::build($target, $ctx);

        $latest = MongoManager::collection('GENDOC01T')->findOne(
            ['target_key' => $targetKey],
            ['sort' => ['version' => -1], 'projection' => ['version' => 1]]
        );

        $nextVer = 1;
        if ($latest && isset($latest['version'])) $nextVer = ((int)$latest['version']) + 1;

        $now = new MongoDB\BSON\UTCDateTime();

        $doc = [
            'target_key' => $targetKey,
            'target' => [
                'module' => (string)($target['module'] ?? ''),
                'doc_type' => (string)($target['doc_type'] ?? ''),
                'doc_id' => (string)($target['doc_id'] ?? ''),
                'doc_no' => $target['doc_no'] ?? null,
                'doc_title' => $target['doc_title'] ?? null,
            ],
            'context' => [
                'CDEF01_id' => $ctx['CDEF01_id'] ?? null,
                'period_id' => $ctx['period_id'] ?? null,
                'facility_id' => $ctx['facility_id'] ?? null,
                'UDEF01_id' => $ctx['UDEF01_id'] ?? null,
                'username' => $ctx['username'] ?? null,
                'session_id' => $ctx['session_id'] ?? session_id(),
            ],
            'version' => $nextVer,
            'created_at' => $now,
            'data' => $data,
        ];

        $res = MongoManager::collection('GENDOC01T')->insertOne($doc);

        return [
            'ok' => true,
            'id' => (string)$res->getInsertedId(),
            'target_key' => $targetKey,
            'version' => $nextVer,
        ];
    }

    public static function findLatestByTarget(array $ctx, array $target): ?array
    {
        $targetKey = TargetKey::build($target, $ctx);

        $doc = MongoManager::collection('GENDOC01T')->findOne(
            ['target_key' => $targetKey],
            ['sort' => ['version' => -1]]
        );

        if (!$doc) return null;
        if ($doc instanceof MongoDB\Model\BSONDocument) $doc = $doc->getArrayCopy();
        return $doc;
    }
}
