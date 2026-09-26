# SportsPress gamesheet box score importer

A single PHP script you upload to your WordPress site and call with a URL
(query parameters), rather than a tool you run from your own machine. It
parses an ice hockey gamesheet (the "de-html" print view, e.g.
`https://stats.nihlnational.com/pdf/print/de-html/<id>`) and imports it into
a SportsPress event's box score: creates any players missing from the site
(assigned to the correct team, nationality defaulted to GB), and writes
**G, A, PIM** (skaters) and **SA, GA, SV** (goalies).

**Known gap:** this gamesheet layout only records Shots On Goal as a team
total per period, not per skater, so there's no way to attribute it to
individual players — **SOG is not written for anyone**. Everything else is
populated. The script's report always shows this warning so it isn't easy to
miss. The star-of-the-game field isn't on the gamesheet either (per the
original request), so that stays a manual edit, as does double-checking
each newly-created player's nationality.

## Files

```
wordpress-plugin/
  gamesheet-import.php           <- the script you upload and call
  includes/
    gamesheet-parser.php         <- gamesheet HTML parser it depends on
tests/
  fixtures/gamesheet_920.html    <- a real sample gamesheet
  test_parser.php                <- parser tests against that fixture
```

Both files under `wordpress-plugin/` need to be uploaded together, keeping
`includes/gamesheet-parser.php` in an `includes` subfolder next to
`gamesheet-import.php` (that's the only path relationship that matters —
where you put the pair on your server is up to you).

## One-time setup

1. **Pick somewhere on your server to put it**, e.g.
   `wp-content/gamesheet-import/`. Upload `gamesheet-import.php` and
   `includes/gamesheet-parser.php` there (via FTP/SFTP, your host's file
   manager, or the WordPress admin's plugin/theme file editor's upload if
   it allows arbitrary folders).

   This does **not** need to be a real WordPress plugin folder — it's a
   plain script that bootstraps WordPress itself (it looks for `wp-load.php`
   a few directories up from wherever you put it). Anywhere under your
   WordPress install works.

2. **Set a secret token.** Open `gamesheet-import.php` and change:
   ```php
   define('GSI_ACCESS_TOKEN', 'change-me-to-a-long-random-string');
   ```
   to a long random string (e.g. generate one with `openssl rand -hex 32`,
   or any password generator). Every request must include this as
   `?token=...` — without it (or with the placeholder value) the script
   refuses to run at all. Treat this like a password: don't reuse a real
   one, keep it out of anywhere public, and only call the script over
   HTTPS.

3. **Visit the health check** to confirm it's reachable and SportsPress is
   detected:
   ```
   https://yoursite.com/wp-content/gamesheet-import/gamesheet-import.php?token=YOUR_TOKEN&action=health
   ```
   should show `"sportspress_active": true`.

4. **Confirm the field mappings against your real site.** The defaults
   near the top of `gamesheet-import.php` are educated guesses at
   SportsPress's data model, not guaranteed for your specific
   version/setup. Using an existing real event ID that already has its two
   teams assigned in SportsPress:
   ```
   https://yoursite.com/wp-content/gamesheet-import/gamesheet-import.php?token=YOUR_TOKEN&action=discover-event&event_id=1234
   ```
   This dumps the event's raw postmeta/taxonomies, both teams, and one
   sample player per team with *its* full meta/taxonomies (as JSON — it's a
   setup tool, not the report page). Check:
   - `teams` is non-empty and lists the right two teams. If empty, your
     event→team meta key isn't `sp_team` — look through the dump's
     `post_meta` for the array of team post IDs, and update
     `GSI_EVENT_TEAMS_META` in the PHP file to match.
   - `sample_player.post_meta` shows how number/position/first name/last
     name/nationality are actually stored on a real player. Update
     `GSI_PLAYER_META_KEYS` in the PHP file if the keys differ from the
     defaults.
   - `sample_player.taxonomies` shows which taxonomy links the player to
     the team. Update `GSI_TEAM_TAXONOMY` if it isn't `sp_team`.

   Then check your Performance Variables (the G/A/PIM/SA/GA/SV definitions
   in SportsPress):
   ```
   https://yoursite.com/wp-content/gamesheet-import/gamesheet-import.php?token=YOUR_TOKEN&action=discover-performance-vars
   ```
   Find the terms matching Goals, Assists, PIM, Saves, Goals Against, Save
   %, and set their `slug` values in `GSI_STAT_SLUGS` in the PHP file. Also
   check your Save % variable's number format (decimal like `0.900` vs.
   percentage like `90.0`) and set `GSI_SV_FORMAT` to match.

## Running an import

Visit the URL (in a browser, or `curl`) with `apply` omitted or `0` first —
this parses everything and shows exactly what would be created/written
without touching WordPress:

```
https://yoursite.com/wp-content/gamesheet-import/gamesheet-import.php
  ?token=YOUR_TOKEN
  &action=import
  &gamesheet_url=https://stats.nihlnational.com/pdf/print/de-html/920
  &event_id=1234
```

You'll get an HTML report: game info, any warnings, and for each team a
table showing every gamesheet player matched to an existing WordPress
player (by shirt number) or flagged to be created, plus a preview of the
stats that would be written. Check that looks right, then add `&apply=1` to
actually write it:

```
https://yoursite.com/wp-content/gamesheet-import/gamesheet-import.php
  ?token=YOUR_TOKEN
  &action=import
  &gamesheet_url=https://stats.nihlnational.com/pdf/print/de-html/920
  &event_id=1234
  &apply=1
```

It's safe to re-run: matching is by shirt number within each team, so a
second run against the same event updates the same players' stats (merge
mode — it won't touch star ratings or anything else already on the event)
rather than creating duplicates.

Add `&format=json` to either URL to get the same result as raw JSON
instead of the HTML report (useful for scripting/automation).

## How stats are read from the gamesheet

- **G / A / PIM** (skaters): read directly from the per-player box score
  table inside `#homestats`/`#awaystats` (No., Name, Pos., G, A, PIM).
- **SA / GA**: read from the `#summary` section's Saves-by-period table
  (total row, per goalie) and Goalie Summary table (goals against, and
  which shirt number is which goalie slot).
- **SV**: computed here as `SA / (SA + GA)`, not read from the page.
- **SOG**: not available per-player on this gamesheet layout (see "Known
  gap" above) — never written.
- **Player creation fields** (number, name, position, date of birth): read
  from the full roster table inside `#teams`, which has both teams' Pos.,
  No., Name ("SURNAME Firstname"), and Date of birth. Nationality isn't on
  the gamesheet at all and is set to `GSI_DEFAULT_NATIONALITY` in the PHP
  file (`GB` by default) — review/correct these manually afterwards.

If a different league's gamesheet has a different layout, the parser
functions in `includes/gamesheet-parser.php` are all header/id/class based
rather than fixed positions, so most layout variations only need small
tweaks there.

## Security notes

This script is a privileged, unauthenticated-by-default endpoint (it can
create posts and rewrite event data) gated only by the token check. Some
care is worth taking:

- Always use a long random token, and only call the script over HTTPS.
- Consider removing the file from your server (or renaming it to something
  unguessable) when you're not actively importing gamesheets.
- The `discover-*` actions dump raw postmeta and are meant for one-time
  setup — no need to leave them easy to hit either.

## Tests

The parser (`includes/gamesheet-parser.php`) has no WordPress dependency
and can be tested standalone:
```
php tests/test_parser.php
```
It runs a set of assertions against `tests/fixtures/gamesheet_920.html` (a
real sample gamesheet) verifying rosters, G/A/PIM, and SA/GA/SV are all
read correctly — that's how the values documented above were confirmed
before this was wired into WordPress. Testing the WordPress-writing half
(`gamesheet-import.php` itself) needs an actual WordPress/SportsPress
install; run it with `apply=0` (the default) against a real event first to
check its report before trusting `apply=1`.
