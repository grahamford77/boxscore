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
   should show `"sportspress_active": true`. That's it — the field
   mappings near the top of `gamesheet-import.php` (`GSI_PLAYER_META_KEYS`,
   `GSI_POSITION_TERM_SLUGS`, `GSI_STAT_SLUGS`, etc.) are already confirmed
   working against a live SportsPress site, so there's nothing left to
   verify before running a real import.

   If you ever set this up on a *different* SportsPress site and something
   doesn't match (players not linking to the right team, positions not
   applying, stats not showing), the script has built-in diagnostics for
   that: `action=discover-event&event_id=<id>` dumps an event's raw
   postmeta/taxonomies plus its teams' sample players, and
   `action=discover-performance-vars` lists Performance Variable taxonomy
   terms if your setup uses one. Compare what they show against the
   `GSI_*` constants at the top of the file and adjust as needed.

## Running an import

Identify the event with **one of** `&event_id=1234` (the numeric post ID),
`&event_slug=romford-raiders-vs-peterborough-phantoms` (its URL slug), or
`&event_url=` set to the full public event page link, pasted as-is — e.g.
`&event_url=https://yoursite.com/event/romford-raiders-vs-peterborough-phantoms/`.
The URL form is the safest to use since it's exactly what you'd copy from
your browser's address bar while looking at the event, with no risk of
copying the wrong numeric ID from a different event. Every report (HTML or
JSON) also states the event ID/title it actually acted on right at the
top — always check that matches what you meant before trusting the rest
of it.

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
rather than creating duplicates. `apply=1` writes to two separate postmeta
keys: `sp_players` (the stat values, keyed by team then player) and
`sp_player` — a player's stats alone aren't enough for them to appear
anywhere (public page or wp-admin) without their ID also being in this
second, singular-named list, which the display actually reads to decide
who to show. It isn't a flat bag either: it's positionally split into one
section per team, `[0, <team A player ids>, 0, <team B player ids>]`,
using the literal string `"0"` as a section marker (a real WordPress post
ID is never 0, so every `"0"` here is unambiguously a marker, never a
player). This script rebuilds the whole list from `sp_players`'
team-keyed data on every `apply=1`, so it stays correct — including
self-healing if the list was ever left in a bad state — rather than just
appending to whatever was there.

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
- **Player creation fields** (number, name, position): read from the full
  roster table inside `#teams`, which has both teams' Pos., No., and Name
  ("SURNAME Firstname", with a trailing "C"/"A" for captain/alternate
  captain stripped before splitting) — the name becomes the new player's
  post title, number becomes `sp_number`, and position is resolved to a
  real `sp_position` taxonomy term via `GSI_POSITION_TERM_SLUGS`. Date of
  birth is parsed by the script but not currently written anywhere (no
  confirmed postmeta key for it yet). Nationality isn't on the gamesheet
  at all and is set to `GSI_DEFAULT_NATIONALITY` in the PHP file (`gbr` by
  default) — review/correct these manually afterwards.

If a different league's gamesheet has a different layout, the parser
functions in `includes/gamesheet-parser.php` are all header/id/class based
rather than fixed positions, so most layout variations only need small
tweaks there.

## Careful with the WordPress admin box score editor after importing

Confirmed the hard way: opening an event's edit screen in wp-admin and
clicking **Update** — even just to add or tweak one player by hand —
appeared to **replace that team's entire `sp_players` entry with only
whatever the admin form currently held**, discarding every other
player's stats that this script (or a previous admin session) had
written, rather than merging with what was already there. If you need to
hand-edit a box score after running this script, treat it as replacing
the whole thing for that team, not adding to it — re-run this script
afterwards (it's a merge, so it's safe) if you want the imported stats
back.

## Security notes

This script is a privileged, unauthenticated-by-default endpoint (it can
create posts and rewrite event data) gated only by the token check. Some
care is worth taking:

- Always use a long random token, and only call the script over HTTPS.
- Consider removing the file from your server (or renaming it to something
  unguessable) when you're not actively importing gamesheets.
- The `discover-*` actions dump raw postmeta — no need to leave them easy
  to hit either.

## Tests

The parser (`includes/gamesheet-parser.php`) has no WordPress dependency
and can be tested standalone:
```
php tests/test_parser.php
```
It runs a set of assertions against `tests/fixtures/gamesheet_920.html` (a
real sample gamesheet) verifying rosters, G/A/PIM, and SA/GA/SV are all
read correctly. Testing the WordPress-writing half (`gamesheet-import.php`
itself) needs an actual WordPress/SportsPress install; run it with
`apply=0` (the default) against a real event first to check its report
before trusting `apply=1`.
