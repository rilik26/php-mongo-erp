<?php
/**
 * UDEF01Document
 *
 * Kullanıcı Tanım Evrakı (UDEF01E)
 *
 * SORUMLULUKLAR:
 * - Kullanıcı oluşturma
 * - Kullanıcı pasif etme
 * - Kimlik doğrulama için veri sağlama
 *
 * NOT:
 * - Şifre kontrolü burada yapılmaz
 * - Login işlemi service katmanında yapılır
 */

class UDEF01Document extends BaseDocument
{
    protected string $collectionCode = 'UDEF01E';

    /**
     * Yeni kullanıcı oluşturur
     */
    public function createUser(array $data): void
    {
        // Minimum alan zorunluluğu
        foreach (['username', 'password_hash'] as $field) {
            if (empty($data[$field])) {
                throw new Exception("Missing field: {$field}");
            }
        }

        parent::create([
            'username'      => $data['username'],
            'password_hash'=> $data['password_hash'],
            'is_active'     => true,
        ]);
    }

    /**
     * Kullanıcıyı pasif eder
     */
    public function disable(string $userId): void
    {
        PermissionChecker::check('UDEF01E', 'disable');

        MongoManager::collection(
            $this->collectionCode,
            ['firm_id' => AuthManager::companyId()]
        )->updateOne(
            ['_id' => $userId],
            ['$set' => ['is_active' => false]]
        );
    }
}
