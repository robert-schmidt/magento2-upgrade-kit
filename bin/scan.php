<?php
/**
 * Pattern scanner/fixer. Runs on any PHP >= 8.1 (host or container), no deps.
 *
 *   php scan.php [--fix] [--only=id1,id2] [--json=out.json] rules/a.php[,rules/b.php] <path> [<path>...]
 *
 * Rule file returns a list of:
 *   ['id'=>, 'level'=>'fatal|deprecated|behavior|review', 'files'=>'/\.php$/', 'find'=>PCRE,
 *    'note'=>'...', 'fix'=>replacement|callable (optional), 'skipComments'=>true,
 *    'when'=>PCRE the whole file must match for the rule to apply (optional)]
 * 'fix' is applied only with --fix, and only for rules that define it.
 * Exit code: 0 = no findings (after fixes), 1 = findings remain.
 */
$opts = ['fix' => false, 'only' => null, 'json' => null];
$args = array_slice($argv, 1);
while ($args && str_starts_with($args[0], '--')) {
    $a = array_shift($args);
    if ($a === '--fix') $opts['fix'] = true;
    elseif (str_starts_with($a, '--only=')) $opts['only'] = explode(',', substr($a, 7));
    elseif (str_starts_with($a, '--json=')) $opts['json'] = substr($a, 7);
}
if (count($args) < 2) {
    fwrite(STDERR, "usage: php scan.php [--fix] [--only=ids] [--json=file] rules.php[,rules2.php] <path>...\n");
    exit(2);
}
$rules = [];
foreach (explode(',', array_shift($args)) as $rf) {
    foreach (require $rf as $r) {
        $r += ['level' => 'review', 'files' => '/\.(php|phtml)$/', 'skipComments' => true, 'fix' => null, 'note' => ''];
        $r['source'] = basename($rf);
        if (!$opts['only'] || in_array($r['id'], $opts['only'], true)) $rules[] = $r;
    }
}

// Blank out comments (keep offsets/newlines) so patterns don't hit docblocks.
function stripComments(string $code): string
{
    if (!str_contains($code, '<?')) return $code;
    $out = '';
    foreach (@token_get_all($code) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            $out .= preg_replace('/[^\n]/', ' ', $t[1]);
        } else {
            $out .= is_array($t) ? $t[1] : $t;
        }
    }
    return $out;
}

$findings = [];
$fixedFiles = 0;
foreach ($args as $root) {
    if (!file_exists($root)) { fwrite(STDERR, "skip missing path: $root\n"); continue; }
    $it = is_dir($root)
        ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS))
        : [new SplFileInfo($root)];
    foreach ($it as $f) {
        $path = $f->getPathname();
        if (preg_match('#/(Test|tests|\.git|node_modules)/#', $path)) continue; // ponytail: tests skipped; drop this line to include them
        $applicable = array_filter($rules, fn($r) => preg_match($r['files'], $path));
        if (!$applicable) continue;
        $code = file_get_contents($path);
        $orig = $code;
        foreach ($applicable as $r) {
            if (!empty($r['when']) && !preg_match($r['when'], $code)) continue;
            if ($opts['fix'] && $r['fix'] !== null) {
                $code = is_callable($r['fix'])
                    ? preg_replace_callback($r['find'], $r['fix'], $code)
                    : preg_replace($r['find'], $r['fix'], $code);
            }
            $hay = $r['skipComments'] ? stripComments($code) : $code;
            if (!preg_match_all($r['find'], $hay, $m, PREG_OFFSET_CAPTURE)) continue;
            foreach ($m[0] as [$txt, $off]) {
                $line = substr_count($hay, "\n", 0, $off) + 1;
                $src = trim(explode("\n", $code)[$line - 1] ?? '');
                $findings[] = ['rule' => $r['id'], 'level' => $r['level'], 'file' => $path, 'line' => $line,
                    'code' => mb_strimwidth($src, 0, 160, '…'), 'note' => $r['note'], 'autofix' => $r['fix'] !== null];
            }
        }
        if ($code !== $orig) { file_put_contents($path, $code); $fixedFiles++; }
    }
}

$byRule = [];
foreach ($findings as $x) {
    printf("%-10s %-28s %s:%d  %s\n", $x['level'], $x['rule'], $x['file'], $x['line'], $x['code']);
    $byRule[$x['rule']] ??= [0, $x['level'], $x['note'], $x['autofix']];
    $byRule[$x['rule']][0]++;
}
echo "\n==== summary ====\n";
ksort($byRule);
foreach ($byRule as $id => [$n, $lvl, $note, $af]) printf("%5d  %-10s %-28s %s%s\n", $n, $lvl, $id, $af ? '[autofix] ' : '', $note);
if ($opts['fix']) echo "files modified: $fixedFiles\n";
if ($opts['json']) file_put_contents($opts['json'], json_encode($findings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
exit($findings ? 1 : 0);
