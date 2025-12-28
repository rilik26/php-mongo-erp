<?php
/**
 * core/i18n/LanguageManager.php (FINAL - FIX)
 *
 * - boot(): aktif dilleri yükler (BSONDocument stabil)
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
  private static array $activeMeta = []; // list of LANG01E docs (array normalized)
  private static array $dictCache = [];  // ['tr' => ['key'=>'text', ...], ...]

  /** BSONDocument/BSONArray -> array normalize (recursive) */
  private static function normalize($v) {
    if ($v instanceof MongoDB\Model\BSONDocument || $v instanceof MongoDB\Model\BSONArray) {
      $v = $v->getArrayCopy();
    }
    if (is_array($v)) {
      $out = [];
      foreach ($v as $k => $vv) $out[$k] = self::normalize($vv);
      return $out;
    }
    return $v;
  }

  /** activeMeta'dan aktif dil kodlarını güvenli çıkar */
  private static function extractActiveCodes(array $meta): array {
    $codes = [];
    foreach ($meta as $d) {
      $d = self::normalize($d);
      if (!is_array($d)) continue;
      $lc = strtolower(trim((string)($d['lang_code'] ?? '')));
      if ($lc !== '') $codes[] = $lc;
    }
    $codes = array_values(array_unique($codes));
    return $codes;
  }

  public static function boot(): void
  {
    if (self::$booted) return;

    $default = 'tr';

    try {
      $raw = LANG01ERepository::listActive();
      $raw = self::normalize($raw);
      self::$activeMeta = is_array($raw) ? $raw : [];

      $def = LANG01ERepository::getDefaultLang();
      $def = self::normalize($def);

      // getDefaultLang bazen doc/array döndürür -> normalize et
      if (is_string($def)) {
        $default = strtolower(trim($def)) ?: 'tr';
      } elseif (is_array($def)) {
        $tmp = strtolower(trim((string)($def['lang_code'] ?? '')));
        if ($tmp !== '') $default = $tmp;
      }
    } catch (Throwable $e) {
      self::$activeMeta = [
        ['lang_code'=>'tr','name'=>'Türkçe','direction'=>'ltr','is_default'=>true,'is_active'=>true],
        ['lang_code'=>'en','name'=>'English','direction'=>'ltr','is_default'=>false,'is_active'=>true],
      ];
      $default = 'tr';
    }

    // aktif diller boş geldiyse fallback (en kritik kısım)
    $activeCodes = self::extractActiveCodes(self::$activeMeta);
    if (empty($activeCodes)) {
      self::$activeMeta = [
        ['lang_code'=>'tr','name'=>'Türkçe','direction'=>'ltr','is_default'=>true,'is_active'=>true],
        ['lang_code'=>'en','name'=>'English','direction'=>'ltr','is_default'=>false,'is_active'=>true],
      ];
      $activeCodes = ['tr','en'];
      $default = 'tr';
    }

    $sessLang = '';
    try { $sessLang = (string)($_SESSION['lang'] ?? ''); } catch (Throwable $e) { $sessLang=''; }
    $sessLang = strtolower(trim($sessLang));

    if ($sessLang !== '' && in_array($sessLang, $activeCodes, true)) {
      self::$current = $sessLang;
    } else {
      self::$current = in_array($default, $activeCodes, true) ? $default : ($activeCodes[0] ?? 'tr');
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

    $activeCodes = self::extractActiveCodes(self::$activeMeta);
    if (empty($activeCodes)) return;

    if (!in_array($lc, $activeCodes, true)) return;

    self::$current = $lc;
    $_SESSION['lang'] = $lc;

    // (opsiyonel ama faydalı) dict cache'i temizle
    // self::$dictCache = [];
  }

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

    $dict = [];
    try {
      $rows = LANG01TRepository::dumpAll($lc);
      $rows = self::normalize($rows);

      if (is_array($rows)) {
        foreach ($rows as $k => $r) {
          if (is_array($r)) $dict[(string)$k] = (string)($r['text'] ?? '');
          else $dict[(string)$k] = (string)$r;
        }
      }
    } catch (Throwable $e) {
      $dict = [];
    }

    self::$dictCache[$lc] = $dict;
    return $dict;
  }

  public static function t(string $key, array $params = []): string
  {
    if (!self::$booted) self::boot();

    $k = trim($key);
    if ($k === '') return '';

    $lc = self::$current ?: 'tr';
    $dict = self::loadDict($lc);
    $txt = $dict[$k] ?? '';

    if ($txt === '') {
      $def = 'tr';
      try {
        $d = LANG01ERepository::getDefaultLang();
        $d = self::normalize($d);
        if (is_string($d)) $def = strtolower(trim($d)) ?: 'tr';
        elseif (is_array($d)) {
          $tmp = strtolower(trim((string)($d['lang_code'] ?? '')));
          if ($tmp !== '') $def = $tmp;
        }
      } catch (Throwable $e) { $def = 'tr'; }

      if ($def !== $lc) {
        $d2 = self::loadDict($def);
        $txt = $d2[$k] ?? '';
      }
    }

    if ($txt === '') $txt = $k;

    if (!empty($params)) {
      foreach ($params as $pk => $pv) {
        $txt = str_replace('{' . $pk . '}', (string)$pv, $txt);
      }
    }

    return $txt;
  }
}
