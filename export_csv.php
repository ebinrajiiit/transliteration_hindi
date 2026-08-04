<?php
/**
 * CSV export.
 *
 * This is the step where charset bugs usually surface (CLAUDE.md section 7):
 * the data can look fine in the browser and still open as mojibake in Excel.
 *
 * Two things make it work:
 *  1. The UTF-8 BOM. Excel on Windows assumes the system ANSI codepage for a
 *     .csv without one and will render Devanagari as garbage.
 *  2. Writing bytes straight out — no htmlentities, no iconv, no re-encoding.
 */

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib/persons.php';

$rows = person_all();

$filename = 'persons-' . date('Y-m-d') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');

// UTF-8 BOM — EF BB BF.
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, array('id', 'name_en', 'name_hi', 'created'), ',', '"', CSV_ESCAPE);

foreach ($rows as $r) {
    fputcsv($out, array(
        $r['id'],
        $r['name_en'],
        $r['name_hi'],
        $r['created'],
    ), ',', '"', CSV_ESCAPE);
}

fclose($out);
