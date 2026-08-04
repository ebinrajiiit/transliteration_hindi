<?php
/**
 * Person master table access. `name_hi` lives here and only here — see the
 * architectural note in CLAUDE.md section 6. Modules read this column, they do
 * not generate their own Hindi spelling.
 */

/**
 * @return array  List of error strings; empty means valid.
 */
function person_validate($name_en, $name_hi)
{
    global $CONFIG;
    $errors = array();
    $max = $CONFIG['max_name_chars'];

    // mb_strlen throughout: a Devanagari codepoint is 3 bytes, so strlen()
    // would reject valid names. See CLAUDE.md section 4, trap 3.
    if (name_len(trim($name_en)) === 0) {
        $errors[] = 'English name is required.';
    } elseif (name_len(trim($name_en)) > $max) {
        $errors[] = 'English name must be ' . $max . ' characters or fewer.';
    }

    if (name_len(trim($name_hi)) === 0) {
        $errors[] = 'Hindi name is required. If transliteration was unavailable, type it in.';
    } elseif (name_len(trim($name_hi)) > $max) {
        $errors[] = 'Hindi name must be ' . $max . ' characters or fewer.';
    } elseif (!has_devanagari($name_hi)) {
        $errors[] = 'Hindi name does not contain any Devanagari characters.';
    }

    return $errors;
}

function person_insert($name_en, $name_hi)
{
    $st = db()->prepare('INSERT INTO persons (name_en, name_hi) VALUES (?, ?)');
    $st->execute(array(trim($name_en), trim($name_hi)));
    return (int) db()->lastInsertId();
}

function person_update($id, $name_en, $name_hi)
{
    $st = db()->prepare('UPDATE persons SET name_en = ?, name_hi = ? WHERE id = ?');
    $st->execute(array(trim($name_en), trim($name_hi), (int) $id));
}

function person_delete($id)
{
    $st = db()->prepare('DELETE FROM persons WHERE id = ?');
    $st->execute(array((int) $id));
}

function person_get($id)
{
    $st = db()->prepare('SELECT * FROM persons WHERE id = ? LIMIT 1');
    $st->execute(array((int) $id));
    return $st->fetch();
}

/**
 * List rows. Includes the byte-level diagnostic columns from CLAUDE.md
 * section 7 so the storage layer can be eyeballed without opening a SQL client.
 */
function person_all()
{
    return db()->query(
        'SELECT id, name_en, name_hi, created,
                CHAR_LENGTH(name_hi) AS hi_chars,
                LENGTH(name_hi)      AS hi_bytes,
                HEX(name_hi)         AS hi_hex
           FROM persons
          ORDER BY id DESC'
    )->fetchAll();
}
