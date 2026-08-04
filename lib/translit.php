<?php
/**
 * Transliteration (sound), NOT translation (meaning). See CLAUDE.md section 1.
 *
 * Everything that knows the shape of the upstream endpoint lives in this file.
 * Callers only ever see translit_phrase() / translit_word().
 */

/** True if the string already contains Devanagari — pass it through untouched. */
function has_devanagari($s)
{
    return (bool) preg_match('/\p{Devanagari}/u', $s);
}

/**
 * Split a phrase into alternating word / separator tokens, preserving the
 * separators verbatim so that "Dr. Anjali" keeps its "." and spacing.
 *
 * Returns a list of array('t' => string, 'word' => bool).
 */
function translit_tokenize($text)
{
    $parts = preg_split(
        '/([^\p{L}\p{M}\p{N}]+)/u',
        $text,
        -1,
        PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
    );
    if ($parts === false) {
        return array(array('t' => $text, 'word' => true));
    }

    $out = array();
    foreach ($parts as $p) {
        $is_word = (bool) preg_match('/[\p{L}\p{M}\p{N}]/u', $p);
        $out[] = array('t' => $p, 'word' => $is_word);
    }
    return $out;
}

/**
 * Reorder candidates so that well-formed ones come first.
 *
 * The upstream endpoint sometimes ranks an orthographically impossible spelling
 * top. As of this writing "ebin" returns ["ेबिन","ेबीन","एबिन",...] — the first
 * two begin with a dependent vowel sign (a matra), which no Devanagari word can
 * do; the correct "एबिन" is third.
 *
 * A leading Unicode Mark (\p{M} — matras, virama, anusvara, chandrabindu) is a
 * reliable signal of this. We demote rather than drop: the upstream ranking is
 * still available further down the candidate list, and the user makes the call.
 */
function translit_rank_candidates(array $cands)
{
    $good = array();
    $bad  = array();
    foreach ($cands as $c) {
        if (preg_match('/^\p{M}/u', $c)) {
            $bad[] = $c;
        } else {
            $good[] = $c;
        }
    }
    return array_merge($good, $bad);
}

/* ------------------------------------------------------------------ *
 * Cache
 * ------------------------------------------------------------------ */

function translit_cache_get($word)
{
    global $CONFIG;
    if (empty($CONFIG['translit']['cache_enabled'])) {
        return null;
    }
    try {
        $st = db()->prepare('SELECT candidates_json FROM translit_cache WHERE word_en = ? LIMIT 1');
        $st->execute(array(mb_strtolower($word, 'UTF-8')));
        $row = $st->fetch();
    } catch (PDOException $ex) {
        return null; // cache is an optimisation, never a failure mode
    }
    if (!$row) {
        return null;
    }
    $decoded = json_decode($row['candidates_json'], true);
    return is_array($decoded) && $decoded ? $decoded : null;
}

function translit_cache_put($word, array $candidates)
{
    global $CONFIG;
    if (empty($CONFIG['translit']['cache_enabled']) || !$candidates) {
        return;
    }
    try {
        $st = db()->prepare(
            'INSERT INTO translit_cache (word_en, candidates_json) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE candidates_json = VALUES(candidates_json)'
        );
        $st->execute(array(
            mb_strtolower($word, 'UTF-8'),
            json_encode($candidates, JSON_UNESCAPED_UNICODE),
        ));
    } catch (PDOException $ex) {
        // ignore
    }
}

/* ------------------------------------------------------------------ *
 * Upstream call — the only place that knows the endpoint's response shape
 * ------------------------------------------------------------------ */

/**
 * Fetch candidates for a single word.
 *
 * @return array|null  List of Devanagari strings, or null when the lookup
 *                     could not be performed (offline, timeout, bad response).
 *                     null means "let the user type it" — never an exception.
 */
function translit_fetch_candidates($word)
{
    global $CONFIG;
    $c = $CONFIG['translit'];

    if (!function_exists('curl_init')) {
        return null;
    }

    $url = $c['endpoint'] . '?' . http_build_query(array(
        'text' => $word,
        'itc'  => $c['itc'],
        'num'  => $c['num_candidates'],
        'cp'   => 0,
        'cs'   => 1,
        'ie'   => 'utf-8',
        'oe'   => 'utf-8',
        'app'  => 'demopage',
    ));

    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => $c['connect_timeout'],
        CURLOPT_TIMEOUT        => $c['total_timeout'],
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 2,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; translit-proxy/1.0)',
    ));
    $body = curl_exec($ch);
    $err  = curl_errno($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    // curl_close() is required to free the resource on PHP 7.x but is a
    // deprecated no-op from 8.5. Guarded so both runtimes stay warning-free.
    if (PHP_VERSION_ID < 80000) {
        curl_close($ch);
    }

    if ($err !== 0 || $code !== 200 || $body === false || $body === '') {
        return null;
    }

    // Expected: ["SUCCESS",[["ebin",["एबिन","एबीन",...],[],{}]]]
    $json = json_decode($body, true);
    if (!is_array($json) || !isset($json[0]) || $json[0] !== 'SUCCESS') {
        return null;
    }
    if (!isset($json[1][0][1]) || !is_array($json[1][0][1])) {
        return null;
    }

    $cands = array();
    foreach ($json[1][0][1] as $cand) {
        if (is_string($cand) && $cand !== '') {
            $cands[] = $cand;
        }
    }
    return $cands ? $cands : null;
}

/**
 * Candidates for one word, cache-first.
 *
 * @return array  array('candidates' => string[], 'source' => 'passthrough|cache|remote|none')
 */
function translit_word($word)
{
    if ($word === '') {
        return array('candidates' => array(), 'source' => 'none');
    }

    if (has_devanagari($word)) {
        return array('candidates' => array($word), 'source' => 'passthrough');
    }

    // Ranking is applied on read, not on write, so cache rows written before a
    // ranking change are corrected too.
    $cached = translit_cache_get($word);
    if ($cached !== null) {
        return array('candidates' => translit_rank_candidates($cached), 'source' => 'cache');
    }

    $fetched = translit_fetch_candidates($word);
    if ($fetched === null) {
        return array('candidates' => array(), 'source' => 'none');
    }

    translit_cache_put($word, $fetched);
    return array('candidates' => translit_rank_candidates($fetched), 'source' => 'remote');
}

/**
 * Transliterate a whole phrase word by word, then join.
 *
 * @return array{
 *   hindi: string,          best-guess joined result ('' if nothing resolved)
 *   tokens: array,          per-token detail for the candidate picker UI
 *   complete: bool,         true when every word resolved
 *   offline: bool           true when at least one lookup could not be performed
 * }
 */
function translit_phrase($text)
{
    $tokens   = translit_tokenize($text);
    $out      = array();
    $detail   = array();
    $complete = true;
    $offline  = false;

    foreach ($tokens as $tok) {
        if (!$tok['word']) {
            $out[] = $tok['t'];
            $detail[] = array(
                'en'         => $tok['t'],
                'word'       => false,
                'chosen'     => $tok['t'],
                'candidates' => array(),
            );
            continue;
        }

        if ($offline) {
            // Circuit breaker. The service has already failed once for this
            // phrase, so do not spend another full connect timeout on every
            // remaining word — a three-word name would otherwise stall for
            // 3 x the timeout. Local cache hits cost nothing, so still take those.
            $cached = has_devanagari($tok['t']) ? array($tok['t']) : translit_cache_get($tok['t']);
            $res = array(
                'candidates' => $cached ? translit_rank_candidates($cached) : array(),
                'source'     => $cached ? 'cache' : 'none',
            );
        } else {
            $res = translit_word($tok['t']);
        }
        $chosen = isset($res['candidates'][0]) ? $res['candidates'][0] : '';

        if ($chosen === '') {
            $complete = false;
            if ($res['source'] === 'none') {
                $offline = true;
            }
        }

        $out[] = $chosen;
        $detail[] = array(
            'en'         => $tok['t'],
            'word'       => true,
            'chosen'     => $chosen,
            'candidates' => $res['candidates'],
            'source'     => $res['source'],
        );
    }

    $hindi = implode('', $out);
    // If nothing at all resolved, return an empty field rather than stray
    // punctuation — the user should see a blank box, not "..".
    if (!preg_match('/\p{Devanagari}/u', $hindi)) {
        $hindi = '';
    }

    return array(
        'hindi'    => $hindi,
        'tokens'   => $detail,
        'complete' => $complete,
        'offline'  => $offline,
    );
}
