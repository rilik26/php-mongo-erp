<?php
/**
 * core/snapshot/SnapshotRepository.php
 *
 * Basit snapshot okuma helper’ları (V1)
 */

use MongoDB\Model\BSONDocument;

final class SnapshotRepository
{
    /**
     * BSONDocument -> array normalize
     */
    private static function toArray($doc): ?array
    {
        if (!$doc) return null;

        // findOne BSONDocument döner
        if ($doc instanceof BSONDocument) {
            $doc = $doc->getArrayCopy();
        }

        // nested BSONDocument varsa (data/target/context) onları da array'a çevir
        foreach (['target','context','data','summary','meta','refs'] as $k) {
            if (isset($doc[$k]) && $doc[$k] instanceof BSONDocument) {
                $doc[$k] = $doc[$k]->getArrayCopy();
            }
        }

        // _id string olsun
        if (isset($doc['_id'])) {
            $doc['_id'] = (string)$doc['_id'];
        }

        // prev_snapshot_id string olsun
        if (isset($doc['prev_snapshot_id']) && $doc['prev_snapshot_id']) {
            $doc['prev_snapshot_id'] = (string)$doc['prev_snapshot_id'];
        }

        return $doc;
    }

    public static function findById(string $id): ?array
    {
        try {
            $oid = new MongoDB\BSON\ObjectId($id);
        } catch (Throwable $e) {
            return null;
        }

        $doc = MongoManager::collection('SNAP01E')->findOne(['_id' => $oid]);
        return self::toArray($doc);
    }

    /**
     * ✅ Sende patlayan metod bu: lang_admin.php bunu çağırıyor
     */
    public static function findByTargetKeyAndVersion(string $targetKey, int $version): ?array
    {
        $doc = MongoManager::collection('SNAP01E')->findOne([
            'target_key' => $targetKey,
            'version'    => (int)$version
        ]);

        return self::toArray($doc);
    }

    public static function findLatestByTargetKey(string $targetKey): ?array
    {
        $doc = MongoManager::collection('SNAP01E')->findOne(
            ['target_key' => $targetKey],
            ['sort' => ['version' => -1]]
        );

        return self::toArray($doc);
    }

    public static function findPrevByTargetKey(string $targetKey, int $currentVersion): ?array
    {
        $doc = MongoManager::collection('SNAP01E')->findOne(
            [
                'target_key' => $targetKey,
                'version'    => (int)$currentVersion - 1
            ],
            ['sort' => ['version' => -1]]
        );

        return self::toArray($doc);
    }

    /**
     * Liste: zincir (en yeni -> eski)
     */
    public static function listChain(string $targetKey, int $limit = 50): array
    {
        $cur = MongoManager::collection('SNAP01E')->find(
            ['target_key' => $targetKey],
            ['sort' => ['version' => -1], 'limit' => (int)$limit]
        );

        $out = [];
        foreach ($cur as $doc) {
            $a = self::toArray($doc);
            if ($a) $out[] = $a;
        }
        return $out;
    }
}
