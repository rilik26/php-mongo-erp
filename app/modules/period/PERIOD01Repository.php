<?php
/**
 * app/modules/period/PERIOD01Repository.php (FINAL)
 *
 * MODEL (PERIOD01T):
 * - _id      : ObjectId
 * - CDEF01_id: string (firma _id, 24 char)
 * - period_id: "2025"
 * - title    : "2025 Dönemi"
 * - is_open  : true/false
 *
 * DÖNÜŞ (UI):
 * - period_oid (string) ✅ select value olacak
 * - period_id
 * - title
 * - is_open
 */

final class PERIOD01Repository
{
    public static function listAllPeriods(string $companyId): array
    {
        $cursor = MongoManager::collection('PERIOD01T')->find(
            ['CDEF01_id' => $companyId],
            ['sort' => ['period_id' => 1]]
        );

        return self::normalizeCursor($cursor);
    }

    public static function listOpenPeriods(string $companyId): array
    {
        $cursor = MongoManager::collection('PERIOD01T')->find(
            ['CDEF01_id' => $companyId, 'is_open' => true],
            ['sort' => ['period_id' => 1]]
        );

        return self::normalizeCursor($cursor);
    }

    // ✅ LOGIN/CHANGE_PERIOD güvenliği: _id bazlı kontrol
    public static function isOpenById(string $periodOid, string $companyId): bool
    {
        if ($periodOid === '' || strlen($periodOid) !== 24) return false;

        $doc = MongoManager::collection('PERIOD01T')->findOne([
            '_id'      => new MongoDB\BSON\ObjectId($periodOid),
            'CDEF01_id'=> $companyId,
            'is_open'  => true
        ]);

        return (bool)$doc;
    }

    // ✅ Label için
    public static function getById(string $periodOid): ?array
    {
        if ($periodOid === '' || strlen($periodOid) !== 24) return null;

        $doc = MongoManager::collection('PERIOD01T')->findOne([
            '_id' => new MongoDB\BSON\ObjectId($periodOid)
        ]);

        if (!$doc) return null;
        if ($doc instanceof MongoDB\Model\BSONDocument) $doc = $doc->getArrayCopy();
        return is_array($doc) ? $doc : null;
    }

    private static function normalizeCursor($cursor): array
    {
        $out = [];

        foreach ($cursor as $doc) {
            if ($doc instanceof MongoDB\Model\BSONDocument) $doc = $doc->getArrayCopy();
            if (!is_array($doc)) continue;

            $oid = '';
            try {
                if (isset($doc['_id']) && $doc['_id'] instanceof MongoDB\BSON\ObjectId) {
                    $oid = (string)$doc['_id'];
                } else {
                    $oid = (string)($doc['_id'] ?? '');
                }
            } catch (Throwable $e) {}

            $out[] = [
                'period_oid' => $oid, // ✅ select value
                'period_id'  => (string)($doc['period_id'] ?? ''),
                'title'      => (string)($doc['title'] ?? ($doc['period_id'] ?? '')),
                'is_open'    => (bool)($doc['is_open'] ?? false),
            ];
        }

        return $out;
    }
}
