<?php
/**
 * app/modules/stok/STOK01Repository.php (FINAL)
 *
 * STOK01E - Stok Kartı
 * Mongo alanları:
 * - code, name, name2, unit
 * - GDEF01_unit_code: "adet"
 * - is_active
 *
 * Tenant:
 * - CDEF01_id, PERIOD01T_id
 */

final class STOK01Repository
{
  private static function col() { return MongoManager::collection('STOK01E'); }

  private static function nowUtc(): MongoDB\BSON\UTCDateTime {
    return new MongoDB\BSON\UTCDateTime((int) floor(microtime(true) * 1000));
  }

  private static function oid(string $id): MongoDB\BSON\ObjectId { return new MongoDB\BSON\ObjectId($id); }

  private static function tenantFilter(array $ctx): array
  {
    $cdef   = (string)($ctx['CDEF01_id'] ?? '');
    $period = (string)($ctx['PERIOD01T_id'] ?? ($ctx['period_id'] ?? ''));
    return [
      'CDEF01_id'    => $cdef,
      'PERIOD01T_id' => $period,
    ];
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

  public static function listByContext(array $ctx, int $limit = 300): array
  {
    $filter = self::tenantFilter($ctx);
    $cur = self::col()->find($filter, ['sort' => ['updated_at' => -1, 'created_at' => -1], 'limit' => $limit]);

    $out = [];
    foreach ($cur as $r) {
      if ($r instanceof MongoDB\Model\BSONDocument) $r = $r->getArrayCopy();
      $out[] = [
        '_id' => (string)($r['_id'] ?? ''),
        'code' => (string)($r['code'] ?? ''),
        'name' => (string)($r['name'] ?? ''),
        'name2' => (string)($r['name2'] ?? ''),
        'unit' => (string)($r['unit'] ?? ''),
        'GDEF01_unit_code' => (string)($r['GDEF01_unit_code'] ?? ''),
        'is_active' => (bool)($r['is_active'] ?? true),
        'version' => (int)($r['version'] ?? 1),
      ];
    }
    return $out;
  }

  public static function save(array $fields, array $ctx, ?string $id = null): array
  {
    $now = self::nowUtc();
    $tenant = self::tenantFilter($ctx);
    $user = (string)($ctx['username'] ?? '');

    $isUpdate = ($id && strlen($id) === 24);
    $existing = null;

    if ($isUpdate) {
      $existing = self::col()->findOne(array_merge($tenant, ['_id' => self::oid($id)]));
      if ($existing instanceof MongoDB\Model\BSONDocument) $existing = $existing->getArrayCopy();
      if (!$existing) $isUpdate = false;
    }

    $code = trim((string)($fields['code'] ?? ''));
    if ($code === '') throw new InvalidArgumentException('code_required');

    $name  = trim((string)($fields['name'] ?? ''));
    $name2 = trim((string)($fields['name2'] ?? ''));
    $unitCode = trim((string)($fields['GDEF01_unit_code'] ?? ''));
    if ($unitCode === '') throw new InvalidArgumentException('unit_required');

    // UI'da görünen: "code - name" ama dokümanda hem code hem unit text tutuyoruz
    $unitText = trim((string)($fields['unit'] ?? '')); // UI doldurur, yoksa boş kalabilir

    $isActive = true;
    if (array_key_exists('is_active', $fields)) $isActive = (bool)$fields['is_active'];

    // uniq: tenant + code
    $dupFilter = array_merge($tenant, ['code' => $code]);
    if ($isUpdate) $dupFilter['_id'] = ['$ne' => self::oid((string)$id)];
    $dup = self::col()->findOne($dupFilter, ['projection' => ['_id' => 1]]);
    if ($dup) throw new InvalidArgumentException('code_not_unique');

    $prevVersion = (int)($existing['version'] ?? 0);
    $newVersion  = $isUpdate ? ($prevVersion + 1) : 1;

    $doc = array_merge($tenant, [
      'code' => $code,
      'name' => $name,
      'name2' => $name2,

      'GDEF01_unit_code' => $unitCode,
      'unit' => $unitText,

      'is_active' => $isActive,

      'version' => $newVersion,
      'updated_at' => $now,
      'updated_by' => $user,
    ]);

    if (!$isUpdate) {
      $doc['created_at'] = $now;
      $doc['created_by'] = $user;
      $ins = self::col()->insertOne($doc);
      $id = (string)$ins->getInsertedId();
    } else {
      self::col()->updateOne(array_merge($tenant, ['_id' => self::oid((string)$id)]), ['$set' => $doc]);
    }

    return [
      'STOK01_id' => (string)$id,
      'code' => $code,
      'name' => $name,
      'name2' => $name2,
      'unit' => $unitText,
      'GDEF01_unit_code' => $unitCode,
      'is_active' => $isActive,
      'version' => $newVersion,
    ];
  }
}
