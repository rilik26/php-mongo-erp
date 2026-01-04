<?php
function listDir($dir, $prefix = '') {
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        if ($item === '.git' || $item === 'vendor') continue;

        $path = $dir . DIRECTORY_SEPARATOR . $item;
        echo $prefix . $item . (is_dir($path) ? "/" : "") . "\n";
        if (is_dir($path)) {
            listDir($path, $prefix . "  ");
        }
    }
}

header('Content-Type: text/plain; charset=utf-8');
listDir(__DIR__ . "/..");
