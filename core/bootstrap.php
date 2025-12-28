<?php
/**
 * core/bootstrap.php
 *
 * AMAÇ:
 * - Autoload
 * - MongoManager init
 * - Collection whitelist (registry)
 * - i18n boot (Mongo init'ten sonra!)
 *
 * FIX:
 * - LanguageManager::boot() session'a bakıyor.
 *   Bu yüzden i18n boot öncesi session kesin başlatılır.
 */

require_once __DIR__ . '/../vendor/autoload.php';

// --- Core sınıflar ---
require_once __DIR__ . '/db/MongoManager.php';
require_once __DIR__ . '/registry/collections/CollectionRegistry.php';

// ✅ Session garantisi (i18n boot öncesi)
require_once __DIR__ . '/auth/SessionManager.php';
SessionManager::start();

// --- Mongo init (ÖNCE) ---
$mongoConfig = require __DIR__ . '/../config/database/mongo.php';

MongoManager::init(
  $mongoConfig['uri'],
  $mongoConfig['database']
);

require_once __DIR__ . '/auth/permission_helpers.php';

// --- Collection whitelist ---
if (file_exists(__DIR__ . '/../config/registry/collections.php')) {
  CollectionRegistry::load(require __DIR__ . '/../config/registry/collections.php');
}

// --- i18n (Mongo hazır + Session aktif olduktan sonra) ---
if (!defined('SKIP_I18N_BOOT')) {
  require_once __DIR__ . '/i18n/LanguageManager.php';
  require_once __DIR__ . '/i18n/helpers.php';
  LanguageManager::boot();
}

require_once __DIR__ . '/helpers/html.php';
