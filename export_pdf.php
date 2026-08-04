<?php
/**
 * PDF export.
 *
 * CLAUDE.md section 9: Devanagari will not render in a PDF without an explicit
 * font, and DomPDF produces visually wrong output even with the font loaded
 * because it does not do Indic shaping (conjuncts, reordered matras). mPDF is
 * the one to use.
 *
 * mPDF needs Composer, which needs outbound internet — not guaranteed here
 * (section 3). So: use mPDF when it is installed, otherwise fall back to a
 * print-optimised HTML page the browser can "Save as PDF". The browser does
 * correct Indic shaping, so the fallback output is visually right; it just
 * needs one manual step.
 *
 *   composer require mpdf/mpdf
 */

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib/persons.php';

$rows = person_all();
$has_mpdf = file_exists(__DIR__ . '/vendor/autoload.php');

if ($has_mpdf) {
    require __DIR__ . '/vendor/autoload.php';
}

if ($has_mpdf && class_exists('\Mpdf\Mpdf')) {
    $html = render_rows_html($rows, false);
    try {
        $mpdf = new \Mpdf\Mpdf(array(
            'mode'         => 'utf-8',
            'format'       => 'A4',
            // autoScriptToLang/autoLangToFont make mPDF pick a Devanagari-capable
            // font and apply Indic shaping for the Devanagari runs.
            'autoScriptToLang' => true,
            'autoLangToFont'   => true,
            'default_font'     => 'freeserif',
        ));
        $mpdf->WriteHTML($html);
        $mpdf->Output('persons-' . date('Y-m-d') . '.pdf', \Mpdf\Output\Destination::DOWNLOAD);
        exit;
    } catch (\Exception $ex) {
        // fall through to the print view rather than showing an error page
    }
}

html_header_utf8();
echo render_rows_html($rows, true);


function render_rows_html($rows, $with_print_ui)
{
    ob_start();
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Persons — print</title>
<style>
  body { font: 12pt/1.5 system-ui, sans-serif; color: #111; margin: 2rem; }
  h1 { font-size: 16pt; margin: 0 0 .25rem; }
  .meta { color: #666; font-size: 9pt; margin: 0 0 1.5rem; }
  table { width: 100%; border-collapse: collapse; }
  th { text-align: left; font-size: 9pt; text-transform: uppercase; letter-spacing: .04em;
       color: #555; border-bottom: 1.5px solid #333; padding: 4px 8px; }
  td { padding: 6px 8px; border-bottom: 1px solid #ddd; }
  .hi { font-family: "Noto Sans Devanagari", "Nirmala UI", system-ui, sans-serif; font-size: 13pt; }
  .note { margin: 0 0 1.5rem; padding: .7rem .9rem; background: #fff4e5;
          border-radius: 6px; font-size: 10pt; color: #6b4b00; }
  .note code { background: #00000010; padding: 1px 4px; border-radius: 3px; }
  @media print { .note, .noprint { display: none; } body { margin: 0; } }
</style>
</head>
<body>

<h1>Persons</h1>
<p class="meta">Generated <?= e(date('Y-m-d H:i')) ?> &middot; <?= count($rows) ?> record(s)</p>

<?php if ($with_print_ui): ?>
<div class="note">
  <strong>mPDF is not installed</strong>, so this is the print view. Use your
  browser's Print &rarr; Save as PDF — the browser shapes Devanagari conjuncts
  correctly. For server-side PDF generation run <code>composer require mpdf/mpdf</code>
  and reload this page; it will then download a PDF directly.
</div>
<?php endif; ?>

<table>
  <thead>
    <tr><th>#</th><th>English</th><th>Hindi</th><th>Created</th></tr>
  </thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><?= e($r['id']) ?></td>
      <td><?= e($r['name_en']) ?></td>
      <td class="hi"><?= e($r['name_hi']) ?></td>
      <td><?= e($r['created']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<?php if ($with_print_ui): ?>
<p class="noprint" style="margin-top:1.5rem;font-size:10pt">
  <a href="index.php">&larr; Back</a>
</p>
<?php endif; ?>

</body>
</html>
    <?php
    return ob_get_clean();
}
