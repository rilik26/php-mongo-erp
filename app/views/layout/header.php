<?php
// ✅ en üstte, HTML basmadan önce:
require_once __DIR__ . '../../../../core/auth/SessionManager.php';
SessionManager::start(); // ✅ session önce

require_once __DIR__ . '../../../../core/bootstrap.php'; // ✅ bootstrap sonra (i18n boot session varken)

require_once __DIR__ . '../../../../core/base/Context.php';
require_once __DIR__ . '../../../../core/base/ContextException.php';
require_once __DIR__ . '../../../../core/auth/permission_helpers.php';
require_once __DIR__ . '../../../../core/action/ActionLogger.php';
require_once __DIR__ . '../../../../core/event/EventWriter.php';
require_once __DIR__ . '../../../../core/snapshot/SnapshotWriter.php';

require_once __DIR__ . '../../../../app/modules/lang/LANG01ERepository.php';
require_once __DIR__ . '../../../../app/modules/lang/LANG01TRepository.php';
require_once __DIR__ . '../../../../app/modules/period/PERIOD01Repository.php';

// h() yoksa fallback
if (!function_exists('h')) {
  function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
?>
<!doctype html>

<html
  lang="en"
  class="layout-navbar-fixed layout-menu-fixed layout-compact"
  dir="ltr"
  data-skin="default"
  data-bs-theme="light"
  data-assets-path="theme/assets/"
  data-template="vertical-menu-template">
  <head>
    <meta charset="utf-8" />
    <meta
      name="viewport"
      content="width=device-width, initial-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0" />
    <meta name="robots" content="noindex, nofollow" />
    <title>Demo: Dashboard - Analytics | Materialize - Bootstrap Dashboard PRO</title>

    <meta name="description" content="" />

    <link rel="icon" type="image/x-icon" href="theme/assets/img/favicon/favicon.ico" />

    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
      href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&ampdisplay=swap"
      rel="stylesheet" />

    <link rel="stylesheet" href="theme/assets/vendor/fonts/iconify-icons.css" />

    <link rel="stylesheet" href="theme/assets/vendor/libs/node-waves/node-waves.css" />
    <link rel="stylesheet" href="theme/assets/vendor/libs/pickr/pickr-themes.css" />
    <link rel="stylesheet" href="theme/assets/vendor/css/core.css" />
    <link rel="stylesheet" href="theme/assets/css/demo.css" />
    <link rel="stylesheet" href="theme/assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />

    <link rel="stylesheet" href="theme/assets/vendor/libs/apex-charts/apex-charts.css" />
    <link rel="stylesheet" href="theme/assets/vendor/libs/swiper/swiper.css" />
    <link rel="stylesheet" href="theme/assets/vendor/css/pages/cards-statistics.css" />

    <script src="theme/assets/vendor/js/helpers.js"></script>
    <script src="theme/assets/vendor/js/template-customizer.js"></script>
    <script src="theme/assets/js/config.js"></script>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/flag-icon-css/7.5.0/css/flag-icons.min.css" />
  </head>
