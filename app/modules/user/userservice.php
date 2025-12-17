<?php
/**
 * ------------------------------------------------------------
 * UserService
 * ------------------------------------------------------------
 * Kullanıcı modülünün iş kurallarını içerir
 */

require_once __DIR__ . '/userrepository.php';

class UserService
{
    protected UserRepository $userRepository;

    /**
     * Service oluşturulurken repository enjekte edilir
     */
    public function __construct()
    {
        $this->userRepository = new UserRepository();
    }

    /**
     * Sistem testi / örnek bilgi
     */
    public function info(): string
    {
        return 'UserService + UserRepository aktif';
    }

    /**
     * Tüm kullanıcıları getir
     */
    public function getAllUsers(): array
    {
        return $this->userRepository->findAll();
    }

    /**
     * ID ile kullanıcı getir
     */
    public function getUserById(string $id): ?array
    {
        return $this->userRepository->findById($id);
    }

    /**
     * Yeni kullanıcı oluştur
     */
    public function createUser(array $data): string
    {
        /**
         * Burada ileride:
         * - Validation
         * - Şifreleme
         * - Varsayılan roller
         * eklenecek
         */

        return $this->userRepository->create($data);
    }
}
