# AI Seeder for Flarum

Generate members, discussions and replies with OpenAI, **spread day by day over a
period you choose** — for example January to May 2026.

You give it volumes (X members, X discussions, X replies), a period and a model.
It works out how many publications and replies land on each day, shows you that
calendar for free, and only then starts spending.

Built for Flarum **1.8**.

---

## What it does

- **Plans before it spends.** The whole calendar is computed locally: who signs up
  when, which discussion opens at which hour, how many replies each thread gets and
  when. No OpenAI call, no cost, nothing created. You see a day-by-day table and a
  chart, plus a cost estimate, and decide from there.
- **Realistic rhythm.** Three distribution strategies. The default, *organic*,
  combines a weekday rhythm (quieter weekends), a growth ramp over the period (a
  young forum starts slow), a lunchtime/evening posting curve, and a reply decay
  curve (most replies within 24h, a long tail after). A handful of members and
  threads carry most of the activity, as they do on a real forum.
- **Coherent threads.** Every reply in a thread is generated in one call, with the
  opening post and each replier's persona in context, so people actually answer each
  other rather than posting N independent comments.
- **Resumable.** Runs in the background in short slices. Pause, resume, cancel, or
  retry just the failed items. A crashed worker loses nothing.
- **Fully reversible.** Every created member, discussion and post is traced. One
  click removes everything a run created, and nothing else.
- **Any OpenAI-compatible endpoint.** The model list is fetched live from whatever
  base URL you point it at: OpenAI, Azure OpenAI, OpenRouter, LiteLLM, a local server.

Generated members get an `@example.invalid` address (a reserved, non-routable
domain) and are created without going through the registration flow, so **seeding
never sends an email and never triggers notifications**.

## Requirements

- Flarum 1.8 (`^1.8`)
- PHP 8.0+
- An OpenAI API key (or a compatible endpoint)
- **A working queue** — see below

## Installation

The package is not on Packagist, and `composer require` only ever looks at
Packagist — so Composer has to be pointed at the repository first. From the forum
root:

```bash
composer config repositories.flarum-ai git https://github.com/PhilippeBiaut/flarum-ai.git
```

Type `git` rather than `vcs` on purpose: `vcs` hands the repository to Composer's
GitHub driver, which queries the GitHub API and, when that fails for want of a
token, falls back to an SSH URL it generates itself — failing with
`git@github.com: Permission denied (publickey)` on any server without a deploy
key. The plain git driver clones the HTTPS URL as given, no credentials needed.

```bash
composer require pbiaut/flarum-ai-seeder:dev-main
```

```bash
php flarum migrate && php flarum cache:clear
```

Then enable *AI Seeder* in the admin panel and open its page.

Deploying through Ploi? [`deploy/ploi.sh`](deploy/ploi.sh) is the same thing,
ready to paste into the site's *Deploy script* field.

## The queue (read this one)

Generation runs as background jobs. Flarum's default queue driver is `sync`, which
executes jobs inside the HTTP request — a long run would simply time out.

Install a real queue driver (for example `flarum/redis`, or any queue extension),
then keep a worker running:

```bash
php flarum queue:work
```

No `--queue` flag is needed: the jobs stay on the default queue on purpose.

**No worker possible?** Use the command line instead — same code, no queue:

```bash
php flarum ai-seeder:run --users=20 --discussions=50 --replies=300 --from=2026-01-01 --to=2026-05-31
```

## Using it

1. **Connection** — paste your API key, hit *Test the connection*, pick a model.
   Fill in the token prices if you want a cost estimate (they are not hardcoded
   because OpenAI's rates change several times a year).
2. **Context** — say what the forum is about, in which language and with what tone.
   This is the single biggest lever on how generic the result feels. "Self-hosting,
   home servers and NAS, for hobbyists who like tinkering" beats "technology".
3. **Volumes and period** — members, discussions, replies, start and end dates,
   distribution strategy, posting hours.
4. **Compute the plan** — free and instant. Check the totals, the shape of the
   chart and the estimated cost.
5. **Generate** — watch the progress bar. Pause or cancel at any time.

Keep the **seed** if you want to reproduce the exact same calendar later; leave it
empty for a new one each time.

## Command line

```bash
# Preview only, no API call, no cost
php flarum ai-seeder:run --users=20 --discussions=50 --replies=300 \
    --from=2026-01-01 --to=2026-05-31 --dry-run

# Generate
php flarum ai-seeder:run --users=20 --discussions=50 --replies=300 \
    --from=2026-01-01 --to=2026-05-31 --theme="self-hosting and home NAS" --language=French

# Finish (or restart) an existing run
php flarum ai-seeder:run --batch=3

# Undo one
php flarum ai-seeder:run --revert=3

# What has been run so far
php flarum ai-seeder:run --list
```

## How it keeps the numbers honest

Ask for 50 discussions and 300 replies and you get exactly 50 and 300. Day counts
come from a largest-remainder split for the *uniform* strategy, and from a
multinomial draw for the others — the draw matters when there are fewer items than
days, where a plain proportional split would dump everything on the busiest days and
leave the start of the period empty.

Two invariants are enforced at planning time, not hoped for:

- no member ever posts before they joined (join dates are pulled back if needed);
- reply timestamps strictly increase within a thread and never leave the period —
  a thread that would overflow the end date is compressed into the room left.

## Costs

The run is chunked into roughly `2 x discussions` API calls: personas 25 at a time,
titles 20 at a time, one call per opening post, one call per thread of replies.
Actual token usage is recorded per run and shown in the history.

The estimate is exactly that — an estimate. Keep a spending cap on the key you use.

## Tags

If `flarum/tags` is enabled, a tag picker appears. Each tag gets a weight that
decides how often it is drawn. Without the extension, discussions are simply
created untagged.

## Search indexes

Content is written straight to the database, so extensions that maintain their own
search index via events will not see it. Run their reindex command after a large
seeding run.

## Development

```bash
# Planner tests: no forum, no network, no API key, no dependencies
php tests/run-unit.php

# See a plan in your terminal
php tests/preview.php

# Full suites, inside a real Flarum install
composer test:unit
composer test:integration

# Admin bundle
cd js && npm install && npm run build
```

`js/dist` is committed on purpose: forums installed with Composer alone have no
Node toolchain.

## A word on what this is for

This is a seeding tool: demo forums, staging data, a community launch that does not
open on an empty page. The trace of everything it generates lives in
`ai_seeder_items`, so you can always tell what came from here. If your forum has
real users, whether and how you tell them is an editorial decision — and it is yours.

## License

MIT
