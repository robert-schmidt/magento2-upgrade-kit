<?php
/**
 * Ports project customizations of CORE files onto the new vendor version with a 3-way merge.
 * Run from the Magento root AFTER `composer update`, with the pre-update vendor copied to vendor_orig/
 * (steps/20 and 30 do that).
 *
 *   php core-forks.php <magento-root> <out-dir>
 *
 * Covers two kinds of customization:
 *  1. classmap forks: composer.json "exclude-from-classmap": ["vendor/x/File.php"] + "classmap": ["lib/forks/.../File.php"]
 *     -> fork = your file, old = vendor_orig/x/File.php, new = vendor/x/File.php
 *  2. files marshalled from magento/magento2-base (setup/, pub/errors/, app/*.php, lib/web ...) that were edited in git
 *     -> fork = `git show HEAD:<path>`, old = vendor_orig/magento/magento2-base/<src>, new = vendor/.../<src>
 * For each: writes <out>/<name>.upstream.diff and <out>/<name>.merged (git merge-file --diff3; conflicts marked).
 * Nothing in the project is modified: copy the .merged file over your fork after review.
 */
[$_, $root, $out] = $argv + [null, null, null];
if (!$root || !$out) { fwrite(STDERR, "usage: php core-forks.php <magento-root> <out-dir>\n"); exit(2); }
$root = rtrim(realpath($root), '/');
if (!is_dir("$root/vendor_orig")) { fwrite(STDERR, "$root/vendor_orig missing: copy vendor/ there before composer update\n"); exit(1); }
@mkdir($out, 0777, true);

function classOf(string $file): ?string
{
    if (!is_file($file)) return null;
    $ns = ''; $t = token_get_all(file_get_contents($file));
    foreach ($t as $i => $tok) {
        if (!is_array($tok)) continue;
        if ($tok[0] === T_NAMESPACE) {
            for ($j = $i + 1; isset($t[$j]) && $t[$j] !== ';' && $t[$j] !== '{'; $j++) $ns .= is_array($t[$j]) ? trim($t[$j][1]) : '';
        }
        if (in_array($tok[0], [T_CLASS, T_INTERFACE, T_TRAIT], true)) {
            for ($j = $i + 1; isset($t[$j]); $j++) if (is_array($t[$j]) && $t[$j][0] === T_STRING) return ltrim("$ns\\{$t[$j][1]}", '\\');
        }
    }
    return null;
}

function merge(string $name, string $forkCode, string $old, string $new, string $out): string
{
    if (!is_file($old)) return "SKIP  $name: not in vendor_orig";
    if (!is_file($new)) return "GONE  $name: removed upstream in the new version -> fork is now dead code or must be re-homed";
    $o = file_get_contents($old); $n = file_get_contents($new);
    if ($o === $n) return "OK    $name: upstream unchanged";
    $safe = str_replace('/', '__', $name);
    $tmp = tempnam(sys_get_temp_dir(), 'fork');
    file_put_contents($tmp, $forkCode);
    exec('git diff --no-index --no-color ' . escapeshellarg($old) . ' ' . escapeshellarg($new) . ' > ' . escapeshellarg("$out/$safe.upstream.diff"));
    exec('git merge-file -p --diff3 ' . escapeshellarg($tmp) . ' ' . escapeshellarg($old) . ' ' . escapeshellarg($new) . ' > ' . escapeshellarg("$out/$safe.merged"), $_, $rc);
    unlink($tmp);
    return $rc === 0 ? "MERGE $name: upstream changed, merged cleanly -> review $safe.merged"
                     : "CONFL $name: upstream changed, $rc conflict(s) -> resolve $safe.merged";
}

$c = json_decode(file_get_contents("$root/composer.json"), true);
$lines = [];

// 1. classmap forks, paired by class name
$forkByClass = [];
foreach ($c['autoload']['classmap'] ?? [] as $p) if ($cls = classOf("$root/$p")) $forkByClass[$cls] = $p;
foreach ($c['autoload']['exclude-from-classmap'] ?? [] as $p) {
    if (!str_starts_with($p, 'vendor/')) continue;
    $rel = substr($p, 7);
    $cls = classOf("$root/vendor_orig/$rel") ?? classOf("$root/vendor/$rel");
    $fork = $cls ? ($forkByClass[$cls] ?? null) : null;
    if (!$fork) { $lines[] = "????  $p: excluded from classmap but no replacement class found in classmap"; continue; }
    $lines[] = merge($rel, file_get_contents("$root/$fork"), "$root/vendor_orig/$rel", "$root/vendor/$rel", $out) . "  (fork: $fork)";
}

// 2. edited marshalled magento2-base files
$baseJson = "$root/vendor_orig/magento/magento2-base/composer.json";
$git = 'git -C ' . escapeshellarg($root);
if (is_file($baseJson)) {
    foreach (json_decode(file_get_contents($baseJson), true)['extra']['map'] ?? [] as [$src, $dest]) {
        $srcAbs = "$root/vendor_orig/magento/magento2-base/$src";
        $files = is_dir($srcAbs)
            ? iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcAbs, FilesystemIterator::SKIP_DOTS)))
            : [new SplFileInfo($srcAbs)];
        foreach ($files as $f) {
            if (!$f->isFile()) continue;
            $relSrc = ltrim(substr($f->getPathname(), strlen("$root/vendor_orig/magento/magento2-base/")), '/');
            $relDest = $dest . substr($relSrc, strlen($src));
            // committed copy if this is a git checkout, else the working file (a container volume is not a checkout)
            $head = shell_exec("$git show HEAD:./" . escapeshellarg($relDest) . ' 2>/dev/null');
            if (($head === null || $head === '') && is_file("$root/$relDest")) $head = file_get_contents("$root/$relDest");
            if ($head === null || $head === '' || $head === file_get_contents($f->getPathname())) continue; // not tracked or not customized
            $lines[] = merge($relDest, $head, $f->getPathname(), "$root/vendor/magento/magento2-base/$relSrc", $out)
                . "  (marshalled from magento2-base: composer install with magento-force=override re-deploys vendor's copy over your edit)";
        }
    }
}

sort($lines);
file_put_contents("$out/SUMMARY.txt", implode("\n", $lines) . "\n");
echo implode("\n", $lines), "\n\nfull output: $out\n";
