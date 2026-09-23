<?php
/**
 * Keeps app/etc/config.php consistent after a version bump (run after composer update).
 *   php config-php.php <magento-root> [--dry-run]
 *  - removes modules that no longer exist in the codebase (e.g. Magento_Elasticsearch7 in 2.4.8)
 *  - adds NEW modules as disabled (0) when any module they depend on (<sequence>) is disabled
 *    (e.g. Magento_Elasticsearch8 while Magento_Elasticsearch is off: this project runs without a search engine)
 *    Everything else new is left out, so setup:upgrade enables it as usual.
 */
[$_, $root] = $argv + [null, null];
$dry = in_array('--dry-run', $argv, true);
if (!$root) { fwrite(STDERR, "usage: php config-php.php <magento-root> [--dry-run]\n"); exit(2); }
$file = "$root/app/etc/config.php";
$config = require $file;
$modules = $config['modules'];

$deps = [];
$scan = array_merge(glob("$root/vendor/*/*/etc/module.xml") ?: [], glob("$root/app/code/*/*/etc/module.xml") ?: []);
foreach ($scan as $xml) {
    $x = @simplexml_load_file($xml);
    if (!$x || !isset($x->module['name'])) continue;
    $name = (string)$x->module['name'];
    $deps[$name] = [];
    foreach ($x->module->sequence->module ?? [] as $m) $deps[$name][] = (string)$m['name'];
}
if (count($deps) < 100) { fwrite(STDERR, "found only " . count($deps) . " modules: is vendor/ installed?\n"); exit(1); }

foreach (array_keys($modules) as $m) {
    if (!isset($deps[$m])) { echo "remove  $m (no longer in codebase)\n"; unset($modules[$m]); }
}
// new modules: disable if a dependency is disabled; repeat until stable (chains like A -> B -> disabled)
do {
    $added = false;
    foreach ($deps as $m => $seq) {
        if (array_key_exists($m, $modules)) continue;
        $off = array_filter($seq, fn($d) => ($modules[$d] ?? 1) === 0);
        if ($off) { $modules[$m] = 0; $added = true; echo "add     $m => 0 (depends on disabled " . implode(', ', $off) . ")\n"; }
    }
} while ($added);
$new = array_diff(array_keys($deps), array_keys($modules));
if ($new) echo "new, will be enabled by setup:upgrade: " . implode(', ', $new) . "\n";

if ($dry) { echo "(dry run)\n"; exit(0); }
$config['modules'] = $modules;
file_put_contents($file, "<?php\nreturn " . var_export($config, true) . ";\n");
echo "written $file (var_export format; bin/magento app:config:dump rewrites it in Magento's style)\n";
