<?php
/**
 * Plain PHP CLI test for wordpress-plugin/includes/gamesheet-parser.php.
 * No WordPress needed - run with: php tests/test_parser.php
 */
require __DIR__ . '/../wordpress-plugin/includes/gamesheet-parser.php';

$failures = 0;
$checks = 0;

function check(string $label, $actual, $expected): void {
    global $failures, $checks;
    $checks++;
    if ($actual !== $expected) {
        $failures++;
        echo "FAIL: $label\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n";
    }
}

$html = file_get_contents(__DIR__ . '/fixtures/gamesheet_920.html');
$sheet = gsi_parse_gamesheet($html, 'https://stats.nihlnational.com/pdf/print/de-html/920');

// --- game meta ---
check('home name', $sheet['home']['name'], 'Romford Raiders');
check('away name', $sheet['away']['name'], 'Peterborough Phantoms');
check('date', $sheet['date'], '2026-09-06');
check('venue', $sheet['venue'], 'Sapphire Ice and Leisure Romford');
check('competition', $sheet['competition'], 'Pre-season 26');

// --- rosters ---
check('home roster count', count($sheet['home']['roster']), 20);
check('away roster count', count($sheet['away']['roster']), 20);

$ceci = null;
foreach ($sheet['home']['roster'] as $r) {
    if ($r['number'] === '33') { $ceci = $r; break; }
}
check('ceci last name', $ceci['last_name'] ?? null, 'Ceci');
check('ceci first name', $ceci['first_name'] ?? null, 'Cole');
check('ceci position', $ceci['position'] ?? null, 'GK');
check('ceci dob', $ceci['date_of_birth'] ?? null, '1998-03-04');

$hyphenated = null;
foreach ($sheet['away']['roster'] as $r) {
    if ($r['number'] === '36') { $hyphenated = $r; break; }
}
check('hyphenated last name', $hyphenated['last_name'] ?? null, 'Clarke-Pizzo');
check('hyphenated first name', $hyphenated['first_name'] ?? null, 'Morgan');

// --- skater box score ---
$bacallao = null;
foreach ($sheet['home']['skaters'] as $s) {
    if ($s['number'] === '21') { $bacallao = $s; break; }
}
check('bacallao goals', $bacallao['goals'] ?? null, 4);
check('bacallao assists', $bacallao['assists'] ?? null, 1);
check('bacallao pim', $bacallao['pim'] ?? null, 0);

$gretton = null;
foreach ($sheet['away']['skaters'] as $s) {
    if ($s['number'] === '85') { $gretton = $s; break; }
}
check('gretton pim', $gretton['pim'] ?? null, 32);

// --- goalies ---
$home_goalie = $sheet['home']['goalies'][0] ?? null;
check('home goalie number', $home_goalie['number'] ?? null, '33');
check('home goalie saves', $home_goalie['saves'] ?? null, 30);
check('home goalie ga', $home_goalie['goals_against'] ?? null, 4);
check('home goalie shots_against', $home_goalie['shots_against'] ?? null, 34);
check('home goalie save_pct', $home_goalie['save_pct'] ?? null, round(30 / 34, 3));

$away_goalies = [];
foreach ($sheet['away']['goalies'] as $g) {
    $away_goalies[$g['number']] = $g;
}
check('away goalie 42 saves', $away_goalies['42']['saves'] ?? null, 21);
check('away goalie 42 ga', $away_goalies['42']['goals_against'] ?? null, 4);
check('away goalie 40 saves', $away_goalies['40']['saves'] ?? null, 6);
check('away goalie 40 ga', $away_goalies['40']['goals_against'] ?? null, 4);

// --- warnings ---
$has_sog_warning = false;
foreach ($sheet['warnings'] as $w) {
    if (str_contains($w, 'Shots On Goal')) { $has_sog_warning = true; }
}
check('sog warning present', $has_sog_warning, true);

echo "\n$checks checks, $failures failures.\n";
exit($failures > 0 ? 1 : 0);
