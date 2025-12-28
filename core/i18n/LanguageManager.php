<?php
/**
 * core/i18n/LanguageManager.php (FINAL)
 *
 * - boot(): aktif dilleri yükler
 * - get(): current lang
 * - set(): session'a yazar
 * - getActiveLangs(): LANG01E aktif listesi (meta ile)
 * - t(): çeviri (LANG01T)
 *
 * Basit cache: request içinde static array.
 */

require_once __DIR__ . '/../../app/modules/lang/LANG01ERepository.php';
require_once __DIR__ . '/../../app/modules/lang/LANG01TRepository.php';

final class LanguageManager
{
  private static bool $booted = false;
  private static string $current = 'tr';
  private static array $activeMeta = []; // list of LANG01E docs
  private static array $dictCache = [];  // ['tr' => ['key'=>'text', ...], ...]

  public static function boot(): void
  {
    if (self::$booted) return;

    try {
      self::$activeMeta = LANG01ERepository::listActive();
      $default = LANG01ERepository::getDefaultLang();
    } catch (Throwable $e) {
      self::$activeMeta = [
        ['lang_code'=>'tr','name'=>'Türkçe','direction'=>'ltr','is_default'=>true,'is_active'=>true],
        ['lang_code'=>'en','name'=>'English','direction'=>'ltr','is_default'=>false,'is_active'=>true],
      ];
      $default = 'tr';
    }

    $sessLang = '';
    try { $sessLang = (string)($_SESSION['lang'] ?? ''); } catch (Throwable $e) { $sessLang=''; }
    $sessLang = strtolower(trim($sessLang));

    $activeCodes = [];
    foreach (self::$activeMeta as $d) {
      $lc = strtolower(trim((string)($d['lang_code'] ?? '')));
      if ($lc !== '') $activeCodes[] = $lc;
    }
    $activeCodes = array_values(array_unique($activeCodes));

    if ($sessLang !== '' && in_array($sessLang, $activeCodes, true)) {
      self::$current = $sessLang;
    } else {
      self::$current = $default ?: 'tr';
      $_SESSION['lang'] = self::$current;
    }

    self::$booted = true;
  }

  public static function get(): string
  {
    if (!self::$booted) self::boot();
    return self::$current;
  }

  public static function set(string $lang): void
  {
    if (!self::$booted) self::boot();

    $lc = strtolower(trim($lang));
    if ($lc === '') return;

    // sadece aktif dillere izin ver
    $activeCodes = array_map(fn($x)=>strtolower(trim((string)($x['lang_code'] ?? ''))), self::$activeMeta);
    $activeCodes = array_values(array_filter($activeCodes));

    if (!in_array($lc, $activeCodes, true)) return;

    self::$current = $lc;
    $_SESSION['lang'] = $lc;
  }

  /**
   * Aktif diller meta listesi döner (header2.php için ideal)
   */
  public static function getActiveLangs(): array
  {
    if (!self::$booted) self::boot();
    return self::$activeMeta;
  }

  private static function loadDict(string $lang): array
  {
    $lc = strtolower(trim($lang));
    if ($lc === '') $lc = 'tr';

    if (isset(self::$dictCache[$lc]) && is_array(self::$dictCache[$lc])) {
      return self::$dictCache[$lc];
    }

    // LANG01TRepository::dumpAll($lc) zaten key=>['text'=>..] gibi dönüyordu sende
    $dict = [];
    try {
      $rows = LANG01TRepository::dumpAll($lc);
      foreach ($rows as $k => $r) {
        if (is_array($r)) {
          $dict[(string)$k] = (string)($r['text'] ?? '');
        } else {
          $dict[(string)$k] = (string)$r;
        }
      }
    } catch (Throwable $e) {
      $dict = [];
    }

    self::$dictCache[$lc] = $dict;
    return $dict;
  }

  /**
   * translate
   */
  public static function t(string $key, array $params = []): string
  {
    if (!self::$booted) self::boot();

    $k = trim($key);
    if ($k === '') return '';

    $lc = self::$current ?: 'tr';
    $dict = self::loadDict($lc);
    $txt = $dict[$k] ?? '';

    // fallback: default lang'a bak
    if ($txt === '') {
      try {
        $def = LANG01ERepository::getDefaultLang();
      } catch (Throwable $e) { $def = 'tr'; }
      if ($def && $def !== $lc) {
        $d2 = self::loadDict($def);
        $txt = $d2[$k] ?? '';
      }
    }

    if ($txt === '') $txt = $k;

    // params replace: {name}
    if (!empty($params)) {
      foreach ($params as $pk => $pv) {
        $txt = str_replace('{' . $pk . '}', (string)$pv, $txt);
      }
    }

    return $txt;
  }
}
