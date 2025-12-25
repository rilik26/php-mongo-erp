<?php
/**
 * MongoManager
 *
 * MongoDB ile olan tüm iletişimin
 * tek ve zorunlu giriş noktasıdır.
 */
require_once __DIR__ . '/../registry/collections/CollectionRegistry.php';

use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\BSON\UTCDateTime;

class MongoManager
{
    protected static ?\MongoDB\Database $db = null;
    protected static ?Client $client = null;
    protected static string $database;

    /**
     * Mongo bağlantısını başlatır
     */
    public static function init(string $uri, string $database): void
    {
        if (self::$client !== null) {
            return;
        }

        self::$client = new MongoDB\Client($uri);
        self::$database = $database;
        self::$db = self::$client->selectDatabase($database);
    }

    /**
     * Koleksiyon nesnesi döner
     */
    public static function collection(string $collectionCode, array $context = []): Collection
    {
        // 🔒 Mongo init guard
        if (!self::$db) {
            throw new Exception('MongoManager not initialized');
        }

        // 1️⃣ Registry kontrolü
        if (!CollectionRegistry::has($collectionCode)) {
            throw new Exception("Collection not registered: {$collectionCode}");
        }
    
        // 2️⃣ Metadata al
        $meta = CollectionRegistry::get($collectionCode);
    
        // 3️⃣ Firma context kontrolü
        if (($meta['firmScoped'] ?? false) && empty($context['CDEF01_id'])) {
            throw new Exception("Firm context required for {$collectionCode}");
        }
    
        // 4️⃣ Dönem context kontrolü
        if (($meta['periodScoped'] ?? false) && empty($context['period_id'])) {
            throw new Exception("Period context required for {$collectionCode}");
        }
    
        // 5️⃣ TEK ve NET dönüş
        return self::$db->selectCollection($collectionCode);
    }

    /**
     * Evrak ekleme
     */
    public static function insertDocument(
        string $collectionCode,
        array $data,
        array $context
    ): void {
        if (!CollectionRegistry::isDocument($collectionCode)) {
            throw new Exception("InsertDocument only allowed for document collections");
        }

        $data['CDEF01_id']  = $context['CDEF01_id'];
        $data['period_id'] = $context['period_id'];
        $data['created_at'] = new UTCDateTime();

        self::collection($collectionCode, $context)
            ->insertOne($data);
    }

    /**
     * Tablo satırı ekleme
     */
    public static function insertTableRow(
        string $collectionCode,
        array $row
    ): void {
        if (CollectionRegistry::isDocument($collectionCode)) {
            throw new Exception("Use insertDocument for document collections");
        }

        if (empty($row['document_id'])) {
            throw new Exception("Table row must have document_id");
        }

        $row['created_at'] = new UTCDateTime();

        self::collection($collectionCode)
            ->insertOne($row);
    }
}
