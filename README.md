# Sheets Sync

Syncs data from Google Sheets into MySQL. You set up the sync in an admin UI: which spreadsheets and tabs to read, which table each tab goes to, and how each sheet column maps to a table column and type. A daily job (or the **Sync now** button) reads the sheets and writes the rows. The job creates the target tables and adds new columns itself.

Built with **Laravel 12**, **Filament 5**, **MySQL 8 / MariaDB** and the **Google Sheets API v4** (service account, read-only).

---

## Contents

1. [Features](#features)
2. [Quick start (server)](#quick-start-server)
3. [Local development](#local-development)
4. [Google API credentials](#google-api-credentials)
5. [Using the app](#using-the-app)
6. [How the sync works](#how-the-sync-works)
7. [Assumptions and decisions](#assumptions-and-decisions)
8. [Known limitations](#known-limitations)
9. [Tests](#tests)
10. [Project structure](#project-structure)

---

## Features

- **Config-driven.** Any number of spreadsheets → tabs → target tables. Nothing about the sheets is hard-coded.
- **Columns are matched by header name, not position.** Files with reordered, renamed or extra columns map to the same table. Matching ignores case and extra whitespace.
- **One tab → many tables**, and **many files → one table**.
- **The schema follows the config.** Missing tables are created. A column added to a mapping is added to the table with `ALTER TABLE` on the next run. Existing columns are never dropped or changed.
- **Three sync modes:** `upsert` (by a unique key), `replace` (the mapping's rows are rewritten) and `snapshot` (one copy per day, history kept). Running any mode twice gives the same data.
- **Typed columns:** string, text, integer, decimal, date, datetime, boolean. Values are converted from raw sheet values, including Google date serial numbers.
- **Failures stay isolated.** A broken tab or a bad value never stops the rest of the run. Every run and every mapping is logged with rows read and written, warnings and errors.
- **Admin UI:** manage the config, test the connection to a spreadsheet, load the header columns from the sheet, sync now, view run history and browse the synced data.
- **Runs three ways:** daily scheduler, `php artisan sheets:sync`, or a queued job from the UI. A lock keeps two runs from overlapping.
- **Demo / fake driver.** The whole app runs and is tested against JSON fixtures, with no Google account.

---

## Quick start (server)

`setup.sh` installs and configures everything on a fresh **Ubuntu 22.04 / 24.04** server: PHP 8.3, MySQL, Nginx, Composer, the database and user, `.env`, migrations, the cron scheduler and a systemd queue worker.

```bash
git clone <repo-url> /var/www/sheets-sync
cd /var/www/sheets-sync

# Demo: fake driver with two sample spreadsheets, no Google account needed
sudo ./setup.sh --demo

# Production: real Google Sheets
sudo ./setup.sh --domain=sheets.example.com \
                --credentials=/path/to/service-account.json \
                --admin-email=me@example.com
```

At the end the script prints the URL (`http://<server>/admin`), the login e-mail and a generated password (also stored in `.env` as `ADMIN_PASSWORD`).

| Option | Meaning |
|---|---|
| `--demo` | Use the fake driver and seed demo spreadsheets (`database/fixtures/sheets`) |
| `--domain=…` | Nginx `server_name` (default: the server IP) |
| `--credentials=…` | Google service-account JSON key; copied to `storage/app/google/` |
| `--admin-email=…` / `--admin-password=…` | Admin login (default password: random) |

You can run the script again safely. It keeps the existing DB password and `APP_KEY`, and applies new options. For example, run it with `--credentials=…` later to switch from demo to Google, or with `--admin-password=…` to reset the login.

> HTTPS is not set up by the script. On a public server, add a certificate afterwards, e.g. `sudo apt install certbot python3-certbot-nginx && sudo certbot --nginx`.

---

## Local development

Requirements: PHP 8.2+ (with `pdo_mysql`, `intl`, `zip`), Composer, and MySQL 8 or MariaDB 10.6+.

```bash
composer install
cp .env.example .env
php artisan key:generate

# .env: set DB_* for your database, and SHEETS_DRIVER=fake to try it without Google
php artisan migrate --seed       # creates the admin user (ADMIN_EMAIL / ADMIN_PASSWORD) + demo config when fake

php artisan serve                # http://127.0.0.1:8000/admin
php artisan queue:work           # needed for the "Sync now" buttons
php artisan sheets:sync          # or run a sync from the CLI
```

`php artisan schedule:work` runs the daily scheduler locally.

### Environment variables

| Variable | Default | |
|---|---|---|
| `SHEETS_DRIVER` | `google` | `google` (real API) or `fake` (JSON fixtures) |
| `GOOGLE_APPLICATION_CREDENTIALS` | `storage/app/google/service-account.json` | Service-account key, absolute or relative to the project root |
| `SHEETS_SYNC_AT` | `02:00` | Daily run time (server timezone) |
| `SHEETS_CHUNK_SIZE` | `500` | Rows per INSERT / UPSERT statement |
| `SHEETS_FAKE_PATH` | `database/fixtures/sheets` | Fixture folder for the fake driver |
| `ADMIN_EMAIL` / `ADMIN_PASSWORD` | `admin@example.com` / `password` | Admin user written by `db:seed` |
| `QUEUE_CONNECTION` | `database` | Queue used by the "Sync now" job |

### CLI

```bash
php artisan sheets:sync                       # all active spreadsheets
php artisan sheets:sync --spreadsheet=1 --spreadsheet=3   # only these (database IDs)
```

The command prints one line per mapping (status, rows read/written, warnings). It exits with code `1` only if the whole run failed.

---

## Google API credentials

The app reads sheets with a **service account**, a Google "robot" user. No one has to log in, and the token never expires.

1. Open the [Google Cloud Console](https://console.cloud.google.com/) and create a project (or pick one).
2. **APIs & Services → Library**: search for **Google Sheets API** and click **Enable**.
3. **IAM & Admin → Service Accounts → Create service account**. Any name works, e.g. `sheets-sync`. It needs no roles, so skip the permissions steps.
4. Open the new service account → **Keys → Add key → Create new key → JSON**. A `.json` file downloads.
5. Put the key on the server:
   - with the script: `sudo ./setup.sh --credentials=/path/to/key.json`, or
   - by hand: copy it to `storage/app/google/service-account.json` (or set `GOOGLE_APPLICATION_CREDENTIALS`) and make sure `www-data` can read it.
6. **Share each spreadsheet** with the service account's e-mail (`client_email` in the JSON, e.g. `sheets-sync@my-project.iam.gserviceaccount.com`). **Viewer** access is enough.
7. In the UI, add the spreadsheet and click **Test connection**. It lists the tabs, or tells you what is wrong. A 403 error means the sheet is not shared with that e-mail. A 404 error means the ID is wrong.

The key is git-ignored (`/storage/app/google`). Never commit it. The app only asks for the `spreadsheets.readonly` scope, so it cannot change any sheet.

---

## Using the app

Log in at `/admin`.

1. **Spreadsheets → New.** Paste the spreadsheet URL or its ID and give it a name. Once it is saved, click **Test connection** (on the edit page or in the row menu).
2. **Add tabs** on the spreadsheet page: the tab name exactly as in Google Sheets, and the **header row** (default `1`; use e.g. `2` when row 1 holds a title).
3. **Map to table.** Pick the tab and the target table: a new name, or an existing table created by the sync. Then pick the sync mode.
   - **Load columns from sheet** reads the header row and fills the column list with suggested table column names (`Product Name` → `product_name`). The type starts as *string*; change it where needed.
   - For each column, set: source header → target column, type, *unique key* (upsert), and an optional date format (e.g. `d/m/Y`).
4. **Sync now** (on a spreadsheet, or "Sync all"). The job runs in the queue. **Sync runs** shows the result per mapping, with warnings and errors.
5. **Synced data** shows the rows that landed in each target table.

To change things later, edit the mapping. Added columns appear in the table on the next run. Turn a spreadsheet, tab or mapping off with its **Active** toggle to leave it out of the daily sync without deleting it.

### Demo data (`--demo` / `SHEETS_DRIVER=fake`)

Two fixture spreadsheets in `database/fixtures/sheets` show the main cases:

- *Store A* `Sales` → `sales` (upsert) **and** `daily_sales_snapshot` (snapshot): one tab feeding two tables.
- *Store B* `Orders` → also `sales`: different column order and header names, header on row 2, and an extra `discount` column that is added to `sales` with `ALTER TABLE`.
- *Store A* `Stock` → `inventory` (replace).

---

## How the sync works

```
for each active spreadsheet
    one batchGet for all its active tabs (raw values: numbers, booleans, date serials)
    for each active mapping of each tab                 ← failure here is recorded, others continue
        find the header row, match configured columns by normalised header name
        ensure schema: CREATE TABLE / ADD COLUMN        ← DDL runs outside the transaction
        cast each cell to the column type                ← bad values → warning, not failure
        write rows in one transaction, in chunks         ← upsert / replace / snapshot
record run + one item per mapping (rows read/written, warnings, error)
```

### Target tables

Every table the sync creates has these system columns on top of the configured ones:

| Column | Purpose |
|---|---|
| `id` | Auto-increment primary key |
| `source_mapping_id` | Which mapping wrote the row; replace/snapshot only touch their own rows |
| `row_key` | `sha1(mapping id + unique-key values)`, with a unique index; used by upsert |
| `sync_date` | Date of the run (the snapshot copy) |
| `synced_at` | Time the row was last written |

All configured columns are nullable. A table that exists but has no `source_mapping_id` is **not** managed by the sync. The sync refuses to write into it, so a mapping can never overwrite an unrelated table. Application tables (`users`, `sync_runs`, …) are blocked by name.

### Sync modes

| Mode | Behaviour | Good for |
|---|---|---|
| `upsert` | Insert new rows; update rows whose unique key already exists. Rows removed from the sheet stay in the table. Rows with an empty key are skipped. If the key appears twice, the last row wins. | Orders, products: anything with an ID |
| `replace` | Delete this mapping's rows, insert the current sheet. Other mappings writing to the same table are left alone. | Current state with no stable key (stock levels) |
| `snapshot` | Like replace, but only for today's `sync_date`. Earlier days are kept. | Daily history / trends |

Every mode runs in a transaction, so a failed write leaves the table as it was.

### Value conversion

The API is asked for `UNFORMATTED_VALUE` + `SERIAL_NUMBER`, so most cells arrive as real numbers, booleans and date serials, not locale-formatted text. Text cells are parsed as well:

| Type | Accepts |
|---|---|
| integer / decimal | numbers; text such as `1,234.50`, `1.234,50`, `€ 12`, `15%` (→ `0.15`) |
| date / datetime | Sheets date serials; text in the column's **date format** (e.g. `d/m/Y`), or any format `Carbon::parse` understands |
| boolean | `TRUE/FALSE`, `1/0`, `yes/no`, `y/n`, `x`, `po/jo` |
| string / text | anything; cut at 255 / 65,535 characters |

When a value cannot be converted, it is stored as `NULL` and shown as a warning with the sheet row number. When a value is **adjusted** to fit (text truncated, a fraction in an integer column rounded), the adjusted value is stored and reported separately. Empty rows are skipped.

### Errors and warnings

| Situation | Result |
|---|---|
| Spreadsheet not shared / wrong ID / API down | All mappings of that spreadsheet *failed*, with a clear message; other spreadsheets continue |
| Tab not found, header row empty | That mapping *failed* |
| Configured column missing from the header | Warning; the column is left out (upsert keeps the stored value) |
| Unique-key column missing, or upsert without a key | That mapping *failed* (it would otherwise write wrong data) |
| Duplicate header in the sheet | Warning; the first one is used |
| Target table exists but is not managed by the sync | That mapping *failed* |

The run's status is `success`, `partial` (some mappings failed) or `failed` (all failed, or a crash). Google API calls retry with exponential backoff on 429 / 5xx.

---

## Assumptions and decisions

- **Service account, not OAuth.** The sync runs unattended on a server. A service account needs no browser login and no refresh token, and the user only has to share the sheet. Access is read-only.
- **Header names are the contract, not positions.** The task said columns may differ between files. Matching by normalised header name (case, extra spaces, BOM and non-breaking spaces ignored) is what makes "same data, different layout" work.
- **The schema only grows.** The sync creates tables and adds columns, but never drops, renames or changes the type of a column, because that loses data. Renaming a target column in a mapping therefore adds a new column, and the old one stays (and stops being filled). Type changes are done by hand.
- **One unique index (`row_key`) for every upsert.** The key columns differ per mapping, and several mappings can share a table. Keying on a hash of mapping ID + key values gives one fixed unique index that works for all of them without per-mapping DDL. As a result, two mappings never update each other's rows, even with the same key values.
- **System columns on every target table** (`source_mapping_id`, `row_key`, `sync_date`, `synced_at`). They make replace/snapshot safe on shared tables, make data traceable to its source, and mark a table as "managed".
- **Safe identifiers.** Table and column names come from user input and go into DDL, where bindings cannot be used. They are whitelisted: `[a-z_][a-z0-9_]{0,63}`.
- **Bad data is a warning, broken config is an error.** One bad cell should not lose a day's sync. A missing key column would write wrong data, so it fails that mapping.
- **Number format:** when text has both `,` and `.`, the last one is the decimal separator. A lone `,` is a thousands separator when followed by groups of exactly 3 digits (`1,234`), and a decimal separator otherwise (`3,5`). A lone `.` is always decimal (`1.234` = 1.234). Most numeric cells come from the API as real numbers, so this only applies to numbers typed as text.
- **Dates:** set a **date format** on a column whenever the sheet uses text dates. Without one, `Carbon::parse` is used, and it reads ambiguous dates such as `03/04/2026` the US way (month/day).
- **Changing a mapping's mode:** to `upsert`, the rows the mapping wrote without a key (replace/snapshot rows) are removed on the next run, so they do not stay as duplicates. To `replace` or `snapshot`, the old rows are rewritten as usual. Note that switching a snapshot mapping to upsert drops its history.
- **Re-seeding resets the admin password** to `ADMIN_PASSWORD`, so `setup.sh --admin-password=…` can always recover the login.
- **Queue for the UI, CLI for the schedule.** "Sync now" goes to the queue so the request returns at once. The scheduler calls the command directly. A cache lock (and `withoutOverlapping`) makes sure only one run happens at a time.
- **Filament** for the admin UI: authenticated, validated CRUD and tables with little code, so the time went into the sync itself.

---

## Known limitations

- **Deletes are not mirrored in upsert mode.** A row removed from the sheet stays in the table. Use `replace` when the table must match the sheet exactly.
- **Very large tabs** (hundreds of thousands of rows) are read into memory in one API call. That is fine for normal sheets; paging by range would be the next step.
- **No column type migrations.** Changing the type of an existing column in a mapping does not alter the table (see above).
- **Single admin role.** Every user can see and change everything.
- **One sync at a time,** across all spreadsheets.

---

## Tests

```bash
php artisan test
```

40 tests on SQLite in memory with the fake driver. No database server or Google account is needed. They cover header matching, value conversion, all three modes and idempotency, schema creation and `ALTER TABLE`, column order differences between files, failure isolation, safety checks on unmanaged tables, the artisan command and the admin panel pages.

`setup.sh` has been tested in a clean `ubuntu:24.04` Docker container (which has no systemd, so the script uses its `service` fallback): two runs in a row (the second one with a new `--admin-password`), then a check that the login page answers and that the demo sync filled the tables.

---

## Project structure

```
app/
  Console/Commands/SyncSheetsCommand.php   php artisan sheets:sync
  Jobs/RunSheetsSync.php                   queued "Sync now"
  Services/GoogleSheets/                   SheetsClient interface, Google + Fake implementations
  Services/Sync/
    SheetSyncService.php                   orchestrates a run, isolates failures, records results
    HeaderMatcher.php                      header name → column index
    ValueCaster.php                        raw cell → typed value
    SchemaManager.php                      CREATE TABLE / ADD COLUMN, managed-table check
    RowWriter.php                          upsert / replace / snapshot in a transaction
  Support/Identifier.php                   safe table/column names
  Filament/                                admin UI (resources, Data Browser page, widgets)
  Models/                                  Spreadsheet, SheetTab, TableMapping, ColumnMapping, SyncRun, SyncRunItem
config/sheets.php                          driver, credentials, schedule, reserved tables
database/fixtures/sheets/                  demo spreadsheets for the fake driver
routes/console.php                         daily schedule
setup.sh                                   one-command server install
```
