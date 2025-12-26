<?php
/**
 * core/snapshot/SnapshotRepository.php
 */

final class SnapshotRepository
{
    public static function findById(string $id): ?array
    {
        try {
            $oid = new MongoDB\BSON\ObjectId($id);
        } catch (Throwable $e) {
            return null;
        }

        $doc = MongoManager::collection('SNAP01E')->findOne(['_id' => $oid]);
        return $doc ? $doc->getArrayCopy() : null;
    }

    public static function findLatestByTargetKey(string $targetKey): ?array
    {
        $doc = MongoManager::collection('SNAP01E')->findOne(
            ['target_key' => $targetKey],
            ['sort' => ['version' => -1]]
        );
        return $doc ? $doc->getArrayCopy() : null;
    }

    public static function findByTargetKeyAndVersion(string $targetKey, int $version): ?array
    {
        $doc = MongoManager::collection('SNAP01E')->findOne([
            'target_key' => $targetKey,
            'version'    => (int)$version
        ]);
        return $doc ? $doc->getArrayCopy() : null;
    }

    public static function findPrevOf(array $snapshot): ?array
    {
        $prevId = $snapshot['prev_snapshot_id'] ?? null;
        if (!$prevId) return null;
        return self::findById((string)$prevId);
    }

    public static function listByTargetKey(string $targetKey, int $limit = 200): array
    {
        if ($limit < 10) $limit = 10;
        if ($limit > 2000) $limit = 2000;

        $cur = MongoManager::collection('SNAP01E')->find(
            ['target_key' => $targetKey],
            [
                'sort' => ['version' => 1],
                'limit' => $limit,
                'projection' => ['data' => 0]
            ]
        );

        $out = [];
        foreach ($cur as $d) $out[] = $d->getArrayCopy();
        return $out;
    }
}
