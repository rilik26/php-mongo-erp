<?php
/**
 * app/modules/lang/LANG01ERepository.php (FINAL)
 *
 * LANG01E: dil meta
 * fields:
 * - lang_code (unique)
 * - name
 * - direction (ltr|rtl)
 * - is_active (bool)
 * - is_default (bool)
 * - version (int)
 * - updated_at (UTCDateTime)
 */

final class LANG01ERepository
{
  private static function col() {
    return MongoManager::collection('LANG01E');
  }

  private static function nowUtc(): MongoDB\BSON\UTCDateTime {
    return new MongoDB\BSON\UTCDateTime((int) floor(microtime(true) * 1000));
  }

  public static function listAll(): array {
    $cur = self::col()->find([], ['sort' => ['lang_code' => 1]]);
    $out = [];
    foreach ($cur as $d) {
      if ($d instanceof MongoDB\Model\BSONDocument) $d = $d->getArrayCopy();
      $out[] = $d;
    }
    return $out;
  }

  public static function listActive(): array {
    $cur = self::col()->find(['is_active' => true], ['sort' => ['is_default' => -1, 'lang_code' => 1]]);
    $out = [];
    foreach ($cur as $d) {
      if ($d instanceof MongoDB\Model\BSONDocument) $d = $d->getArrayCopy();
      $out[] = $d;
    }
    return $out;
  }

  public static function getActiveLangCodes(): array {
    $list = self::listActive();
    $codes = [];
    foreach ($list as $d) {
      $lc = strtolower(trim((string)($d['lang_code'] ?? '')));
      if ($lc !== '') $codes[] = $lc;
    }
    return array_values(array_unique($codes));
  }

  public static function findByCode(string $langCode): ?array {
    $lc = strtolower(trim($langCode));
    if ($lc === '') return null;
    $d = self::col()->findOne(['lang_code' => $lc]);
    if (!$d) return null;
    if ($d instanceof MongoDB\Model\BSONDocument) $d = $d->getArrayCopy();
    return $d;
  }

  public static function isActive(string $langCode): bool {
    $d = self::findByCode($langCode);
    if (!$d) return false;
    return (bool)($d['is_active'] ?? false);
  }

  public static function getVersion(string $langCode): int {
    $d = self::findByCode($langCode);
    if (!$d) return 0;
    return (int)($d['version'] ?? 0);
  }

  public static function bumpVersion(string $langCode): int {
    $lc = strtolower(trim($langCode));
    if ($lc === '') return 0;

    self::col()->updateOne(
      ['lang_code' => $lc],
      [
        '$inc' => ['version' => 1],
        '$set' => ['updated_at' => self::nowUtc()],
      ],
      ['upsert' => true]
    );

    return self::getVersion($lc);
  }

  /**
   * Yeni dil ekleme / mevcut dili güncelleme (upsert)
   */
  public static function upsertLang(string $langCode, array $fields): array {
    $lc = strtolower(trim($langCode));
    if ($lc === '') return ['ok'=>false,'error'=>'lang_code_required'];

    $name = trim((string)($fields['name'] ?? strtoupper($lc)));
    if ($name === '') $name = strtoupper($lc);

    $dir = strtolower(trim((string)($fields['direction'] ?? 'ltr')));
    if (!in_array($dir, ['ltr','rtl'], true)) $dir = 'ltr';

    $isActive  = (bool)($fields['is_active'] ?? true);
    $isDefault = (bool)($fields['is_default'] ?? false);

    // Default seçildiyse önce diğerlerini kapat
    if ($isDefault) {
      self::col()->updateMany([], ['$set' => ['is_default' => false, 'updated_at' => self::nowUtc()]]);
    }

    self::col()->updateOne(
      ['lang_code' => $lc],
      [
        '$set' => [
          'lang_code'  => $lc,
          'name'       => $name,
          'direction'  => $dir,
          'is_active'  => $isActive,
          'is_default' => $isDefault,
          'updated_at' => self::nowUtc(),
        ],
        '$setOnInsert' => [
          'version' => 1,
        ],
      ],
      ['upsert' => true]
    );

    return ['ok'=>true];
  }

  /**
   * Aktif/pasif: mevcut kayıt yoksa upsert yapma (user istemiyor)
   */
  public static function setActive(string $langCode, bool $isActive): array {
    $lc = strtolower(trim($langCode));
    if ($lc === '') return ['ok'=>false,'error'=>'lang_code_required'];

    $res = self::col()->updateOne(
      ['lang_code' => $lc],
      ['$set' => ['is_active' => (bool)$isActive, 'updated_at' => self::nowUtc()]],
      ['upsert' => false]
    );

    if (($res->getMatchedCount() ?? 0) < 1) {
      return ['ok'=>false,'error'=>'lang_not_found'];
    }

    return ['ok'=>true];
  }

  public static function setDefault(string $langCode): array {
    $lc = strtolower(trim($langCode));
    if ($lc === '') return ['ok'=>false,'error'=>'lang_code_required'];

    // kayıt var mı?
    $d = self::findByCode($lc);
    if (!$d) return ['ok'=>false,'error'=>'lang_not_found'];

    self::col()->updateMany([], ['$set' => ['is_default' => false, 'updated_at' => self::nowUtc()]]);
    self::col()->updateOne(['lang_code' => $lc], ['$set' => ['is_default' => true, 'updated_at' => self::nowUtc()]]);

    return ['ok'=>true];
  }

  public static function getDefaultLang(): string {
    $d = self::col()->findOne(['is_default' => true]);
    if ($d && $d instanceof MongoDB\Model\BSONDocument) $d = $d->getArrayCopy();
    $lc = strtolower(trim((string)($d['lang_code'] ?? '')));
    return $lc !== '' ? $lc : 'tr';
  }
}
