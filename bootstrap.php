<?php
/**
 * Loaded first by every entry point. Establishes the UTF-8 environment
 * before any string is touched. See CLAUDE.md section 4.
 *
 * This file is saved as UTF-8 without BOM.
 */

if (!extension_loaded('mbstring')) {
    header('Content-Type: text/plain; charset=utf-8');
    exit("FATAL: the mbstring extension is required. Every length check in this app uses mb_strlen().\n");
}

mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');
mb_regex_encoding('UTF-8');

$CONFIG = require __DIR__ . '/config.php';

if (!empty($CONFIG['debug'])) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
}

/**
 * The only escaping function permitted in this codebase.
 * htmlentities() mangles Devanagari — see CLAUDE.md section 4, trap 2.
 */
function e($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/**
 * JSON output helper. JSON_UNESCAPED_UNICODE keeps Devanagari readable on the
 * wire instead of turning it into न escapes.
 */
function json_out($data, $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function html_header_utf8()
{
    header('Content-Type: text/html; charset=utf-8');
}

/**
 * PHP 8.4 deprecated calling fputcsv()/str_getcsv() without an explicit $escape
 * argument. Passing "" (RFC 4180: no escape character at all) is the correct
 * value and is accepted from PHP 7.4.0; older runtimes get the historical
 * backslash so the call still has an explicit argument either way.
 */
define('CSV_ESCAPE', PHP_VERSION_ID >= 70400 ? '' : "\\");

/** Character count, never byte count. See CLAUDE.md section 4, trap 3. */
function name_len($s)
{
    return mb_strlen($s, 'UTF-8');
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/translit.php';
