<?php
/**
 * LANG01ERepository.php
 *
 * AMAÇ:
 * - Dil metadata (LANG01E) erişimi
 * - Default dili ve version bilgisini almak
 */

final class LANG01ERepository
{
    public static function getDefaultLangCode(): string
    {
        $doc = MongoManager::collection('LANG01E')->findOne(
            ['is_default' => true, 'is_active' => true],
            ['projection' => ['lang_code' => 1]]
        );

        if (!$doc) return 'tr';
        $arr = (array)$doc;

        return (string)($arr['lang_code'] ?? 'tr');
    }

    public static function getVersion(string $langCode): int
    {
        $doc = MongoManager::collection('LANG01E')->findOne(
            ['lang_code' => $langCode],
            ['projection' => ['version' => 1]]
        );

        if (!$doc) return 1;
        $arr = (array)$doc;

        return (int)($arr['version'] ?? 1);
    }

    public static function isActive(string $langCode): bool
    {
        $doc = MongoManager::collection('LANG01E')->findOne(
            ['lang_code' => $langCode, 'is_active' => true],
            ['projection' => ['_id' => 1]]
        );

        return (bool)$doc;
    }

    public static function bumpVersion(string $langCode): void
    {
        MongoManager::collection('LANG01E')->updateOne(
            ['lang_code' => $langCode],
            ['$inc' => ['version' => 1], '$set' => ['updated_at' => new \MongoDB\BSON\UTCDateTime()]]
        );
    }

    public static function listActiveLangs(): array
    {
        $cursor = MongoManager::collection('LANG01E')->find(
            ['is_active' => true],
            ['projection' => ['lang_code' => 1, 'name' => 1, 'is_default' => 1], 'sort' => ['lang_code' => 1]]
        );

        $out = [];
        foreach ($cursor as $d) $out[] = (array)$d;
        return $out;
    }

}
