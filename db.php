<?php
/**
 * PDO connection.
 *
 * The charset is set via the DSN parameter, which is the only correct way with
 * PDO — it configures the client library itself, not just the server session.
 * `SET NAMES` as a query must never appear in this codebase.
 * See CLAUDE.md section 4, trap 1.
 */

function db_dsn(array $db, $with_database = true)
{
    $dsn = 'mysql:host=' . $db['host'] . ';port=' . $db['port'] . ';';
    if ($with_database) {
        $dsn .= 'dbname=' . $db['name'] . ';';
    }
    $dsn .= 'charset=' . $db['charset'];
    return $dsn;
}

function db_connect(array $db, $with_database = true)
{
    $pdo = new PDO(
        db_dsn($db, $with_database),
        $db['user'],
        $db['pass'],
        array(
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Real prepared statements: the server parses the placeholder, so the
            // client never has to escape a multibyte string itself.
            PDO::ATTR_EMULATE_PREPARES   => false,
        )
    );
    return $pdo;
}

/** Lazily-created shared handle. */
function db()
{
    static $pdo = null;
    global $CONFIG;

    if ($pdo === null) {
        try {
            $pdo = db_connect($CONFIG['db'], true);
        } catch (PDOException $ex) {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
            echo '<!doctype html><meta charset="utf-8">';
            echo '<div style="font:14px system-ui;padding:2rem;max-width:44rem">';
            echo '<h2>Database unavailable</h2>';
            echo '<p>' . e($ex->getMessage()) . '</p>';
            echo '<p>Run <code>php install.php</code> once to create the schema, '
               . 'and check that MySQL is running.</p></div>';
            exit;
        }
    }
    return $pdo;
}
