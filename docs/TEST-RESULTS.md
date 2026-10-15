# Test results — October 15, 2026

## Latest: Gemini integration

Laravel **13.36.0** is installed through Composer. The selected local provider is `gemini`, using `gemini-3.1-flash-lite`; the API key is in ignored `.env`.

- Live Gemini against isolated MySQL: **2 tests, 14 assertions passed**. Verified tenant-scoped record retrieval, excluded secret/foreign-tenant values, preview without a write, and explicit application-side confirmation.
- Expanded ordinary suite: **19 tests passed, 44 assertions per backend**, with 2 live cases intentionally skipped during ordinary runs. Both MySQL and SQLite passed.
- PHP lint and frontend static checks passed. No browser interaction or load/concurrent-confirmation tests were performed.

Live testing identified and fixed empty JSON argument-object replay and underspecified ID constraints. Gemini replay preserves thought signatures and function IDs; the final model request disables tools for summarization. The first model, 2.5 Flash, was unavailable to new users; 3.8 Flash had high-demand/time-out failures. The selected 3.1 Flash-Lite completed the final live pass. Provider/network availability may still vary.

The live report is `.runtime/live-gemini-results.xml`. The temporary MySQL test server is shut down after verification. Follow the repeat instructions below to start your isolated database and run `php scripts/test-live.php` again.

## Earlier baseline

Composer installed the package dependencies locally, including Laravel 13.36.0, Orchestra Testbench 11.3.0 and PHPUnit 12.5.39. Tests ran with portable PHP 8.4.26. No system-wide PHP or Composer settings were changed.

| Check | Result |
| --- | --- |
| Full PHPUnit suite — SQLite | PASS: 13 tests, 28 assertions |
| Full PHPUnit suite — MySQL 8.4.6 | PASS: 13 tests, 28 assertions |
| PHP lint — source, config, routes, migration, tests | PASS |
| Composer strict validation | PASS |
| Composer locked dependency audit | No known vulnerability advisories found |
| Frontend JavaScript syntax, text rendering and CSRF wiring | PASS |

MySQL ran on loopback port 13317 using a separate data directory under `.runtime/mysql-data` and the `assistant_test` database. Existing application databases were not used. The temporary server was shut down after verification; its data and installed dependencies remain available for another run.

Coverage includes scoped reads/counts, column redaction, identifier injection rejection, protected fields, create/update/delete confirmation, confirmation idempotency, expiry, permission revocation, tenant isolation, stale edits, audit failure rollback, mocked OpenAI function calling, authenticated chat and Blade rendering.

One overlapping test run encountered a Windows package-cache rename collision. Both final suites were run sequentially and passed. Run these suites sequentially against the shared Testbench installation.

JUnit results are saved locally in `.runtime/sqlite-results.xml` and `.runtime/mysql-results.xml` (ignored by Git).

## Repeat the checks

With PHP and Composer on PATH, use `composer install`, `composer validate --strict`, `composer audit --locked`, and `composer test`. Enable `pdo_sqlite` for the default suite. For an isolated MySQL server, configure the test-only environment:

```powershell
$env:TEST_MYSQL = '1'
$env:TEST_MYSQL_PORT = '13317'
$env:TEST_MYSQL_PASSWORD = ''
php vendor/bin/phpunit --testdox
```

The fixture always targets `assistant_test` on loopback and resets its test tables. Set the password to match your isolated instance; the CI default is `test`. Do not use an application database. Remove `TEST_MYSQL` before running the SQLite suite again.

## Limits of this verification

This is the full current automated suite, not a production certification. OpenAI requests were mocked. Live API credentials/model availability, real-browser interactions, concurrent confirmations, load behavior, PHP 8.3 compatibility, and the eventual host application's authentication and business rules were not exercised. The broader release checks remain in `docs/TESTING.md`.

## Live API attempt — October 15, 2026

A supplied API key was saved in the Git-ignored local `.env`. Two explicitly enabled live tests called OpenAI's Responses endpoint using `gpt-5.4-mini`, against synthetic records in the isolated MySQL instance. Both calls returned HTTP 429 with the provider message: “You have no credits remaining.” Neither test reached a database tool call; live model/tool compatibility is therefore not yet verified.

The live tests cover scoped record retrieval and update preview followed by application-side confirmation. They are skipped during normal test runs. Once API credits are available, start the isolated test database, set the test MySQL environment variables above, and run `php scripts/test-live.php`. This command loads `.env` and makes billable API requests. Results are written to `.runtime/live-results.xml`. Never run it against business data.
