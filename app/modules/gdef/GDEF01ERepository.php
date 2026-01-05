<?php
/**
 * app/modules/gdef/GDEF01ERepository.php (FINAL)
 *
 * GDEF01E (GLOBAL) - Grup Tanımları
 * Alanlar: kod, name, name2, is_active, created_at/by, updated_at/by, version
 */

final class GDEF01ERepository
{
  private static function col() {
    return MongoManager::collection('GDEF01E');
  }

  private static function nowUtc(): MongoDB\BSON\UTCDateTime {
    return new MongoDB\BSON\UTCDateTime((int) floor(microtime(true) * 1000));
  }

  private static function oid(string $id): MongoDB\BSON\ObjectId {
    return new MongoDB\BSON\ObjectId($id);
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

  public static function findById(string $id): ?array
  {
    if ($id === '' || strlen($id) !== 24) return null;
    $doc = self::col()->findOne(['_id' => self::oid($id)]);
    if (!$doc) return null;
    if ($doc instanceof MongoDB\Model\BSONDocument) $doc = $doc->getArrayCopy();
    $doc['_id'] = (string)($doc['_id'] ?? '');
    return (array)$doc;
  }

  public static function findByCode(string $kod): ?array
  {
    $kod = trim((string)$kod);
    if ($kod === '') return null;
    $doc = self::col()->findOne(['kod' => $kod]);
    if (!$doc) return null;
    if ($doc instanceof MongoDB\Model\BSONDocument) $doc = $doc->getArrayCopy();
    $doc['_id'] = (string)($doc['_id'] ?? '');
    return (array)$doc;
  }

  public static function listAll(int $limit = 500): array
  {
    $cur = self::col()->find([], ['sort' => ['kod' => 1], 'limit' => $limit]);
    $out = [];
    foreach ($cur as $r) {
      if ($r instanceof MongoDB\Model\BSONDocument) $r = $r->getArrayCopy();
      $out[] = [
        '_id' => (string)($r['_id'] ?? ''),
        'kod' => (string)($r['kod'] ?? ''),
        'name' => (string)($r['name'] ?? ''),
        'name2' => (string)($r['name2'] ?? ''),
        'is_active' => (bool)($r['is_active'] ?? true),
        'version' => (int)($r['version'] ?? 1),
      ];
    }
    return $out;
  }

  public static function save(array $fields, array $ctx, ?string $id = null): array
  {
    $now = self::nowUtc();
    $user = (string)($ctx['username'] ?? '');

    $isUpdate = ($id && strlen($id) === 24);
    $existing = null;

    if ($isUpdate) {
      $existing = self::col()->findOne(['_id' => self::oid($id)]);
      if ($existing instanceof MongoDB\Model\BSONDocument) $existing = $existing->getArrayCopy();
      if (!$existing) $isUpdate = false;
    }

    $kod = trim((string)($fields['kod'] ?? ''));
    if ($kod === '') throw new InvalidArgumentException('kod_required');

    $name = trim((string)($fields['name'] ?? ''));
    if ($name === '') throw new InvalidArgumentException('name_required');

    $name2 = trim((string)($fields['name2'] ?? ''));
    $isActive = (bool)($fields['is_active'] ?? true);

    // uniq (kod)
    $dupFilter = ['kod' => $kod];
    if ($isUpdate) $dupFilter['_id'] = ['$ne' => self::oid((string)$id)];
    $dup = self::col()->findOne($dupFilter, ['projection' => ['_id' => 1]]);
    if ($dup) throw new InvalidArgumentException('kod_not_unique');

    $prevVersion = (int)($existing['version'] ?? 0);
    $newVersion  = $isUpdate ? ($prevVersion + 1) : 1;

    $doc = [
      'kod' => $kod,
      'name' => $name,
      'name2' => $name2,
      'is_active' => $isActive,

      'version' => $newVersion,
      'updated_at' => $now,
      'updated_by' => $user,
    ];

    if (!$isUpdate) {
      $doc['created_at'] = $now;
      $doc['created_by'] = $user;
      $ins = self::col()->insertOne($doc);
      $id = (string)$ins->getInsertedId();
    } else {
      self::col()->updateOne(['_id' => self::oid((string)$id)], ['$set' => $doc]);
    }

    return [
      'GDEF01E_id' => (string)$id,
      'kod' => $kod,
      'name' => $name,
      'name2' => $name2,
      'is_active' => $isActive,
      'version' => $newVersion,
    ];
  }
}
