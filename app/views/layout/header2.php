<?php
/**
 * header2.php (FINAL)
 *
 * - Materialize navbar yapısını BOZMADAN
 * - Aktif dillerden dinamik language dropdown
 * - Firma bilgisi: session context'ten company_name/company_code gösterir (DB'ye gitmez)
 *
 * BEKLENTİ:
 * - SessionManager::start() ve Context::bootFromSession() daha önce çalışmış olmalı.
 */

$ctx = [];
try { $ctx = Context::get(); } catch (Throwable $e) { $ctx = []; }

$username    = $ctx['username'] ?? '';
$companyId   = $ctx['CDEF01_id'] ?? '';
$companyName = $ctx['company_name'] ?? $companyId; // ✅ artık ID değil name göster
$companyCode = $ctx['company_code'] ?? '';
$period      = $ctx['period_id'] ?? '';

if (!function_exists('_e')) {
  function _e(string $key, array $params = []): void { echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('_t')) {
  function _t(string $key, array $params = []): string { return $key; }
}
if (!function_exists('h')) {
  function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

// ---- language safe fallback ----
$lang = 'tr';
$activeLangs = [
  ['lang_code' => 'tr', 'name' => 'Türkçe',  'direction' => 'ltr', 'is_default' => true],
  ['lang_code' => 'en', 'name' => 'English', 'direction' => 'ltr', 'is_default' => false],
];

try {
  if (class_exists('LanguageManager')) {
    if (method_exists('LanguageManager', 'boot')) {
      LanguageManager::boot();
    }

    if (method_exists('LanguageManager', 'get')) {
      $tmp = (string)LanguageManager::get();
      if ($tmp !== '') $lang = $tmp;
    } elseif (!empty($_SESSION['lang'])) {
      $lang = (string)$_SESSION['lang'];
    }

    if (method_exists('LanguageManager', 'getActiveLangs')) {
      $list = LanguageManager::getActiveLangs();
      if (is_array($list) && !empty($list)) {
        $norm = [];
        foreach ($list as $it) {
          if (is_string($it)) {
            $lc = strtolower(trim($it));
            if ($lc !== '') {
              $norm[] = ['lang_code'=>$lc,'name'=>strtoupper($lc),'direction'=>'ltr','is_default'=>false];
            }
          } elseif (is_array($it)) {
            $lc = strtolower(trim((string)($it['lang_code'] ?? '')));
            if ($lc !== '') {
              $norm[] = [
                'lang_code'   => $lc,
                'name'        => (string)($it['name'] ?? strtoupper($lc)),
                'direction'   => (string)($it['direction'] ?? 'ltr'),
                'is_default'  => (bool)($it['is_default'] ?? false),
              ];
            }
          }
        }
        if (!empty($norm)) $activeLangs = $norm;
      }
    }
  }
} catch (Throwable $e) {}

function lang_flag_class(string $lc): string {
  $lc = strtolower(trim($lc));
  $map = [
    'tr' => 'fi-tr',
    'en' => 'fi-us',
    'de' => 'fi-de',
    'fr' => 'fi-fr',
    'ru' => 'fi-ru',
    'ar' => 'fi-sa',
  ];
  return $map[$lc] ?? 'fi-gl';
}

$langFlag = lang_flag_class($lang);

$next = $_SERVER['REQUEST_URI'] ?? '/php-mongo-erp/public/index.php';
if ($next === '') $next = '/php-mongo-erp/public/index.php';
?>
<nav
  class="layout-navbar container-xxl navbar-detached navbar navbar-expand-xl align-items-center bg-navbar-theme"
  id="layout-navbar">

  <div class="layout-menu-toggle navbar-nav align-items-xl-center me-4 me-xl-0 d-xl-none">
    <a class="nav-item nav-link px-0 me-xl-6" href="javascript:void(0)">
      <i class="icon-base ri ri-menu-line icon-22px"></i>
    </a>
  </div>

  <div class="navbar-nav-right d-flex align-items-center justify-content-end" id="navbar-collapse">

    <div class="navbar-nav align-items-center">
      <div class="nav-item navbar-search-wrapper mb-0">
        <a class="nav-item nav-link search-toggler px-0" href="javascript:void(0);">
          <span class="d-inline-block text-body-secondary fw-normal" id="autocomplete"></span>
        </a>
      </div>
    </div>

    <ul class="navbar-nav flex-row align-items-center ms-md-auto">
      <div>
        <span style="margin-left:10px;"><?php _e('common.firma'); ?>:
          <strong><?php echo h($companyName); ?></strong>
          <?php if ($companyCode !== ''): ?>
            <span class="text-muted" style="font-size:12px;">(<?php echo h($companyCode); ?>)</span>
          <?php endif; ?>
        </span>

        <span style="margin-left:10px;"><?php _e('common.period'); ?>:
          <strong><?php echo h($period); ?></strong>
        </span>
      </div>

      <!-- Quick links (Language) -->
      <li class="nav-item dropdown-shortcuts navbar-dropdown dropdown me-sm-2 me-xl-0">
        <a
          class="nav-link dropdown-toggle hide-arrow btn btn-icon btn-text-secondary rounded-pill"
          href="javascript:void(0);"
          data-bs-toggle="dropdown"
          data-bs-auto-close="outside"
          aria-expanded="false"
          title="<?php echo h(strtoupper($lang)); ?>">
          <i class="fi <?php echo h($langFlag); ?> fis" style="font-size:18px;"></i>
        </a>

        <div class="dropdown-menu dropdown-menu-end p-0">
          <div class="dropdown-menu-header border-bottom">
            <div class="dropdown-header d-flex align-items-center py-3">
              <h6 class="mb-0 me-auto"><?php _e('common.change_language'); ?></h6>
            </div>
          </div>

          <div class="dropdown-shortcuts-list scrollable-container">
            <div class="row row-bordered overflow-visible g-0">

              <?php foreach ($activeLangs as $li):
                $lc = strtolower(trim((string)($li['lang_code'] ?? '')));
                if ($lc === '') continue;

                $name = (string)($li['name'] ?? strtoupper($lc));
                $flag = lang_flag_class($lc);
                $isCurrent = ($lc === strtolower((string)$lang));

                $href = '/php-mongo-erp/public/set_lang.php?lang=' . rawurlencode($lc)
                      . '&next=' . rawurlencode($next);

                $style = $isCurrent ? 'background:rgba(0,0,0,.03);' : '';
              ?>
                <a class="dropdown-shortcuts-item col"
                  href="<?php echo h($href); ?>"
                  style="<?php echo $style; ?> text-decoration:none;">
                  <span class="dropdown-shortcuts-icon rounded-circle mb-2">
                    <i class="fi <?php echo h($flag); ?> fis" style="font-size:25px;"></i>
                  </span>

                  <div class="small" style="text-align:center; margin-top:-4px;">
                    <?php echo h($name); ?>
                    <?php if ($isCurrent): ?>
                      <div class="small text-success">✔</div>
                    <?php endif; ?>
                  </div>
                </a>
              <?php endforeach; ?>

            </div>
          </div>
        </div>
      </li>
      <!-- /Quick links -->

      <!-- Notification (aynı) -->
      <li class="nav-item dropdown-notifications navbar-dropdown dropdown me-4 me-xl-1">
        <a
          class="nav-link dropdown-toggle hide-arrow btn btn-icon btn-text-secondary rounded-pill"
          href="javascript:void(0);"
          data-bs-toggle="dropdown"
          data-bs-auto-close="outside"
          aria-expanded="false">
          <i class="icon-base ri ri-notification-2-line icon-22px"></i>
          <span class="position-absolute top-0 start-50 translate-middle-y badge badge-dot bg-danger mt-2 border"></span>
        </a>
        <?php /* notification dropdown HTML'in olduğu gibi kalsın */ ?>
      </li>

      <span style="float:right;">
        <a href="/php-mongo-erp/public/change_period.php"><?php _e('period.change'); ?></a>
        &nbsp; | &nbsp;
      </span>

      <!-- User dropdown (aynı) -->
      <li class="nav-item navbar-dropdown dropdown-user dropdown">
        <a class="nav-link dropdown-toggle hide-arrow" href="javascript:void(0);" data-bs-toggle="dropdown">
          <div class="avatar avatar-online">
            <img src="theme/assets/img/avatars/1.png" alt="avatar" class="rounded-circle" />
          </div>
        </a>
        <ul class="dropdown-menu dropdown-menu-end mt-3 py-2">
          <li>
            <a class="dropdown-item" href="pages-account-settings-account.html">
              <div class="d-flex align-items-center">
                <div class="flex-shrink-0 me-2">
                  <div class="avatar avatar-online">
                    <img src="theme/assets/img/avatars/1.png" alt="alt" class="w-px-40 h-auto rounded-circle" />
                  </div>
                </div>
                <div class="flex-grow-1">
                  <h6 class="mb-0 small"><?php echo h($ctx['username'] ?? ''); ?></h6>
                  <small class="text-body-secondary">#</small>
                </div>
              </div>
            </a>
          </li>
          <li><div class="dropdown-divider"></div></li>
          <li>
            <div class="d-grid px-4 pt-2 pb-1">
              <a class="btn btn-sm btn-danger d-flex" href="/php-mongo-erp/public/logout.php" target="_blank">
                <small class="align-middle"><?php _e('common.logout'); ?></small>
                <i class="icon-base ri ri-logout-box-r-line ms-2 icon-16px"></i>
              </a>
            </div>
          </li>
        </ul>
      </li>

    </ul>
  </div>
</nav>
