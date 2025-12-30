<?php
/**
 * PERIOD01Repository.php (FINAL)
 *
 * - Firma bazlı PERIOD01T
 * - CDEF01_id alanı bazen string bazen ObjectId olabildiği için
 *   sorgular hem string hem ObjectId destekler.
 */

class PERIOD01Repository
{
    private static function companyFilter(string $companyId): array
    {
        $companyId = trim($companyId);

        // hem string hem ObjectId ile eşleştir
        $or = [
            ['CDEF01_id' => $companyId],
        ];

        if (strlen($companyId) === 24) {
            try {
                $or[] = ['CDEF01_id' => new MongoDB\BSON\ObjectId($companyId)];
            } catch (Throwable $e) {}
        }

        return ['$or' => $or];
    }

    public static function listOpenPeriods(string $companyId): array
    {
        $filter = self::companyFilter($companyId);
        $filter['is_open'] = true;

        $cursor = MongoManager::collection('PERIOD01T')->find(
            $filter,
            ['sort' => ['period_id' => 1]]
        );

        return self::normalizeCursor($cursor);
    }

    public static function listAllPeriods(string $companyId): array
    {
        $filter = self::companyFilter($companyId);

        $cursor = MongoManager::collection('PERIOD01T')->find(
            $filter,
            ['sort' => ['period_id' => 1]]
        );

        return self::normalizeCursor($cursor);
    }

    public static function isOpen(string $periodId, string $companyId): bool
    {
        $periodId = trim($periodId);
        if ($periodId === '') return false;

        $filter = self::companyFilter($companyId);
        $filter['period_id'] = $periodId;
        $filter['is_open'] = true;

        $doc = MongoManager::collection('PERIOD01T')->findOne($filter);

        return (bool)$doc;
    }

    private static function normalizeCursor($cursor): array
    {
        $out = [];

        foreach ($cursor as $doc) {
            if ($doc instanceof MongoDB\Model\BSONDocument) $doc = $doc->getArrayCopy();
            $arr = is_array($doc) ? $doc : (array)$doc;

            $out[] = [
                'period_id' => (string)($arr['period_id'] ?? ''),
                'title'     => (string)($arr['title'] ?? ($arr['period_id'] ?? '')),
                'is_open'   => (bool)($arr['is_open'] ?? false),
            ];
        }

        return $out;
    }
}
