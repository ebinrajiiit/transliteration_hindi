# Name transliteration — English → Hindi

Type a person's name in Latin script, get the Devanagari form, edit it if the
machine guessed wrong, save it. Built to the rules in `CLAUDE.md`.

This is **transliteration** (sound), not translation (meaning): `Ram` → `राम`.

---

## Run it

```bash
php install.php          # creates the database and tables (idempotent)
php -S localhost:8000    # from this directory
```

Open <http://localhost:8000/>.

Verify everything:

```bash
php selftest.php         # exits non-zero if anything fails
```

or open <http://localhost:8000/selftest.php>.

### Configuration

Everything is overridable by environment variable — no need to edit a tracked
file:

| Variable | Default |
|---|---|
| `TRANSLIT_DB_HOST` | `127.0.0.1` |
| `TRANSLIT_DB_PORT` | `3306` |
| `TRANSLIT_DB_NAME` | `translit_demo` |
| `TRANSLIT_DB_USER` | `root` |
| `TRANSLIT_DB_PASS` | *(empty)* |
| `TRANSLIT_ENDPOINT` | Google Input Tools URL |
| `TRANSLIT_DEBUG` | `1` — set to `0` in production |

---

## Files

| File | Role |
|---|---|
| `bootstrap.php` | UTF-8 environment; loaded first by every entry point |
| `config.php` | Settings, all env-overridable |
| `db.php` | PDO connection — charset lives in the DSN |
| `lib/translit.php` | The only file that knows the upstream endpoint |
| `lib/persons.php` | Person master table access + validation |
| `api/translit.php` | JSON proxy the browser calls |
| `index.php` | Form + list |
| `export_csv.php` | CSV with UTF-8 BOM |
| `export_pdf.php` | mPDF when installed, print view otherwise |
| `selftest.php` | Executable version of CLAUDE.md §7 and §10 |
| `install.php` | One-time schema setup |
| `schema.sql` | The same DDL, for manual restore |

---

## How the charset rules are met

Every row of the `CLAUDE.md` §4 table, and where it lives:

| Layer | Where |
|---|---|
| Database `utf8mb4` | `install.php`, `schema.sql` |
| Table + every text column | `schema.sql` — declared explicitly per column |
| PHP connection | `db.php` — `charset=utf8mb4` in the PDO DSN |
| HTTP header | `bootstrap.php` → `html_header_utf8()` |
| HTML head | `index.php` → `<meta charset="utf-8">` |
| Form tag | `index.php` → `accept-charset="UTF-8"` |
| Output escaping | `bootstrap.php` → `e()`, the only escaper used |
| String functions | `mb_*` everywhere; `name_len()` wraps `mb_strlen` |
| JSON | `json_out()` → `JSON_UNESCAPED_UNICODE` |
| Source files | UTF-8 without BOM — `selftest.php` checks this |
| Bootstrap | `mb_internal_encoding('UTF-8')` |

The four specific traps:

1. **`SET NAMES` is never used.** The charset is set via the PDO DSN, which
   configures the client library itself. `selftest.php` greps the codebase for
   it (with comments stripped, so prose about the trap does not count).
2. **`htmlentities()` is never used.** Only `htmlspecialchars($s, ENT_QUOTES, 'UTF-8')`,
   wrapped once in `e()`. Also grepped.
3. **No `strlen()` on user text.** All length checks go through `name_len()`.
   Also grepped, with a lookbehind so `mb_strlen` does not false-positive.
4. **`VARCHAR(191)`** on every indexed text column (191 × 4 = 764 bytes, under
   the 767-byte InnoDB prefix limit), plus `ROW_FORMAT=DYNAMIC`.

---

## Two things worth knowing

**The upstream sometimes ranks a malformed spelling first.** As of now,
`ebin` returns `["ेबिन","ेबीन","एबिन",…]` — the first two begin with a bare
matra (a dependent vowel sign), which no Devanagari word can do. The correct
`एबिन` is third. `translit_rank_candidates()` demotes any candidate starting
with a Unicode Mark rather than dropping it, so the upstream ordering is still
reachable in the picker. Without this, `Ebin Deni Raj` fills in as `ेबिन देनी राज`.

**Spelling is genuinely ambiguous, so nothing is stored silently.** `Deni`
offers `देनी | देनि | डेनी | देणी | डैनी`. The Hindi field is always editable, every
word shows its alternatives as clickable chips, and once you touch the field
the automatic fill stops overwriting it. What gets stored is what you approved.

---

## Offline behaviour

Outbound internet is not assumed. If the endpoint is unreachable:

- the Hindi field is left empty and editable, with an inline message;
- a **Retry** button appears;
- the form still submits — nothing throws, nothing blocks;
- timeouts are 3 s connect / 5 s total, and a circuit breaker stops the app
  paying that cost once per word (a four-word name costs one timeout, not four);
- locally cached words are still served, because the cache is a local table.

Cached lookups live in `translit_cache`. A cache miss or error there is never
surfaced to the user.

---

## Verification

`selftest.php` runs 43 checks covering:

- the PHP environment and the absence of BOMs;
- `character_set_client` / `_connection` / `_results` all `utf8mb4`;
- table and per-column collations, and the `VARCHAR(191)` limit;
- a byte-level round trip of all four `CLAUDE.md` §7 test strings, asserting
  `LENGTH > CHAR_LENGTH` on each — plus `नाम 🙏`, a 4-byte codepoint, which is
  what actually proves the column is `utf8mb4` and not MySQL's 3-byte `utf8`;
- a CSV export/re-parse round trip, including the BOM;
- the source audit described above;
- transliteration, including the malformed-candidate regression.

It writes rows tagged `__selftest` and removes them again.

Manual round trip worth doing once: add a name via the form → check it on the
list → download the CSV → **open it in Excel**, not a text editor. That last
step is where charset bugs usually surface.

---

## Deploying to the institutional server

- Confirm the runtime first: `php -v`, `mysql --version`. The code is written to
  PHP 7.4 syntax and avoids anything newer.
- Set `TRANSLIT_DEBUG=0`.
- Dump and restore with `--default-character-set=utf8mb4`, always.
- `name_hi` belongs on the person master table only. Do not add a copy per
  module — three modules generating their own Hindi spelling of the same person
  is a reconciliation problem later.
- For server-side PDF: `composer require mpdf/mpdf`. `export_pdf.php` picks it
  up automatically and falls back to the print view when it is absent.
