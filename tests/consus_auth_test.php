<?php

require_once __DIR__ . '/../web/consus_auth.php';

function auth_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$candidates = consus_auth_candidates();
auth_test_assert($candidates !== [], 'er is minstens één auth-pad');

$web = str_replace('\\', '/', __DIR__ . '/../web/auth.php');
$foundWeb = false;
$siblingBeforeWeb = false;
$homeBeforeWeb = false;
$home = getenv('HOME');
$homePath = is_string($home) && $home !== ''
    ? str_replace('\\', '/', rtrim($home, '/\\') . '/Repositories/auth.php')
    : '';

foreach ($candidates as $path) {
    $normalized = str_replace('\\', '/', $path);
    if ($homePath !== '' && strcasecmp($normalized, $homePath) === 0) {
        auth_test_assert(!$foundWeb, 'gedeelde ~/Repositories/auth.php komt vóór web/auth.php');
        $homeBeforeWeb = true;
    }
    if (str_ends_with(strtolower($normalized), '/web/auth.php')) {
        $foundWeb = true;
        continue;
    }
    if (!$foundWeb && str_ends_with(strtolower($normalized), '/auth.php')) {
        $siblingBeforeWeb = true;
    }
}

auth_test_assert($foundWeb, 'web/auth.php blijft de serverfallback');
auth_test_assert($siblingBeforeWeb, 'een auth.php naast de repo wint van web/auth.php');
auth_test_assert($homePath === '' || $homeBeforeWeb, 'HOME/Repositories/auth.php staat in de lijst');

$source = (string) file_get_contents(__DIR__ . '/../web/consus_auth.php');
auth_test_assert(!str_contains($source, 'password'), 'loader bevat geen geheim');
auth_test_assert(!str_contains($source, '$baseUrl ='), 'loader zet geen baseUrl');

echo "OK\n";
