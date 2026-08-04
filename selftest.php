<?php
/**
 * Executable version of the CLAUDE.md section 7 verification list and the
 * section 10 definition of done.
 *
 * Run from the CLI (`php selftest.php`, exits non-zero on failure) or open in
 * the browser. It writes a handful of rows tagged `__selftest` and removes them
 * again at the end.
 */

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib/persons.php';

$cli = (php_sapi_name() === 'cli');

/* ---------------------------------------------------------------- *
 * Reporting
 * ---------------------------------------------------------------- */
$RESULTS = array();

function check($group, $name, $pass, $detail = '')
{
    global $RESULTS;
    $RESULTS[] = array('group' => $group, 'name' => $name, 'pass' => $pass, 'detail' => $detail);
}
function info($group, $name, $detail)
{
    check($group, $name, null, $detail);
}

/* ---------------------------------------------------------------- *
 * 1. PHP environment  (CLAUDE.md section 4)
 * ---------------------------------------------------------------- */
$G = 'PHP environment';

check($G, 'mbstring loaded', extension_loaded('mbstring'), PHP_VERSION);
check($G, "mb_internal_encoding is UTF-8", mb_internal_encoding() === 'UTF-8', mb_internal_encoding());
check($G, 'curl available (needed for transliteration)', function_exists('curl_init'),
      function_exists('curl_init') ? 'yes' : 'missing — app still works, field stays manual');
check($G, 'pdo_mysql loaded', extension_loaded('pdo_mysql'), '');

// Source files must be UTF-8 without a BOM.
$bom_offenders = array();
foreach (source_files() as $f) {
    $fh = fopen($f, 'rb');
    $head = fread($fh, 3);
    fclose($fh);
    if ($head === "\xEF\xBB\xBF") {
        $bom_offenders[] = basename($f);
    }
}
check($G, 'no PHP source file has a UTF-8 BOM', !$bom_offenders, implode(', ', $bom_offenders));

/* ---------------------------------------------------------------- *
 * 2. Connection charset  (section 7 charset audit)
 * ---------------------------------------------------------------- */
$G = 'Connection';
$pdo = db();

$vars = array();
foreach ($pdo->query("SHOW VARIABLES LIKE 'character_set%'")->fetchAll() as $v) {
    $vars[$v['Variable_name']] = $v['Value'];
}
foreach (array('character_set_client', 'character_set_connection', 'character_set_results') as $key) {
    $val = isset($vars[$key]) ? $vars[$key] : '(absent)';
    check($G, $key . ' = utf8mb4', $val === 'utf8mb4', $val);
}

$coll = $pdo->query("SHOW VARIABLES LIKE 'collation_connection'")->fetch();
info($G, 'collation_connection', $coll ? $coll['Value'] : '(absent)');

$dbname  = $CONFIG['db']['name'];
$dbcharq = $pdo->prepare(
    'SELECT DEFAULT_CHARACTER_SET_NAME c, DEFAULT_COLLATION_NAME l
       FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?'
);
$dbcharq->execute(array($dbname));
$dbchar = $dbcharq->fetch();
check($G, 'database default charset is utf8mb4',
      $dbchar && $dbchar['c'] === 'utf8mb4',
      $dbchar ? $dbchar['c'] . ' / ' . $dbchar['l'] : 'schema not found');

/* ---------------------------------------------------------------- *
 * 3. Table and column charsets
 * ---------------------------------------------------------------- */
$G = 'Schema';

$cols = $pdo->query('SHOW FULL COLUMNS FROM persons')->fetchAll();
$by_name = array();
foreach ($cols as $c) {
    $by_name[$c['Field']] = $c;
}
foreach (array('name_en', 'name_hi') as $col) {
    if (!isset($by_name[$col])) {
        check($G, "column $col exists", false, 'missing');
        continue;
    }
    $c = $by_name[$col];
    check($G, "persons.$col collation is utf8mb4_unicode_ci",
          $c['Collation'] === 'utf8mb4_unicode_ci', $c['Type'] . ' ' . $c['Collation']);
    // VARCHAR(191) keeps an index under the 767-byte InnoDB prefix limit.
    check($G, "persons.$col is VARCHAR(191) (InnoDB index prefix limit)",
          stripos($c['Type'], 'varchar(191)') !== false, $c['Type']);
}

$create = $pdo->query('SHOW CREATE TABLE persons')->fetch();
$ddl = $create ? $create['Create Table'] : '';
check($G, 'persons table default charset is utf8mb4',
      stripos($ddl, 'CHARSET=utf8mb4') !== false, '');
check($G, 'persons uses ROW_FORMAT=DYNAMIC',
      stripos($ddl, 'ROW_FORMAT=DYNAMIC') !== false,
      stripos($ddl, 'ROW_FORMAT=DYNAMIC') !== false ? '' : 'not set (fine on MySQL >= 5.7 defaults)');

/* ---------------------------------------------------------------- *
 * 4. Byte-level round trip through the database  (section 7)
 * ---------------------------------------------------------------- */
$G = 'Round trip: database';

$TESTS = array(
    'एबिन डेनी राज' => 'conjuncts + matras',
    'कृष्णा'          => 'vowel sign ri',
    'श्रीकुमार'        => 'shra conjunct',
    'डॉ. अंजलि'       => 'anusvara + punctuation',
    // Not from section 7, but this is what actually distinguishes utf8mb4 from
    // MySQL's 3-byte "utf8": a 4-byte codepoint. On utf8mb3 this insert fails
    // or truncates.
    'नाम 🙏'          => '4-byte codepoint (proves utf8mb4, not utf8mb3)',
);

$inserted_ids = array();
$roundtripped = array();

foreach ($TESTS as $hi => $why) {
    $tag = '__selftest';
    try {
        $id = person_insert($tag, $hi);
        $inserted_ids[] = $id;

        $st = db()->prepare(
            'SELECT name_hi, CHAR_LENGTH(name_hi) c, LENGTH(name_hi) b, HEX(name_hi) h
               FROM persons WHERE id = ?'
        );
        $st->execute(array($id));
        $row = $st->fetch();

        $identical = ($row && $row['name_hi'] === $hi);
        check($G, 'stores and reads back "' . $hi . '" (' . $why . ')', $identical,
              $identical ? $row['c'] . ' chars / ' . $row['b'] . ' bytes'
                         : 'got back: ' . ($row ? $row['name_hi'] : '(nothing)'));

        if ($row) {
            // Devanagari is 3 bytes per codepoint in UTF-8. bytes == chars means
            // the string was flattened to a single-byte charset somewhere.
            $ratio_ok = ((int) $row['b'] > (int) $row['c']);
            check($G, '  bytes > chars for "' . $hi . '"', $ratio_ok,
                  'LENGTH=' . $row['b'] . ' CHAR_LENGTH=' . $row['c']
                  . ($ratio_ok ? '' : '  <- flattened, storage is broken'));
            $roundtripped[$id] = $row['name_hi'];
        }
    } catch (PDOException $ex) {
        check($G, 'stores and reads back "' . $hi . '"', false, $ex->getMessage());
    }
}

/* ---------------------------------------------------------------- *
 * 5. Round trip through CSV  (section 7 — corruption often appears only here)
 * ---------------------------------------------------------------- */
$G = 'Round trip: CSV';

$csv = "\xEF\xBB\xBF";
$tmp = fopen('php://temp', 'r+');
fputcsv($tmp, array('id', 'name_hi'), ',', '"', CSV_ESCAPE);
foreach ($roundtripped as $id => $hi) {
    fputcsv($tmp, array($id, $hi), ',', '"', CSV_ESCAPE);
}
rewind($tmp);
$csv .= stream_get_contents($tmp);
fclose($tmp);

check($G, 'export starts with a UTF-8 BOM (Excel needs it)',
      substr($csv, 0, 3) === "\xEF\xBB\xBF", 'EF BB BF');
check($G, 'export bytes are valid UTF-8',
      mb_check_encoding($csv, 'UTF-8'), '');

// Re-parse exactly as a consumer would.
$body  = substr($csv, 3);
$lines = array_filter(explode("\n", trim($body)), 'is_nonempty');
array_shift($lines); // header
$parsed_ok = 0;
$parsed_bad = array();
foreach ($lines as $line) {
    $f = str_getcsv(trim($line, "\r"), ',', '"', CSV_ESCAPE);
    if (count($f) < 2) { continue; }
    $id = (int) $f[0];
    if (isset($roundtripped[$id]) && $roundtripped[$id] === $f[1]) {
        $parsed_ok++;
    } else {
        $parsed_bad[] = $f[1];
    }
}
check($G, 'every row survives export and re-parse',
      $parsed_ok === count($roundtripped) && !$parsed_bad,
      $parsed_ok . '/' . count($roundtripped) . ' identical'
      . ($parsed_bad ? ' — differed: ' . implode(', ', $parsed_bad) : ''));

/* ---------------------------------------------------------------- *
 * 6. Source audit  (section 10 definition of done)
 * ---------------------------------------------------------------- */
$G = 'Source audit';

// Scanned files: the whole app except this one. selftest.php is the auditor —
// it necessarily contains the very strings it is looking for.
// Comments and docblocks are stripped first, so prose about a trap (in db.php,
// bootstrap.php and so on) is not mistaken for the trap itself.
$scanned = source_files(true);
$code_of = array();
foreach ($scanned as $f) {
    $code_of[basename($f)] = strip_php_comments(file_get_contents($f));
}
$scope = count($scanned) . ' file(s), excluding selftest.php';

// Needles are assembled at runtime as a second layer of defence.
$needle_set  = 'SET' . ' ' . 'NAMES';
$needle_ents = 'html' . 'entities';
$re_strlen   = '/(?<![_\w])' . 'str' . 'len\s*\(/i';

$hits_set = array(); $hits_ent = array(); $hits_len = array();
foreach ($code_of as $base => $code) {
    if (stripos($code, $needle_set) !== false) { $hits_set[] = $base; }
    if (stripos($code, $needle_ents) !== false) { $hits_ent[] = $base; }
    if (preg_match($re_strlen, $code))         { $hits_len[] = $base; }
}

check($G, '"' . $needle_set . '" appears nowhere in code', !$hits_set,
      $hits_set ? implode(', ', $hits_set) : $scope);
check($G, $needle_ents . '() appears nowhere in code', !$hits_ent,
      $hits_ent ? implode(', ', $hits_ent) : $scope);
check($G, 'no byte-counting length call on text', !$hits_len,
      $hits_len ? implode(', ', $hits_len) : $scope);

// Every htmlspecialchars() call must pass the explicit 'UTF-8' argument.
$hs_total = 0; $hs_bad = array();
foreach ($code_of as $base => $code) {
    if (preg_match_all('/htmlspecialchars\s*\(([^;]*?)\)\s*;/s', $code, $m)) {
        foreach ($m[1] as $argstr) {
            $hs_total++;
            if (strpos($argstr, "'UTF-8'") === false) { $hs_bad[] = $base; }
        }
    }
}
check($G, "every htmlspecialchars() passes 'UTF-8'", !$hs_bad,
      $hs_bad ? implode(', ', $hs_bad) : $hs_total . ' call site(s), all explicit');

// Every json_encode() call must carry JSON_UNESCAPED_UNICODE.
$je_total = 0; $je_bad = array();
foreach ($code_of as $base => $code) {
    if (preg_match_all('/json_encode\s*\(([^;]*?)\)\s*[;,)]/s', $code, $m)) {
        foreach ($m[1] as $argstr) {
            $je_total++;
            if (strpos($argstr, 'JSON_UNESCAPED_UNICODE') === false) { $je_bad[] = $base; }
        }
    }
}
check($G, 'every json_encode() uses JSON_UNESCAPED_UNICODE', !$je_bad,
      $je_bad ? implode(', ', $je_bad) : $je_total . ' call site(s)');

// Functional, not textual: the DSN the app actually builds.
$live_dsn = db_dsn($CONFIG['db'], true);
check($G, 'PDO DSN carries charset=utf8mb4',
      strpos($live_dsn, 'charset=utf8mb4') !== false, $live_dsn);

$all_src = implode("\n", $code_of);

$idx = file_get_contents(__DIR__ . '/index.php');
check($G, 'form declares accept-charset="UTF-8"',
      strpos($idx, 'accept-charset="UTF-8"') !== false, '');
check($G, 'page declares <meta charset="utf-8">',
      stripos($idx, '<meta charset="utf-8">') !== false, '');
check($G, 'HTTP Content-Type header sets charset=utf-8',
      strpos($all_src, "text/html; charset=utf-8") !== false, '');

/* ---------------------------------------------------------------- *
 * 7. Transliteration  (network-dependent — never a hard failure)
 * ---------------------------------------------------------------- */
$G = 'Transliteration';

$probe = translit_phrase('Ebin Deni Raj');
if ($probe['offline']) {
    info($G, 'upstream reachable', 'NO — offline. This is a supported state: the '
        . 'Hindi field stays empty and editable, and the form still submits.');
} else {
    check($G, 'Ebin Deni Raj resolves to Devanagari',
          has_devanagari($probe['hindi']), $probe['hindi']);

    $alts = 0;
    foreach ($probe['tokens'] as $t) {
        if (!empty($t['word']) && count($t['candidates']) > 1) { $alts++; }
    }
    check($G, 'alternate candidates offered for the user to pick from', $alts > 0,
          $alts . ' word(s) with more than one candidate');

    // The upstream sometimes ranks a spelling that starts with a bare matra
    // top; no Devanagari word can begin with a dependent vowel sign.
    $starts_bad = array();
    foreach ($probe['tokens'] as $t) {
        if (!empty($t['word']) && $t['chosen'] !== '' && preg_match('/^\p{M}/u', $t['chosen'])) {
            $starts_bad[] = $t['en'] . ' -> ' . $t['chosen'];
        }
    }
    check($G, 'no default spelling begins with a dependent vowel sign', !$starts_bad,
          $starts_bad ? implode(', ', $starts_bad) : 'well-formed candidates ranked first');

    $dev = translit_phrase('कृष्णा');
    check($G, 'existing Devanagari passes through untouched',
          $dev['hindi'] === 'कृष्णा', $dev['hindi']);
}

check($G, 'transliteration failure leaves an empty, editable field (not an exception)',
      $probe['hindi'] === '' || has_devanagari($probe['hindi']),
      'no throw on either path');

/* Initials. These resolve from a local table, so they are checked outside the
 * "upstream reachable" branch above — they must hold offline too. A single
 * letter is read as the NAME of the letter (J -> जे), not the sound it makes
 * inside a word (ज). */

$init = translit_phrase('Titus J Sam');
$j_tok = null;
foreach ($init['tokens'] as $t) {
    if (!empty($t['word']) && $t['en'] === 'J') {
        $j_tok = $t;
    }
}
check($G, 'a lone "J" transliterates as जे, not ज',
      $j_tok && $j_tok['chosen'] === 'जे',
      $j_tok ? 'Titus J Sam -> ' . $init['hindi'] : 'J token not found');

check($G, 'the phonetic reading stays available as an alternative',
      $j_tok && in_array('ज', $j_tok['candidates'], true),
      $j_tok ? implode(' | ', $j_tok['candidates']) : '');

$pk = translit_phrase('P.K. Sreekumar');
check($G, 'consecutive initials with periods each resolve',
      mb_strpos($pk['hindi'], 'पी.के.', 0, 'UTF-8') === 0, $pk['hindi']);

/* Every letter must offer at least two candidates: assets/app.js renders no
 * chip row below two, which would leave no visible way to override. */
$bad_letters = array();
foreach (str_split('abcdefghijklmnopqrstuvwxyz') as $ch) {
    $c = translit_letter_candidates($ch);
    if (!$c || count($c) < 2 || preg_match('/^\p{M}/u', $c[0])) {
        $bad_letters[] = $ch;
    }
}
check($G, 'all 26 letters have a well-formed name and an alternative',
      !$bad_letters, $bad_letters ? implode(', ', $bad_letters) : '26/26');

check($G, 'case is ignored for initials',
      translit_letter_candidates('j') === translit_letter_candidates('J'),
      'J and j agree');

check($G, 'multi-letter tokens are left to the engine',
      translit_letter_candidates('PK') === null
      && translit_letter_candidates('Raj') === null,
      'PK and Raj not treated as initials');

/* Learned spellings. Uses nonsense words so a real name is never affected,
 * and clears them again below. */

$LW = 'Zzqmarwick';   // never a real name, so never already known
$LH = 'ज़क़मारविक';

$before = translit_word($LW, false);
check($G, 'an unknown word has nothing learned yet', !$before['candidates'],
      'source: ' . $before['source']);

$pairs = translit_learn('Anu ' . $LW, 'अनु ' . $LH);
check($G, 'a saved record teaches its word pairs', $pairs === 2, $pairs . ' pair(s)');

$after = translit_word($LW, false);
check($G, 'the approved spelling is offered first, without any network',
      isset($after['candidates'][0]) && $after['candidates'][0] === $LH,
      isset($after['candidates'][0]) ? $after['candidates'][0] . ' (' . $after['source'] . ')' : 'nothing');

// A different approved spelling for the same word must not evict the first.
translit_learn('Anu ' . $LW, 'अनु ' . $LH);      // second approval of the same
translit_learn('Bob ' . $LW, 'बॉब ज़क़मारविख');   // a competing spelling, once
$ranked = translit_word($LW, false);
check($G, 'the more-approved spelling outranks a competing one',
      isset($ranked['candidates'][0]) && $ranked['candidates'][0] === $LH
      && count($ranked['candidates']) >= 2,
      implode(' | ', $ranked['candidates']));

check($G, 'a name whose word counts do not line up teaches nothing',
      translit_learn('One Two Three', 'एक दो') === 0,
      'refuses to guess the alignment');

check($G, 'initials are never learned, so the letter table stays authoritative',
      translit_learn('J Sam', 'ज सैम') === 1
      && translit_letter_candidates('j') === array('जे', 'ज', 'ज़'),
      'only the Sam pair was taken');

try {
    $st = db()->prepare('DELETE FROM translit_learned WHERE word_en IN (?, ?)');
    $st->execute(array(mb_strtolower($LW, 'UTF-8'), 'sam'));
    $left = db()->prepare('SELECT COUNT(*) FROM translit_learned WHERE word_en = ?');
    $left->execute(array(mb_strtolower($LW, 'UTF-8')));
    check($G, 'learned test rows removed', ((int) $left->fetchColumn()) === 0, '');
} catch (PDOException $ex) {
    check($G, 'learned test rows removed', false, $ex->getMessage());
}

/* ---------------------------------------------------------------- *
 * Cleanup
 * ---------------------------------------------------------------- */
foreach ($inserted_ids as $id) {
    person_delete($id);
}
$left = db()->query("SELECT COUNT(*) FROM persons WHERE name_en = '__selftest'")->fetchColumn();
check('Cleanup', 'test rows removed', ((int) $left) === 0, $left . ' left behind');


/* ---------------------------------------------------------------- *
 * Helpers
 * ---------------------------------------------------------------- */

function is_nonempty($s) { return $s !== ''; }

/**
 * Every PHP file in the app.
 *
 * @param bool $exclude_self  Leave out selftest.php, which contains the very
 *                            needles the source audit searches for.
 */
function source_files($exclude_self = false)
{
    $files = array();
    $dirs = array(__DIR__, __DIR__ . '/lib', __DIR__ . '/api');
    foreach ($dirs as $d) {
        foreach ((array) glob($d . '/*.php') as $f) {
            if ($exclude_self && realpath($f) === realpath(__FILE__)) {
                continue;
            }
            $files[] = $f;
        }
    }
    sort($files);
    return $files;
}

/** Remove comments so prose about a trap is not mistaken for the trap. */
function strip_php_comments($src)
{
    if (!function_exists('token_get_all')) {
        return $src;
    }
    $out = '';
    foreach (token_get_all($src) as $tok) {
        if (is_array($tok)) {
            if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) {
                continue;
            }
            $out .= $tok[1];
        } else {
            $out .= $tok;
        }
    }
    return $out;
}


/* ---------------------------------------------------------------- *
 * Output
 * ---------------------------------------------------------------- */

$passed = 0; $failed = 0;
foreach ($RESULTS as $r) {
    if ($r['pass'] === true)  { $passed++; }
    if ($r['pass'] === false) { $failed++; }
}

if ($cli) {
    $group = null;
    foreach ($RESULTS as $r) {
        if ($r['group'] !== $group) {
            $group = $r['group'];
            echo "\n" . $group . "\n" . str_repeat('-', mb_strlen($group)) . "\n";
        }
        $mark = $r['pass'] === true ? 'PASS' : ($r['pass'] === false ? 'FAIL' : 'info');
        echo sprintf("  %-4s  %-62s %s\n", $mark, $r['name'], $r['detail']);
    }
    echo "\n" . $passed . ' passed, ' . $failed . " failed\n";
    exit($failed > 0 ? 1 : 0);
}

html_header_utf8();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Self-test</title>
<link rel="stylesheet" href="assets/style.css">
<style>
  .res { width: 100%; border-collapse: collapse; font-size: .88rem; }
  .res td { padding: .35rem .6rem; border-bottom: 1px solid #f0f2f4; }
  .res tr.grp td { background: var(--bg); font-weight: 600; font-size: .78rem;
                   text-transform: uppercase; letter-spacing: .04em; color: var(--dim); }
  .mark { width: 3.5rem; font-family: ui-monospace, Menlo, monospace; font-size: .74rem; font-weight: 700; }
  .m-pass { color: var(--ok); }
  .m-fail { color: var(--bad); }
  .m-info { color: var(--dim); }
  .dtl { color: var(--dim); font-family: ui-monospace, Menlo, monospace; font-size: .78rem; }
  .summary { font-size: 1rem; font-weight: 600; }
</style>
</head>
<body>

<header class="page-head">
  <h1>Self-test</h1>
  <p class="sub">CLAUDE.md section 7 verification and section 10 definition of done.</p>
</header>

<p class="flash <?= $failed ? 'bad' : 'ok' ?> summary">
  <?= (int) $passed ?> passed, <?= (int) $failed ?> failed
</p>

<section class="card">
<table class="res">
<?php
$group = null;
foreach ($RESULTS as $r):
    if ($r['group'] !== $group):
        $group = $r['group'];
?>
  <tr class="grp"><td colspan="3"><?= e($group) ?></td></tr>
<?php endif; ?>
  <tr>
    <td class="mark <?= $r['pass'] === true ? 'm-pass' : ($r['pass'] === false ? 'm-fail' : 'm-info') ?>">
      <?= $r['pass'] === true ? 'PASS' : ($r['pass'] === false ? 'FAIL' : 'info') ?>
    </td>
    <td><?= e($r['name']) ?></td>
    <td class="dtl"><?= e($r['detail']) ?></td>
  </tr>
<?php endforeach; ?>
</table>
</section>

<p><a href="index.php">&larr; Back</a></p>

</body>
</html>
