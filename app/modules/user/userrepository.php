<?php
/**
 * ------------------------------------------------------------
 * UserRepository
 * ------------------------------------------------------------
 * Kullanıcı verilerinin MongoDB üzerindeki işlemlerini yönetir
 */

use MongoDB\Client;
use MongoDB\Collection;

class UserRepository
{
    protected Client $client;
    protected Collection $collection;

    /**
     * MongoDB bağlantısı oluşturulur
     */
    public function __construct()
    {
        /**
         * Atlas bağlantı bilgileri
         * Bunlar ileride .env dosyasından okunacak
         */
        $uri = $_ENV['MONGO_URI'] ?? '';
        $dbName = $_ENV['MONGO_DB'] ?? 'erp';
        $collectionName = 'users';

        if ($uri === '') {
            throw new Exception('MongoDB bağlantı URI tanımlı değil');
        }

        // MongoDB client
        $this->client = new Client($uri);

        // users collection
        $this->collection = $this->client
            ->selectDatabase($dbName)
            ->selectCollection($collectionName);
    }

    /**
     * Tüm kullanıcıları getir
     */
    public function findAll(): array
    {
        return $this->collection->find()->toArray();
    }

    /**
     * ID ile kullanıcı getir
     */
    public function findById(string $id): ?array
    {
        $user = $this->collection->findOne([
            '_id' => new MongoDB\BSON\ObjectId($id)
        ]);

        return $user ? $user->getArrayCopy() : null;
    }

    /**
     * Yeni kullanıcı oluştur
     */
    public function create(array $data): string
    {
        $result = $this->collection->insertOne($data);
        return (string) $result->getInsertedId();
    }
}
