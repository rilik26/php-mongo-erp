<?php

use MongoDB\BSON\UTCDateTime;
use MongoDB\BSON\ObjectId;

/**
 * BaseDocument.php
 *
 * AMAÇ:
 * - Tüm *01E evraklarının ortak atası olmak
 * - Context'ten otomatik beslenen alanları standartlaştırmak
 * - CRUD omurgasını tek yerde toplamak
 *
 * OTOMATİK ALANLAR:
 * - CDEF01_id, period_id (context)
 * - created_at, created_by
 * - updated_at, updated_by
 *
 * SORUMLULUK:
 * - insert/update/find yardımcıları
 * - temel audit alanları
 *
 * YAPMAZ:
 * - HTTP bilmez
 * - UI bilmez
 * - Yetki kontrolü kararını vermez (Controller/Service çağırır)
 *
 * NOT:
 * - Bu sınıfı kullanan her evrak subclass'ı kendi collectionCode() değerini döner.
 */

require_once __DIR__ . '/BaseDocumentException.php';
require_once __DIR__ . '/Context.php'; // Context::get() standardı

abstract class BaseDocument
{
    /**
     * Her evrak subclass'ı bunu implement etmeli.
     * Örn: STOK01E, BOM01E, MFG01E
     */
    abstract public static function collectionCode(): string;

    /**
     * Evrak oluşturma (insert)
     *
     * @param array $data Evrak alanları (iş alanları)
     * @return string inserted _id
     */
    public static function create(array $data): string
    {
        $ctx = self::context();

        $doc = array_merge($data, [
            // bağlam alanları
            'CDEF01_id' => $ctx['CDEF01_id'] ?? null,
            'period_id' => $ctx['period_id'] ?? null,

            // audit
            'created_at' => new UTCDateTime(),
            'created_by' => $ctx['UDEF01_id'] ?? null,

            'updated_at' => null,
            'updated_by' => null,

            // soft delete
            'is_deleted' => false,
            'deleted_at' => null,
            'deleted_by' => null,
        ]);

        $result = MongoManager::collection(static::collectionCode())->insertOne($doc);

        return (string)$result->getInsertedId();
    }

    /**
     * Evrak güncelleme (update)
     *
     * @param string $id Mongo _id (string)
     * @param array $set $set ile güncellenecek alanlar
     * @return bool
     */
    public static function updateById(string $id, array $set): bool
    {
        $ctx = self::context();

        $update = [
            '$set' => array_merge($set, [
                'updated_at' => new UTCDateTime(),
                'updated_by' => $ctx['UDEF01_id'] ?? null,
            ]),
        ];

        $result = MongoManager::collection(static::collectionCode())
            ->updateOne(['_id' => self::oid($id), 'is_deleted' => false], $update);

        return $result->getModifiedCount() > 0;
    }

    /**
     * Evrak soft-delete
     */
    public static function softDeleteById(string $id): bool
    {
        $ctx = self::context();

        $update = [
            '$set' => [
                'is_deleted' => true,
                'deleted_at' => new UTCDateTime(),
                'deleted_by' => $ctx['UDEF01_id'] ?? null,
            ],
        ];

        $result = MongoManager::collection(static::collectionCode())
            ->updateOne(['_id' => self::oid($id), 'is_deleted' => false], $update);

        return $result->getModifiedCount() > 0;
    }

    /**
     * Evrak getir (soft-deleted hariç)
     */
    public static function findById(string $id): ?array
    {
        $doc = MongoManager::collection(static::collectionCode())
            ->findOne(['_id' => self::oid($id), 'is_deleted' => false]);

        if (!$doc) {
            return null;
        }

        return self::normalize($doc);
    }

    /**
     * Listeleme (soft-deleted hariç)
     * $filter ek alanları içindir
     */
    public static function find(array $filter = [], array $options = []): array
    {
        $filter = array_merge(['is_deleted' => false], $filter);

        $cursor = MongoManager::collection(static::collectionCode())->find($filter, $options);

        $out = [];
        foreach ($cursor as $doc) {
            $out[] = self::normalize($doc);
        }

        return $out;
    }

    /**
     * Context güvenli okuma
     */
    protected static function context(): array
    {
        try {
            return Context::get();
        } catch (Throwable $e) {
            throw new BaseDocumentException('Context not available for document operation');
        }
    }

    /**
     * ObjectId dönüşümü
     */
    protected static function oid(string $id): ObjectId
    {
        try {
            return new ObjectId($id);
        } catch (Throwable $e) {
            throw new BaseDocumentException('Invalid ObjectId: ' . $id);
        }
    }

    /**
     * Mongo doc normalize (ObjectId -> string vs.)
     */
    protected static function normalize($doc): array
    {
        $arr = (array)$doc;

        if (isset($arr['_id'])) {
            $arr['_id'] = (string)$arr['_id'];
        }

        return $arr;
    }
}
