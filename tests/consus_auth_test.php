<?php

require_once __DIR__ . '/../web/consus_auth.php';

function auth_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

putenv('CONSUS_AUTH_FILE');

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

$authFile = sys_get_temp_dir() . '/consus-auth-globals-' . getmypid() . '.php';
$expectedUsers = ['planner@example.test', 'buyer@example.test'];
$expectedAuthList = [
    'Test' => ['mode' => 'basic', 'user' => 'svc', 'pass' => 'stub'],
];
$expectedAuth = ['mode' => 'basic', 'user' => 'svc', 'pass' => 'stub'];
$stub = <<<'PHP'
<?php
$baseUrl = 'https://bc.example.test/BC';
$allowedUsers = ['planner@example.test', 'buyer@example.test'];
$auth_list = [
    'Test' => ['mode' => 'basic', 'user' => 'svc', 'pass' => 'stub'],
];
$environment = 'Test';
$auth = ['mode' => 'basic', 'user' => 'svc', 'pass' => 'stub'];
$primaryEnvironment = 'Test';
PHP;

try {
    auth_test_assert(file_put_contents($authFile, $stub) !== false, 'tijdelijk auth.php schrijven');
    putenv('CONSUS_AUTH_FILE=' . $authFile);

    $overridden = consus_auth_candidates();
    auth_test_assert(($overridden[0] ?? '') === $authFile, 'CONSUS_AUTH_FILE wijst naar het testbestand');

    foreach (['baseUrl', 'allowedUsers', 'auth_list', 'environment', 'auth', 'primaryEnvironment'] as $name) {
        unset($GLOBALS[$name]);
    }

    consus_load_auth();

    auth_test_assert(
        ($GLOBALS['allowedUsers'] ?? null) === $expectedUsers,
        'allowedUsers uit auth.php blijft globaal'
    );
    auth_test_assert(($GLOBALS['baseUrl'] ?? null) === 'https://bc.example.test/BC', 'baseUrl uit auth.php blijft globaal');
    auth_test_assert(($GLOBALS['auth_list'] ?? null) === $expectedAuthList, 'auth_list uit auth.php blijft globaal');
    auth_test_assert(($GLOBALS['environment'] ?? null) === 'Test', 'environment uit auth.php blijft globaal');
    auth_test_assert(($GLOBALS['auth'] ?? null) === $expectedAuth, 'auth uit auth.php blijft globaal');
    auth_test_assert(($GLOBALS['primaryEnvironment'] ?? null) === 'Test', 'primaryEnvironment uit auth.php blijft globaal');
} finally {
    putenv('CONSUS_AUTH_FILE');
    if (is_file($authFile)) {
        unlink($authFile);
    }
}

echo "OK\n";
