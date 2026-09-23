<?php
/**
 * Applies deterministic fixes driven by `php -l` output (bin/lint.sh writes it).
 *   php lint-fix.php <lint-output.txt> <project-root>
 *
 * Handles:
 *  - "Optional parameter $x declared before required parameter $y is implicitly treated as a required parameter"
 *    -> drop the "= null" default on that line (param was already effectively required; ?Type keeps null allowed).
 *  - "A method with return type must return a value" (bare `return;` in execute() after the Symfony `: int` fix)
 *    -> `return 1;` (before, Symfony threw TypeError on the null anyway). Printed for review.
 */
[$_, $lintFile, $root] = $argv + [null, null, null];
if (!$lintFile || !$root) { fwrite(STDERR, "usage: php lint-fix.php <lint.txt> <root>\n"); exit(2); }

$fixed = 0;
$seen = [];
foreach (file($lintFile) as $l) {
    if (preg_match('/A method with return type must return a value in (.+) on line (\d+)/', $l, $m)) {
        [, $file, $line] = $m;
        if (isset($seen["$file:$line"])) continue;
        $seen["$file:$line"] = 1;
        if (!str_starts_with($file, '/')) $file = rtrim($root, '/') . '/' . $file;
        $lines = file($file);
        $lines[$line - 1] = preg_replace('/\breturn\s*;/', 'return 1;', $lines[$line - 1], 1, $n);
        if ($n) { file_put_contents($file, implode('', $lines)); $fixed++; echo "review: $file:$line bare return -> return 1;\n"; }
        else fwrite(STDERR, "manual: $file:$line must return a value\n");
        continue;
    }
    if (!preg_match('/Optional parameter \$(\w+) declared before required parameter .* in (.+) on line (\d+)/', $l, $m)) continue;
    [, $param, $file, $line] = $m;
    if (isset($seen["$file:$line:$param"])) continue;
    $seen["$file:$line:$param"] = 1;
    if (!str_starts_with($file, '/')) $file = rtrim($root, '/') . '/' . $file;
    if (!is_file($file)) { fwrite(STDERR, "missing: $file\n"); continue; }
    $lines = file($file);
    $i = (int)$line - 1;
    $new = preg_replace('/(\$' . $param . ')\s*=\s*null\b/i', '$1', $lines[$i] ?? '', 1, $n);
    if ($n) { $lines[$i] = $new; file_put_contents($file, implode('', $lines)); $fixed++; }
    else fwrite(STDERR, "manual: $file:$line \$$param (default is not null)\n");
}
echo "lint-driven fixes applied: $fixed\n";
