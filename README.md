# dblib

A web-based, cut-down phpMyAdmin clone for a college database unit. See
[dblib-design.md](dblib-design.md) for the full design spec.

This is the **scaffold**: structure plus the safety-critical pieces wired up
(single-statement + DROP DATABASE guards, AES-256-GCM credential encryption,
scoped-user provisioning). Teacher/student dashboards are placeholders. No Docker
yet — runs directly under XAMPP.

## Requirements

- XAMPP with PHP 8.2+ and MariaDB
- PHP `openssl` and `pdo_mysql` extensions (both ship enabled with XAMPP)

## Setup (XAMPP, Windows)

The project lives at `C:\xampp\htdocs\dblib`, served at `http://localhost/dblib/`.

1. **Config** — defaults already target a stock XAMPP (root / no password). To
   change anything, edit [config/config.php](config/config.php).

2. **Encryption key** (stored outside the web root):
   ```
   C:\xampp\php\php.exe cli\genkey.php
   ```

3. **Create the metadata DB + a teacher login:**
   ```
   C:\xampp\php\php.exe cli\migrate.php --teacher=teacher@dblib.local --password=changeme
   ```

4. **Provision a demo student** (creates their DB + scoped MySQL user). Students
   log in with their **student ID**; email is optional and for teachers only:
   ```
   C:\xampp\php\php.exe cli\provision_student.php --student-id=S1234567 --password=changeme --name="Alice Smith"
   ```

5. Visit **http://localhost/dblib/** and sign in — teachers with their email,
   students with their student ID (one login field accepts either).

To remove a student (tears down their sandbox: drops the db + scoped user):
```
C:\xampp\php\php.exe cli\delete_student.php --student-id=S1234567
```

### Account rules (enforced)

Account creation goes through `AccountService`, which enforces: a **student**
must have a student ID (email optional); a **teacher** must have a valid email.
A DB `CHECK` constraint (`chk_users_identity`) backs the same rules at the
storage layer, so even a raw INSERT can't create a malformed account.

## Verify the guards (no DB needed)

```
C:\xampp\php\php.exe tests\guard_test.php
```

## Layout

```
index.php            Front controller
bootstrap.php        Autoloader + config + session
config/              Local config (config.php is gitignored)
sql/                 Metadata schema
cli/                 genkey · migrate · provision_student · delete_student
src/Accounts/        AccountService (validated account creation) + exception
src/
  Http/              Request, Response, Router
  Auth/              Session auth over the users table
  Accounts/          AccountService (validated account creation)
  Classroom/         ClassService (classes + rosters, scoped to teacher)
  Enrollment/        StudentEnroller (create account + provision sandbox)
  Seeds/             SeedService (CRUD) + SeedRunner (apply through pipeline)
  Crypto/            CredentialCipher (AES-256-GCM)
  Database/          Metadata (privileged) + Student (scoped) connections
  Sql/               SqlInspector, DropGuard, SingleStatementGuard, ExecutionPipeline
  Provisioning/      Provisioner (provision + deprovision sandboxes)
  Controllers/       Auth, Dashboard, Console, Teacher
  Support/           Config, View
templates/           Plain-PHP views
tests/               guard_test.php
assets/              CSS
```

## Security model (scaffold state)

- Student SQL runs **as the student's own scoped MySQL user**, never the
  provisioning account — isolation is enforced by grants, not app logic.
- Every statement passes through the one `ExecutionPipeline`: single-statement
  guard, then DROP DATABASE/SCHEMA guard (both operate on a comment-stripped,
  string-masked view so they can't be smuggled past).
- Per-student MySQL passwords are encrypted at rest; the key lives outside the
  web root and the database.
- **CSRF**: every state-changing POST is rejected unless it carries the
  per-session token — in a hidden `_token` field (HTML forms) or an
  `X-CSRF-Token` header (AJAX), verified centrally in the front controller.

## Teacher workspace

Sign in as a teacher to reach `/teacher`:

- **Classes** — create classes; each is owned by you and scoped to you.
- **Manual enrolment** — add a student by ID (name/email optional). A blank
  password field generates a temporary one, shown once.
- **Roster import** — upload a CSV with a header row containing `student_id`
  (required) and optionally `name`, `email`, `password`. Missing passwords are
  generated; existing student IDs are skipped; a per-row results table reports
  created / skipped / error and shows the generated passwords once.
- **Open DB** — the full GUI workbench onto that student's sandbox (same
  point-and-click builders + SQL console students get), for fixing things the
  student can't. Runs **as the student's own scoped user** through the same
  guarded pipeline, so `DROP DATABASE` stays blocked. Every `/db/*` and export
  request resolves its target sandbox via one ownership check — a teacher can
  only reach students in their own classes (verified at the page *and* every API
  endpoint, not just the link). A teacher's edits aren't recorded in the
  student's session history.
- **Reset pw** — generates a new temporary password for a locked-out student,
  shown once in the flash message.
- **Remove** — deletes the account and tears down its sandbox (db + scoped user).

Both enrolment paths funnel through `StudentEnroller` (create validated account
→ provision sandbox), so the UI and the CLI produce identical students.

### Seeds (starter datasets)

On a class page a teacher can save **seed** scripts (schema + sample data) and
push one to the whole class:

- The teacher picks the apply mode per push:
  - **Reset then load** (default) drops each student's existing tables (FK checks
    off), then runs the script — the class shares one identical dataset, and
    re-applying is idempotent.
  - **Append** runs the script over existing data without dropping (use
    `CREATE TABLE IF NOT EXISTS` / `INSERT` so it doesn't clash with tables
    already there) — for adding to what students have built.
- The script is split into statements and each runs through the **same
  `ExecutionPipeline`** students use, so the single-statement and `DROP DATABASE`
  guards apply — a seed can't drop a sandbox either. Failures are reported
  per-student (which statement, what error).
- A seed flagged **apply-on-enrolment** loads automatically into every newly
  enrolled student's fresh sandbox.

## Student workbench (GUI builders)

Students open `/db` for a point-and-click workbench. The GUI is **only a SQL
generator** — every action posts structured params to `/db/*`, the server turns
them into SQL (`SqlBuilder`), and that exact SQL runs through the same
`ExecutionPipeline` and is shown next to the result as evidence.

- **Tables sidebar** + **create-table** builder (column name/type/size/null/PK/
  auto-inc). The type dropdown comes from `SqlBuilder::catalog()`; `VARCHAR`/`CHAR`
  take a length and `DECIMAL` a `precision,scale`, validated server-side as
  integers within range (so the size can't inject SQL).
- **Data browser** — paginated `SELECT` with a selectable page size (10/25/50/100)
  and a **Browse / Structure** tab toggle; Structure lists each column's type,
  nullability, key, default, and extra (and shows the `SHOW COLUMNS` SQL that
  yields it).
- **Insert / edit / delete row** — per-column Value / NULL / Default; edit and
  delete locate the row by primary key (or the full row if the table has none),
  always `LIMIT 1`.
- **Drop table** button. (`DROP DATABASE` stays blocked by the pipeline guard.)

Safety: identifiers are whitelisted to `[A-Za-z0-9_]` and back-tick quoted;
values are escaped with `PDO::quote()`; the single-statement guard blocks any
`;`-smuggling. The raw SQL console (`/console`) shares the same pipeline.

### CSV export

- **Workbench** — an *Export CSV* button on any table downloads the **whole
  table** (server runs `SELECT *` with no LIMIT, so it's the full dataset, not
  just the visible page).
- **Console** — after a `SELECT`, a *Download CSV* button exports that exact
  result set. Console results are capped at 500 rows for display (with a notice);
  the query still runs in full and CSV export returns everything.

Both go through `POST /export/csv` → the same pipeline, so the export can only
read the student's own sandbox; non-`SELECT` statements and `DROP DATABASE` are
rejected, and table names are identifier-validated. Files carry a UTF-8 BOM so
Excel opens accented data correctly.

### Session history

The console shows a **History (this session)** pane — the re-run/tweak stream,
like phpMyAdmin's. Console runs *and* GUI builder actions both record into it, so
it's one uniform stream of runnable SQL; clicking an entry loads it back into the
editor. It lives in the PHP session (ephemeral, per-login, never written to the
database — not the "audit log" the design rules out) and clears on demand.

**Download .sql** exports the session stream as a runnable script (`GET
/history/export`): successful statements live and in order, failed attempts
commented out with a note. (The route has no `.sql` extension so it isn't caught
by the `.htaccess` deny rule; the filename comes from `Content-Disposition`.)

## Passwords

- **Self-service** — any logged-in user can change their own password from their
  dashboard (verifies the current password; min 6 chars). `POST /account/password`.
- **Teacher reset** — a teacher resets a locked-out student to a generated
  temporary password (shown once). Only the app-login hash changes; the MySQL
  sandbox credential is untouched.

## Not yet built (next steps)

- Docker packaging (deferred — dev is on XAMPP).

Note: ALTER, indexes, and foreign keys are intentionally SQL-console-only (the
design keeps them out of the GUI), so no builders are planned for them.
- ALTER / index / foreign-key GUI helpers (SQL-only for now, by design).
- Docker packaging (deferred — dev is on XAMPP).
- Teacher admin access into a student's sandbox.
- ALTER / index / foreign-key helpers (SQL-only for now, by design).
- Docker packaging (deferred — dev is on XAMPP).
