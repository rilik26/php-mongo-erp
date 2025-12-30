<?php
/**
 * public/login.php (FINAL)
 *
 * - Tek ekranda login: username + password + period (PERIOD01T_id)
 * - period select: username'e göre AJAX ile doldurulur
 * - Dil değiştirme: TR/EN
 */

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/auth/SessionManager.php';
require_once __DIR__ . '/../core/auth/AuthService.php';

SessionManager::start();

$error = null;

if (isset($_SESSION['context']) && is_array($_SESSION['context'])) {
    header('Location: /php-mongo-erp/public/index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username  = (string)($_POST['username'] ?? '');
    $password  = (string)($_POST['password'] ?? '');
    // ✅ artık select value = PERIOD01T _id (24 char)
    $periodOid = (string)($_POST['period_id'] ?? '');

    if (AuthService::attempt($username, $password, $periodOid)) {
        header('Location: /php-mongo-erp/public/index.php');
        exit;
    }

    $error = __('auth.login_failed');
}
?>

<!doctype html>
<html
  lang="en"
  class="layout-wide customizer-hide"
  dir="ltr"
  data-skin="default"
  data-bs-theme="light"
  data-assets-path="theme/assets/"
  data-template="vertical-menu-template">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0" />
    <meta name="robots" content="noindex, nofollow" />

    <title><?php _e('auth.login'); ?></title>
    <meta name="description" content="" />

    <link rel="icon" type="image/x-icon" href="theme/assets/img/favicon/favicon.ico" />

    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&ampdisplay=swap" rel="stylesheet" />

    <link rel="stylesheet" href="theme/assets/vendor/fonts/iconify-icons.css" />
    <link rel="stylesheet" href="theme/assets/vendor/libs/node-waves/node-waves.css" />
    <script src="theme/assets/vendor/libs/@algolia/autocomplete-js.js"></script>
    <link rel="stylesheet" href="theme/assets/vendor/libs/pickr/pickr-themes.css" />
    <link rel="stylesheet" href="theme/assets/vendor/css/core.css" />
    <link rel="stylesheet" href="theme/assets/css/demo.css" />
    <link rel="stylesheet" href="theme/assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />
    <link rel="stylesheet" href="theme/assets/vendor/libs/@form-validation/form-validation.css" />
    <link rel="stylesheet" href="theme/assets/vendor/css/pages/page-auth.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/flag-icon-css/7.5.0/css/flag-icons.min.css" />

    <script src="theme/assets/vendor/js/helpers.js"></script>
    <script src="theme/assets/vendor/js/template-customizer.js"></script>
    <script src="theme/assets/js/config.js"></script>
  </head>

  <body>
    <div class="authentication-wrapper authentication-cover">
      <a href="index.html" class="auth-cover-brand d-flex align-items-center gap-2">
        <span class="app-brand-logo demo">
          <span class="text-primary">
            <!-- logo svg aynı -->
          </span>
        </span>
        <span class="app-brand-text demo text-heading fw-semibold">Materialize</span>
      </a>

      <div class="authentication-inner row m-0">
        <div class="d-none d-lg-flex col-lg-7 col-xl-8 align-items-center justify-content-center p-12 pb-2">
          <img
            src="theme/assets/img/illustrations/auth-login-illustration-light.png"
            class="auth-cover-illustration w-100"
            alt="auth-illustration"
            data-app-light-img="illustrations/auth-login-illustration-light.png"
            data-app-dark-img="illustrations/auth-login-illustration-dark.png" />
          <img
            alt="mask"
            src="theme/assets/img/illustrations/auth-basic-login-mask-light.png"
            class="authentication-image d-none d-lg-block"
            data-app-light-img="illustrations/auth-basic-login-mask-light.png"
            data-app-dark-img="illustrations/auth-basic-login-mask-dark.png" />
        </div>

        <div class="d-flex col-12 col-lg-5 col-xl-4 align-items-center authentication-bg position-relative py-sm-12 px-12 py-6">
          <div class="w-px-400 mx-auto pt-12 pt-lg-0">
            <h4 class="mb-1"><?php _e('auth.welcome_title'); ?></h4>
            <p class="mb-5"><?php _e('auth.login'); ?></p>

            <?php if ($error): ?>
              <p style="color:red;"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
            <?php endif; ?>

            <form method="POST">
              <div class="form-floating form-floating-outline mb-5">
                <input type="text" class="form-control" id="username" name="username" required>
                <label for="username"><?php _e('auth.username'); ?></label>
              </div>

              <div class="mb-5">
                <div class="form-password-toggle">
                  <div class="input-group input-group-merge">
                    <div class="form-floating form-floating-outline">
                      <input type="password" class="form-control" id="password" name="password" required>
                      <label for="password"><?php _e('auth.password'); ?></label>
                    </div>
                    <span class="input-group-text cursor-pointer">
                      <i class="icon-base ri ri-eye-off-line icon-20px"></i>
                    </span>
                  </div>
                </div>
              </div>

              <div class="mb-5">
                <div class="input-group input-group-merge">
                  <div class="form-floating form-floating-outline">
                    <!-- ✅ name aynı kaldı ama value artık PERIOD01T_id -->
                    <select id="period_id" class="form-control" name="period_id" required>
                      <option value=""><?php _e('period.select'); ?></option>
                    </select>
                    <label for="period_id"><?php _e('auth.period'); ?></label>
                  </div>
                </div>
              </div>

              <button type="submit" class="btn btn-primary d-grid w-100"><?php _e('auth.login'); ?></button>
            </form>

            <div class="divider my-5">
              <div class="divider-text"><?php _e('common.languages'); ?></div>
            </div>

            <div class="d-flex justify-content-center gap-2">
              <a href="/php-mongo-erp/public/set_lang.php?lang=tr"><i class="fi fi-tr fis" style="font-size:25px;"></i></a>
              <a href="/php-mongo-erp/public/set_lang.php?lang=en"><i class="fi fi-us fis" style="font-size:25px;"></i></a>
            </div>

          </div>
        </div>
      </div>
    </div>

    <script src="theme/assets/vendor/libs/jquery/jquery.js"></script>
    <script src="theme/assets/vendor/libs/popper/popper.js"></script>
    <script src="theme/assets/vendor/js/bootstrap.js"></script>
    <script src="theme/assets/vendor/libs/node-waves/node-waves.js"></script>
    <script src="theme/assets/vendor/libs/@algolia/autocomplete-js.js"></script>
    <script src="theme/assets/vendor/libs/pickr/pickr.js"></script>
    <script src="theme/assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
    <script src="theme/assets/vendor/libs/hammer/hammer.js"></script>
    <script src="theme/assets/vendor/libs/i18n/i18n.js"></script>
    <script src="theme/assets/vendor/js/menu.js"></script>

    <script src="theme/assets/vendor/libs/@form-validation/popular.js"></script>
    <script src="theme/assets/vendor/libs/@form-validation/bootstrap5.js"></script>
    <script src="theme/assets/vendor/libs/@form-validation/auto-focus.js"></script>

    <script src="theme/assets/js/main.js"></script>
    <script src="theme/assets/js/pages-auth.js"></script>

    <script>
      window.I18N = {
        select: "<?php echo addslashes(_t('period.select')); ?>",
        loading: "<?php echo addslashes(_t('period.loading')); ?>",
        none: "<?php echo addslashes(_t('period.none_open')); ?>",
        failed: "<?php echo addslashes(_t('period.load_failed')); ?>",
        enterUser: "<?php echo addslashes(_t('auth.enter_username_first')); ?>",
        closedTag: "<?php echo addslashes(_t('period.closed')); ?>"
      };
    </script>

    <script>
      (function() {
        const usernameEl = document.getElementById('username');
        const periodEl = document.getElementById('period_id');

        function resetPeriods(msg) {
          periodEl.innerHTML = '';
          const opt = document.createElement('option');
          opt.value = '';
          opt.textContent = msg;
          periodEl.appendChild(opt);
        }

        async function loadPeriods(username) {
          resetPeriods(window.I18N.loading);

          try {
            const url = '/php-mongo-erp/public/api/open_periods.php?username=' + encodeURIComponent(username);
            const res = await fetch(url, { method: 'GET' });
            const data = await res.json();

            if (!data.ok || !Array.isArray(data.periods) || data.periods.length === 0) {
              resetPeriods(window.I18N.none);
              return;
            }

            periodEl.innerHTML = '';
            const first = document.createElement('option');
            first.value = '';
            first.textContent = window.I18N.select;
            periodEl.appendChild(first);

            data.periods.forEach(p => {
              const opt = document.createElement('option');
              // ✅ value = PERIOD01T _id
              opt.value = p.period_oid || '';
              opt.textContent = p.title || p.period_id || '';

              if (p.is_open === false) {
                opt.disabled = true;
                opt.style.color = '#999';
                opt.textContent += ' (' + window.I18N.closedTag + ')';
              }

              periodEl.appendChild(opt);
            });

          } catch (e) {
            resetPeriods(window.I18N.failed);
          }
        }

        let last = '';
        usernameEl.addEventListener('blur', () => {
          const u = (usernameEl.value || '').trim();

          if (!u) {
            resetPeriods(window.I18N.enterUser);
            return;
          }

          if (u === last) return;
          last = u;
          loadPeriods(u);
        });
      })();
    </script>
  </body>
</html>
