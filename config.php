<?php
/**
 * Configuration. Override any value with an environment variable so that
 * deployment does not require editing a tracked file.
 *
 * NOTE: PHP 7.4-compatible syntax only. See CLAUDE.md section 3.
 */

function env_or($key, $default)
{
    $v = getenv($key);
    return ($v === false || $v === '') ? $default : $v;
}

return array(
    'db' => array(
        'host'    => env_or('TRANSLIT_DB_HOST', '127.0.0.1'),
        'port'    => env_or('TRANSLIT_DB_PORT', '3306'),
        'name'    => env_or('TRANSLIT_DB_NAME', 'translit_demo'),
        'user'    => env_or('TRANSLIT_DB_USER', 'root'),
        // Empty password is the Homebrew MySQL default; getenv('')===false handled above.
        'pass'    => getenv('TRANSLIT_DB_PASS') === false ? '' : getenv('TRANSLIT_DB_PASS'),
        // Set to /cloudsql/PROJECT:REGION:INSTANCE on Cloud Run or App Engine,
        // where Cloud SQL is reached over a Unix socket rather than TCP. When
        // this is set, host and port are ignored.
        'socket'  => env_or('TRANSLIT_DB_SOCKET', ''),
        'charset' => 'utf8mb4',
        'collate' => 'utf8mb4_unicode_ci',
    ),

    'translit' => array(
        // Undocumented endpoint. Isolated here + in lib/translit.php so it can be
        // swapped without touching callers. See CLAUDE.md section 5.
        // Overridable so the endpoint can be swapped or pointed at an internal
        // mirror without a code change — and so the offline path is testable.
        'endpoint'        => env_or('TRANSLIT_ENDPOINT', 'https://inputtools.google.com/request'),
        'itc'             => 'hi-t-i0-und',
        'num_candidates'  => 5,
        // Must never block a form submit.
        'connect_timeout' => 3,
        'total_timeout'   => 5,
        'cache_enabled'   => true,
    ),

    // Max characters (not bytes) for a name field. Column is VARCHAR(191).
    'max_name_chars' => 191,

    'debug' => (bool) env_or('TRANSLIT_DEBUG', '1'),
);
