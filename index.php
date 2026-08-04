<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib/persons.php';

html_header_utf8();
session_start();

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf'];

$errors  = array();
$notice  = '';
$form    = array('id' => '', 'name_en' => '', 'name_hi' => '');

/* ---------------------------------------------------------------- *
 * POST — Post/Redirect/Get so a refresh never re-submits
 * ---------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, isset($_POST['csrf']) ? (string) $_POST['csrf'] : '')) {
        $errors[] = 'Session expired. Please try again.';
    } else {
        $action = isset($_POST['action']) ? $_POST['action'] : '';

        if ($action === 'delete') {
            person_delete((int) $_POST['id']);
            header('Location: index.php?deleted=1');
            exit;
        }

        // The Hindi value posted here is whatever the user approved in the
        // field — never a value regenerated on the server. See section 5, UX rule.
        $name_en = isset($_POST['name_en']) ? (string) $_POST['name_en'] : '';
        $name_hi = isset($_POST['name_hi']) ? (string) $_POST['name_hi'] : '';
        $id      = isset($_POST['id']) && $_POST['id'] !== '' ? (int) $_POST['id'] : null;

        $errors = person_validate($name_en, $name_hi);

        if (!$errors) {
            if ($id) {
                person_update($id, $name_en, $name_hi);
                header('Location: index.php?saved=1');
            } else {
                person_insert($name_en, $name_hi);
                header('Location: index.php?saved=1');
            }
            exit;
        }

        $form = array('id' => $id, 'name_en' => $name_en, 'name_hi' => $name_hi);
    }
}

/* ---------------------------------------------------------------- *
 * GET
 * ---------------------------------------------------------------- */
if (isset($_GET['saved']))   { $notice = 'Saved.'; }
if (isset($_GET['deleted'])) { $notice = 'Deleted.'; }

if (isset($_GET['edit'])) {
    $row = person_get((int) $_GET['edit']);
    if ($row) {
        $form = array('id' => $row['id'], 'name_en' => $row['name_en'], 'name_hi' => $row['name_hi']);
    }
}

$people = person_all();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Name transliteration — English to Hindi</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>

<header class="page-head">
  <h1>Name transliteration</h1>
  <p class="sub">English (Latin) &rarr; Hindi (Devanagari). Sound, not meaning.</p>
</header>

<?php if ($notice): ?>
  <p class="flash ok"><?= e($notice) ?></p>
<?php endif; ?>

<?php if ($errors): ?>
  <ul class="flash bad">
    <?php foreach ($errors as $err): ?>
      <li><?= e($err) ?></li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>

<section class="card">
  <h2><?= $form['id'] ? 'Edit person #' . e($form['id']) : 'Add a person' ?></h2>

  <!-- accept-charset pins the submission encoding. See CLAUDE.md section 4. -->
  <form method="post" action="index.php" accept-charset="UTF-8" id="person-form">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="id" value="<?= e($form['id']) ?>">

    <div class="field">
      <label for="name_en">Name in English</label>
      <input type="text" id="name_en" name="name_en" maxlength="191" autocomplete="off"
             spellcheck="false" placeholder="Ebin Deni Raj"
             value="<?= e($form['name_en']) ?>">
      <p class="hint">Type a name; the Hindi form is filled in automatically.</p>
    </div>

    <div class="field">
      <label for="name_hi">
        Name in Hindi
        <span id="translit-status" class="status" aria-live="polite"></span>
      </label>
      <input type="text" id="name_hi" name="name_hi" maxlength="191" lang="hi"
             class="devanagari" placeholder="एबिन डेनी राज"
             value="<?= e($form['name_hi']) ?>">
      <p class="hint" id="hi-hint">
        Always editable. Nothing is stored until you save it.
      </p>
    </div>

    <div id="candidates" class="candidates" hidden>
      <p class="cand-lead">Alternatives — click one to use it:</p>
      <div id="candidate-rows"></div>
      <p class="cand-warn" id="cand-warn" hidden>
        You have edited the Hindi field by hand. Clicking a suggestion will
        replace the whole field.
      </p>
    </div>

    <div class="actions">
      <button type="submit" class="primary">Save</button>
      <?php if ($form['id']): ?>
        <a class="btn-link" href="index.php">Cancel</a>
      <?php endif; ?>
      <button type="button" id="retry-btn" class="secondary" hidden>Retry transliteration</button>
    </div>
  </form>
</section>

<section class="card">
  <div class="card-head">
    <h2>Saved people <span class="count"><?= count($people) ?></span></h2>
    <div class="exports">
      <a href="export_csv.php">CSV</a>
      <a href="export_pdf.php">PDF</a>
      <a href="selftest.php">Run self-test</a>
    </div>
  </div>

  <?php if (!$people): ?>
    <p class="empty">Nothing saved yet.</p>
  <?php else: ?>
  <div class="table-wrap">
  <table>
    <thead>
      <tr>
        <th>#</th>
        <th>English</th>
        <th>Hindi</th>
        <th title="CHAR_LENGTH vs LENGTH — Devanagari should be about 3 bytes per character">chars / bytes</th>
        <th>Created</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($people as $p): ?>
      <?php
        // Correct storage gives roughly 3 bytes per Devanagari character.
        // Equal values mean the bytes were mangled on the way in.
        $ratio_ok = $p['hi_chars'] > 0 && $p['hi_bytes'] >= $p['hi_chars'] * 2;
      ?>
      <tr>
        <td><?= e($p['id']) ?></td>
        <td><?= e($p['name_en']) ?></td>
        <td class="devanagari big"><?= e($p['name_hi']) ?></td>
        <td class="mono <?= $ratio_ok ? 'good' : 'warn' ?>"
            title="HEX: <?= e($p['hi_hex']) ?>">
          <?= e($p['hi_chars']) ?> / <?= e($p['hi_bytes']) ?>
        </td>
        <td class="mono dim"><?= e($p['created']) ?></td>
        <td class="row-actions">
          <a href="index.php?edit=<?= e($p['id']) ?>">Edit</a>
          <form method="post" action="index.php" class="inline">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= e($p['id']) ?>">
            <button type="submit" class="link-danger">Delete</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</section>

<footer class="page-foot">
  <p>
    Storage check: Devanagari should show roughly 3 bytes per character in the
    <code>chars / bytes</code> column. Equal numbers mean the data is broken at
    the storage layer, however it looks here.
  </p>
</footer>

<script src="assets/app.js"></script>
</body>
</html>
