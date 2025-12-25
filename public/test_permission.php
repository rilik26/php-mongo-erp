<?php
/**
 * test_permission.php
 * Amaç: Permission kontrolünü hızlı test etmek
 */

require_once __DIR__ . '/../core/bootstrap.php';

require_once __DIR__ . '/../core/auth/SessionManager.php';
require_once __DIR__ . '/../core/base/Context.php';
require_once __DIR__ . '/../core/base/ContextException.php';
require_once __DIR__ . '/../core/auth/PermissionChecker.php';

SessionManager::start();
Context::bootFromSession();

PermissionChecker::check('STOK01E', 'create');
echo 'OK';
