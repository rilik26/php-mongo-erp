<?php
/**
 * CollectionRegistry.php
 *
 * AMAÇ:
 * - MongoManager üzerinden erişilebilecek collection'ları whitelist + opsiyonel mapping ile yönetmek
 *
 * SORUMLULUK:
 * - load(): config ile toplu register
 * - register(): tek ekleme
 * - has(): whitelist kontrolü
 * - get(): collection adı mapping'i döndürmek (MongoManager uyumluluğu)
 *
 * NOT:
 * - get($name) varsayılan olarak $name döndürür.
 * - İleride alias gerekiyorsa config'i associative array yapıp mapping ekleyebilirsin.
 */

final class CollectionRegistry
{
    /**
     * collections:
     * - ['UDEF01E' => 'UDEF01E', 'PERIOD01T' => 'PERIOD01T'] gibi tutulur
     */
    private static array $collections = [];

    /**
     * Config'ten gelen list ile whitelist'i doldurur.
     * Config iki tip olabilir:
     * 1) ['UDEF01E','PERIOD01T']
     * 2) ['UDEF01E'=>'UDEF01E', 'PERIOD01T'=>'PERIOD01T'] (mapping/alias)
     */
    public static function load(array $collections): void
    {
        self::$collections = [];

        foreach ($collections as $k => $v) {
            if (is_int($k)) {
                // list format
                self::register((string)$v, (string)$v);
            } else {
                // mapping format
                self::register((string)$k, (string)$v);
            }
        }
    }

    /**
     * @param string $name  Kod tarafında istenen isim
     * @param string $real  Mongo'daki gerçek collection adı (genelde aynı)
     */
    public static function register(string $name, ?string $real = null): void
    {
        $name = trim($name);
        if ($name === '') {
            return;
        }

        $real = $real !== null ? trim($real) : $name;
        if ($real === '') {
            $real = $name;
        }

        self::$collections[$name] = $real;
    }

    /**
     * MongoManager'ın beklediği: has()
     */
    public static function has(string $name): bool
    {
        return isset(self::$collections[$name]);
    }

    /**
     * MongoManager'ın beklediği: get()
     * - whitelist'te yoksa exception (daha güvenli)
     * - varsa gerçek collection adını döndürür
     */
    public static function get(string $name): string
    {
        if (!self::has($name)) {
            throw new Exception('Collection not registered: ' . $name);
        }

        return self::$collections[$name];
    }

    public static function all(): array
    {
        return array_keys(self::$collections);
    }
}
