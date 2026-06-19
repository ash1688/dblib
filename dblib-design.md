# dblib — Design Spec

A web-based, cut-down phpMyAdmin clone for a college **database unit**. Students learn SQL and complete assignments through a browser — removing the need for local MariaDB + HeidiSQL installs — with evidence-gathering built in. Fourth tool in the department internal-tools suite, alongside **StudentHomepage** (PHP), **MockSocial** (TS), and **UnitBoard** (PHP).

**Status:** design settled (2026-06-19), not yet built.
**Philosophy:** ease of access & speed over forcing manual SQL. Goals are (A) pedagogical guardrails + (C) sandboxing/safety.

---

## Architecture

- **Topology:** one central MariaDB; **one database + one scoped MySQL user per student**. Isolation is enforced by MySQL grants (each user locked to its own schema), not by app logic.
- **Stack:** **PHP + MariaDB**. The app connects *as each student's own scoped MySQL user* on each request — the per-request connect/run/disconnect lifecycle fits the model with no connection-pool gymnastics, deploys trivially, and matches the existing PHP tools.
- **Credentials:** per-student MySQL passwords are generated at provisioning, **encrypted at rest**, with the key kept out of the web root and out of the database.
- **Metadata:** the app has its own metadata schema for accounts, classes, roster, seed scripts, and stored credentials — separate from the student sandboxes.

## Accounts & structure

- **App-owned accounts.** Build the user table so it *could* later defer to a shared suite-wide auth service without a rewrite.
- **Two roles:**
  - **Student** — sandboxed to their own database, guardrailed.
  - **Teacher** — full admin over any student DB (can fix/delete when a student can't).
- **Provisioning:** teacher **roster import (CSV)** *or* **manual user creation**. Creating a user auto-provisions their database + scoped MySQL user.
- **Class entity:** one student → one class; one class → one owning teacher (no co-teaching, no multi-class). Rosters, seeds, and teacher views all scope to a class.

## The core loop

- **GUI and hand-typed SQL both feed ONE execution pipeline.** The GUI is just a SQL *generator*.
- **Generated SQL is always shown and logged** — every action becomes runnable SQL in one uniform stream.
- **GUI builders (v1):** create table; insert / edit / delete row; drop-table button; paginated data browser.
- **SQL-only:** ALTER, indexes, foreign keys.
- **After each run:** the UI shows the result grid **+** the exact SQL that produced it, side by side — one screenshot captures both as evidence.
- **Session history pane** (per-session, like phpMyAdmin's): re-run / tweak past queries.
- **Export:** "export SQL" button + export query results to **CSV**.
- **No** assignment/grading engine. **No** persistent server-side audit log.

## Limitations (deliberately minimal)

- **MySQL grants do the heavy lifting** — no cross-DB access, no `CREATE DATABASE`/`CREATE USER`/`GRANT`, no file operations. All blocked for free by the scoped user.
- **One app-level guard:** reject `DROP DATABASE` / `DROP SCHEMA` (case-insensitive) so a student can't nuke their own sandbox and lock themselves out.
- **One statement per run** (no `;`-batched multi-statement). Keeps the drop-guard un-bypassable and makes the "SQL + result" evidence exact.
- Students **can** freely drop and rebuild their own *tables* — rebuilding is part of learning.

> Note: in MariaDB there is no privilege that allows dropping tables but not the database, which is why the single `DROP DATABASE` guard is enforced at the app layer rather than via grants. Verify exact grant behaviour at build time.

## Seeds (starter datasets)

- A teacher pushes a SQL script (schema + sample data) to a **class**, executed through the same pipeline students use.
- **Default = reset-then-load** (drop existing tables, load fresh) so a shared dataset is identical across the class.
- Optionally applied to new students on creation.

## Import / export

- **Students:** CSV export of query results only.
- **Bulk data-in:** teacher seeds.
- **Not in v1:** student SQL-file import (conflicts with the single-statement rule) and CSV-into-table import.

## Deployment

- **Single self-hosted Docker image + its OWN dedicated MariaDB container** on the department server (Dokploy-manageable, like MockSocial).
- A dedicated MariaDB instance — *not* the shared departmental DB — so dblib's constant `CREATE`/`DROP DATABASE` provisioning churn cannot reach StudentHomepage/UnitBoard data. Isolation by instance, not just by grant.
- The provisioning account needs `CREATE DATABASE` + `CREATE USER`/`GRANT`; it's dedicated to dblib and locked down.
- **Scale:** department-sized — ~30 concurrent users, low-hundreds of total accounts. Single-tenant (no SaaS multi-org).

## Deferred (decide at build time)

- Browse-UX details: pagination size, structure tab, result row caps.
- Datatype list offered in the create-table builder.
- Student password-reset flow.
- Whether seeds can also **append** (default is reset).

## Load-bearing bets to sanity-check before coding

1. **Single-statement execution** carries a lot (drop-guard integrity + clean evidence). Confirm no part of the syllabus requires students to run multi-statement scripts.
2. **Dedicated MariaDB instance** (revised from an earlier "shared departmental DB" assumption). Confirm the server has headroom for another MariaDB container.
