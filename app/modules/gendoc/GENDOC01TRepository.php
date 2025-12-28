<?php
/**
 * app/modules/gendoc/GENDOC01TRepository.php (FINAL)
 *
 * GENDOC Body Versions
 * - unique: (target_key, version)
 * - insertVersion: insertOne (upsert değil)
 * - latestByTargetKey: son versiyonu getirir
 * - ✅ BSONDocument/BSONArray -> array stabil
 * - ✅ period boşsa GLOBAL yaz
 */

final class GENDOC01TRepository
{
  public static function collectionName(): string { return 'GENDOC01T'; }

  private static function bson_to_array($v) {
    if ($v instanceof MongoDB\Model\BSONDocument || $v instanceof MongoDB\Model\BSONArray) {
      $v = $v->getArrayCopy();
    }
    if ($v instanceof MongoDB\BSON\UTCDateTime) return $v->toDateTime()->format('c');
    if ($v instanceof MongoDB\BSON\ObjectId) return (string)$v;
    if (is_array($v)) {
      $out = [];
      foreach ($v as $k => $vv) $out[$k] = self::bson_to_array($vv);
      return $out;
    }
    return $v;
  }

  public static function latestByTargetKey(string $targetKey): ?array
  {
    if ($targetKey === '') return null;

    $doc = MongoManager::collection(self::collectionName())->findOne(
      ['target_key' => $targetKey],
      ['sort' => ['version' => -1]]
    );

    if (!$doc) return null;
    return self::bson_to_array($doc);
  }

  /**
   * Version insert (V1→V2→V3)
   * $metaTarget: module/doc_type/doc_id + doc_no/doc_title/status
   */
  public static function insertVersion(
    string $targetKey,
    int $version,
    array $body,
    array $ctx,
    array $metaTarget = []
  ): array {
    if ($targetKey === '') throw new InvalidArgumentException('target_key required');
    if ($version <= 0) throw new InvalidArgumentException('version must be >= 1');

    $now = new MongoDB\BSON\UTCDateTime((int)floor(microtime(true) * 1000));

    $period = $ctx['period_id'] ?? null;
    if ($period === '' || $period === null) $period = 'GLOBAL';

    $facility = $ctx['facility_id'] ?? null;
    if ($facility === '') $facility = null;

    $doc = [
      'target_key' => $targetKey,
      'version'    => $version,
      'body'       => $body,

      'context'    => [
        'CDEF01_id'   => $ctx['CDEF01_id'] ?? null,
        'period_id'   => $period,
        'facility_id' => $facility,
        'UDEF01_id'   => $ctx['UDEF01_id'] ?? null,
        'username'    => $ctx['username'] ?? null,
        'role'        => $ctx['role'] ?? null,
        'session_id'  => $ctx['session_id'] ?? session_id(),
        'ip'          => $ctx['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? null),
        'user_agent'  => $ctx['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? null),
      ],

      'target' => [
        'module'    => $metaTarget['module'] ?? null,
        'doc_type'  => $metaTarget['doc_type'] ?? null,
        'doc_id'    => $metaTarget['doc_id'] ?? null,
        'doc_no'    => $metaTarget['doc_no'] ?? null,
        'doc_title' => $metaTarget['doc_title'] ?? null,
        'status'    => $metaTarget['status'] ?? null,
      ],

      'created_at' => $now,
    ];

    $res = MongoManager::collection(self::collectionName())->insertOne($doc);

    return [
      'ok' => true,
      'inserted_id' => (string)$res->getInsertedId(),
      'version' => $version,
    ];
  }
      /** Belirli versiyonu getir */
    public static function findByTargetKeyVersion(string $targetKey, int $version): ?array
    {
        if ($targetKey === '' || $version <= 0) return null;

        $doc = MongoManager::collection(self::collectionName())->findOne(
            ['target_key' => $targetKey, 'version' => $version]
        );

        if (!$doc) return null;
        if ($doc instanceof MongoDB\Model\BSONDocument) $doc = $doc->getArrayCopy();
        return is_array($doc) ? $doc : null;
    }

    /** Versiyon listesini getir (dropdown için) */
    public static function listVersions(string $targetKey, int $limit = 200): array
    {
        if ($targetKey === '') return [];

        if ($limit < 10) $limit = 10;
        if ($limit > 500) $limit = 500;

        $cur = MongoManager::collection(self::collectionName())->find(
            ['target_key' => $targetKey],
            [
                'sort' => ['version' => -1],
                'limit' => $limit,
                'projection' => [
                    'version' => 1,
                    'created_at' => 1,
                    'context.username' => 1,
                ],
            ]
        );

        $rows = iterator_to_array($cur);
        $out = [];

        foreach ($rows as $r) {
            if ($r instanceof MongoDB\Model\BSONDocument) $r = $r->getArrayCopy();
            $out[] = $r;
        }

        return $out;
    }

}
