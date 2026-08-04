<?php
/**
 * One-time installer. Runs from the CLI (`php install.php`) or the browser.
 * Idempotent — safe to run again.
 */

require __DIR__ . '/bootstrap.php';

$cli = (php_sapi_name() === 'cli');
if (!$cli) {
    html_header_utf8();
    echo '<!doctype html><meta charset="utf-8"><title>Install</title>'
       . '<style>body{font:14px/1.6 ui-monospace,Menlo,monospace;padding:2rem;max-width:52rem}'
       . '.ok{color:#137333}.bad{color:#c5221f}</style>';
}

$lines = array();
function say($msg, $status = '')
{
    global $cli, $lines;
    if ($cli) {
        $prefix = $status === 'ok' ? '  ok  ' : ($status === 'bad' ? ' FAIL ' : '      ');
        echo $prefix . $msg . "\n";
    } else {
        $cls = $status ? ' class="' . $status . '"' : '';
        echo '<div' . $cls . '>' . e($msg) . '</div>';
    }
    flush();
}

$db = $CONFIG['db'];

try {
    // Connect without selecting a database so we can create it.
    $root = db_connect($db, false);
    say('Connected to MySQL at ' . $db['host'] . ':' . $db['port'] . ' as ' . $db['user'], 'ok');

    $ver = $root->query('SELECT VERSION()')->fetchColumn();
    say('Server version: ' . $ver);
    say('PHP version: ' . PHP_VERSION);

    $root->exec(
        'CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '', $db['name']) . '` '
        . 'CHARACTER SET ' . $db['charset'] . ' COLLATE ' . $db['collate']
    );
    say('Database `' . $db['name'] . '` ready (' . $db['charset'] . ' / ' . $db['collate'] . ')', 'ok');

    // Reconnect with the database selected and charset in the DSN.
    $pdo = db_connect($db, true);

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS `persons` (
           `id`      INT AUTO_INCREMENT PRIMARY KEY,
           `name_en` VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
           `name_hi` VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
           `created` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
           KEY `idx_name_en` (`name_en`)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC'
    );
    say('Table `persons` ready', 'ok');

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS `translit_cache` (
           `word_en`         VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
           `candidates_json` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
           `created`         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
           PRIMARY KEY (`word_en`)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC'
    );
    say('Table `translit_cache` ready', 'ok');

    // Confirm the live session really is utf8mb4 on every axis.
    $vars = $pdo->query("SHOW VARIABLES LIKE 'character_set%'")->fetchAll();
    $must = array('character_set_client', 'character_set_connection', 'character_set_results');
    $seen = array();
    foreach ($vars as $v) {
        $seen[$v['Variable_name']] = $v['Value'];
    }
    $all_ok = true;
    foreach ($must as $m) {
        $val = isset($seen[$m]) ? $seen[$m] : '(absent)';
        $ok  = ($val === 'utf8mb4');
        $all_ok = $all_ok && $ok;
        say($m . ' = ' . $val, $ok ? 'ok' : 'bad');
    }

    if ($all_ok) {
        say('');
        say('Install complete. Next: php -S localhost:8000  then open http://localhost:8000/', 'ok');
    } else {
        say('');
        say('Connection charset is NOT utf8mb4 — fix this before storing any data.', 'bad');
    }
} catch (PDOException $ex) {
    say('Install failed: ' . $ex->getMessage(), 'bad');
    if ($cli) {
        exit(1);
    }
}
