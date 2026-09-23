<?php
/**
 * Syncs a root composer.json from one Magento tag to another, keeping project-specific packages.
 * Works for both layouts:
 *  - project template (magento/project-*-edition): only the metapackage + a few plugins are Magento's; the rest is yours
 *  - a copy of the magento/magento2 git-root composer.json (many laminas/symfony/... pins): those pins are synced
 *    to the target tag's git root, so they don't block the update
 *
 *   php composer-sync.php <composer.json> <target-tag> <php-constraint> [--dry-run]
 *   e.g. php composer-sync.php composer.json 2.4.8-p5 "~8.4.0"
 *
 * Base tag = current magento/product-(enterprise|community)-edition version in the file.
 * Git-root composer.json per tag is read from ../data/magento2-root-<tag>.json, else fetched from GitHub.
 *
 * For require + require-dev:
 *   key in base & target          -> take target constraint (reports when it overrides a project pin)
 *   key in base, not in target    -> removed (Adobe dropped it)
 *   key new in target             -> added
 *   key only in project           -> kept, listed for manual compatibility check
 * Also: php constraint, product-enterprise-edition version, config.platform.php,
 * then data/overrides-<target>.json ({"require": {"pkg": "^x" | null}}) for project packages that block the target.
 */
[$_, $file, $target, $php] = $argv + [null, null, null, null];
$dry = in_array('--dry-run', $argv, true);
if (!$file || !$target || !$php) { fwrite(STDERR, "usage: php composer-sync.php <composer.json> <target-tag> <php-constraint> [--dry-run]\n"); exit(2); }

function rootFor(string $tag): array
{
    $local = __DIR__ . "/../data/magento2-root-$tag.json";
    if (!is_file($local)) {
        $json = @file_get_contents("https://raw.githubusercontent.com/magento/magento2/$tag/composer.json");
        if ($json === false) { fwrite(STDERR, "cannot get magento2 git root composer.json for $tag (put it in data/)\n"); exit(1); }
        file_put_contents($local, $json);
    }
    return json_decode(file_get_contents($local), true, flags: JSON_THROW_ON_ERROR);
}

$c = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
$meta = isset($c['require']['magento/product-enterprise-edition']) ? 'magento/product-enterprise-edition' : 'magento/product-community-edition';
$baseTag = $c['require'][$meta] ?? null;
if (!$baseTag || !preg_match('/^\d+\.\d+\.\d+(-p\d+)?$/', $baseTag)) { fwrite(STDERR, "$meta must be pinned to an exact version (found: " . var_export($baseTag, true) . ")\n"); exit(1); }
$base = rootFor($baseTag);
$tgt = rootFor($target);
// Layout: a git-root copy carries many of the git root's third-party pins; a project template carries almost none.
$overlap = count(array_filter(array_keys($c['require'] ?? []), fn($p) => isset($base['require'][$p]) && !str_starts_with($p, 'magento/') && str_contains($p, '/')));
$gitRootCopy = $overlap >= 10;
echo "base $baseTag -> target $target (" . ($gitRootCopy ? "git-root copy layout: $overlap pinned libs, syncing all" : "project template layout: only existing entries are updated") . ")\n";

$custom = [];
foreach (['require', 'require-dev'] as $sec) {
    $b = $base[$sec] ?? []; $t = $tgt[$sec] ?? []; $o = $c[$sec] ?? [];
    foreach ($o as $pkg => $ver) {
        if ($pkg === 'php' || $pkg === $meta || str_starts_with($pkg, 'ext-') || str_starts_with($pkg, 'lib-')) continue;
        if (array_key_exists($pkg, $b)) {
            if (array_key_exists($pkg, $t)) {
                if ($ver !== $t[$pkg]) {
                    $pinned = $ver !== $b[$pkg] ? "  <-- project pin overridden (was $ver, Adobe base had {$b[$pkg]})" : '';
                    echo "  update  [$sec] $pkg: $ver -> {$t[$pkg]}$pinned\n";
                    $o[$pkg] = $t[$pkg];
                }
            } else {
                echo "  remove  [$sec] $pkg ($ver): dropped by Adobe in $target\n";
                unset($o[$pkg]);
            }
        } else {
            $custom[] = [$sec, $pkg];
        }
    }
    foreach ($gitRootCopy ? $t : [] as $pkg => $ver) {
        if (!array_key_exists($pkg, $b) && !array_key_exists($pkg, $o) && $pkg !== 'php' && !str_starts_with($pkg, 'ext-')) {
            echo "  add     [$sec] $pkg: $ver\n";
            $o[$pkg] = $ver;
        }
    }
    // ext-* requirements follow the target too (e.g. ext-ftp added in 2.4.8)
    if ($gitRootCopy && array_filter(array_keys($o), fn($p) => str_starts_with($p, 'ext-'))) {
        foreach ($t as $pkg => $ver) if (str_starts_with($pkg, 'ext-') && !isset($o[$pkg])) { echo "  add     [$sec] $pkg\n"; $o[$pkg] = $ver; }
    }
    ksort($o);
    // keep php/ext first like composer does
    uksort($o, fn($x, $y) => [!($x === 'php'), !str_starts_with($x, 'ext-') && !str_starts_with($x, 'lib-'), $x] <=> [!($y === 'php'), !str_starts_with($y, 'ext-') && !str_starts_with($y, 'lib-'), $y]);
    $c[$sec] = $o;
}
// project-specific constraint bumps for this target (found by trial: composer why-not / resolution errors)
$ovFile = __DIR__ . "/../data/overrides-$target.json";
if (is_file($ovFile)) {
    foreach (json_decode(file_get_contents($ovFile), true, flags: JSON_THROW_ON_ERROR) as $sec => $pkgs) {
        if ($sec[0] === '_') continue;
        foreach ($pkgs as $pkg => $ver) {
            if (!array_key_exists($pkg, $c[$sec] ?? [])) continue; // only bump what the project actually requires
            echo "  override[$sec] $pkg: " . ($c[$sec][$pkg] ?? '-') . " -> " . ($ver ?? 'removed') . "  (data/overrides-$target.json)\n";
            if ($ver === null) unset($c[$sec][$pkg]); else $c[$sec][$pkg] = $ver;
        }
    }
}
echo "  set     php: " . ($c['require']['php'] ?? '(none)') . " -> $php\n";
$c['require']['php'] = $php;
echo "  set     $meta: $baseTag -> $target\n";
$c['require'][$meta] = $target;
$c['version'] = preg_replace('/-p\d+$/', '', $target);
// resolve the lock for the target runtime regardless of the PHP running composer
preg_match('/(\d+\.\d+)/', $php, $m);
$c['config']['platform']['php'] = $m[1] . '.0';
echo "  set     config.platform.php: {$m[1]}.0\n";

echo "\nProject-only packages (check each supports PHP {$m[1]} + $target; composer why-not will tell):\n";
foreach ($custom as [$sec, $pkg]) if (isset($c[$sec][$pkg])) echo "  [$sec] $pkg {$c[$sec][$pkg]}\n";
if (!empty($c['extra']['patches'])) {
    echo "\ncomposer-patches (re-validate against $target; cweagans 1.x skips failing patches silently unless composer-exit-on-patch-failure=true):\n";
    foreach ($c['extra']['patches'] as $pkg => $ps) foreach ($ps as $desc => $p) echo "  $pkg: $desc -> $p\n";
}
$forks = array_filter($c['autoload']['exclude-from-classmap'] ?? [], fn($p) => str_starts_with($p, 'vendor/'));
if ($forks) {
    echo "\nCORE FORKS via exclude-from-classmap + classmap (your copy REPLACES the new vendor file; port upstream changes: bin/core-forks.php):\n  " . implode("\n  ", $forks) . "\n";
}
if ($dry) { echo "\n(dry run, nothing written)\n"; exit(0); }
file_put_contents($file, json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
echo "\nwritten $file\n";
