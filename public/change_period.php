<?php
/**
 * public/change_period.php (FINAL)
 *
 * AMAÇ:
 * - Login guard
 * - Firma bazlı açık dönem kontrolü (PERIOD01T._id)
 * - Session context'te SADECE period değiştirir
 *
 * YENİ MODEL:
 * - context.PERIOD01T_id
 * - context.period_label
 */

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/auth/SessionManager.php';
require_once __DIR__ . '/../core/base/Context.php';
require_once __DIR__ . '/../core/base/ContextException.php';
require_once __DIR__ . '/../core/action/ActionLogger.php';
require_once __DIR__ . '/../app/modules/period/PERIOD01Repository.php';

SessionManager::start();

/* ------------------ LOGIN GUARD ------------------ */
if (!isset($_SESSION['context']) || !is_array($_SESSION['context'])) {
    header('Location: /php-mongo-erp/public/login.php');
    exit;
}

try {
    Context::bootFromSession();
} catch (ContextException $e) {
    header('Location: /php-mongo-erp/public/login.php');
    exit;
}

$ctx = Context::get();

function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/* ------------------ CONTEXT ------------------ */
$companyId = (string)($ctx['CDEF01_id'] ?? '');
if ($companyId === '' || strlen($companyId) !== 24) {
    echo 'company_not_in_context';
    exit;
}

// Firma adı/kodu context’te yoksa tamamla (safe)
if (empty($_SESSION['context']['company_name']) || !array_key_exists('company_code', $_SESSION['context'])) {
    try {
        $c = MongoManager::collection('CDEF01E')->findOne([
            '_id'    => new MongoDB\BSON\ObjectId($companyId),
            'active' => true
        ]);
        if ($c instanceof MongoDB\Model\BSONDocument) $c = $c->getArrayCopy();
        if (is_array($c)) {
            $_SESSION['context']['company_name'] ??= (string)($c['name'] ?? $companyId);
            $_SESSION['context']['company_code'] ??= (string)($c['code'] ?? '');
        }
    } catch (Throwable $e) {}
}

/* ------------------ PERIOD LIST ------------------ */
$periods = PERIOD01Repository::listAllPeriods($companyId);

/* ------------------ POST: CHANGE PERIOD ------------------ */
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $periodOid = trim((string)($_POST['PERIOD01T_id'] ?? ''));

    if ($periodOid === '' || strlen($periodOid) !== 24) {
        $error = 'period_required';
    }
    elseif (!PERIOD01Repository::isOpenById($periodOid, $companyId)) {
        $error = 'period_closed_or_invalid';
    }
    else {
        // period label bul
        $periodLabel = $periodOid;
        try {
            $p = PERIOD01Repository::getById($periodOid);
            if ($p) {
                $periodLabel = (string)($p['title'] ?? ($p['period_id'] ?? $periodLabel));
            }
        } catch (Throwable $e) {}

        // ✅ context güncelle
        $_SESSION['context']['PERIOD01T_id'] = $periodOid;
        $_SESSION['context']['period_label'] = $periodLabel;

        ActionLogger::success('PERIOD.CHANGE', [
            'source'        => 'public/change_period.php',
            'PERIOD01T_id'  => $periodOid,
        ], $_SESSION['context']);

        // Context singleton sync
        try {
            Context::bootFromSession();
        } catch (Throwable $e) {}

        header('Location: /php-mongo-erp/public/index.php');
        exit;
    }
}
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <title>Dönem Değiştir</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <style>
        body { font-family: Arial, sans-serif; background:#0f1220; color:#e7eaf3; margin:0; }
        .wrap { max-width:720px; margin:0 auto; padding:18px; }
        .card { background:#272b40; border:1px solid rgba(255,255,255,.10); border-radius:14px; padding:14px; }
        .row { display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
        select,button { height:44px; border-radius:10px; border:1px solid rgba(255,255,255,.14); background:transparent; color:#e7eaf3; padding:0 12px; }
        button { cursor:pointer; background:#5865f2; border-color:transparent; }
        .muted { color:#a7adc3; font-size:12px; margin-top:8px; }
        .err { margin-top:10px; color:#ffb4b4; font-size:13px; }
    </style>
</head>
<body>
<div class="wrap">

<div class="card">
    <h3 style="margin:0 0 10px;">Dönem Değiştir</h3>

    <div class="muted">
        Firma:
        <b><?php echo h($_SESSION['context']['company_name'] ?? $companyId); ?></b>
        <?php if (!empty($_SESSION['context']['company_code'])): ?>
            (<?php echo h($_SESSION['context']['company_code']); ?>)
        <?php endif; ?>
        <br>
        Mevcut dönem:
        <b><?php echo h($ctx['period_label'] ?? '-'); ?></b>
    </div>

    <?php if ($error): ?>
        <div class="err">Hata: <?php echo h($error); ?></div>
    <?php endif; ?>

    <form method="POST" style="margin-top:12px;">
        <div class="row">
            <select name="PERIOD01T_id" required>
                <option value="">Dönem seç</option>

                <?php foreach ($periods as $p):
                    $oid   = (string)($p['period_oid'] ?? '');
                    if ($oid === '') continue;

                    $title = (string)($p['title'] ?? $oid);
                    $isOpen = (bool)($p['is_open'] ?? false);
                    $sel = (($ctx['PERIOD01T_id'] ?? '') === $oid) ? 'selected' : '';
                    $dis = $isOpen ? '' : 'disabled';
                    $tag = $isOpen ? '' : ' (KAPALI)';
                ?>
                    <option value="<?php echo h($oid); ?>" <?php echo $sel; ?> <?php echo $dis; ?>>
                        <?php echo h($title . $tag); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit">Kaydet</button>
            <a href="/php-mongo-erp/public/index.php"
               style="color:#a7adc3; text-decoration:none; line-height:44px;">
               İptal
            </a>
        </div>
    </form>

    <div class="muted">
        Not: Kapalı dönem seçilemez. Güvenlik kontrolü server tarafında yapılır.
    </div>

</div>
</div>
</body>
</html>
