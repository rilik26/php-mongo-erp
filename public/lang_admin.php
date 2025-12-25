<?php
/**
 * public/lang_admin.php
 *
 * AMAÇ:
 * - TR & EN aynı tabloda yan yana
 * - Toplu kaydet (bulk upsert)
 * - Cache invalidation için LANG01E.version++ (tr/en)
 *
 * GUARD:
 * - login şart (context)
 * - permission: lang.manage
 *
 * AUDIT:
 * - UACT01E log: I18N.ADMIN.VIEW / I18N.ADMIN.SAVE
 * - EVENT01E event: I18N.ADMIN.VIEW / I18N.ADMIN.SAVE (+ data.summary)
 * - SNAP01E snapshot: LANG01T dictionary (FINAL STATE)
 */

require_once __DIR__ . '/../core/bootstrap.php';

require_once __DIR__ . '/../core/auth/SessionManager.php';
require_once __DIR__ . '/../core/base/Context.php';
require_once __DIR__ . '/../core/base/ContextException.php';

require_once __DIR__ . '/../core/auth/permission_helpers.php';
require_once __DIR__ . '/../core/action/ActionLogger.php';
require_once __DIR__ . '/../core/event/EventWriter.php';
require_once __DIR__ . '/../core/snapshot/SnapshotWriter.php';

require_once __DIR__ . '/../core/snapshot/SnapshotRepository.php';
require_once __DIR__ . '/../core/snapshot/SnapshotDiff.php';

require_once __DIR__ . '/../app/modules/lang/LANG01ERepository.php';
require_once __DIR__ . '/../app/modules/lang/LANG01TRepository.php';

SessionManager::start();

// Eğer session context yoksa direkt login
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

// Permission guard (403)
require_perm('lang.manage');

// ✅ VIEW: Log + Event
ActionLogger::info('I18N.ADMIN.VIEW', [
    'source' => 'public/lang_admin.php'
], $ctx);

EventWriter::emit(
    'I18N.ADMIN.VIEW',
    ['source' => 'public/lang_admin.php'],
    ['module' => 'i18n', 'doc_type' => 'LANG01T', 'doc_id' => 'DICT'],
    $ctx
);

$langCodes = ['tr', 'en'];

$q = trim($_GET['q'] ?? '');
$msgKey = null;
$errKey = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rows = $_POST['rows'] ?? [];

    if (!is_array($rows) || empty($rows)) {
        $errKey = 'lang.admin.required_error';
    } else {
        // 1) DB update
        LANG01TRepository::bulkUpsertPivot($rows, $langCodes);

        // 2) cache invalidation
        LANG01ERepository::bumpVersion('tr');
        LANG01ERepository::bumpVersion('en');

        // ✅ SAVE: Log
        ActionLogger::success('I18N.ADMIN.SAVE', [
            'source' => 'public/lang_admin.php',
            'rows'   => count($rows)
        ], $ctx);

        /**
         * ✅ SNAPSHOT: FINAL STATE (DB’den çek)
         */
        $dictTr = LANG01TRepository::dumpAll('tr'); // key => [module,key,text]
        $dictEn = LANG01TRepository::dumpAll('en');

        $finalRows = [];
        $keys = array_unique(array_merge(array_keys($dictTr), array_keys($dictEn)));

        foreach ($keys as $k) {
            $finalRows[$k] = [
                'module' => $dictTr[$k]['module'] ?? ($dictEn[$k]['module'] ?? 'common'),
                'key'    => $k,
                'tr'     => $dictTr[$k]['text'] ?? '',
                'en'     => $dictEn[$k]['text'] ?? '',
            ];
        }
        ksort($finalRows);

        $snap = SnapshotWriter::capture(
            [
                'module'   => 'i18n',
                'doc_type' => 'LANG01T',
                'doc_id'   => 'DICT',
                'doc_no'   => 'LANG01T-DICT',
            ],
            [
                'rows' => $finalRows
            ],
            [
                'reason'         => 'bulk_upsert',
                'changed_fields' => ['rows'],
                'rows_count'     => count($rows),
            ],
            [
                // ✅ i18n period bağımsız olsun:
                'period_id'   => 'GLOBAL',
                'facility_id' => null,
            ]
        );

        /**
         * ✅ Diff + Summary (Event data.summary içine)
         * SnapshotWriter prev_snapshot_id döndürmediği için version-1 ile prev buluyoruz.
         */
        $summary = [
            'mode' => 'lang',
            'note' => 'no_prev_snapshot'
        ];

        $targetKey = $snap['target_key'] ?? null;
        $ver = (int)($snap['version'] ?? 1);
        $prevVer = $ver - 1;

        if ($targetKey && $prevVer >= 1) {
            $prevSnap = SnapshotRepository::findByTargetKeyAndVersion($targetKey, $prevVer);
            if ($prevSnap) {
                $oldRows = (array)($prevSnap['data']['rows'] ?? []);
                $newRows = (array)($finalRows ?? []);

                $diff = SnapshotDiff::diffLangRows($oldRows, $newRows);
                $summary = SnapshotDiff::summarizeLangDiff($diff, 8);
            }
        }

        // ✅ EVENT: summary data içine
        EventWriter::emit(
            'I18N.ADMIN.SAVE',
            [
                'source'  => 'public/lang_admin.php',
                'rows'    => count($rows),
                'summary' => $summary,
                 'debug'   => [
                'target_key' => $snap['target_key'] ?? null,
                'version'    => $snap['version'] ?? null,
                'prev_ver'   => $prevVer ?? null,
              ]
            ],
            [
                'module'   => 'i18n',
                'doc_type' => 'LANG01T',
                'doc_id'   => 'DICT'
            ],
            $ctx,
            [
                'snapshot_id'  => $snap['snapshot_id'] ?? null,
                'target_key'   => $snap['target_key'] ?? null,
                'version'      => $ver,
                'prev_version' => $prevVer >= 1 ? $prevVer : null,
            ]
        );

        $msgKey = 'lang.admin.saved';
    }
}

$pivot = LANG01TRepository::listPivot($langCodes, $q, 800);
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title><?php _e('lang.admin.title'); ?></title>
  <style>
    table { border-collapse: collapse; width: 100%; }
    th, td { border:1px solid #ddd; padding:8px; vertical-align: top; }
    th { background:#f7f7f7; text-align:left; }
    input[type="text"]{ width:100%; box-sizing:border-box; }
    .small { font-size:12px; color:#666; }
    .stickybar { display:flex; gap:10px; align-items:center; margin:10px 0; flex-wrap:wrap; }
    .btn { padding:6px 10px; border:1px solid #ccc; background:#fff; cursor:pointer; }
    .btn-primary { border-color:#1e88e5; background:#1e88e5; color:#fff; }
  </style>
</head>
<body>

<?php require_once __DIR__ . '/../app/views/layout/header.php'; ?>

<h3><?php _e('lang.admin.title'); ?></h3>

<?php if ($msgKey): ?><p style="color:green;"><?php _e($msgKey); ?></p><?php endif; ?>
<?php if ($errKey): ?><p style="color:red;"><?php _e($errKey); ?></p><?php endif; ?>

<form method="GET" class="stickybar">
  <label><?php _e('lang.admin.search'); ?>:</label>
  <input type="text" name="q"
         value="<?php echo htmlspecialchars($q, ENT_QUOTES, 'UTF-8'); ?>"
         placeholder="key/text/module">
  <button class="btn" type="submit"><?php _e('lang.admin.fetch'); ?></button>
  <span class="small"><?php _e('lang.admin.bulk_hint'); ?></span>
</form>

<form method="POST">
  <div class="stickybar">
    <button class="btn btn-primary" type="submit"><?php _e('lang.admin.save'); ?></button>
    <span class="small"><?php echo (int)count($pivot); ?> <?php _e('lang.admin.rows'); ?></span>
  </div>

  <table>
    <tr>
      <th style="width:140px;"><?php _e('lang.admin.module'); ?></th>
      <th style="width:240px;"><?php _e('lang.admin.key'); ?></th>
      <th><?php _e('lang.admin.tr_text'); ?></th>
      <th><?php _e('lang.admin.en_text'); ?></th>
    </tr>

    <?php foreach ($pivot as $key => $row):
      $module = (string)($row['module'] ?? 'common');
      $tr = (string)($row['tr'] ?? '');
      $en = (string)($row['en'] ?? '');
      $safeKey = htmlspecialchars($key, ENT_QUOTES, 'UTF-8');
    ?>
      <tr>
        <td>
          <input type="text"
                 name="rows[<?php echo $safeKey; ?>][module]"
                 value="<?php echo htmlspecialchars($module, ENT_QUOTES, 'UTF-8'); ?>">
        </td>

        <td>
          <div><strong><?php echo $safeKey; ?></strong></div>
          <input type="hidden"
                 name="rows[<?php echo $safeKey; ?>][key]"
                 value="<?php echo $safeKey; ?>">
        </td>

        <td>
          <input type="text"
                 name="rows[<?php echo $safeKey; ?>][tr]"
                 value="<?php echo htmlspecialchars($tr, ENT_QUOTES, 'UTF-8'); ?>">
        </td>

        <td>
          <input type="text"
                 name="rows[<?php echo $safeKey; ?>][en]"
                 value="<?php echo htmlspecialchars($en, ENT_QUOTES, 'UTF-8'); ?>">
        </td>
      </tr>
    <?php endforeach; ?>
  </table>

  <div class="stickybar">
    <button class="btn btn-primary" type="submit"><?php _e('lang.admin.save'); ?></button>
  </div>
</form>

</body>
</html>
