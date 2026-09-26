<?php
/**
 * Pure PHP parser for ice hockey gamesheets (the "de-html" print view, e.g.
 * https://stats.nihlnational.com/pdf/print/de-html/<id>). No WordPress
 * dependency - can be required and exercised standalone (see
 * tests/test_parser.php), which is how the exact values below were
 * verified against a real gamesheet before this was wired into WordPress.
 *
 * Markup this relies on (see README.md "How stats are read" for the same
 * notes in context):
 *
 *   #info / #topinfo   - game meta. #info's <td>s each hold
 *                        <span class="small">Label</span><span class="big">Value</span>.
 *                        #topinfo instead alternates plain <td>Label</td><td>Value</td>.
 *   #teams              - two <table class="table-team">, home then away:
 *                        full roster (Pos., No., Name, Status, Date of
 *                        birth, Season Age, Starting six). Name is printed
 *                        "SURNAME Firstname". Used for player creation.
 *   #homestats/#awaystats - each contains a table (same "table-team" class,
 *                        found here by header match instead of position)
 *                        with columns No., Name, Pos., G, A, PIM - the
 *                        actual box score for skaters (goalies appear here
 *                        too, always 0/0/0).
 *   #summary            - table.table-stats elements: one is team-level
 *                        period-by-period G/SoG/PIM/PPG/SHG totals (no
 *                        per-skater shots - see KNOWN GAP below); one is
 *                        saves-by-period-per-goalie (columns GKA1, GKA2,
 *                        EGA, GKB1, GKB2, EGB, last row = TOTAL); one is
 *                        the goalie summary (No. A, Min, GA, No. B, Min,
 *                        GA - shirt numbers in GKA1/GKA2/GKB1/GKB2 order).
 *
 * KNOWN GAP: this layout has no per-skater Shots On Goal column anywhere -
 * only team totals per period. SOG is therefore never produced by this
 * parser (not defaulted to 0, which would misrepresent missing data as a
 * real stat).
 */

function gsi_parse_gamesheet(string $html, ?string $source_url = null): array {
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);

    $warnings = [];

    $info = gsi_label_value_pairs($xpath, gsi_by_id($xpath, 'info'));
    $topinfo = gsi_alternating_label_value_pairs($xpath, gsi_by_id($xpath, 'topinfo'));

    $home_name = $info['Home'] ?? '';
    $away_name = $info['Visitor'] ?? '';
    if (!$home_name || !$away_name) {
        $warnings[] = 'Could not read home/away team names from #info.';
    }

    $teams_container = gsi_by_id($xpath, 'teams');
    $team_tables = $teams_container ? gsi_tables_with_class($xpath, $teams_container, 'table-team') : [];
    $home_roster = isset($team_tables[0]) ? gsi_parse_roster_table($xpath, $team_tables[0]) : [];
    $away_roster = isset($team_tables[1]) ? gsi_parse_roster_table($xpath, $team_tables[1]) : [];
    if (!$home_roster || !$away_roster) {
        $warnings[] = 'Could not parse full rosters from #teams (expected two table.table-team elements).';
    }

    $home_skaters = gsi_parse_boxscore_table($xpath, gsi_by_id($xpath, 'homestats'));
    $away_skaters = gsi_parse_boxscore_table($xpath, gsi_by_id($xpath, 'awaystats'));
    if (!$home_skaters || !$away_skaters) {
        $warnings[] = 'Could not parse per-player G/A/PIM box score from #homestats/#awaystats.';
    }

    [$home_goalies, $away_goalies] = gsi_parse_goalies($xpath, gsi_by_id($xpath, 'summary'));
    if (!$home_goalies || !$away_goalies) {
        $warnings[] = 'Could not parse goalie SA/GA from #summary.';
    }

    $warnings[] = 'This gamesheet layout has no per-skater Shots On Goal column - only team '
        . 'SoG totals per period are recorded, not attributed to individual skaters. SOG will '
        . 'not be written for any player; G, A, PIM (skaters) and SA, GA, SV (goalies) are still populated.';

    return [
        'source_url' => $source_url,
        'competition' => $topinfo['Competition'] ?? null,
        'venue' => $topinfo['Place'] ?? ($info['Place'] ?? null),
        'date' => gsi_parse_uk_date($topinfo['Date'] ?? ($info['Date'] ?? '')),
        'attendance' => $topinfo['Attendance'] ?? null,
        'home' => ['name' => $home_name, 'roster' => $home_roster, 'skaters' => $home_skaters, 'goalies' => $home_goalies],
        'away' => ['name' => $away_name, 'roster' => $away_roster, 'skaters' => $away_skaters, 'goalies' => $away_goalies],
        'warnings' => $warnings,
    ];
}

/** Adds shots_against and save_pct (SA/(SA+GA), 3dp) to every goalie in the parsed sheet. */
function gsi_compute_goalie_derived(array $goalie): array {
    $sa = $goalie['saves'] + $goalie['goals_against'];
    $goalie['shots_against'] = $sa;
    $goalie['save_pct'] = $sa > 0 ? round($goalie['saves'] / $sa, 3) : null;
    return $goalie;
}

// --- DOM helpers -----------------------------------------------------------

function gsi_by_id(DOMXPath $xpath, string $id): ?DOMElement {
    $nodes = $xpath->query("//*[@id='" . $id . "']");
    return $nodes->length ? $nodes->item(0) : null;
}

function gsi_has_class(DOMElement $el, string $class): bool {
    $attr = ' ' . trim((string) $el->getAttribute('class')) . ' ';
    return strpos($attr, ' ' . $class . ' ') !== false;
}

function gsi_text(?DOMNode $node): string {
    if (!$node) {
        return '';
    }
    // Collapse whitespace including non-breaking spaces (\xC2\xA0), which the
    // gamesheet uses to pad otherwise-empty cells and which PCRE's \s does
    // not match by default.
    $text = str_replace("\xc2\xa0", ' ', $node->textContent);
    return trim(preg_replace('/\s+/', ' ', $text));
}

function gsi_tables_with_class(DOMXPath $xpath, DOMElement $container, string $class): array {
    $out = [];
    foreach ($xpath->query(".//table", $container) as $table) {
        if (gsi_has_class($table, $class)) {
            $out[] = $table;
        }
    }
    return $out;
}

function gsi_row_cells(DOMElement $tr): array {
    $cells = [];
    foreach ($tr->childNodes as $child) {
        if ($child instanceof DOMElement && in_array(strtolower($child->tagName), ['td', 'th'], true)) {
            $cells[] = gsi_text($child);
        }
    }
    return $cells;
}

function gsi_rows(DOMElement $table): array {
    $rows = [];
    foreach ($table->getElementsByTagName('tr') as $tr) {
        $rows[] = $tr;
    }
    return $rows;
}

// --- #info / #topinfo --------------------------------------------------------

function gsi_label_value_pairs(DOMXPath $xpath, ?DOMElement $table): array {
    $out = [];
    if (!$table) {
        return $out;
    }
    foreach ($table->getElementsByTagName('td') as $td) {
        $label = null;
        $value = null;
        foreach ($xpath->query(".//span", $td) as $span) {
            if (gsi_has_class($span, 'small') && $label === null) {
                $label = gsi_text($span);
            } elseif (gsi_has_class($span, 'big') && $value === null) {
                $value = gsi_text($span);
            }
        }
        if ($label) {
            $out[$label] = $value ?? '';
        }
    }
    return $out;
}

function gsi_alternating_label_value_pairs(DOMXPath $xpath, ?DOMElement $table): array {
    $out = [];
    if (!$table) {
        return $out;
    }
    foreach ($table->getElementsByTagName('tr') as $tr) {
        $cells = gsi_row_cells($tr);
        for ($i = 0; $i < count($cells) - 1; $i += 2) {
            if ($cells[$i] !== '') {
                $out[$cells[$i]] = $cells[$i + 1];
            }
        }
    }
    return $out;
}

function gsi_parse_uk_date(string $value): ?string {
    if (!preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', trim($value), $m)) {
        return null;
    }
    [, $d, $mo, $y] = $m;
    if (!checkdate((int) $mo, (int) $d, (int) $y)) {
        return null;
    }
    return sprintf('%04d-%02d-%02d', $y, $mo, $d);
}

// --- Names -------------------------------------------------------------------

/** "SURNAME Firstname[ Middle]" -> [last_name, first_name]. */
function gsi_split_name(string $printed_name): array {
    $tokens = preg_split('/\s+/', trim($printed_name), -1, PREG_SPLIT_NO_EMPTY);
    if (!$tokens) {
        return ['', ''];
    }
    $last_tokens = [];
    $i = 0;
    while ($i < count($tokens) && preg_match('/[A-Z]/', $tokens[$i]) && strtoupper($tokens[$i]) === $tokens[$i]) {
        $last_tokens[] = $tokens[$i];
        $i++;
    }
    if (!$last_tokens) {
        return [$tokens[0], implode(' ', array_slice($tokens, 1))];
    }
    $last_name = implode(' ', $last_tokens);
    $last_name_display = preg_replace_callback('/[A-Za-z]+/', function ($m) {
        return ucfirst(strtolower($m[0]));
    }, $last_name);
    $first_name = implode(' ', array_slice($tokens, $i));
    return [$last_name_display, $first_name];
}

// --- Rosters & box score tables ----------------------------------------------

function gsi_parse_roster_table(DOMXPath $xpath, DOMElement $table): array {
    $entries = [];
    $rows = gsi_rows($table);
    array_shift($rows); // header
    foreach ($rows as $tr) {
        $cells = gsi_row_cells($tr);
        if (count($cells) < 5 || $cells[1] === '') {
            continue;
        }
        [$pos, $number, $name] = [$cells[0], $cells[1], $cells[2]];
        $dob = isset($cells[4]) ? gsi_parse_uk_date($cells[4]) : null;
        [$last_name, $first_name] = gsi_split_name($name);
        $entries[] = [
            'number' => $number,
            'last_name' => $last_name,
            'first_name' => $first_name,
            'position' => $pos,
            'date_of_birth' => $dob,
        ];
    }
    return $entries;
}

function gsi_find_table_by_header(DOMXPath $xpath, ?DOMElement $container, array $expected_header): ?DOMElement {
    if (!$container) {
        return null;
    }
    $normalized_expected = array_map(fn($h) => rtrim(strtolower($h), '.'), $expected_header);
    foreach ($xpath->query(".//table", $container) as $table) {
        $first_row = $table->getElementsByTagName('tr')->item(0);
        if (!$first_row) {
            continue;
        }
        $cells = array_map(fn($c) => rtrim(strtolower($c), '.'), gsi_row_cells($first_row));
        if ($cells === $normalized_expected) {
            return $table;
        }
    }
    return null;
}

function gsi_parse_boxscore_table(DOMXPath $xpath, ?DOMElement $container): array {
    $table = gsi_find_table_by_header($xpath, $container, ['No.', 'Name', 'Pos.', 'G', 'A', 'PIM']);
    $stats = [];
    if (!$table) {
        return $stats;
    }
    $rows = gsi_rows($table);
    array_shift($rows);
    foreach ($rows as $tr) {
        $cells = gsi_row_cells($tr);
        if (count($cells) < 6 || $cells[0] === '') {
            continue;
        }
        [$number, $name, $pos, $g, $a, $pim] = array_slice($cells, 0, 6);
        $stats[] = [
            'number' => $number,
            'name' => $name,
            'position' => $pos,
            'goals' => (int) ($g ?: 0),
            'assists' => (int) ($a ?: 0),
            'pim' => (int) ($pim ?: 0),
        ];
    }
    return $stats;
}

function gsi_parse_goalies(DOMXPath $xpath, ?DOMElement $summary_container): array {
    $stats_tables = $summary_container ? gsi_tables_with_class($xpath, $summary_container, 'table-stats') : [];

    $saves_table = null;
    $goalie_summary_table = null;
    foreach ($stats_tables as $t) {
        $first_row = $t->getElementsByTagName('tr')->item(0);
        if (!$first_row) {
            continue;
        }
        $header = gsi_row_cells($first_row);
        if (array_slice($header, 0, 2) === ['GKA1', 'GKA2']) {
            $saves_table = $t;
        } elseif (array_slice($header, 0, 2) === ['No. A', 'Min']) {
            $goalie_summary_table = $t;
        }
    }

    $home_goalies = [];
    $away_goalies = [];
    $home_order = [];
    $away_order = [];

    if ($goalie_summary_table) {
        $rows = gsi_rows($goalie_summary_table);
        array_shift($rows);
        foreach ($rows as $tr) {
            $cells = gsi_row_cells($tr);
            if (count($cells) < 6) {
                continue;
            }
            [$no_a, $min_a, $ga_a, $no_b, $min_b, $ga_b] = array_slice($cells, 0, 6);
            if ($no_a !== '') {
                $home_order[] = $no_a;
                $home_goalies[$no_a] = ['number' => $no_a, 'minutes' => $min_a, 'saves' => 0, 'goals_against' => (int) ($ga_a ?: 0)];
            }
            if ($no_b !== '') {
                $away_order[] = $no_b;
                $away_goalies[$no_b] = ['number' => $no_b, 'minutes' => $min_b, 'saves' => 0, 'goals_against' => (int) ($ga_b ?: 0)];
            }
        }
    }

    if ($saves_table && ($home_order || $away_order)) {
        $rows = gsi_rows($saves_table);
        array_shift($rows);
        $data_rows = array_values(array_filter($rows, fn($tr) => implode('', gsi_row_cells($tr)) !== ''));
        if ($data_rows) {
            $total_row = end($data_rows);
            $cells = gsi_row_cells($total_row);
            $cells = array_pad($cells, 6, '');
            [$gka1, $gka2, , $gkb1, $gkb2] = array_slice($cells, 0, 6);
            $slots = [[$gka1, 0, $home_order], [$gka2, 1, $home_order], [$gkb1, 0, $away_order], [$gkb2, 1, $away_order]];
            foreach ($slots as [$slot_value, $order, $goalie_order]) {
                if ($slot_value !== '' && isset($goalie_order[$order])) {
                    $num = $goalie_order[$order];
                    if (isset($home_goalies[$num])) {
                        $home_goalies[$num]['saves'] = (int) $slot_value;
                    } elseif (isset($away_goalies[$num])) {
                        $away_goalies[$num]['saves'] = (int) $slot_value;
                    }
                }
            }
        }
    }

    $finish = fn($goalies) => array_map('gsi_compute_goalie_derived', array_values($goalies));
    return [$finish($home_goalies), $finish($away_goalies)];
}
