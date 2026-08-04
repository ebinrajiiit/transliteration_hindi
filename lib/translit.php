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

/**
 * Candidates for a single Latin letter standing alone — an initial.
 *
 * "Titus J Sam" must give "टाइटस जे सैम". The J is read aloud as the *name* of the
 * letter ("jay" -> जे), not as the /dʒ/ *sound* it makes inside a word (ज).
 *
 * The upstream endpoint cannot express this, and no transliteration engine can:
 * a bare "J" is genuinely ambiguous, and only the surrounding context — a
 * one-letter token in a person's name — settles it. That context is ours, not the
 * engine's. Measured against the live endpoint, only 4 of 26 letters came back as
 * the letter name (B, D, Q, T); feeding it the spoken name instead does not help
 * either ("jay" -> जय, "double u" -> डबल ु). So this is a lookup table, and it is
 * the right tool: 26 bounded cases, deterministic, and no network call.
 *
 * The letter name leads and the phonetic reading follows, so the previous
 * behaviour stays one click away — CLAUDE.md section 5 says offer the choice,
 * never impose it. Every entry carries at least two candidates on purpose:
 * assets/app.js renders no chip row for a token with fewer than two, which would
 * leave the user no visible way to override.
 *
 * @return array|null  Candidates, or null when this is not a single letter, in
 *                     which case the caller carries on to the normal path.
 */
function translit_letter_candidates($word)
{
    static $names = array(
        'a' => array('ए', 'आ', 'अ'),
        'b' => array('बी', 'ब'),
        'c' => array('सी', 'क', 'च'),
        'd' => array('डी', 'ड', 'द'),
        'e' => array('ई', 'ए', 'इ'),
        'f' => array('एफ', 'फ', 'फ़'),
        'g' => array('जी', 'ग', 'घ'),
        'h' => array('एच', 'ह'),
        'i' => array('आई', 'ई', 'इ'),
        'j' => array('जे', 'ज', 'ज़'),
        'k' => array('के', 'क', 'ख'),
        'l' => array('एल', 'ल'),
        'm' => array('एम', 'म'),
        'n' => array('एन', 'न'),
        'o' => array('ओ', 'आ'),
        'p' => array('पी', 'प', 'फ'),
        'q' => array('क्यू', 'क़', 'क'),
        'r' => array('आर', 'र'),
        's' => array('एस', 'स', 'श'),
        't' => array('टी', 'ट', 'त'),
        'u' => array('यू', 'उ', 'ऊ'),
        'v' => array('वी', 'व'),
        'w' => array('डब्ल्यू', 'व'),
        'x' => array('एक्स', 'क्ष'),
        'y' => array('वाई', 'य'),
        'z' => array('ज़ेड', 'जेड', 'ज़'),
    );

    // Character count, not byte count — see CLAUDE.md section 4, trap 3.
    if (mb_strlen($word, 'UTF-8') !== 1) {
        return null;
    }

    $key = mb_strtolower($word, 'UTF-8');
    return isset($names[$key]) ? $names[$key] : null;
}

/* ------------------------------------------------------------------ *
 * Learned spellings — what humans actually approved
 * ------------------------------------------------------------------ */

/**
 * Spellings previously approved for a word, most-approved first.
 *
 * The engine is good but not authoritative. Measured on 20 real Indian
 * surnames: wanted spelling top for 10, present-but-ranked-lower for 6, absent
 * entirely for 4 (Varghese, Nambiar, Iyer, Mathew). Remembering what a person
 * approved fixes both failure modes at once, and needs no model.
 *
 * @return array  List of Devanagari spellings; empty when nothing is known.
 */
function translit_learned_get($word)
{
    if ($word === '' || mb_strlen($word, 'UTF-8') > 64) {
        return array();
    }
    try {
        $st = db()->prepare(
            'SELECT word_hi FROM translit_learned
              WHERE word_en = ?
              ORDER BY approvals DESC, updated DESC
              LIMIT 5'
        );
        $st->execute(array(mb_strtolower($word, 'UTF-8')));
        $rows = $st->fetchAll();
    } catch (PDOException $ex) {
        // Same contract as the cache: a learning failure must never surface.
        return array();
    }

    $out = array();
    foreach ($rows as $r) {
        $out[] = $r['word_hi'];
    }
    return $out;
}

/** Record one approved pair, or bump its count if already known. */
function translit_learned_put($word_en, $word_hi)
{
    if ($word_en === '' || $word_hi === '') {
        return;
    }
    if (mb_strlen($word_en, 'UTF-8') > 64 || mb_strlen($word_hi, 'UTF-8') > 64) {
        return;
    }
    try {
        $st = db()->prepare(
            'INSERT INTO translit_learned (word_en, word_hi, approvals) VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE approvals = approvals + 1'
        );
        $st->execute(array(mb_strtolower($word_en, 'UTF-8'), $word_hi));
    } catch (PDOException $ex) {
        // ignore
    }
}

/**
 * Learn from a saved record by aligning its English and Hindi words.
 *
 * "Sreekumar Nair" / "श्रीकुमार नायर" yields Sreekumar->श्रीकुमार and Nair->नायर.
 *
 * Alignment is positional and only attempted when both sides have the same
 * number of word tokens. If they differ the user has restructured the name and
 * we cannot say which part maps to which, so we learn nothing rather than
 * guess — a wrong pair would be served to every later user.
 *
 * @return int  Number of pairs recorded.
 */
function translit_learn($name_en, $name_hi)
{
    $en = array();
    foreach (translit_tokenize($name_en) as $t) {
        if ($t['word']) {
            $en[] = $t['t'];
        }
    }
    $hi = array();
    foreach (translit_tokenize($name_hi) as $t) {
        if ($t['word']) {
            $hi[] = $t['t'];
        }
    }

    if (!$en || count($en) !== count($hi)) {
        return 0;
    }

    $learned = 0;
    foreach ($en as $i => $word) {
        // Single letters are initials and already resolved deterministically;
        // letting them be learned would let one odd entry erode that.
        if (mb_strlen($word, 'UTF-8') === 1) {
            continue;
        }
        // Only learn a genuinely Devanagari spelling, and never learn a word
        // that was already Devanagari on the English side.
        if (has_devanagari($word) || !has_devanagari($hi[$i])) {
            continue;
        }
        translit_learned_put($word, $hi[$i]);
        $learned++;
    }
    return $learned;
}

/** Merge lists preserving order, first occurrence wins. */
function translit_merge_unique(array $first, array $second)
{
    $out = array();
    foreach (array_merge($first, $second) as $c) {
        if ($c !== '' && !in_array($c, $out, true)) {
            $out[] = $c;
        }
    }
    return $out;
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
function translit_word($word, $allow_network = true)
{
    if ($word === '') {
        return array('candidates' => array(), 'source' => 'none');
    }

    if (has_devanagari($word)) {
        return array('candidates' => array($word), 'source' => 'passthrough');
    }

    // Initials are resolved locally and must be checked BEFORE the cache: rows
    // written by earlier versions (the live cache already holds k => ["क",...]
    // and p => ["प",...]) would otherwise win and reinstate the wrong spelling.
    // Checking first also makes those rows permanently inert, so no purge is
    // needed.
    $letter = translit_letter_candidates($word);
    if ($letter !== null) {
        return array('candidates' => $letter, 'source' => 'letter');
    }

    // What a human approved outranks what the engine guessed.
    $learned = translit_learned_get($word);

    // Ranking is applied on read, not on write, so cache rows written before a
    // ranking change are corrected too.
    $cached = translit_cache_get($word);
    $engine = ($cached !== null) ? translit_rank_candidates($cached) : null;

    // Only go to the network when it would add something. With two or more
    // approved spellings the picker already has real choices, so an initial
    // lookup is not worth a timeout.
    if ($engine === null && $allow_network && count($learned) < 2) {
        $fetched = translit_fetch_candidates($word);
        if ($fetched !== null) {
            translit_cache_put($word, $fetched);
            $engine = translit_rank_candidates($fetched);
        }
    }

    if ($learned) {
        return array(
            'candidates' => translit_merge_unique($learned, $engine ? $engine : array()),
            'source'     => 'learned',
        );
    }

    if ($engine !== null) {
        return array('candidates' => $engine, 'source' => ($cached !== null) ? 'cache' : 'remote');
    }

    return array('candidates' => array(), 'source' => 'none');
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

        // Checked ahead of the circuit breaker below, which reads the cache
        // directly and never enters translit_word(). Without this, an initial
        // appearing after a failed lookup would still come back wrong.
        $letter = translit_letter_candidates($tok['t']);

        if ($letter !== null) {
            $res = array('candidates' => $letter, 'source' => 'letter');
        } else {
            // Circuit breaker: once the service has failed for this phrase, keep
            // resolving from local sources but stop paying a connect timeout per
            // remaining word — a three-word name would otherwise stall for 3x the
            // timeout. Learned spellings and cache hits are local, so they still
            // work with the network down.
            $res = translit_word($tok['t'], !$offline);
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
