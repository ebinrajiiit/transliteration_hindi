<?php
/**
 * Server-side proxy for the transliteration endpoint.
 *
 * The browser never calls the upstream service directly: that would hit CORS,
 * pin the endpoint into client code, and skip the cache. See CLAUDE.md section 5.
 *
 * GET/POST  text=<latin name>
 * Returns   {ok, hindi, tokens[], complete, offline, message}
 *
 * This endpoint always returns HTTP 200 with ok=false on a lookup failure.
 * A failed lookup is a normal outcome, not an error the form should choke on.
 */

require __DIR__ . '/../bootstrap.php';

$text = isset($_REQUEST['text']) ? (string) $_REQUEST['text'] : '';
$text = trim($text);

if ($text === '') {
    json_out(array(
        'ok'       => false,
        'hindi'    => '',
        'tokens'   => array(),
        'complete' => false,
        'offline'  => false,
        'message'  => 'Nothing to transliterate.',
    ));
}

// Guard against someone posting a novel. Character count, not byte count.
if (name_len($text) > $CONFIG['max_name_chars']) {
    json_out(array(
        'ok'       => false,
        'hindi'    => '',
        'tokens'   => array(),
        'complete' => false,
        'offline'  => false,
        'message'  => 'Name is longer than ' . $CONFIG['max_name_chars'] . ' characters.',
    ));
}

$res = translit_phrase($text);

$message = '';
if ($res['offline']) {
    $message = 'Could not reach the transliteration service. Type the Hindi name yourself.';
} elseif (!$res['complete']) {
    $message = 'Some words could not be transliterated. Please check and edit.';
}

json_out(array(
    'ok'       => ($res['hindi'] !== ''),
    'hindi'    => $res['hindi'],
    'tokens'   => $res['tokens'],
    'complete' => $res['complete'],
    'offline'  => $res['offline'],
    'message'  => $message,
));
