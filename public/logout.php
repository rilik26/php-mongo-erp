<?php
require_once __DIR__ . '/../core/bootstrap.php';

require_once __DIR__ . '/../core/auth/SessionManager.php';
require_once __DIR__ . '/../core/auth/AuthService.php';

SessionManager::start();

AuthService::logout();

header('Location: /php-mongo-erp/public/login.php');
exit;
