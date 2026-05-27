# Folio Take-Home — Nikhil's Submission

## Setup

Requires Docker (with Compose). Everything else ships inside the container.

```
docker compose up
```

Open http://localhost:8000. First run builds the image (~30 seconds); subsequent runs start instantly.

Each `docker compose up` re-seeds `db.sqlite` from scratch so you always start with a known state. Stop with `Ctrl+C`.

To run the tests:

```
docker compose exec app php tests/test.php
```

You edit files on your host machine — the container has them mounted, so changes show up immediately on browser refresh.

---

## What I Built

I completed all three features within the ~3 hour budget.

### Feature 1 — Scheduled Publishing

Staff can now set an optional publish date and time when creating a document. If a recipient clicks a share link before that time, they see a "Not yet available" page with the scheduled date. After the time passes, the same link works automatically with no action needed from staff.

**Implementation:**
- `migrations/001_scheduled_publishing.sql` adds a nullable `publish_at TEXT` column to `documents`
- `public/admin.php` has a `datetime-local` input on the create form (blank = publish immediately)
- `public/view.php` compares `publish_at` against the current time and returns HTTP 403 with a "not yet available" screen if the document isn't ready yet
- Both blocked and successful views are written to `audit_log`
- The admin document list shows a green "Published" badge or an amber "Scheduled: <datetime>" badge per document

**Design decision:** `publish_at` is stored in the app's local timezone (America/Chicago, set in bootstrap.php) and compared using PHP's `date()` rather than SQLite's `datetime('now')` which returns UTC. This keeps the comparison self-consistent and matches what staff see when they pick a date in the form.

---

### Feature 2 — Human-Readable Document IDs

Every document now gets a readable ID automatically on creation — for example `welcome-packet-3d77` or `onboarding-guide-7249`.

**Format:** `<title-slug>-<4-char-hex-suffix>`

The title is lowercased and non-alphanumeric characters replaced with hyphens. A 4-character random hex suffix (~65,000 values) is appended to avoid collisions without needing a round-trip uniqueness check — practical for an internal tool at any reasonable scale.

**Implementation:**
- `migrations/002_readable_ids.sql` adds a `readable_id TEXT UNIQUE` column with a unique index
- `make_readable_id()` helper lives in `lib/bootstrap.php`
- `public/share.php` accepts `?rid=<readable-id>` as an alternative to `?doc=<int>` for staff lookups
- Readable IDs are shown in the admin document table

**Key design decision — readable IDs are staff-facing only.** Recipients continue to use opaque hex share tokens. If readable IDs were exposed in recipient-facing URLs, anyone could enumerate documents by guessing slugs (e.g. `view.php?rid=budget-2026-xxxx`). The two mechanisms complement each other rather than one replacing the other.

I considered replacing the integer IDs entirely (e.g. `FOLIO-BASE36`) but chose slug + suffix because it reads more naturally and is self-describing without needing a legend.

---

### Feature 3 — Search by Title

A search box on the admin document list lets staff filter documents by typing any part of the title.

**Implementation:**
- No migration needed — purely a UI and query change in `public/admin.php`
- Uses `LIKE %query%` (substring match)

**Why substring and not prefix or fuzzy:** Staff often remember a word from the middle of a document name, not necessarily the first word. Prefix match (`title LIKE 'q%'`) would miss `"Q3 Budget Report"` if you search `budget`. Fuzzy matching (Levenshtein) is expensive in SQLite without extensions and overkill for an internal tool. Substring is fast, predictable, and covers how people actually search. FTS5 full-text search would be the right next step for a larger dataset.

---

## Migration System

The README specified that schema changes should go through migration files rather than edits to `schema.sql`. There was no migration system — I built a minimal one.

**Structure:**
- `migrations/` folder with numbered SQL files (`001_*.sql`, `002_*.sql`)
- `lib/migrate.php` — a runner that creates a `migrations` tracking table, reads all files in order, and applies any that haven't been recorded yet
- Each migration runs inside a transaction so a partial failure leaves the DB unchanged
- `seed.php` calls `run_migrations()` after applying `schema.sql`, so `docker compose up` always works from a clean clone

This is intentionally lightweight. In production I'd add checksum verification, a lock mechanism to prevent concurrent runs, and use a proper migration library like Phinx.

---

## Tests

```
docker compose exec app php tests/test.php
```

12 tests covering:
- Existing share link behaviour (regression)
- Feature 1: `publish_at` null, past, and future cases
- Feature 2: slug format, uniqueness, DB lookup by readable ID
- Feature 3: exact match, substring match, empty results, case insensitivity

---

## Things I Noticed in the Existing Code

A few things worth flagging that were already there before my changes:

1. **No CSRF protection** on any POST form. Every state-mutating form should include a token to prevent cross-site request forgery.
2. **`current_staff()` always returns row #1.** Fine for a demo but this is hardcoded single-user access — not real authentication.
3. **Timezone mismatch:** `created_at` uses SQLite's `datetime('now')` which is UTC, but `bootstrap.php` sets the app timezone to `America/Chicago`. Datetimes display without conversion. This is a silent inconsistency I worked around in the `publish_at` implementation.
4. **No input length limits** on title or body — a very large body could grow the SQLite file unboundedly.

---

## What I'd Do With More Time

- **FTS5 full-text search** — SQLite ships with FTS5; a virtual table over `documents(title, body)` would give ranked, tokenised search far beyond LIKE
- **Timezone-aware display** — store datetimes in UTC, show them in the browser's local timezone using `Intl.DateTimeFormat`
- **CSRF tokens** — add a `csrf_token()` helper and validate on every POST
- **Pagination** — the document list has no pagination; with hundreds of documents it would become unusable
- **Migration checksums** — detect if a previously-applied migration file has been edited on disk

---

## AI Usage Declaration

I used Kimi AI to help with syntax lookups and boilerplate scaffolding during development. All design decisions — readable ID format, search match style, timezone handling, keeping readable IDs staff-only — were made by me after reading and understanding the existing codebase. Every line of code was reviewed and verified before committing.
