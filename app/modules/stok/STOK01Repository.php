<?php
/**
 * app/modules/stok/STOK01Repository.php (FINAL)
 *
 * STOK01E - Stok Kartı
 *
 * Mongo alanları:
 * - kod   (benzersiz)
 * - name
 * - name2 (yeni)
 * - unit
 * - is_active (aktif/pasif)
 *
 * Geriye uyum:
 * - eski alanlar (stok_kodu/stok_adi/birim) varsa okumada destekler
 * - benzersizlik kontrolünde hem kod hem stok_kodu taranır
 */

final class STOK01Repository
{
    private static function col() {
        return MongoManager::collection('STOK01E');
    }

    private static function nowUtc(): MongoDB\BSON\UTCDateTime {
        return new MongoDB\BSON\UTCDateTime((int) floor(microtime(true) * 1000));
    }

    private static function oid(string $id): MongoDB\BSON\ObjectId {
        return new MongoDB\BSON\ObjectId($id);
    }

    private static function tenantFilter(array $ctx): array
    {
        $cdef   = (string)($ctx['CDEF01_id'] ?? '');
        $period = (string)($ctx['PERIOD01T_id'] ?? ($ctx['period_id'] ?? ''));

        return [
            'CDEF01_id'    => $cdef,
            'PERIOD01T_id' => $period,
        ];
    }

    private static function pickCode($doc): string
    {
        $kod = trim((string)($doc['kod'] ?? ''));
        if ($kod !== '') return $kod;
        return trim((string)($doc['stok_kodu'] ?? '')); // legacy
    }

    private static function pickName($doc): string
    {
        $name = trim((string)($doc['name'] ?? ''));
        if ($name !== '') return $name;
        return trim((string)($doc['stok_adi'] ?? '')); // legacy
    }

    private static function pickUnit($doc): string
    {
        $unit = trim((string)($doc['unit'] ?? ''));
        if ($unit !== '') return $unit;
        return trim((string)($doc['birim'] ?? '')); // legacy
    }

    public static function dumpFull(string $id): array
    {
        if ($id === '' || strlen($id) !== 24) return [];
        $doc = self::col()->findOne(['_id' => self::oid($id)]);
        if (!$doc) return [];
        if ($doc instanceof MongoDB\Model\BSONDocument) $doc = $doc->getArrayCopy();
        $doc['_id'] = (string)($doc['_id'] ?? '');
        return (array)$doc;
    }

    public static function listByContext(array $ctx, int $limit = 200): array
    {
        $filter = self::tenantFilter($ctx);

        $cur = self::col()->find(
            $filter,
            ['sort' => ['updated_at' => -1, 'created_at' => -1], 'limit' => $limit]
        );

        $out = [];
        foreach ($cur as $r) {
            if ($r instanceof MongoDB\Model\BSONDocument) $r = $r->getArrayCopy();

            $out[] = [
                '_id'      => (string)($r['_id'] ?? ''),
                'kod'      => self::pickCode($r),
                'name'     => self::pickName($r),
                'name2'    => (string)($r['name2'] ?? ''), // yeni
                'unit'     => self::pickUnit($r),
                'is_active'=> (bool)($r['is_active'] ?? true),
                'version'  => (int)($r['version'] ?? 1),
            ];
        }
        return $out;
    }

    public static function findByCode(string $kod, array $ctx): ?array
    {
        $kod = trim((string)$kod);
        if ($kod === '') return null;

        $tenant = self::tenantFilter($ctx);

        // hem yeni hem legacy alanı arıyoruz
        $filter = array_merge($tenant, [
            '$or' => [
                ['kod' => $kod],
                ['stok_kodu' => $kod],
            ]
        ]);

        $doc = self::col()->findOne($filter);
        if (!$doc) return null;
        if ($doc instanceof MongoDB\Model\BSONDocument) $doc = $doc->getArrayCopy();
        $doc['_id'] = (string)($doc['_id'] ?? '');
        return (array)$doc;
    }

    /**
     * Save (create/update)
     * - benzersiz anahtar: kod
     * - durum: is_active (bool)
     * - versiyon: her save'de +1
     */
    public static function save(array $fields, array $ctx, ?string $id = null): array
    {
        $now    = self::nowUtc();
        $tenant = self::tenantFilter($ctx);
        $user   = (string)($ctx['username'] ?? '');

        $isUpdate = ($id && strlen($id) === 24);
        $existing = null;

        if ($isUpdate) {
            $existing = self::col()->findOne(array_merge($tenant, ['_id' => self::oid($id)]));
            if ($existing instanceof MongoDB\Model\BSONDocument) $existing = $existing->getArrayCopy();
            if (!$existing) $isUpdate = false;
        }

        // --- iş alanları ---
        $kod = trim((string)($fields['kod'] ?? ($fields['stok_kodu'] ?? ''))); // legacy accept
        if ($kod === '') {
            throw new InvalidArgumentException('kod_required');
        }

        $name  = trim((string)($fields['name'] ?? ($fields['stok_adi'] ?? ''))); // legacy accept
        $name2 = trim((string)($fields['name2'] ?? ''));
        $unit  = trim((string)($fields['unit'] ?? ($fields['birim'] ?? ''))); // legacy accept

        $isActive = (bool)($fields['is_active'] ?? true);

        // --- benzersiz kontrol ---
        // aynı tenant içinde aynı kod başka dokümanda varsa hata
        // (hem kod hem stok_kodu üzerinden kontrol)
        $dupFilter = array_merge($tenant, [
            '$or' => [
                ['kod' => $kod],
                ['stok_kodu' => $kod],
            ]
        ]);

        if ($isUpdate) {
            $dupFilter['_id'] = ['$ne' => self::oid((string)$id)];
        }

        $dup = self::col()->findOne($dupFilter, ['projection' => ['_id' => 1]]);
        if ($dup) {
            throw new InvalidArgumentException('kod_not_unique');
        }

        $prevVersion = (int)($existing['version'] ?? 0);
        $newVersion  = $isUpdate ? ($prevVersion + 1) : 1;

        $doc = array_merge($tenant, [
            // ✅ yeni alanlar
            'kod'       => $kod,
            'name'      => $name,
            'name2'     => $name2,
            'unit'      => $unit,
            'is_active' => $isActive,

            'version'   => $newVersion,
            'updated_at'=> $now,
            'updated_by'=> $user,
        ]);

        if (!$isUpdate) {
            $doc['created_at'] = $now;
            $doc['created_by'] = $user;
            $ins = self::col()->insertOne($doc);
            $id = (string)$ins->getInsertedId();
        } else {
            self::col()->updateOne(
                array_merge($tenant, ['_id' => self::oid((string)$id)]),
                ['$set' => $doc]
            );
        }

        return [
            'STOK01_id' => (string)$id,
            'kod'       => $kod,
            'name'      => $name,
            'name2'     => $name2,
            'unit'      => $unit,
            'is_active' => $isActive,
            'version'   => $newVersion,
        ];
    }
}
