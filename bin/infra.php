<?php
/**
 * Rewrites build/runtime files for a target PHP / Magento:
 * Dockerfiles, docker/, CI configs, shell scripts, docker-compose, and app/etc/env.php.
 *   INFRA_PATHS="Dockerfile .docker ..." MAGENTO_ROOT=... php infra.php <repo-root> <8.4|8.5> <2.4.8|2.4.9> [--dry-run]
 * INFRA_PATHS are relative to <repo-root> (defaults in kit.env). Prints every change; review with git diff.
 */
[$_, $repo, $php, $mage] = $argv + [null, null, null, null];
$dry = in_array('--dry-run', $argv, true);
if (!$repo || !preg_match('/^8\.\d$/', (string)$php) || !in_array($mage, ['2.4.8', '2.4.9'], true)) {
    fwrite(STDERR, "usage: php infra.php <repo-root> <8.4|8.5> <2.4.8|2.4.9> [--dry-run]\n"); exit(2);
}
$repo = rtrim($repo, '/');
$pv = preg_quote($php, '/');

$rules = [
    // php8.3 / php-fpm8.3 / php8.3-fpm.sock / /etc/php/8.3 / /usr/bin/php8.3  -> target (any older 8.x)
    ['php version refs', '/\b(php(?:-fpm)?)8\.[0-4]\b(?!\.\d)/', fn($m) => $m[1] . $php, '*'],
    // CI: php-version: '8.3' / PHP_VERSION=8.3 / image: php:8.3-fpm
    ['php version (CI/env)', '/\b(php[-_]?version\s*[:=]\s*[\'"]?|PHP_VERSION\s*[:=]\s*[\'"]?|image:\s*[\w.\/-]*php:)8\.[0-4]\b(?!\.\d)/i', fn($m) => $m[1] . $php, '*'],
    ['php etc path', '#(/php/)8\.[0-4]\b#', fn($m) => $m[1] . $php, '*'],
    // composer pinned to an old 2.x -> latest 2.x (2.4.8 needs >=2.9.3, 2.4.9 lists 2.10)
    ['composer version', '/(composer-setup\.php|installer \| php --[^\n]*?)--version=2\.\d+\.\d+/', fn($m) => $m[1] . '--2', 'Dockerfile'],
    ['composer version', '/--filename=composer --version=2\.\d+\.\d+/', fn() => '--filename=composer --2', 'Dockerfile'],
];
if (version_compare($php, '8.5', '>=')) {
    // opcache is always built in since 8.5: there is no php8.5-opcache package, and loading it warns
    $rules[] = ['no opcache pkg on 8.5', '/[ \t]+php8\.5-opcache\b/', fn() => '', 'Dockerfile'];
    $rules[] = ['no zend_extension=opcache on 8.5', '/^([ \t]*)(zend_extension\s*=\s*\S*opcache\S*)/m', fn($m) => $m[1] . '; ' . $m[2] . ' ; built in since PHP 8.5', 'ini'];
    // PDO MySQL attribute integers shifted in 8.5 (8.3/8.4: SSL_CA=1009, VERIFY=1014; 8.5: 1008, 1013, and 1014 = LOCAL_INFILE_DIRECTORY)
    $rules[] = ['env.php PDO attr SSL_CA', "/(['\"]driver_options['\"]\s*=>\s*\[[^\]]*?)\b1009(\s*=>)/", fn($m) => $m[1] . '\\Pdo\\Mysql::ATTR_SSL_CA' . $m[2], 'env'];
    $rules[] = ['env.php PDO attr VERIFY', "/(['\"]driver_options['\"]\s*=>\s*\[[^\]]*?)\b1014(\s*=>)/", fn($m) => $m[1] . '\\Pdo\\Mysql::ATTR_SSL_VERIFY_SERVER_CERT' . $m[2], 'env'];
}
if ($mage === '2.4.9') {
    // 2.4.9 (symfony/cache) silently falls back to var/cache/symfony files for class-name backends (magento/magento2#41213)
    $rules[] = ['2.4.9 cache backend alias', "/(['\"]backend['\"]\s*=>\s*)['\"](?:\\\\?\\\\?Magento\\\\{1,2}Framework\\\\{1,2}Cache\\\\{1,2}Backend\\\\{1,2}Redis|Cm_Cache_Backend_Redis)['\"]/", fn($m) => $m[1] . "'redis'", 'env'];
}
// docker-compose MySQL 8.0 -> 8.4 (managed DBs are upgraded separately, see README)
$rules[] = ['mysql image', '/image:\s*mysql:8\.0[\w.-]*/', fn() => 'image: mysql:8.4', 'compose'];
$rules[] = ['mysql 8.4 flags', '/--default-authentication-plugin=mysql_native_password/', fn() => '--restrict-fk-on-non-standard-key=OFF', 'compose'];

$files = [];
$envPhp = getenv('MAGENTO_ENV_PHP') ?: rtrim(getenv('MAGENTO_ROOT') ?: $repo, '/') . '/app/etc/env.php';
$infraPaths = preg_split('/\s+/', trim(getenv('INFRA_PATHS') ?: 'Dockerfile docker .docker pipelines .github .gitlab-ci.yml bitbucket-pipelines.yml azure-pipelines.yml bin docker-compose.yml docker-compose.override.yml docker-compose.dev.yml'));
foreach (array_merge(array_map(fn($p) => "$repo/$p", $infraPaths), [$envPhp]) as $abs) {
    if (is_file($abs)) $files[] = $abs;
    elseif (is_dir($abs)) foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($abs, FilesystemIterator::SKIP_DOTS)) as $f) {
        if ($f->isFile() && preg_match('/(\.(sh|ya?ml|conf|ini|env)|Dockerfile[^\/]*)$/', $f->getFilename())) $files[] = $f->getPathname();
    }
}
foreach ($repo === '' ? [] : glob("$repo/Dockerfile*") as $f) $files[] = $f;
$files = array_unique($files);

$kind = fn(string $f) => match (true) {
    str_contains(basename($f), 'Dockerfile') => 'Dockerfile',
    str_ends_with($f, '.ini') => 'ini',
    str_contains($f, 'docker-compose') => 'compose',
    $f === $envPhp || (bool)preg_match('#(env|config)[^/]*\.sh$#', $f) => 'env', // env.php and scripts that generate it
    default => 'other',
};
$changed = 0;
foreach ($files as $f) {
    $code = file_get_contents($f); $orig = $code;
    foreach ($rules as [$name, $re, $fn, $scope]) {
        if ($scope !== '*' && $scope !== $kind($f)) continue;
        $code = preg_replace_callback($re, function ($m) use ($fn, $name, $f, $repo) {
            $new = $fn($m);
            printf("%-34s %s\n    - %s\n    + %s\n", $name, substr($f, strlen($repo) + 1), trim($m[0]), trim($new));
            return $new;
        }, $code);
    }
    if ($code !== $orig) { $changed++; if (!$dry) file_put_contents($f, $code); }
}
echo "\nfiles " . ($dry ? 'that would change' : 'changed') . ": $changed\n";
echo "Not automated (check by hand): PECL extensions built from source for PHP $php, managed DB server parameters, CI config outside this repo.\n";
