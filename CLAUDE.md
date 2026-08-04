# CLAUDE.md

Project memory for Claude Code. Read this before touching any file in this repo.

---

## 1. What we are building

A PHP page (backed by MySQL) where a user types a person's name in **English/Latin
script** and the **Hindi (Devanagari)** form appears automatically, is saved to the
database, and renders back correctly on every later read.

Concrete example: typing `Ebin Deni Raj` should produce `एबिन डेनी राज`, save it,
and display it identically on the list page, on a PDF export, and in a CSV download.

### This is transliteration, NOT translation

We are converting **sound**, not meaning. `Ram` → `राम`, never "the Hindi word for
a male sheep". Do not reach for any translation API (Google Translate, LibreTranslate,
DeepL). If a proposed solution involves translating, it is the wrong solution.

---

## 2. The problem that has already bitten us once

A previous attempt at this feature stored corrupted data — the classic
`?????` / `à¤¨à¤¾à¤®` mojibake. **The root cause was the UTF-8 pipeline, not the
transliteration.** Treat charset correctness as the primary engineering concern of
this project, not an afterthought.

Every link in the chain must be utf8mb4. One weak link corrupts everything
downstream, and the corruption is often silent until someone opens an export file.

---

## 3. Environment and constraints

- **Stack:** PHP + MySQL. This is a legacy institutional portal environment.
- **Age:** Sibling portals are 7–8 years old. Assume MySQL may be older than 5.7
  and that PHP may be 7.x. Do not use syntax newer than PHP 7.4 unless you have
  confirmed the runtime version first (`php -v`).
- **Some deployments may be intranet-only.** Outbound internet from the web server
  is not guaranteed. Any online dependency must degrade gracefully to manual entry,
  never to a hard failure or a blank field.
- **Character set decision (locked):** `utf8mb4` / `utf8mb4_unicode_ci`.
  Never `utf8` (MySQL's `utf8` is really `utf8mb3` — a 3-byte subset that breaks on
  emoji and some Indic sequences).

---

## 4. NON-NEGOTIABLE charset rules

Apply all of these. Do not skip one because "it probably doesn't matter here."

| Layer | Required |
|---|---|
| Database | `CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci` |
| Table + every text column | Same as above, declared explicitly |
| **PHP connection** | PDO DSN `charset=utf8mb4`, or `mysqli_set_charset($c,'utf8mb4')` |
| HTTP header | `header('Content-Type: text/html; charset=utf-8')` |
| HTML head | `<meta charset="utf-8">` |
| Form tag | `accept-charset="UTF-8"` |
| Output escaping | `htmlspecialchars($s, ENT_QUOTES, 'UTF-8')` |
| String functions | `mb_strlen`, `mb_substr`, `mb_strtolower` |
| JSON | `json_encode($x, JSON_UNESCAPED_UNICODE)` |
| PHP source files | Saved as UTF-8 **without BOM** |
| Bootstrap | `mb_internal_encoding('UTF-8')` |

### Specific traps — these are the ones that actually cause the bug

1. **`SET NAMES utf8mb4` as a query is NOT a substitute for `set_charset()`.**
   With mysqli it updates the server session but leaves the client-side escaping
   charset stale. Always use `mysqli_set_charset()` or the PDO DSN parameter.
   Never write `$conn->query("SET NAMES ...")` in this codebase.

2. **`htmlentities()` mangles Devanagari.** Only `htmlspecialchars()` with an
   explicit `'UTF-8'` third argument is acceptable.

3. **`strlen()` counts bytes, not characters.** Each Devanagari codepoint is 3
   bytes in UTF-8, so `strlen()` on a validation path will reject valid names.
   Use `mb_strlen()` for every length check.

4. **InnoDB index prefix limit.** MySQL counts `VARCHAR` in characters but indexes
   in bytes. On MySQL < 5.7 the limit is 767 bytes, so a unique index on
   `VARCHAR(255) utf8mb4` fails with error 1071. Use `VARCHAR(191)`
   (191 × 4 = 764) for any indexed text column, or add `ROW_FORMAT=DYNAMIC`.

5. **`mysqldump` needs `--default-character-set=utf8mb4`** or migrations silently
   corrupt on restore.

---

## 5. Transliteration approach

**Primary engine:** Google Input Tools. Free, no API key, ML-trained on real Indic
names so casual English spellings work (`Reshma`, `Sreekumar`, `Ebin`).

```
https://inputtools.google.com/request?text=<word>&itc=hi-t-i0-und&num=5&cp=0&cs=1&ie=utf-8&oe=utf-8&app=demopage
```

Response shape: `["SUCCESS",[["ebin",["एबिन","एबीन",...],[],{}]]]`

Rules:
- **Proxy the call through PHP**, not directly from the browser. This avoids CORS
  problems, keeps the endpoint swappable in one place, and lets us add caching.
- Transliterate **word by word**, then join. The endpoint is tuned for single tokens.
- Set a short curl timeout (3s connect / 5s total). This must never block a form.
- If the word already contains Devanagari (`preg_match('/\p{Devanagari}/u', $w)`),
  pass it through untouched.
- The endpoint is undocumented. Isolate it behind a single function so it can be
  replaced without touching callers.

**Offline fallback:** if the request fails, leave the Hindi field empty and editable,
show a quiet inline message, and let the user type. Do not throw, do not block submit.
If a fully offline install is needed later, bundle `indic-transliteration/sanscript`
— but note it is rule-based and expects ITRANS conventions (`raama`, not `ram`), so
it is a worse fit for casual name spelling.

### UX rule — do not skip this

Name transliteration is genuinely ambiguous (`एबिन` vs `एबीन`, `राज` vs `राझ`).
**Never store the machine output silently.** The Hindi field must be editable, must
show alternate candidates for the user to pick from, and must stop auto-overwriting
once the user has hand-edited it. We store what the user *approved*.

---

## 6. Data model

Keep both forms as separate columns. Never derive Hindi on the fly at read time.

```sql
CREATE TABLE persons (
  id       INT AUTO_INCREMENT PRIMARY KEY,
  name_en  VARCHAR(191) NOT NULL,
  name_hi  VARCHAR(191) NOT NULL,
  created  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  ROW_FORMAT=DYNAMIC;
```

Architectural note: `name_hi` belongs on the **person/user master table only**.
Do not duplicate it per module. Transliteration is a one-time capture at
registration; three modules each generating their own Hindi spelling of the same
person creates a reconciliation problem later.

---

## 7. Verification — run these before claiming the feature works

**Byte-level check.** Correct Devanagari gives `LENGTH` ≈ 3× `CHAR_LENGTH`.
If they are equal, the data is broken at the storage layer regardless of how it
looks in the browser.

```sql
SELECT name_hi, HEX(name_hi), CHAR_LENGTH(name_hi), LENGTH(name_hi) FROM persons;
```

**Charset audit.**

```sql
SHOW VARIABLES LIKE 'character_set%';
SHOW VARIABLES LIKE 'collation%';
SHOW FULL COLUMNS FROM persons;
```

Every `character_set_client`, `character_set_connection`, `character_set_results`
must read `utf8mb4`.

**Round-trip test.** Insert via the form → read back on the list page → export to
CSV → reopen the CSV. Corruption commonly appears only at the last step.

**Test strings.** Use these, not just `नाम`:
- `एबिन डेनी राज` (conjuncts + matras)
- `कृष्णा` (vowel sign ऋ)
- `श्रीकुमार` (श्र conjunct)
- `डॉ. अंजलि` (chandrabindu/anusvara + punctuation)

---

## 8. Repairing already-corrupted columns

Only if the bytes are valid UTF-8 but the column was **declared** latin1:

```sql
ALTER TABLE persons MODIFY name_hi VARBINARY(255);
ALTER TABLE persons MODIFY name_hi VARCHAR(191)
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

**Take a full backup first.** This is destructive if the data is genuinely
double-encoded (mojibake already baked into the bytes) rather than just mislabelled.
Diagnose with `HEX()` before running it — do not run it speculatively.

---

## 9. Downstream rendering

Devanagari will not render in exports without an explicit font.

- **PDF:** DomPDF/TCPDF/mPDF all need Noto Sans Devanagari registered. Prefer
  **mPDF** — it handles Indic shaping (conjuncts, reordered matras) far better
  than DomPDF, which will produce visually wrong output even with the font loaded.
- **Excel:** PhpSpreadsheet needs the font set on the cell style.
- **HTML:** font stack `"Noto Sans Devanagari", "Nirmala UI", system-ui, sans-serif`.

---

## 10. Definition of done

- [ ] All ten rows of the section 4 table verified in the actual code
- [ ] `SET NAMES` appears nowhere in the codebase
- [ ] `htmlentities` appears nowhere in the codebase
- [ ] No `strlen()` on any user-facing text field
- [ ] Transliteration failure leaves a usable, editable form
- [ ] Hindi field is user-editable and offers alternate candidates
- [ ] Section 7 byte-level and round-trip checks pass
- [ ] All four test strings from section 7 survive a full round trip
