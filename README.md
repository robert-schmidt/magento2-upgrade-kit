# Magento 2 upgrade kit: 2.4.7 → 2.4.8 → 2.4.9, PHP 8.3 → 8.4 → 8.5, MySQL 8.0 → 8.4

Scripts that take an Adobe Commerce / Magento Open Source **2.4.7** codebase to **2.4.9 on PHP 8.5**. They work on
patterns across the codebase rather than on individual files, and they reuse the standard tools (Rector,
PHPCompatibility, composer, git merge) wherever those already solve the problem.

- **Portable.** It's plain bash plus PHP ≥ 8.1, and everything project-specific is an environment variable.
- **Non-destructive.** Each step produces one git diff for you to review. The kit never commits, deploys or touches a
  database.
- **Iterative.** Run a step, read the reports, fix what's left, re-run. New findings become a one-line rule in `rules/*.php`.

> Status: verified against Adobe release notes, the system requirements, the backward-incompatible-changes reference,
> php-src `UPGRADING`, and real runs on a large 2.4.7 EE codebase (~3,000 custom PHP files). It is not affiliated with
> Adobe. Read the diffs; you own the result.

---

## Contents
1. [Why this order](#1-why-this-order)
2. [The one Magento fact that makes this hard](#2-the-one-magento-fact-that-makes-this-hard)
3. [Quick start](#3-quick-start)
4. [Configuration](#4-configuration)
5. [Release plan and steps](#5-release-plan-and-steps)
6. [Tools reference](#6-tools-reference)
7. [Database: MySQL 8.0 → 8.4](#7-database-mysql-80--84)
8. [Manual checklist](#8-manual-checklist)
9. [Known issues worth knowing](#9-known-issues-worth-knowing-sept-2026)
10. [Finding runtime deprecations](#10-finding-runtime-deprecations)
11. [Extending the rules](#11-extending-the-rules)
12. [Limitations](#12-limitations)
13. [Sources](#13-sources)

---

## 1. Why this order

| Version | PHP | MySQL | Notes |
|---|---|---|---|
| 2.4.7-p10 | 8.2, 8.3 | none listed (p9: 8.0) | MySQL 8.0 reached EOL on 2026-04-30. 2.4.7 never supported MySQL 8.4; Adobe recommends MariaDB for 2.4.7. |
| 2.4.8-p5 | 8.3, **8.4** | **8.4** (release notes: 8.0 "still supported, upgrade recommended") | No PHP 8.5: root `composer.json` has `php: ~8.2.0\|\|~8.3.0\|\|~8.4.0` |
| 2.4.9 | **8.5** (8.4 "for upgrade purposes only") | 8.4 | First version with PHP 8.5; PHP 8.2 and 8.3 dropped. **2.4.9-p1 is announced for January 2027** (magento/magento2#41066) and carries the PHP 8.5 null-offset fixes and the cache fallback fix (#41213); until then only isolated security patch ZIPs exist. |

Neither the PHP 8.5 jump nor MySQL 8.4 is possible on 2.4.7, and 2.4.8 cannot run PHP 8.5. That gives three releases:

| Release | Magento | PHP | DB | What it contains |
|---|---|---|---|---|
| **R1** | 2.4.7-pX (unchanged) | 8.3 | 8.0 | Code fixes that are valid on both 8.3 and 8.5. Low risk; ship it on its own. |
| **R2** | 2.4.8-pN | 8.4 | **8.4** | Composer bump, Monolog 3, Symfony Mailer, the PHP 8.4 runtime and MySQL 8.4, all in one maintenance window. |
| **R3** | 2.4.9 | 8.5 | 8.4 | Composer bump, Symfony 7.4, native MVC, Symfony Cache, the PHP 8.5 runtime. |

MySQL 9.x isn't supported by any version. Redis no longer appears in Adobe's requirements matrix (Valkey 8/9 does): the
cache-options page says Redis "is not supported for 2.4.9, or patch releases later than 2.4.7-p9 and 2.4.8-p4". Redis
keeps working at the protocol level (Valkey 8 is a Redis 7.2 fork and the clients are the same), but it is formally
unsupported. **2.4.9 GA routes every cache backend through symfony/cache** (`App\Cache\Frontend\Factory` has no legacy
path any more); only the aliases `file`, `redis`, `valkey` and `symfony_l2` are known, the Redis DSN is always
`redis://` (no TLS, no cluster), and anything else silently becomes a filesystem cache (#41213). A custom backend class
therefore has to be ported to a `SymfonyAdapterProvider` preference/plugin, or wait for 2.4.9-p1.

## 2. The one Magento fact that makes this hard

`app/bootstrap.php` calls `error_reporting(E_ALL)`, and `Magento\Framework\App\ErrorHandler::handler()` **throws an
`\Exception` for every reported error, including `E_DEPRECATED`**, in every mode.

So on PHP 8.4/8.5 each deprecation is a crashed request, cron or CLI command, with this message:

```
Exception #0 (Exception): Deprecated Functionality: X::__construct(): Implicitly marking parameter $y as nullable is deprecated ...
```

This covers compile-time deprecations, which fire as soon as the class is autoloaded, runtime deprecations, and
**third-party vendor code** too. The kit is strict about deprecations for that reason. It also lints the packages you
added on top of Magento, and it can patch abandoned ones (`bin/vendor-patch.sh`).

## 3. Quick start

```bash
git clone https://github.com/robert-schmidt/magento2-upgrade-kit ~/upgrade-kit
cd /path/to/your/repo/src                      # your Magento root: has app/, bin/magento, composer.json
export CUSTOM_PATHS="app/code app/design"      # plus any other dirs with your PHP (see §4)

~/upgrade-kit/steps/00-report.sh               # read-only: what's broken, how much, where
~/upgrade-kit/steps/10-php-safe-fixes.sh       # R1 code changes (review, commit, release)
~/upgrade-kit/steps/20-to-2.4.8.sh             # R2
~/upgrade-kit/steps/30-to-2.4.9.sh             # R3
```

**Requirements on the machine that runs it:**
- bash and PHP ≥ 8.1 at any version (it only runs the tools). git is optional: inside a container whose
  `/var/www/html` is a volume rather than a checkout, the clean-tree gate is skipped with a warning and you review the
  diff on the host.
- composer ≥ 2.9.3, with `auth.json` for repo.magento.com and any private repos you use.
- PHP binaries for the versions you lint with. `bin/lint.sh` looks for `PHP_BIN_85`-style variables, then `php8.5`
  on `PATH` (the ondrej packages install `/usr/bin/php8.5` next to `php8.3`), then `PHP_BIN`, and only then falls back
  to docker `php:8.x-cli` images.

The kit doesn't need to live inside your project, and it doesn't need a running Magento or a database.

## 4. Configuration

Everything is in `kit.env`. Override it with environment variables, or put your values in a file and set `KIT_ENV_FILE=/path/to/file`.

| Variable | Default | Meaning |
|---|---|---|
| `MAGENTO_ROOT` | `$PWD` | Directory with `app/`, `bin/magento` and the root `composer.json` |
| `CUSTOM_PATHS` | `app/code app/design` | **Your** PHP code, relative to `MAGENTO_ROOT`. Add extra `lib/<Vendor>` dirs, forked-vendor dirs, custom `setup/src/<Vendor>` and so on. |
| `INFRA_PATHS` | `Dockerfile docker .docker pipelines .github .gitlab-ci.yml bitbucket-pipelines.yml azure-pipelines.yml bin docker-compose*.yml` | Build and CI files that mention the PHP version, relative to the git root |
| `REPO_ROOT` | `git rev-parse --show-toplevel` | Git root, if it isn't the Magento root |
| `M248_VERSION` / `M249_VERSION` | `2.4.8-p5` / `2.4.9` | Target tags |
| `PHP_BIN` | (unset) | Lint with this binary instead of docker. It must be the version you are linting for. |
| `COMPOSER_BIN`, `COMPOSER_FLAGS` | `composer`, (none) | Composer command, plus extra flags for `composer update` |
| `REPORT_DIR`, `TOOLS_DIR` | `<kit>/reports`, `<kit>/.tools` | Where reports and the isolated tool installs go |

Example `KIT_ENV_FILE`:
```bash
MAGENTO_ROOT=/srv/shop/public
CUSTOM_PATHS="app/code app/design lib/Acme vendor-forks setup/src/Acme"
INFRA_PATHS="Dockerfile .docker deploy/pipelines docker-compose.yml"
```

## 5. Release plan and steps

### Step 00: report (read-only, run any time)
`steps/00-report.sh` writes `reports/00-*` and changes nothing:
- `lint` with PHP 8.4 and 8.5, for compile-time deprecations and fatals.
- Pattern scans for PHP 8.4/8.5 runtime issues and the 2.4.8/2.4.9 breaking changes. Each finding is labelled `fatal`,
  `deprecated`, `behavior` or `review`, and `[autofix]` where the kit can fix it.
- PHPCompatibility 10 (`testVersion 8.4-8.5`) as a second opinion.
- Dry runs of the `composer.json` changes for both targets and of the infra changes.

### Step 10: R1, PHP-syntax release (on 2.4.7 / PHP 8.3)
`steps/10-php-safe-fixes.sh` applies only rewrites whose output **also runs on 8.3**:

| Fix | Tool |
|---|---|
| `Type $x = null` → `?Type $x = null` (the biggest group, often hundreds) | Rector `ExplicitNullableParamTypeRector` |
| `fgetcsv/fputcsv/str_getcsv(...)` → `..., escape: '\\'` | Rector `AddEscapeArgumentRector` |
| `(integer)(boolean)(double)(binary)` → `(int)(bool)(float)(string)` | `rules/php.php` |
| standalone `curl_close()`, `finfo_close()`, `imagedestroy()`, `xml_parser_free()`, `->setAccessible()` removed | `rules/php.php` |
| `utf8_encode/decode()` → `mb_convert_encoding(...)` (exact equivalent) | `rules/php.php` |
| literal `$a[null]` → `$a['']` (what PHP did anyway) | `rules/php.php` |
| console commands: `execute(...)` → `execute(...): int`, `isEnabled(): bool` (valid on Symfony 6.4, required on 7.4) | `rules/m249.php` |
| "optional parameter before required" left by the nullable fix, and a bare `return;` in an `execute(): int` | `bin/lint-fix.php` (driven by lint output) |

It then lints again on 8.5 and on 8.3. **`10-lint-8.3-after.txt` must be empty.** That proves the release still runs
on your current PHP.

### Step 20: R2, 2.4.8 + PHP 8.4 + MySQL 8.4
`steps/20-to-2.4.8.sh`. Preconditions: R1 is released, `composer install` has been run on the current lock, and the git
tree is clean. It then:
1. Snapshots `vendor/` → `vendor_orig/`. It is added to `.git/info/exclude`, so it never gets committed.
2. `bin/composer-sync.php` → `composer.json` for 2.4.8-pN / `~8.4.0`, then runs `composer update -W`. On failure it
   prints `composer why-not` output.
3. `bin/config-php.php` makes `app/etc/config.php` consistent: it removes `Magento_Elasticsearch7`, and adds new
   modules as disabled where their dependencies are disabled.
4. `bin/infra.php` moves Dockerfile, CI and scripts from `php8.3` to `php8.4` and unpins the composer version.
5. Rector's `php84` set, plus the Monolog 3 handler signature autofix.
6. `bin/core-forks.php` 3-way merges your copies of core files onto the new vendor versions.
7. `bin/preferences.php` lists every core class your `di.xml` files override (`<preference>`) or intercept
   (`<plugin>`) whose upstream file changed, and flags changed constructors, changed signatures of methods you
   override, and plugin targets that no longer exist.
8. Lint on 8.4 of your code **and** of the packages you added on top of Magento.

Then run `bin/magento setup:di:compile` in a PHP 8.4 container with the new vendor. Signature mismatches against the new
core show up there. Next, run `setup:upgrade` against a copy of production on MySQL 8.4 (§7).

### Step 30: R3, 2.4.9 + PHP 8.5
`steps/30-to-2.4.9.sh` has the same shape as step 20, targeting 2.4.9 / `~8.5.0`, and adds:
- **PDO MySQL attribute integers in `env.php`.** In PHP 8.5 the values of `Pdo\Mysql::ATTR_*` above 1003 shift down by
  one. On PHP 8.3/8.4, `SSL_CA=1009` and `SSL_VERIFY_SERVER_CERT=1014`. On 8.5 they are `1008` and `1013`, and `1014`
  becomes `LOCAL_INFILE_DIRECTORY`. Hard-coded integers in `driver_options` are rewritten to `\Pdo\Mysql::ATTR_*`
  constants. (This was verified by building `pdo_mysql` in the official `php:8.3/8.4/8.5-cli` images.)
- **Cache backend alias.** 2.4.9 replaced Zend_Cache with symfony/cache. A cache `backend` given as a class name
  (`Magento\Framework\Cache\Backend\Redis`) silently falls back to files in `var/cache/symfony`
  (magento/magento2#41213). It is rewritten to `'redis'`.
- **opcache.** There is no `php8.5-opcache` package, because opcache is built in from 8.5 on, and
  `zend_extension=opcache.so` now warns. Both are handled.
- Rector's `php85` set. `__sleep`/`__wakeup` → `__serialize` is deliberately skipped: a child class defining
  `__serialize` silently disables the `__sleep` of a parent such as `AbstractModel`.

## 6. Tools reference

All of these work on their own, not just from the steps.

| Tool | What it does |
|---|---|
| `bin/scan.php [--fix] [--only=ids] [--json=f] rules.php[,more.php] <paths>` | PCRE rule engine. It ignores comments, a rule can apply only to files that match a `when` guard, and `--fix` applies only rules that define a `fix`. |
| `bin/lint.sh <8.3\|8.4\|8.5> [out]` | `php -l` using that exact PHP version: parallel, one process per file, output reduced to deprecations and fatals |
| `bin/lint-fix.php <lint.txt> <root>` | Fixes what lint points at exactly: optional-before-required params, and bare returns in typed methods |
| `bin/composer-sync.php <composer.json> <tag> <php> [--dry-run]` | Updates the root `composer.json` (see below) |
| `bin/config-php.php <root> [--dry-run]` | Prunes and completes the module list in `app/etc/config.php` |
| `bin/infra.php <repo> <8.4\|8.5> <2.4.8\|2.4.9> [--dry-run]` | Rewrites PHP version references in build and CI files, plus `env.php` |
| `bin/core-forks.php <root> <out>` | 3-way merge of core-file customizations onto the new vendor (see below) |
| `bin/preferences.php <root> <out>` | Preferences/plugins on core classes vs the new vendor: `CTOR` (constructor changed, your preference has its own), `SIG` (overridden/hooked method changed), `GONE` (method or class removed), with the upstream diff per class. Needs `vendor_orig/`. |
| `bin/vendor-patch.sh <vendor/pkg>...` | Builds a composer-patches patch with the PHP 8.4/8.5 fixes for a vendor package you can't upgrade |
| `bin/tools.sh [uph]` | Installs Rector 2.6 and PHPCompatibility 10 into `.tools/`. With `uph`, also installs convenient/upgrade-patch-helper. |
| `sql/preflight-mysql84.sql` | Read-only DB checks before MySQL 8.4 and 2.4.8 (§7) |

**How `composer-sync.php` works.** Many projects' root `composer.json` isn't the slim project template but a copy of
the **magento/magento2 git-root** `composer.json`. That copy carries dozens of pinned laminas, symfony and monolog
packages, and `composer require-commerce` alone won't touch them.

The script diffs the git-root `composer.json` of your current tag against the target tag. Those are shipped in `data/`,
or fetched from GitHub. Then, for every package:
- In both tags: it takes the target's constraint, and tells you when that overrides one of your own pins.
- Only in the base tag: it removes the package, because Adobe dropped it (e.g. `laminas-mail`, `laminas-mime`).
- New in the target: it adds the package.
- Only yours: it keeps the package and lists it for you to check.

If your `composer.json` is the slim project template, which it detects from how many git-root pins it carries, it only
updates entries you already have. The metapackage brings in the rest.

It also sets `php`, the metapackage version and `config.platform.php`, so the lock resolves for the target PHP whatever
PHP runs composer. Finally it applies `data/overrides-<tag>.json`: known constraint bumps for common third-party
packages that block the target, applied only when you actually require them. For example, `dompdf/dompdf ^2` can't
install on PHP 8.5, and `^3.1` can.

**How `core-forks.php` works.** It finds two kinds of hidden core customization and merges them with
`git merge-file --diff3`:
1. **Classmap forks.** `"exclude-from-classmap": ["vendor/magento/framework/.../File.php"]` together with your
   replacement class in `"classmap"`. After an upgrade your old copy silently replaces the new core file.
2. **Edited files deployed from `magento/magento2-base`**, such as `setup/src/...`, `pub/errors/...` or
   `app/bootstrap.php`. `composer install` with `magento-force: override` re-deploys vendor's copy over your edit.

For each file it writes `*.upstream.diff` and a `*.merged` candidate with conflict markers, to `reports/<step>-forks/`.

**How `vendor-patch.sh` works.** For abandoned packages (for example `microsoft/azure-storage-*`) it runs the safe fixes
on a copy, lints the result with the target PHP, and writes `patches/composer/<pkg>-php8x.patch`. It also prints the
`extra.patches` entry for [cweagans/composer-patches](https://github.com/cweagans/composer-patches).

**Optional deeper check.** `bin/tools.sh uph` installs the maintained
[upgrade-patch-helper fork](https://github.com/convenient/convenient-magento2-upgrade-patch-helper). Steps 20 and 30
write `vendor-<ver>.patch` and print the `analyse` command. It reports preferences, plugins, and template, layout and
knockout overrides whose core counterpart changed. It needs an instance with a database.

## 7. Database: MySQL 8.0 → 8.4

**Before anything else**, run `sql/preflight-mysql84.sql` against a restored copy of production:

```bash
mysql --force --table -h <host> -u <user> -p <db> < sql/preflight-mysql84.sql
```

| Check | Why it matters |
|---|---|
| FKs that reference a non-unique key | MySQL 8.4 sets `restrict_fk_on_non_standard_key=ON`, which gives `ERROR 6125` on import or DDL. EE staging tables (`row_id`/`entity_id`) hit this. Adobe's fix is to set the parameter to `OFF`. |
| duplicate `eav_attribute_option_value (option_id, store_id)` | 2.4.8 adds a UNIQUE key there, so duplicates break `setup:upgrade`. Dedupe first. |
| 8.4 reserved words (`MANUAL`, `PARALLEL`, `QUALIFY`, `TABLESAMPLE`) as identifiers | Unquoted custom SQL breaks |
| `AUTO_INCREMENT` on FLOAT/DOUBLE | Removed in 8.4 |
| tables without a primary key | These make `setup:upgrade` fail when `sql_generate_invisible_primary_key=ON`, which is a default on some managed services (magento/magento2#38114) |
| non-`trg_*` triggers | Custom triggers interfere with mview |
| utf8mb3 tables | 2.4.8 defaults to utf8mb4, so custom tables joined to core ones can hit "Illegal mix of collations" |
| server variables and auth plugins | `log_bin_trust_function_creators=1`, `explicit_defaults_for_timestamp=1`, `mysql_native_password` users |
| `SET SESSION restrict_fk_on_non_standard_key=OFF` (8.4 only) | Proves the app user can switch it off per connection. Verified on `mysql:8.4`: a user with only `ALL ON db.*` can. If the managed service does not expose the global parameter (Azure does not document it), put the statement in `env.php` → `db/connection/*/initStatements`, which Magento runs on every connection. |

**Procedure** (self-hosted, Azure Database for MySQL Flexible Server, or AWS RDS/Aurora):
1. Restore production to a new server (PITR or snapshot) and run the preflight on it.
2. Set these server parameters:
   - `restrict_fk_on_non_standard_key=OFF` once the server is on 8.4. On managed services, **check that it's exposed as
     modifiable**; if it isn't, open a support ticket before the production window.
   - `sql_generate_invisible_primary_key=OFF`
   - `log_bin_trust_function_creators=ON`
3. Move the Magento DB user to `caching_sha2_password`. `mysql_native_password` is disabled by default in 8.4 and
   removed in 9.0, and PHP's mysqlnd supports `caching_sha2_password` over TLS.
4. Major-upgrade the copy and time it. Deploy the R2 build against it: `setup:upgrade`, reindex, smoke test.
   - Managed services: a major upgrade is **irreversible**, so rollback means restoring a backup.
   - Azure Database for MySQL Flexible Server: 8.4 is GA (8.4.9 as of Nov 2026), in-place 8.0 → 8.4 is
     `az mysql flexible-server upgrade --version 8.4` (replicas first, HA supported, not zero-downtime, Burstable tier is
     temporarily scaled to General Purpose). Standard support for 8.0 ends **2027-01-31**, then auto-billed Extended
     Support until 2029-05-31. `mysql_native_password` stays enabled on Azure 8.4. Terraform: `azurerm` ≥ 4.55.0 accepts
     `version = "8.4"` on `azurerm_mysql_flexible_server` and applies it as an in-place PATCH, not a replacement.
     Microsoft strongly recommends MySQL Shell `util.checkForServerUpgrade()` before the portal Validate step.
5. Production window:
   1. Enable maintenance mode.
   2. Stop crons and consumers.
   3. Take a backup.
   4. Upgrade MySQL.
   5. Deploy R2.
   6. Run `setup:upgrade`.
   7. Run the checks.
   8. Disable maintenance mode.

## 8. Manual checklist

These are things no script can decide for you. Work through the `fatal` and `deprecated` rows without `[autofix]` in
the reports first.

**2.4.8**
- [ ] **Monolog 3.** `write(array $record)` becomes `write(LogRecord $record)`; the kit autofixes the signature.
  **Writing** to a record throws `LogicException` for every key except `extra` and `formatted`, e.g.
  `$record['channel'] = ...` or a plugin setting `$record['some_flag']`. Use `$record = $record->with(extra: [...])`.
  Rules `m248.monolog.record-write` and `m248.monolog.array-record-param` find these.
- [ ] **Mail.** Laminas Mail was replaced by Symfony Mailer/Mime. `Message`, `Transport`, `TransportBuilder`, the
  attachment code and SMTP modules need to be checked. The STARTTLS-on-587 bug is fixed in 2.4.8-p2+
  (magento/magento2#39947).
- [ ] **REST APIs.** From 2.4.8-p3 (and 2.4.7-p8), only scalars and `*\Api\Data\*Interface` types are hydrated from
  payloads (CVE-2025-54236, KB ka-27501). If you're already on 2.4.7-p8+ and your APIs work, they're fine.
- [ ] **WYSIWYG.** TinyMCE is replaced by HugeRTE (2.4.8-p3+), and `lib/web/extjs` is removed. Check custom editor
  plugins and any build step that copies old TinyMCE or jQuery files over `lib/web`.
- [ ] **Indexers.** 2.4.8 switches **all indexers to "Update by Schedule" on upgrade**. Check
  `bin/magento indexer:show-mode`, especially for custom indexers.
- [ ] **2FA.** `twofactorauth/google/otp_window` is replaced by `leeway` (e.g. `config:set twofactorauth/google/leeway 29`).
  Duo moves to Universal Prompt and needs a Client ID and Secret.
- [ ] **Search.** Elasticsearch 7 is gone. Use OpenSearch 2.19/3. If you run without a search engine, keep
  `Magento_Elasticsearch8` disabled; `config-php.php` does this when its dependency is off.
- [ ] **JS bundling and minification in production.** SRI hashes break checkout on some patch levels (KB ka-27997,
  magento/magento2#40247). Apply Adobe's hotfix or turn one of them off.
- [ ] **Composer patches** (`extra.patches`). Drop those fixed upstream (`vendor/bin/magento-patches status`), and set
  `"composer-exit-on-patch-failure": true` so a patch that no longer applies fails the build instead of being skipped.
- [ ] **Your own packages and extensions.** Check `composer why-not php 8.4.0` and `8.5.0`, and look at the vendor lint
  in the step 20/30 reports. Get vendor releases for 2.4.8/2.4.9, or run `vendor-patch.sh` on abandoned packages.
- [ ] **PECL extensions built from source in your Dockerfile** (rdkafka, redis and so on). The ondrej PPA ships
  `php8.4-*`/`php8.5-*` packages for redis, rdkafka, apcu, amqp, igbinary and imagick.
- [ ] **Encryption key.** Rotation is CLI-only now. The Admin "Data Collector" was removed.

**2.4.9**
- [ ] Laminas MVC and the Laminas DB fork were removed (native MVC, `php-db/phpdb`). Check any `Laminas\Mvc`,
  `Laminas\Router` or `Laminas\Db` use, including custom factories under `setup/src`.
- [ ] Code that uses `Zend_Cache_*` or reaches into cache backends: move to the Magento cache interfaces.
- [ ] Other libraries: subclasses of Symfony 7.4 classes, `web-token/jwt-framework` 4, `firebase/php-jwt` 7,
  `pelago/emogrifier` 8.
- [ ] PHPUnit 12 for your unit tests: attributes instead of annotations.
- [ ] GraphQL now limits aliases (10) and query length (1 MB). Raise them if your headless or PWA clients need more.
- [ ] Admin: `admin/security/minimum_password_length`. New Relic now uses NerdGraph (new credentials needed).
- [ ] A custom cache backend class (Redis Cluster, Sentinel wrappers, ...) is bypassed on 2.4.9 GA: `infra.php` prints
  a `WARN` for every `'backend'` value that is not `file|redis|valkey|symfony_l2`. Port it or wait for 2.4.9-p1.
- [ ] After deploying:
  - `var/cache/symfony` stays empty, which shows the cache really is in Redis/Valkey.
  - DB SSL works (`env.php` `driver_options`).
  - Admin tax rate edit, design configuration and PDF printing all work (see §9).

## 9. Known issues worth knowing (Sept 2026)

| Issue | Affects | Status / workaround |
|---|---|---|
| PHP 8.5 "null as array offset" crashes in core: tax rate edit, design config, PDF print, downloadable orders, PDP without a category (#41025, #41261, #41134, #41230, #40891) | 2.4.9 + PHP 8.5 | Some are fixed in 2.4-develop. Apply the hotfixes, or wait for 2.4.9-p1 (announced for early 2027). |
| Class-name cache backend silently falls back to files (#41213); Redis over a unix socket does the same (#41118) | 2.4.9 | Use `'backend' => 'redis'`/`'valkey'` (step 30 does this) |
| `--session-save=valkey` silently uses file sessions (#41108) | 2.4.9 | Use `redis` sessions |
| `ConfigOptionsListConstants::KEY_MYSQL_SSL_VERIFY = 1014` is wrong on PHP 8.5 (#40855) | 2.4.9 + 8.5 + `db-ssl-verify` | PR #40849. Avoid that option, or patch it. |
| `GLOB_BRACE` undefined on Alpine/musl breaks every `bin/magento` call (#41066) | 2.4.9 on Alpine | Use Debian/Ubuntu images; the fix is due in 2.4.9-p1 |
| EE `db_schema` error on `catalog_product_entity_media_gallery_value_to_entity` (#40140) | 2.4.8-p1/p2 EE | Use p3+ |
| SMTP STARTTLS on port 587 (#39947) | 2.4.8, 2.4.8-p1 | Fixed in p2+ |
| JS bundling + minification + SRI breaks checkout (KB ka-27997) | 2.4.7-p8+, 2.4.8-p3+ | Adobe hotfix, or disable one of the two |
| FK error 1822 during the utf8mb4 conversion (#40808) | 2.4.8-p4 → p5 | See the issue |

## 10. Finding runtime deprecations

Lint only sees compile-time issues. The pattern scans and PHPCompatibility flag *likely* runtime issues. The rest, such
as a `null` array key held in a variable or float-to-int overflow, only appears when the code runs. On staging with the
new PHP, exercise crons, queue consumers, imports and exports, admin grids, checkout and the APIs, then:

```bash
grep -h "Deprecated Functionality\|Deprecated:" var/log/*.log var/report/* 2>/dev/null \
  | sed 's/ in .* on line.*//' | sort | uniq -c | sort -rn
```

## 11. Extending the rules

A rule is one array entry in `rules/php.php`, `rules/m248.php` or `rules/m249.php`:

```php
['id' => 'm248.something', 'level' => 'fatal',           // fatal | deprecated | behavior | review
 'find' => '/PCRE on the file contents/',
 'files' => '/\.(php|phtml)$/',                           // optional, default php+phtml
 'when' => '/the file must also match this/',             // optional guard, e.g. only Command subclasses
 'skipComments' => true,                                  // optional, default true
 'note' => 'what to do',
 'fix' => 'replacement with $1' /* or fn(array $m): string, optional; only applied with --fix */],
```

Only add a `fix` when the output is correct in every match *and* valid on the PHP that is currently running (see
§5). Everything else stays report-only. PRs with new rules and `data/overrides-*.json` entries are welcome.

## 12. Limitations

- Pattern scans are heuristics: they produce false positives and can't prove the absence of problems. Lint and
  `setup:di:compile` are the hard gates, and runtime testing (§10) is the final one.
- Nothing here checks JavaScript, Knockout, Less or layout XML beyond a few patterns. Use upgrade-patch-helper and
  test the storefront and admin.
- The kit knows about the Commerce metapackages (`product-enterprise-edition` / `product-community-edition`). B2B and
  other Adobe extensions follow the metapackage; extensions from other vendors are your call.
- Adobe's own Upgrade Compatibility Tool (`magento/upgrade-compatibility-tool` on repo.magento.com, Commerce only)
  complements this kit: `bin/uct upgrade:check <root> -c 2.4.9`.

## 13. Sources

- Adobe: [2.4.9 release notes](https://experienceleague.adobe.com/en/docs/commerce-operations/release/notes/adobe-commerce/2-4-9) ·
  [2.4.8 release notes](https://experienceleague.adobe.com/en/docs/commerce-operations/release/notes/adobe-commerce/2-4-8) ·
  [2.4.8 patches](https://experienceleague.adobe.com/en/docs/commerce-operations/release/notes/security-patches/2-4-8-patches) ·
  [system requirements](https://experienceleague.adobe.com/en/docs/commerce-operations/installation-guide/system-requirements) ·
  [backward-incompatible changes](https://developer.adobe.com/commerce/php/development/backward-incompatible-changes/) ·
  [upgrade prerequisites (MySQL 8.4)](https://experienceleague.adobe.com/en/docs/commerce-operations/upgrade-guide/prepare/prerequisites) ·
  [REST constructor-parameter validation, KB ka-27501](https://experienceleague.adobe.com/en/docs/experience-cloud-kcs/kbarticles/ka-27501)
- PHP: [migration 8.4](https://www.php.net/manual/en/migration84.php) · [migration 8.5](https://www.php.net/manual/en/migration85.php) ·
  [UPGRADING 8.5](https://github.com/php/php-src/blob/PHP-8.5/UPGRADING) ·
  [deprecations 8.4](https://wiki.php.net/rfc/deprecations_php_8_4) · [deprecations 8.5](https://wiki.php.net/rfc/deprecations_php_8_5)
- Tools: [Rector](https://github.com/rectorphp/rector) · [PHPCompatibility](https://github.com/PHPCompatibility/PHPCompatibility) ·
  [upgrade-patch-helper (maintained fork)](https://github.com/convenient/convenient-magento2-upgrade-patch-helper) ·
  [composer-patches](https://github.com/cweagans/composer-patches)
- MySQL: [8.4 keywords](https://dev.mysql.com/doc/refman/8.4/en/keywords.html) ·
  [native password in 8.4](https://dev.mysql.com/doc/refman/8.4/en/native-pluggable-authentication.html) ·
  [Azure Flexible Server major upgrade](https://learn.microsoft.com/en-us/azure/mysql/flexible-server/how-to-upgrade)
- Issues referenced: [magento/magento2](https://github.com/magento/magento2/issues) #38114 #39947 #40140 #40247 #40808
  #40849 #40855 #40891 #41025 #41066 #41108 #41118 #41134 #41213 #41230 #41261

## License
MIT
