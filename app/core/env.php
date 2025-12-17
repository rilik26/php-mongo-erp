<?php
/**
 * ------------------------------------------------------------
 * Env Loader
 * ------------------------------------------------------------
 * .env dosyasındaki değişkenleri $_ENV ve getenv() içine yükler
 */

class Env
{
    /**
     * .env dosyasını yükler
     *
     * @param string $filePath
     * @return void
     */
    public static function load(string $filePath): void
    {
        if (!file_exists($filePath)) {
            throw new Exception(".env dosyası bulunamadı: $filePath");
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            // Yorum satırını atla
            if (str_starts_with(trim($line), '#')) continue;

            // key=value
            [$name, $value] = explode('=', $line, 2);

            $name = trim($name);
            $value = trim($value);

            // Tırnak varsa kaldır
            $value = trim($value, '"\'');

            // $_ENV ve getenv ile set et
            $_ENV[$name] = $value;
            putenv("$name=$value");
        }
    }
}
