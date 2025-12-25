<?php
/**
 * change_period.php
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

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/auth/SessionManager.php';
require_once __DIR__ . '/../core/base/Context.php';
require_once __DIR__ . '/../core/base/ContextException.php';
require_once __DIR__ . '/../core/action/ActionLogger.php';
require_once __DIR__ . '/../app/modules/period/PERIOD01Repository.php';

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
        // Mevcut döneme tekrar set etmeye gerek yok
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
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Dönem Değiştir</title>
</head>
<body>
<div style="text-align:right; margin-bottom:10px;">
    <a href="/php-mongo-erp/public/set_lang.php?lang=tr">TR</a> |
    <a href="/php-mongo-erp/public/set_lang.php?lang=en">EN</a>
</div>
<h3>Dönem Değiştir</h3>

<?php if ($error): ?>
    <p style="color:red;"><?php echo htmlspecialchars($error); ?></p>
<?php endif; ?>

<p>
    Kullanıcı: <strong><?php echo htmlspecialchars($ctx['username'] ?? ''); ?></strong><br>
    Mevcut Dönem: <strong><?php echo htmlspecialchars($currentPeriod ?? ''); ?></strong>
</p>

<form method="POST">
    <label>Dönem</label><br>
    <select name="period_id" required>
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
                value="<?php echo htmlspecialchars($pid); ?>"
                <?php echo $disabled ? 'disabled' : ''; ?>
                <?php echo $isCurrent ? 'selected' : ''; ?>
                style="<?php echo $style; ?>"
            >
                <?php echo htmlspecialchars($title . $suffix); ?>
            </option>
        <?php endforeach; ?>

    </select><br><br>

    <button type="submit">Kaydet</button>
    &nbsp;
    <a href="/php-mongo-erp/public/index.php">İptal</a>
</form>

</body>
</html>
