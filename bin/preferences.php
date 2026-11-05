<?php
/**
 * After `composer update` (with the old vendor in vendor_orig/): which core classes that YOUR di.xml files
 * override (<preference for="Magento\..."> ) or intercept (<plugin> on <type name="Magento\...">) changed upstream,
 * and did the methods you override / hook change signature or disappear. This is the #1 post-upgrade breakage:
 * a preference class calling parent::__construct() with the old argument list, or a plugin on a method that is gone.
 *
 *   CUSTOM_PATHS="app/code ..." php preferences.php <magento-root> <out-dir>
 * Output: <out>/SUMMARY.txt, lines prefixed CTOR / SIG / GONE / FILE / OK. Nothing in the project is modified.
 * Needs no autoloader: classes are located via vendor/composer/autoload_psr4.php and signatures are read with
 * token_get_all(), so it runs on any PHP >= 8.1.
 */
[$_, $root, $out] = $argv + [null, null, null];
if (!$root || !$out) { fwrite(STDERR, "usage: CUSTOM_PATHS=... php preferences.php <magento-root> <out-dir>\n"); exit(2); }
$root = rtrim(realpath($root), '/');
if (!is_dir("$root/vendor_orig")) { fwrite(STDERR, "$root/vendor_orig missing: snapshot vendor/ before composer update\n"); exit(1); }
@mkdir($out, 0777, true);
$paths = preg_split('/\s+/', trim(getenv('CUSTOM_PATHS') ?: 'app/code app/design'));

// class -> file, via composer's PSR-4 map of a vendor dir (works for vendor and vendor_orig alike)
function locator(string $vendor): Closure
{
    $psr4 = is_file("$vendor/composer/autoload_psr4.php") ? require "$vendor/composer/autoload_psr4.php" : [];
    $classmap = is_file("$vendor/composer/autoload_classmap.php") ? require "$vendor/composer/autoload_classmap.php" : [];
    krsort($psr4); // longest prefix first
    return function (string $class) use ($psr4, $classmap, $vendor): ?string {
        if (isset($classmap[$class]) && str_contains($classmap[$class], '/vendor')) return preg_replace('#^.*?/vendor(_orig)?/#', "$vendor/", $classmap[$class]);
        foreach ($psr4 as $prefix => $dirs) {
            if (!str_starts_with($class, $prefix)) continue;
            foreach ($dirs as $d) {
                $f = preg_replace('#^.*?/vendor(_orig)?/#', "$vendor/", $d) . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                if (is_file($f)) return $f;
            }
        }
        return null;
    };
}
// method name -> normalized signature string, from tokens (no reflection, no autoload)
function signatures(string $file): array
{
    $t = token_get_all(file_get_contents($file)); $sigs = []; $n = count($t);
    for ($i = 0; $i < $n; $i++) {
        if (!is_array($t[$i]) || $t[$i][0] !== T_FUNCTION) continue;
        $j = $i + 1; while (isset($t[$j]) && (is_array($t[$j]) && in_array($t[$j][0], [T_WHITESPACE, T_COMMENT]))) $j++;
        if (!isset($t[$j]) || !is_array($t[$j]) || $t[$j][0] !== T_STRING) continue; // closure
        $name = $t[$j][1]; $sig = ''; $depth = 0;
        for ($k = $j + 1; isset($t[$k]); $k++) {
            $s = is_array($t[$k]) ? $t[$k][1] : $t[$k];
            if ($s === '{' || $s === ';') break;
            if (is_array($t[$k]) && in_array($t[$k][0], [T_COMMENT, T_DOC_COMMENT])) continue;
            $sig .= $s;
        }
        $sigs[$name] = preg_replace('/\s+/', ' ', trim($sig));
    }
    return $sigs;
}
function classOf(string $file): ?string
{
    if (!preg_match('/^namespace\s+([^;]+);/m', $c = file_get_contents($file), $ns)) return null;
    return preg_match('/^(?:abstract\s+|final\s+)?(?:class|interface|trait)\s+(\w+)/m', $c, $cl) ? trim($ns[1]) . '\\' . $cl[1] : null;
}

// 1. collect preferences and plugins from the project's di.xml files
$prefs = []; $plugins = [];
foreach ($paths as $p) {
    if (!is_dir("$root/$p")) continue;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$p", FilesystemIterator::SKIP_DOTS)) as $f) {
        if ($f->getFilename() !== 'di.xml') continue;
        $x = @simplexml_load_file($f->getPathname()); if (!$x) continue;
        $rel = substr($f->getPathname(), strlen($root) + 1);
        foreach ($x->preference as $pr) {
            $for = ltrim((string)$pr['for'], '\\'); $type = ltrim((string)$pr['type'], '\\');
            if (str_starts_with($for, 'Magento\\') && !str_starts_with($type, 'Magento\\')) $prefs[$for][] = [$type, $rel];
        }
        foreach ($x->type as $ty) {
            $target = ltrim((string)$ty['name'], '\\');
            if (!str_starts_with($target, 'Magento\\')) continue;
            foreach ($ty->plugin as $pl) {
                if ((string)$pl['disabled'] === 'true') continue;
                $plugins[$target][] = [ltrim((string)$pl['type'], '\\'), $rel];
            }
        }
    }
}
$old = locator("$root/vendor_orig"); $new = locator("$root/vendor"); $custom = locator("$root/vendor");
$lines = [];
foreach (array_unique(array_merge(array_keys($prefs), array_keys($plugins))) as $core) {
    $fo = $old($core); $fn = $new($core);
    if (!$fo) { $lines[] = "SKIP  $core: not found in vendor_orig (autoload map)"; continue; }
    if (!$fn) { $lines[] = "GONE  $core: class removed upstream  <- " . implode(', ', array_map(fn($x) => $x[0], array_merge($prefs[$core] ?? [], $plugins[$core] ?? []))); continue; }
    if (file_get_contents($fo) === file_get_contents($fn)) { $lines[] = "OK    $core: unchanged upstream"; continue; }
    $so = signatures($fo); $sn = signatures($fn);
    $safe = str_replace('\\', '__', $core);
    exec('git diff --no-index --no-color ' . escapeshellarg($fo) . ' ' . escapeshellarg($fn) . ' > ' . escapeshellarg("$out/$safe.upstream.diff"));
    $changed = array_keys(array_filter($so, fn($sig, $m) => !isset($sn[$m]) || $sn[$m] !== $sig, ARRAY_FILTER_USE_BOTH));
    // which of the changed methods matter: overridden in a preference class, or hooked by a plugin
    foreach ($prefs[$core] ?? [] as [$type, $rel]) {
        $f = findClassFile($root, $paths, $type) ?? $custom($type);
        $mine = $f ? signatures($f) : [];
        if (isset($mine['__construct']) && in_array('__construct', $changed, true)) $lines[] = "CTOR  $core::__construct changed; preference $type ($rel) defines its own constructor -> re-sync parent::__construct() args. Diff: $safe.upstream.diff";
        foreach ($changed as $m) {
            if ($m === '__construct' || !isset($mine[$m])) continue;
            $lines[] = (isset($sn[$m]) ? "SIG   " : "GONE  ") . "$core::$m " . (isset($sn[$m]) ? "signature changed" : "removed upstream") . "; overridden by $type ($rel)" . (isset($sn[$m]) ? "\n        old: {$so[$m]}\n        new: {$sn[$m]}" : '');
        }
    }
    foreach ($plugins[$core] ?? [] as [$type, $rel]) {
        $f = findClassFile($root, $paths, $type) ?? $custom($type);
        foreach ($f ? signatures($f) : [] as $pm => $_) {
            if (!preg_match('/^(before|around|after)(\w+)$/', $pm, $mm)) continue;
            $target = lcfirst($mm[2]);
            if (!isset($sn[$target]) && isset($so[$target])) $lines[] = "GONE  $core::$target removed upstream; plugin $type::$pm ($rel) will never run";
            elseif (in_array($target, $changed, true)) $lines[] = "SIG   $core::$target signature changed; plugin $type::$pm ($rel)\n        old: {$so[$target]}\n        new: {$sn[$target]}";
        }
    }
    $lines[] = "FILE  $core: changed upstream (" . count($changed) . " method signature(s) changed/removed) -> $safe.upstream.diff";
}
function findClassFile(string $root, array $paths, string $class): ?string
{
    // app/code/<Vendor>/<Module>/... = PSR-0 "" on app/code; lib/<Vendor> and composer psr-4 dirs are tried by the caller
    foreach ($paths as $p) {
        $f = "$root/$p/" . str_replace('\\', '/', $class) . '.php';
        if (is_file($f)) return $f;
    }
    return null;
}
usort($lines, fn($a, $b) => [strncmp($a, 'OK', 2) === 0, strncmp($a, 'FILE', 4) === 0, $a] <=> [strncmp($b, 'OK', 2) === 0, strncmp($b, 'FILE', 4) === 0, $b]);
file_put_contents("$out/SUMMARY.txt", implode("\n", $lines) . "\n");
$n = fn($p) => count(array_filter($lines, fn($l) => str_starts_with($l, $p)));
printf("preferences: %d core classes, plugins: %d core classes | CTOR %d  SIG %d  GONE %d  FILE %d  OK %d  SKIP %d -> %s/SUMMARY.txt\n",
    count($prefs), count($plugins), $n('CTOR'), $n('SIG'), $n('GONE'), $n('FILE'), $n('OK'), $n('SKIP'), $out);
foreach ($lines as $l) if (!str_starts_with($l, 'OK') && !str_starts_with($l, 'FILE')) echo $l, "\n";
