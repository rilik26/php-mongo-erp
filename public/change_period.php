<?php
/**
 * change_period.php (FINAL)
 *
 * AMAÇ:
 * - Login olmuş kullanıcı için period_id değiştirmek
 * - Firma bazlı dönemleri göstermek
 * - Kapalı dönem: gri + disabled
 * - Mevcut dönem: yeşil + disabled (görünsün ama seçilemesin)
 * - Diğer açık dönemler: seçilebilir
 * - Seçilen dönem açık değilse değişikliğe izin verme
 * - CHANGE_PERIOD aksiyonunu loglamak
 */

require_once __DIR__ . '/../app/views/layout/header.php';

SessionManager::start();

try {
  Context::bootFromSession();
} catch (ContextException $e) {
  header('Location: /php-mongo-erp/public/login.php');
  exit;
}

$ctx = Context::get();
$companyId = $ctx['CDEF01_id'] ?? null;
$currentPeriod = $ctx['period_id'] ?? null;

if (!$companyId) {
  echo "Firma bulunamadı.";
  exit;
}

$periods = PERIOD01Repository::listAllPeriods($companyId);

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $newPeriodId = $_POST['period_id'] ?? '';

  if ($newPeriodId === '') {
    $error = "Dönem seçilmelidir.";
  } elseif ($newPeriodId === $currentPeriod) {
    $error = "Zaten bu dönemdensiniz.";
  } else {
    if (!PERIOD01Repository::isOpen($newPeriodId, $companyId)) {
      $error = "Seçilen dönem kapalı veya geçersiz.";
    } else {
      $oldPeriod = $_SESSION['context']['period_id'] ?? null;

      $_SESSION['context']['period_id'] = $newPeriodId;

      ActionLogger::log('CHANGE_PERIOD', [
        'from' => $oldPeriod,
        'to'   => $newPeriodId
      ], $_SESSION['context']);

      header('Location: /php-mongo-erp/public/index.php');
      exit;
    }
  }
}

// esc/h varsa çakışmasın diye
if (!function_exists('esc')) {
  function esc($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
?>

<body>
<div class="layout-wrapper layout-content-navbar">
  <div class="layout-container">

    <?php require_once __DIR__ . '/../app/views/layout/left.php'; ?>

    <div class="layout-page">

      <?php require_once __DIR__ . '/../app/views/layout/header2.php'; ?>

      <div class="content-wrapper">
        <div class="container-xxl flex-grow-1 container-p-y">

          <div class="row g-6">

            <div class="col-md-12">
              <div class="card card-border-shadow-primary">
                <div class="card-body">
                  <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <div>
                      <h4 class="mb-1">Dönem Değiştir</h4>
                      <div class="small text-muted">
                        Kullanıcı: <b><?php echo esc($ctx['username'] ?? ''); ?></b>
                        &nbsp;|&nbsp; Firma: <b><?php echo esc($companyId); ?></b>
                        &nbsp;|&nbsp; Mevcut dönem: <b><?php echo esc($currentPeriod ?? ''); ?></b>
                      </div>
                    </div>

                    <div class="d-flex gap-2">
                      <a class="btn btn-outline-secondary btn-sm" href="/php-mongo-erp/public/set_lang.php?lang=tr">TR</a>
                      <a class="btn btn-outline-secondary btn-sm" href="/php-mongo-erp/public/set_lang.php?lang=en">EN</a>
                    </div>
                  </div>

                  <?php if ($error): ?>
                    <div class="alert alert-outline-danger mt-3" role="alert">
                      <?php echo esc($error); ?>
                    </div>
                  <?php endif; ?>

                  <form method="POST" class="mt-3" style="max-width:520px;">
                    <div class="mb-3">
                      <label class="form-label">Dönem</label>
                      <select class="form-select" name="period_id" required>
                        <option value="">Seçiniz</option>

                        <?php foreach ($periods as $p):
                          $pid    = $p['period_id'] ?? '';
                          $title  = $p['title'] ?? $pid;
                          $isOpen = (bool)($p['is_open'] ?? false);

                          $isCurrent = ($pid !== '' && $pid === $currentPeriod);

                          // Kapalı dönem: disabled + gri
                          // Mevcut dönem: disabled + yeşil
                          $disabled = (!$isOpen) || $isCurrent;

                          $style = '';
                          if (!$isOpen) {
                            $style = 'color:#999;';
                          } elseif ($isCurrent) {
                            $style = 'color:green; font-weight:bold;';
                          }

                          $suffix = '';
                          if (!$isOpen) {
                            $suffix = ' (kapalı)';
                          } elseif ($isCurrent) {
                            $suffix = ' (mevcut)';
                          }
                        ?>
                          <option
                            value="<?php echo esc($pid); ?>"
                            <?php echo $disabled ? 'disabled' : ''; ?>
                            <?php echo $isCurrent ? 'selected' : ''; ?>
                            style="<?php echo $style; ?>"
                          >
                            <?php echo esc($title . $suffix); ?>
                          </option>
                        <?php endforeach; ?>

                      </select>
                    </div>

                    <div class="d-flex gap-2">
                      <button class="btn btn-primary" type="submit">Kaydet</button>
                      <a class="btn btn-outline-secondary" href="/php-mongo-erp/public/index.php">İptal</a>
                    </div>

                    <div class="text-muted mt-3" style="font-size:12px;">
                      Not: Kapalı dönemler seçilemez. Mevcut dönem yeşil gösterilir.
                    </div>
                  </form>

                </div>
              </div>
            </div>

          </div>

        </div>
        <div class="content-backdrop fade"></div>
      </div>

    </div>
  </div>

  <div class="layout-overlay layout-menu-toggle"></div>
  <div class="drag-target"></div>
</div>

<?php require_once __DIR__ . '/../app/views/layout/footer.php'; ?>
</body>
</html>
