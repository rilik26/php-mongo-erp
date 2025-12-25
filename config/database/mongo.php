<?php
/**
 * MongoDB Bağlantı Konfigürasyonu
 *
 * AMAÇ:
 * - Ortamdan bağımsız bağlantı tanımı
 * - MongoManager::init için tek kaynak
 *
 * NOT:
 * - Şifre, repo içine gömülmez
 * - .env veya server env tercih edilir
 */

return [
  'uri' => 'mongodb://localhost:27017',
'database' => 'erp_main'
];



