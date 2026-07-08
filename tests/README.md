# Tests

Two layered suites:

| Suite | Framework | Needs WordPress + DB? | Runs where |
|-------|-----------|-----------------------|------------|
| **Unit** (`tests/Unit`) | PHPUnit + Brain Monkey + Mockery | No — WP functions are mocked | Anywhere / CI, in seconds |
| **Integration** (`tests/Integration`) | PHPUnit + `WP_UnitTestCase` | Yes | Local / CI with a WP test install |

Coverage focus (first pass): the resumable WXR importer (`ImportState`, `ChunkedImport`) plus core utilities (`Helpers`, `Functions`, `Requester`). This is an extensible base, not full-plugin coverage.

## Unit suite

```bash
composer install
composer test        # or: vendor/bin/phpunit
```

No database or WordPress install required. Uses `phpunit.xml.dist` and `tests/bootstrap-unit.php`.

## Integration suite

Needs a WordPress test-suite install and a **throwaway** test database (it is wiped between tests — never point it at real data).

### Option A — WP-CLI install script

```bash
# args: db-name db-user db-pass db-host wp-version
bash bin/install-wp-tests.sh wordpress_test root '' 127.0.0.1 latest
export WP_TESTS_DIR=/tmp/wordpress-tests-lib
composer test:integration
```

(Use the standard `install-wp-tests.sh` from the WP-CLI scaffold if `bin/` does not yet contain one.)

### Option B — `@wordpress/env` (Docker)

```bash
npx wp-env start
WP_TESTS_DIR=$(npx wp-env install-path)/tests-wordpress \
	composer test:integration
```

Uses `phpunit-integration.xml.dist` and `tests/bootstrap-integration.php`. The bootstrap reads `WP_TESTS_DIR` (or `WP_PHPUNIT__DIR`).

## Layout

```
tests/
├── bootstrap-unit.php            # Brain Monkey bootstrap (no WP)
├── bootstrap-integration.php     # WP test-suite bootstrap
├── stubs/wp-importer-stub.php    # hollow WP_Importer so ChunkedImport loads without WP
├── fixtures/sample.wxr           # tiny 2-post WXR for the integration cycle test
├── Unit/                         # Brain Monkey unit tests
└── Integration/                  # WP_UnitTestCase tests
```
