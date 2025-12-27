<?php
/**
 * app/modules/gendoc/GENDOC01TRepository.php (FINAL)
 *
 * GENDOC Body Versions
 * - unique: (target_key, version)
 * - insertVersion: insertOne ile yazar (upsert değil!)
 * - latestByTargetKey: son versiyonu getirir
 */

final class GENDOC01TRepository
{
    public static function collectionName(): string { return 'GENDOC01T'; }

    public static function latestByTargetKey(string $targetKey): ?array
    {
        if ($targetKey === '') return null;

        $doc = MongoManager::collection(self::collectionName())->findOne(
            ['target_key' => $targetKey],
            ['sort' => ['version' => -1]]
        );

        if (!$doc) return null;
        if ($doc instanceof MongoDB\Model\BSONDocument) $doc = $doc->getArrayCopy();
        return $doc;
    }

    /**
     * Version insert (V1→V2→V3)
     * $metaTarget: module/doc_type/doc_id + doc_no/doc_title/status
     */
    public static function insertVersion(string $targetKey, int $version, array $body, array $ctx, array $metaTarget = []): array
    {
        if ($targetKey === '') throw new InvalidArgumentException('target_key required');
        if ($version <= 0) throw new InvalidArgumentException('version must be >= 1');

        $now = new MongoDB\BSON\UTCDateTime((int)floor(microtime(true) * 1000));

        $doc = [
            'target_key' => $targetKey,
            'version'    => $version,
            'body'       => $body,
            'context'    => [
                'CDEF01_id'   => $ctx['CDEF01_id'] ?? null,
                'period_id'   => $ctx['period_id'] ?? null,
                'facility_id' => $ctx['facility_id'] ?? null,
                'UDEF01_id'   => $ctx['UDEF01_id'] ?? null,
                'username'    => $ctx['username'] ?? null,
                'role'        => $ctx['role'] ?? null,
                'session_id'  => $ctx['session_id'] ?? session_id(),
                'ip'          => $ctx['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? null),
                'user_agent'  => $ctx['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? null),
            ],
            'target' => [
                'module'    => $metaTarget['module'] ?? null,
                'doc_type'  => $metaTarget['doc_type'] ?? null,
                'doc_id'    => $metaTarget['doc_id'] ?? null,
                'doc_no'    => $metaTarget['doc_no'] ?? null,
                'doc_title' => $metaTarget['doc_title'] ?? null,
                'status'    => $metaTarget['status'] ?? null,
            ],
            'created_at' => $now,
        ];

        $res = MongoManager::collection(self::collectionName())->insertOne($doc);

        return [
            'ok' => true,
            'inserted_id' => (string)$res->getInsertedId(),
            'version' => $version,
        ];
    }
}
