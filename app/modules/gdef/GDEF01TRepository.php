<?php
/**
 * app/modules/gdef/GDEF01TRepository.php (FINAL)
 *
 * Global Grup Tanımları
 * - GDEF01E: grup başlığı (code, name, name2, is_active)  -> GLOBAL
 * - GDEF01T: grup satırları (GDEF01E_code, code, name, name2, is_active) -> GLOBAL
 *
 * Bu repo hem grup satırlarını hem de ihtiyaç olan yerlerde grup dokümanını okur.
 *
 * ✅ Eklenen methodlar (hataları çözenler):
 * - listByGroup($groupCode)
 * - listActiveByGroup($groupCode)
 * - findById($id)
 * - setActive($id, $isActive)
 * - dumpFull($id)
 * - findItem($groupCode, $itemCode)
 */

final class GDEF01TRepository
{
    private static function colE() {
        return MongoManager::collection('GDEF01E');
    }

    private static function colT() {
        return MongoManager::collection('GDEF01T');
    }

    private static function nowUtc(): MongoDB\BSON\UTCDateTime {
        return new MongoDB\BSON\UTCDateTime((int) floor(microtime(true) * 1000));
    }

    private static function oid(string $id): MongoDB\BSON\ObjectId {
        return new MongoDB\BSON\ObjectId($id);
    }

    private static function arr($doc): array {
        if ($doc instanceof MongoDB\Model\BSONDocument || $doc instanceof MongoDB\Model\BSONArray) {
            $doc = $doc->getArrayCopy();
        }
        if (!is_array($doc)) return [];
        // ObjectId -> string
        if (isset($doc['_id']) && $doc['_id'] instanceof MongoDB\BSON\ObjectId) $doc['_id'] = (string)$doc['_id'];
        return $doc;
    }

    public static function findGroupByCode(string $groupCode): ?array
    {
        $groupCode = trim((string)$groupCode);
        if ($groupCode === '') return null;

        $g = self::colE()->findOne(['code' => $groupCode]);
        if (!$g) return null;

        $g = self::arr($g);
        if (!isset($g['is_active'])) $g['is_active'] = true;

        return $g;
    }

    public static function listByGroup(string $groupCode): array
    {
        $groupCode = trim((string)$groupCode);
        if ($groupCode === '') return [];

        $cur = self::colT()->find(
            ['GDEF01E_code' => $groupCode],
            ['sort' => ['code' => 1]]
        );

        $out = [];
        foreach ($cur as $r) {
            $r = self::arr($r);
            if (!isset($r['is_active'])) $r['is_active'] = true;
            $out[] = [
                '_id' => (string)($r['_id'] ?? ''),
                'GDEF01E_code' => (string)($r['GDEF01E_code'] ?? ''),
                'code' => (string)($r['code'] ?? ''),
                'name' => (string)($r['name'] ?? ''),
                'name2' => $r['name2'] ?? null,
                'is_active' => (bool)($r['is_active'] ?? true),
            ];
        }
        return $out;
    }

    public static function listActiveByGroup(string $groupCode): array
    {
        $groupCode = trim((string)$groupCode);
        if ($groupCode === '') return [];

        $cur = self::colT()->find(
            ['GDEF01E_code' => $groupCode, 'is_active' => true],
            ['sort' => ['code' => 1]]
        );

        $out = [];
        foreach ($cur as $r) {
            $r = self::arr($r);
            $out[] = [
                '_id' => (string)($r['_id'] ?? ''),
                'GDEF01E_code' => (string)($r['GDEF01E_code'] ?? ''),
                'code' => (string)($r['code'] ?? ''),
                'name' => (string)($r['name'] ?? ''),
                'name2' => $r['name2'] ?? null,
                'is_active' => true,
            ];
        }
        return $out;
    }

    public static function findItem(string $groupCode, string $itemCode): ?array
    {
        $groupCode = trim((string)$groupCode);
        $itemCode  = trim((string)$itemCode);
        if ($groupCode === '' || $itemCode === '') return null;

        $doc = self::colT()->findOne(['GDEF01E_code' => $groupCode, 'code' => $itemCode]);
        if (!$doc) return null;

        $doc = self::arr($doc);
        if (!isset($doc['is_active'])) $doc['is_active'] = true;
        return $doc;
    }

    public static function findById(string $id): ?array
    {
        $id = trim((string)$id);
        if ($id === '' || strlen($id) !== 24) return null;

        $doc = self::colT()->findOne(['_id' => self::oid($id)]);
        if (!$doc) return null;

        $doc = self::arr($doc);
        if (!isset($doc['is_active'])) $doc['is_active'] = true;
        return $doc;
    }

    public static function dumpFull(string $id): array
    {
        $doc = self::findById($id);
        return is_array($doc) ? $doc : [];
    }

    /**
     * Satır create/update
     * - groupCode zorunlu
     * - code zorunlu (alfabetik listeleniyor)
     * - aynı group içinde code unique
     */
    public static function saveItem(string $groupCode, array $fields, array $ctx, ?string $id = null): array
    {
        $now = self::nowUtc();
        $user = (string)($ctx['username'] ?? '');

        $groupCode = trim((string)$groupCode);
        if ($groupCode === '') throw new InvalidArgumentException('group_required');

        // grup var mı?
        $g = self::findGroupByCode($groupCode);
        if (!$g) throw new InvalidArgumentException('group_not_found');
        if (empty($g['is_active'])) throw new InvalidArgumentException('group_passive');

        $code = trim((string)($fields['code'] ?? ''));
        if ($code === '') throw new InvalidArgumentException('code_required');

        $name = trim((string)($fields['name'] ?? ''));
        if ($name === '') throw new InvalidArgumentException('name_required');

        $name2 = $fields['name2'] ?? null;
        if (is_string($name2)) {
            $name2 = trim($name2);
            if ($name2 === '') $name2 = null;
        }

        $isActive = true;
        if (array_key_exists('is_active', $fields)) $isActive = (bool)$fields['is_active'];

        $isUpdate = ($id && strlen($id) === 24);
        $existing = null;

        if ($isUpdate) {
            $existing = self::colT()->findOne(['_id' => self::oid($id)]);
            if ($existing) $existing = self::arr($existing);
            if (!$existing) $isUpdate = false;
            else {
                // başka grubun satırıysa izin verme
                if ((string)($existing['GDEF01E_code'] ?? '') !== $groupCode) {
                    throw new InvalidArgumentException('group_mismatch');
                }
            }
        }

        // unique check (same group)
        $dupFilter = ['GDEF01E_code' => $groupCode, 'code' => $code];
        if ($isUpdate) $dupFilter['_id'] = ['$ne' => self::oid($id)];
        $dup = self::colT()->findOne($dupFilter, ['projection' => ['_id' => 1]]);
        if ($dup) throw new InvalidArgumentException('code_not_unique');

        $doc = [
            'GDEF01E_code' => $groupCode,
            'code' => $code,
            'name' => $name,
            'name2' => $name2,
            'is_active' => $isActive,
            'updated_at' => $now,
            'updated_by' => $user,
        ];

        if (!$isUpdate) {
            $doc['created_at'] = $now;
            $doc['created_by'] = $user;
            $ins = self::colT()->insertOne($doc);
            $id = (string)$ins->getInsertedId();
        } else {
            self::colT()->updateOne(['_id' => self::oid($id)], ['$set' => $doc]);
        }

        return [
            '_id' => (string)$id,
            'GDEF01E_code' => $groupCode,
            'code' => $code,
            'name' => $name,
            'name2' => $name2,
            'is_active' => $isActive,
        ];
    }

    public static function setActive(string $id, bool $isActive, array $ctx): array
    {
        $id = trim((string)$id);
        if ($id === '' || strlen($id) !== 24) throw new InvalidArgumentException('invalid_id');

        $user = (string)($ctx['username'] ?? '');
        $now = self::nowUtc();

        $cur = self::findById($id);
        if (!$cur) throw new InvalidArgumentException('item_not_found');

        self::colT()->updateOne(
            ['_id' => self::oid($id)],
            ['$set' => ['is_active' => $isActive, 'updated_at' => $now, 'updated_by' => $user]]
        );

        $cur['is_active'] = $isActive;
        return $cur;
    }
}
