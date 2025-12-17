<?php
require_once __DIR__ . '/userservice.php';

class UserController
{
    protected UserService $userService;

    public function __construct()
    {
        $this->userService = new UserService();
    }

    /**
     * Varsayılan endpoint
     * GET /user
     */
    public function index()
    {
        return 'UserService + UserRepository aktif';
    }

    /**
     * GET /user/list
     * Tüm kullanıcıları getir
     */
    public function list()
    {
        $users = $this->userService->getAllUsers();

        $response = new Response();
        return $response->setBody($users);
    }

    /**
     * POST /user/create
     * JSON body ile yeni kullanıcı ekler
     * Örnek body: {"name":"Ali","email":"ali@example.com"}
     */
    public function create()
    {
        // JSON body oku
        $input = json_decode(file_get_contents('php://input'), true);

        if (!$input || !isset($input['name']) || !isset($input['email'])) {
            $response = new Response();
            return $response->setBody([
                'success' => false,
                'message' => 'Eksik veri: name ve email gerekli'
            ]);
        }

        $id = $this->userService->createUser($input);

        $response = new Response();
        return $response->setBody([
            'success' => true,
            'id' => $id
        ]);
    }

}
