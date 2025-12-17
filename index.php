<?php
/**
 * ------------------------------------------------------------
 * Front Controller
 * ------------------------------------------------------------
 * Uygulamanın tek giriş noktasıdır.
 * Tüm HTTP istekleri buradan başlar.
 * Bu dosyada iş kuralı YAZILMAZ.
 */

// Proje kök dizini
define('BASE_PATH', __DIR__);

// PHP hata ayarları (geliştirme aşaması için)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Composer autoload
require_once BASE_PATH . '/vendor/autoload.php';

// Env loader
require_once BASE_PATH . '/app/core/env.php';
Env::load(BASE_PATH . '/config/.env');

// Basit autoload (framework yok, sade yapı)
spl_autoload_register(function ($class) 
{
    $class = strtolower($class);

    $paths = [
        BASE_PATH . '/app/core/' . $class . '.php',
        BASE_PATH . '/app/shared/helpers/' . $class . '.php',
        BASE_PATH . '/app/shared/exceptions/' . $class . '.php',
    ];

    foreach ($paths as $file) 
    {
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});

// Uygulama ayarlarını yükle
$appConfig = require BASE_PATH . '/config/app.php';

// Application başlat
$app = new Application($appConfig);
$app->run();
