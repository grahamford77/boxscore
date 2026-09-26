<?php
/**
 * Gamesheet -> SportsPress box score importer.
 *
 * Upload this file AND includes/gamesheet-parser.php (keep them in the same
 * relative layout - i.e. an "includes" subfolder next to this file) to your
 * WordPress site, then call it with query parameters. See README.md for
 * full setup instructions. Quick reference:
 *
 *   https://yoursite.com/wp-content/gamesheet-import/gamesheet-import.php
 *       ?token=YOUR_TOKEN
 *       &action=import
 *       &gamesheet_url=https://stats.nihlnational.com/pdf/print/de-html/920
 *       &event_id=1234
 *       &apply=1                (omit or 0 = dry run, writes nothing)
 *       &format=html            (or json)
 *
 * Other actions (all also require &token=...): action=health,
 * action=discover-event&event_id=1234, action=discover-performance-vars.
 * Run discover-event and discover-performance-vars once against a real
 * event before your first import - see README.md "One-time setup".
 *
 * SECURITY: this script is a privileged endpoint (it can create posts and
 * write event data) gated only by GSI_ACCESS_TOKEN below. Set that to a
 * long random string before uploading, keep this over HTTPS, and consider
 * removing the file from your server when you're not actively importing
 * gamesheets.
 */

// ====================== CONFIGURE ME BEFORE UPLOADING =======================

// A long random secret required on every request as ?token=... . Generate
// one with e.g. `openssl rand -hex 32` and do not reuse a real WP password.
define('GSI_ACCESS_TOKEN', 'change-me-to-a-long-random-string');

// Taxonomy linking a sp_player post to a sp_team post. Confirm with
// action=discover-event (see README.md).
define('GSI_TEAM_TAXONOMY', 'sp_team');

// Postmeta key on the sp_event post holding the two team IDs. Confirm with
// action=discover-event.
define('GSI_EVENT_TEAMS_META', 'sp_team');

// Postmeta key on the sp_event post holding the per-player stats array.
// Confirm with action=discover-event.
define('GSI_EVENT_PLAYERS_META', 'sp_players');

// Postmeta keys used on sp_player posts for basic fields. Confirm with
// action=discover-event (it dumps a sample player's full meta).
define('GSI_PLAYER_META_KEYS', [
    'first_name'  => 'first_name',
    'last_name'   => 'last_name',
    'number'      => 'number',
    'position'    => 'position',
    'nationality' => 'nationality',
]);

// Slugs of your SportsPress Performance Variable taxonomy terms for each
// stat. Confirm with action=discover-performance-vars, which dumps every
// term with its label/abbreviation/slug so you can match G/A/PIM/SA/GA/SV.
define('GSI_STAT_SLUGS', [
    'goals'         => 'g',
    'assists'       => 'a',
    'pim'           => 'pim',
    'saves'         => 'sa',
    'goals_against' => 'ga',
    'save_pct'      => 'sv',
]);

define('GSI_DEFAULT_NATIONALITY', 'GB');

// 'decimal' writes e.g. 0.9 (SA/(SA+GA)); 'percent' writes e.g. 90.0. Match
// whatever your SV performance variable's number format is set to.
define('GSI_SV_FORMAT', 'decimal');

// ==============================================================================

require __DIR__ . '/includes/gamesheet-parser.php';

// --- Bootstrap WordPress -----------------------------------------------------

$wp_load_candidates = [
    __DIR__ . '/wp-load.php',
    __DIR__ . '/../wp-load.php',
    __DIR__ . '/../../wp-load.php',
    __DIR__ . '/../../../wp-load.php',
    __DIR__ . '/../../../../wp-load.php',
];
$wp_loaded = false;
foreach ($wp_load_candidates as $candidate) {
    if (file_exists($candidate)) {
        require $candidate;
        $wp_loaded = true;
        break;
    }
}
if (!$wp_loaded) {
    http_response_code(500);
    die('Could not locate wp-load.php near this script. Move this file (and its includes/ folder) somewhere under your WordPress install, e.g. wp-content/gamesheet-import/.');
}

// --- Auth ---------------------------------------------------------------------

$token = isset($_GET['token']) ? (string) $_GET['token'] : '';
if (GSI_ACCESS_TOKEN === 'change-me-to-a-long-random-string' || !hash_equals(GSI_ACCESS_TOKEN, $token)) {
    http_response_code(403);
    die('Forbidden. Set GSI_ACCESS_TOKEN in this file and pass the same value as ?token=...');
}

// --- WordPress/SportsPress integration ----------------------------------------

function gsi_team_term_id(int $team_post_id): int {
    $team = get_post($team_post_id);
    if (!$team) {
        return 0;
    }
    $term = get_term_by('slug', $team->post_name, GSI_TEAM_TAXONOMY);
    if ($term) {
        return $term->term_id;
    }
    $term = get_term_by('name', $team->post_title, GSI_TEAM_TAXONOMY);
    return $term ? $term->term_id : 0;
}

function gsi_normalize_name(string $s): string {
    $s = remove_accents($s);
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9 ]/', ' ', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
}

function gsi_event_teams(int $event_id): array {
    $team_ids = get_post_meta($event_id, GSI_EVENT_TEAMS_META, true);
    $teams = [];
    if (is_array($team_ids)) {
        foreach ($team_ids as $key => $team_id) {
            $team_id = (int) $team_id;
            if ($team_id && get_post_type($team_id) === 'sp_team') {
                $teams[$key] = ['team_id' => $team_id, 'title' => get_the_title($team_id)];
            }
        }
    }
    return $teams;
}

function gsi_pick_matching_wp_team(string $gamesheet_team_name, array $wp_teams): ?array {
    $target = gsi_normalize_name($gamesheet_team_name);
    $exact = [];
    $partial = [];
    foreach ($wp_teams as $team) {
        $title = gsi_normalize_name($team['title']);
        if ($title === $target) {
            $exact[] = $team;
        } elseif ($title && (str_contains($title, $target) || str_contains($target, $title))) {
            $partial[] = $team;
        }
    }
    if (count($exact) === 1) {
        return $exact[0];
    }
    if (!$exact && count($partial) === 1) {
        return $partial[0];
    }
    return null;
}

function gsi_get_team_players(int $team_id): array {
    $term_id = gsi_team_term_id($team_id);
    if (!$term_id) {
        return [];
    }
    $ids = get_posts([
        'post_type' => 'sp_player',
        'posts_per_page' => -1,
        'tax_query' => [['taxonomy' => GSI_TEAM_TAXONOMY, 'field' => 'term_id', 'terms' => $term_id]],
        'fields' => 'ids',
    ]);
    $out = [];
    foreach ($ids as $pid) {
        $out[] = [
            'player_id' => $pid,
            'title' => get_the_title($pid),
            'number' => get_post_meta($pid, GSI_PLAYER_META_KEYS['number'], true),
        ];
    }
    return $out;
}

function gsi_match_existing_player(string $number, string $last_name, string $first_name, array $wp_players): ?array {
    foreach ($wp_players as $p) {
        if ($number !== '' && trim((string) $p['number']) === trim($number)) {
            return $p;
        }
    }
    $target = gsi_normalize_name("$first_name $last_name");
    $target_rev = gsi_normalize_name("$last_name $first_name");
    foreach ($wp_players as $p) {
        $title = gsi_normalize_name($p['title']);
        if ($title === $target || $title === $target_rev) {
            return $p;
        }
    }
    return null;
}

function gsi_create_player(int $team_id, string $first_name, string $last_name, string $number, string $position, string $nationality): array {
    $title = trim("$first_name $last_name");
    $player_id = wp_insert_post(['post_type' => 'sp_player', 'post_title' => $title, 'post_status' => 'publish'], true);
    if (is_wp_error($player_id)) {
        throw new RuntimeException('Failed to create player ' . $title . ': ' . $player_id->get_error_message());
    }
    $keys = GSI_PLAYER_META_KEYS;
    update_post_meta($player_id, $keys['first_name'], $first_name);
    update_post_meta($player_id, $keys['last_name'], $last_name);
    if ($number !== '') {
        update_post_meta($player_id, $keys['number'], $number);
    }
    if ($position !== '') {
        update_post_meta($player_id, $keys['position'], $position);
    }
    update_post_meta($player_id, $keys['nationality'], $nationality);
    update_post_meta($player_id, '_gsi_gamesheet_import', 1);

    $term_id = gsi_team_term_id($team_id);
    if ($term_id) {
        wp_set_object_terms($player_id, [$term_id], GSI_TEAM_TAXONOMY, false);
    }
    return ['player_id' => $player_id, 'title' => $title, 'created' => true];
}

function gsi_write_boxscore_meta(int $event_id, array $stats): array {
    $existing = get_post_meta($event_id, GSI_EVENT_PLAYERS_META, true);
    if (!is_array($existing)) {
        $existing = [];
    }
    $merged = $existing;
    foreach ($stats as $team_id => $players) {
        if (!isset($merged[$team_id]) || !is_array($merged[$team_id])) {
            $merged[$team_id] = [];
        }
        foreach ($players as $player_id => $player_stats) {
            if (!isset($merged[$team_id][$player_id]) || !is_array($merged[$team_id][$player_id])) {
                $merged[$team_id][$player_id] = [];
            }
            foreach ($player_stats as $slug => $value) {
                $merged[$team_id][$player_id][$slug] = $value;
            }
        }
    }
    update_post_meta($event_id, GSI_EVENT_PLAYERS_META, $merged);
    return $merged;
}

function gsi_all_meta(int $post_id): array {
    $out = [];
    foreach (get_post_meta($post_id) as $key => $values) {
        $decoded = array_map('maybe_unserialize', $values);
        $out[$key] = count($decoded) === 1 ? $decoded[0] : $decoded;
    }
    return $out;
}

function gsi_all_terms(int $post_id, string $post_type): array {
    $out = [];
    foreach (get_object_taxonomies($post_type) as $tax) {
        $terms = wp_get_post_terms($post_id, $tax, ['fields' => 'all']);
        if (is_wp_error($terms) || empty($terms)) {
            continue;
        }
        $out[$tax] = array_map(fn($t) => ['term_id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug], $terms);
    }
    return $out;
}

// --- Import orchestration -------------------------------------------------

/**
 * @return array{report: array, stats: array, applied: bool}
 */
function gsi_run_import(string $gamesheet_url, int $event_id, bool $apply): array {
    $response = wp_remote_get($gamesheet_url, ['timeout' => 30, 'user-agent' => 'Mozilla/5.0 (gamesheet-import-script)']);
    if (is_wp_error($response)) {
        throw new RuntimeException('Failed to fetch gamesheet: ' . $response->get_error_message());
    }
    $code = wp_remote_retrieve_response_code($response);
    if ($code < 200 || $code >= 300) {
        throw new RuntimeException("Gamesheet URL returned HTTP $code");
    }
    $html = wp_remote_retrieve_body($response);
    $sheet = gsi_parse_gamesheet($html, $gamesheet_url);

    $wp_teams = gsi_event_teams($event_id);
    if (!$wp_teams) {
        throw new RuntimeException("Event $event_id has no teams under postmeta key '" . GSI_EVENT_TEAMS_META . "'. Run action=discover-event to find the right key.");
    }

    $report = ['sides' => [], 'sheet' => $sheet];
    $stats_payload = [];

    foreach (['home', 'away'] as $side) {
        $team_data = $sheet[$side];
        $wp_team = gsi_pick_matching_wp_team($team_data['name'], $wp_teams);
        if (!$wp_team) {
            $candidates = implode(', ', array_column($wp_teams, 'title'));
            throw new RuntimeException("Could not match gamesheet team '{$team_data['name']}' ($side) to one of the event's WordPress teams: $candidates");
        }
        $team_id = $wp_team['team_id'];
        $roster_by_number = [];
        foreach ($team_data['roster'] as $r) {
            $roster_by_number[$r['number']] = $r;
        }
        $wp_roster = gsi_get_team_players($team_id);

        $side_report = ['gamesheet_name' => $team_data['name'], 'wp_team' => $wp_team, 'players' => []];
        $team_stats = [];

        $resolved_this_run = [];
        $resolve = function (string $number) use (&$wp_roster, $roster_by_number, $team_id, $apply, &$side_report, &$resolved_this_run) {
            // A goalie appears in both the skaters and goalies gamesheet
            // tables (for their 0/0/0 G/A/PIM row); resolve them once and
            // reuse the result so they aren't reported/created twice.
            if (isset($resolved_this_run[$number])) {
                return $resolved_this_run[$number];
            }
            $roster_entry = $roster_by_number[$number] ?? null;
            $last_name = $roster_entry['last_name'] ?? '';
            $first_name = $roster_entry['first_name'] ?? '';
            $position = $roster_entry['position'] ?? '';

            $existing = gsi_match_existing_player($number, $last_name, $first_name, $wp_roster);
            if ($existing) {
                $side_report['players'][] = ['number' => $number, 'name' => trim("$first_name $last_name"), 'action' => 'matched', 'player_id' => $existing['player_id']];
                return $resolved_this_run[$number] = $existing['player_id'];
            }
            if (!$apply) {
                $side_report['players'][] = ['number' => $number, 'name' => trim("$first_name $last_name"), 'action' => 'would_create', 'position' => $position];
                return $resolved_this_run[$number] = "NEW:$number";
            }
            $created = gsi_create_player($team_id, $first_name, $last_name, $number, $position, GSI_DEFAULT_NATIONALITY);
            $wp_roster[] = ['player_id' => $created['player_id'], 'title' => $created['title'], 'number' => $number];
            $side_report['players'][] = ['number' => $number, 'name' => $created['title'], 'action' => 'created', 'player_id' => $created['player_id']];
            return $resolved_this_run[$number] = $created['player_id'];
        };

        $slugs = GSI_STAT_SLUGS;

        foreach ($team_data['skaters'] as $skater) {
            $player_id = $resolve($skater['number']);
            $s = [];
            if (isset($slugs['goals'])) $s[$slugs['goals']] = $skater['goals'];
            if (isset($slugs['assists'])) $s[$slugs['assists']] = $skater['assists'];
            if (isset($slugs['pim'])) $s[$slugs['pim']] = $skater['pim'];
            $team_stats[(string) $player_id] = $s;
        }

        foreach ($team_data['goalies'] as $goalie) {
            $player_id = $resolve($goalie['number']);
            $sv = $goalie['save_pct'];
            if ($sv !== null && GSI_SV_FORMAT === 'percent') {
                $sv = round($sv * 100, 1);
            }
            $s = $team_stats[(string) $player_id] ?? [];
            if (isset($slugs['saves'])) $s[$slugs['saves']] = $goalie['saves'];
            if (isset($slugs['goals_against'])) $s[$slugs['goals_against']] = $goalie['goals_against'];
            if (isset($slugs['save_pct']) && $sv !== null) $s[$slugs['save_pct']] = $sv;
            $team_stats[(string) $player_id] = $s;
        }

        $stats_payload[(string) $team_id] = $team_stats;
        $report['sides'][$side] = $side_report;
    }

    $applied = false;
    if ($apply) {
        gsi_write_boxscore_meta($event_id, $stats_payload);
        $applied = true;
    }

    return ['report' => $report, 'stats' => $stats_payload, 'applied' => $applied];
}

// --- Output helpers -------------------------------------------------------

function gsi_output(array $data, string $format): void {
    if ($format === 'json') {
        header('Content-Type: application/json');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return;
    }
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Gamesheet import</title>';
    echo '<style>body{font-family:system-ui,sans-serif;max-width:900px;margin:2em auto;padding:0 1em;}';
    echo 'table{border-collapse:collapse;width:100%;margin:0.5em 0 1.5em;} td,th{border:1px solid #ccc;padding:4px 8px;text-align:left;font-size:14px;}';
    echo '.warn{background:#fff3cd;border:1px solid #ffe69c;padding:0.75em;border-radius:4px;margin:0.5em 0;}';
    echo '.err{background:#f8d7da;border:1px solid #f5c2c7;padding:0.75em;border-radius:4px;}';
    echo '.banner{padding:0.75em;border-radius:4px;font-weight:bold;}';
    echo '.dry{background:#cff4fc;border:1px solid #9eeaf9;} .applied{background:#d1e7dd;border:1px solid #a3cfbb;}';
    echo 'pre{background:#f6f8fa;padding:1em;overflow:auto;}</style></head><body>';

    if (isset($data['error'])) {
        echo '<h1>Gamesheet import failed</h1><div class="err">' . htmlspecialchars($data['error']) . '</div>';
        echo '</body></html>';
        return;
    }

    $sheet = $data['report']['sheet'];
    echo '<h1>' . htmlspecialchars($sheet['home']['name']) . ' vs ' . htmlspecialchars($sheet['away']['name']) . '</h1>';
    echo '<p>' . htmlspecialchars($sheet['date'] ?? '') . ' &middot; ' . htmlspecialchars($sheet['venue'] ?? '') . ' &middot; ' . htmlspecialchars($sheet['competition'] ?? '') . '</p>';

    echo '<div class="banner ' . ($data['applied'] ? 'applied' : 'dry') . '">' . ($data['applied'] ? 'APPLIED - box score written to WordPress.' : 'DRY RUN - nothing was written. Add &amp;apply=1 to write this.') . '</div>';

    foreach ($sheet['warnings'] as $w) {
        echo '<div class="warn">' . htmlspecialchars($w) . '</div>';
    }

    foreach (['home', 'away'] as $side) {
        $side_report = $data['report']['sides'][$side];
        echo '<h2>' . htmlspecialchars(ucfirst($side)) . ': ' . htmlspecialchars($side_report['gamesheet_name']) . ' &rarr; WP team #' . (int) $side_report['wp_team']['team_id'] . ' "' . htmlspecialchars($side_report['wp_team']['title']) . '"</h2>';
        echo '<table><tr><th>#</th><th>Name</th><th>Action</th><th>Player ID</th></tr>';
        foreach ($side_report['players'] as $p) {
            echo '<tr><td>' . htmlspecialchars($p['number']) . '</td><td>' . htmlspecialchars($p['name']) . '</td><td>' . htmlspecialchars($p['action']) . '</td><td>' . htmlspecialchars((string) ($p['player_id'] ?? '')) . '</td></tr>';
        }
        echo '</table>';
    }

    echo '<h2>Stats payload written' . ($data['applied'] ? '' : ' (preview)') . '</h2>';
    echo '<pre>' . htmlspecialchars(json_encode($data['stats'], JSON_PRETTY_PRINT)) . '</pre>';
    echo '</body></html>';
}

// --- Dispatch ---------------------------------------------------------------

$action = $_GET['action'] ?? 'import';
$format = ($_GET['format'] ?? 'html') === 'json' ? 'json' : 'html';

try {
    switch ($action) {
        case 'health':
            gsi_output([
                'ok' => true,
                'sportspress_active' => post_type_exists('sp_event') && post_type_exists('sp_player') && post_type_exists('sp_team'),
                'wordpress_version' => get_bloginfo('version'),
            ], $format);
            break;

        case 'discover-event':
            $event_id = (int) ($_GET['event_id'] ?? 0);
            if (!$event_id || get_post_type($event_id) !== 'sp_event') {
                throw new RuntimeException('Pass a valid ?event_id= for an existing sp_event post.');
            }
            $teams = gsi_event_teams($event_id);
            foreach ($teams as $key => &$team) {
                $sample = get_posts([
                    'post_type' => 'sp_player', 'posts_per_page' => 1, 'fields' => 'ids',
                    'tax_query' => [['taxonomy' => GSI_TEAM_TAXONOMY, 'field' => 'term_id', 'terms' => gsi_team_term_id($team['team_id'])]],
                ]);
                $team['post_meta'] = gsi_all_meta($team['team_id']);
                $team['sample_player'] = $sample ? ['player_id' => $sample[0], 'title' => get_the_title($sample[0]), 'post_meta' => gsi_all_meta($sample[0]), 'taxonomies' => gsi_all_terms($sample[0], 'sp_player')] : null;
            }
            unset($team);
            gsi_output([
                'event_id' => $event_id,
                'title' => get_the_title($event_id),
                'post_meta' => gsi_all_meta($event_id),
                'taxonomies' => gsi_all_terms($event_id, 'sp_event'),
                'teams_meta_key_used' => GSI_EVENT_TEAMS_META,
                'teams' => $teams,
            ], 'json'); // always JSON - this is a raw setup-inspection dump
            break;

        case 'discover-performance-vars':
            $found = [];
            foreach (get_taxonomies() as $tax) {
                if (stripos($tax, 'performance') === false) {
                    continue;
                }
                $terms = get_terms(['taxonomy' => $tax, 'hide_empty' => false]);
                if (is_wp_error($terms)) {
                    continue;
                }
                $found[$tax] = array_map(fn($t) => ['term_id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug, 'term_meta' => get_term_meta($t->term_id)], $terms);
            }
            gsi_output(['taxonomies_checked' => array_keys($found), 'terms' => $found], 'json');
            break;

        case 'import':
        default:
            $gamesheet_url = $_GET['gamesheet_url'] ?? '';
            $event_id = (int) ($_GET['event_id'] ?? 0);
            $apply = ($_GET['apply'] ?? '0') === '1';
            if (!$gamesheet_url || !filter_var($gamesheet_url, FILTER_VALIDATE_URL)) {
                throw new RuntimeException('Pass a valid ?gamesheet_url=');
            }
            if (!$event_id || get_post_type($event_id) !== 'sp_event') {
                throw new RuntimeException('Pass a valid ?event_id= for an existing sp_event post.');
            }
            $result = gsi_run_import($gamesheet_url, $event_id, $apply);
            gsi_output($result, $format);
            break;
    }
} catch (Throwable $e) {
    http_response_code(400);
    gsi_output(['error' => $e->getMessage()], $format);
}
