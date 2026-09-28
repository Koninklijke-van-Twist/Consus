<?php

/**
 * Normaliseert environment-input naar een unieke lijst.
 */
function auth_normalize_environment_list(mixed $value): array
{
    $items = [];

    if (is_array($value)) {
        $items = $value;
    } elseif (is_string($value)) {
        $items = preg_split('/[\s,;]+/', $value) ?: [];
    } elseif ($value !== null) {
        $items = [(string) $value];
    }

    $normalized = [];
    $seen = [];
    foreach ($items as $item) {
        $environment = trim((string) $item);
        if ($environment === '' || isset($seen[$environment])) {
            continue;
        }

        $seen[$environment] = true;
        $normalized[] = $environment;
    }

    return $normalized;
}

/**
 * Laadt odata.php indien nodig en checkt of Mímir actief is ($mimirApi gezet).
 */
function auth_ensure_odata_loaded(): void
{
    if (function_exists('odata_mimir_enabled')) {
        return;
    }
    $odataPath = __DIR__ . '/odata.php';
    if (is_file($odataPath)) {
        require_once $odataPath;
    }
}

function auth_mimir_enabled(): bool
{
    auth_ensure_odata_loaded();
    return function_exists('odata_mimir_enabled') && odata_mimir_enabled();
}

/**
 * Mímir nog proberen in dit proces. Na de eerste fout geldt de directe BC-route.
 */
function auth_mimir_live(): bool
{
    if (!auth_mimir_enabled()) {
        return false;
    }
    if (function_exists('odata_mimir_circuit_open') && odata_mimir_circuit_open()) {
        return false;
    }
    return true;
}


/**
 * Geeft de actieve environments terug op basis van config.
 */
function auth_get_active_environments(): array
{
    global $auth_list, $environment;

    if (function_exists('odata_mimir_circuit_open') && odata_mimir_circuit_open() && function_exists('odata_bc_restore_fallback_credentials')) {
        odata_bc_restore_fallback_credentials();
    }

    $configured = [];
    if (isset($environment)) {
        $configured = auth_normalize_environment_list($environment);
    }

    $known = is_array($auth_list ?? null) ? array_keys($auth_list) : [];
    if ($configured !== []) {
        $knownMap = array_fill_keys($known, true);
        $configured = array_values(array_filter($configured, static function (string $item) use ($knownMap): bool {
            return isset($knownMap[$item]);
        }));
    }

    if ($configured === [] && $known !== []) {
        return [(string) $known[0]];
    }

    if ($configured !== []) {
        return $configured;
    }

    // Geen lokale BC-config: bij Mímir environments afleiden uit companies.php.
    // Na een Mímir-fout in dit proces niet opnieuw proberen; de aanroeper valt terug op BC.
    if (auth_mimir_live()) {
        $cached = $GLOBALS['demeter_active_environments'] ?? null;
        if (is_array($cached) && $cached !== []) {
            return array_values(array_map('strval', $cached));
        }
        try {
            auth_ensure_odata_loaded();
            if (!function_exists('odata_mimir_companies_as_rows')) {
                return [];
            }
            $rows = odata_mimir_companies_as_rows(null);
            $envs = [];
            $seen = [];
            foreach ($rows as $row) {
                $env = trim((string) ($row['environment'] ?? ''));
                if ($env === '' || isset($seen[$env])) {
                    continue;
                }
                $seen[$env] = true;
                $envs[] = $env;
            }
            return $envs;
        } catch (Throwable $ignored) {
            return [];
        }
    }

    // Fallback: geen $auth_list, wel een primaire $environment. Die hoort dan in de companylijst.
    if (function_exists('odata_mimir_circuit_open') && odata_mimir_circuit_open()) {
        $fallbackEnv = isset($environment) ? auth_normalize_environment_list($environment) : [];
        foreach ($fallbackEnv as $candidate) {
            if ($candidate !== '' && strcasecmp($candidate, 'mimir') !== 0) {
                return [$candidate];
            }
        }
    }

    return $configured;
}

/**
 * Geeft de primaire environment terug.
 */
function auth_get_primary_environment(): string
{
    $active = auth_get_active_environments();
    return (string) ($active[0] ?? '');
}

/**
 * Circuit open: directe BC-route, niet meer de Mímir-sentinel.
 */
function auth_bc_fallback_active(): bool
{
    if (!auth_mimir_enabled()) {
        return false;
    }
    return function_exists('odata_mimir_circuit_open') && odata_mimir_circuit_open();
}

/**
 * $auth mag de primaire environment dekken, of elke environment als $auth_list leeg is.
 * Een ander environment met gevulde $auth_list niet.
 */
function auth_environment_may_use_primary_auth(string $environmentKey): bool
{
    global $auth_list, $environment;
    $list = is_array($auth_list ?? null) ? $auth_list : [];
    $populated = false;
    foreach ($list as $entry) {
        if (function_exists('odata_auth_is_usable') && odata_auth_is_usable($entry)) {
            $populated = true;
            break;
        }
    }
    if (!$populated) {
        return true;
    }
    $primary = isset($environment) && is_string($environment) ? trim($environment) : '';
    if (($primary === '' || strcasecmp($primary, 'mimir') === 0) && function_exists('odata_bc_primary_environment_name')) {
        $resolved = odata_bc_primary_environment_name();
        $primary = is_string($resolved) ? $resolved : '';
    }
    if ($primary === '' || strcasecmp($primary, 'mimir') === 0) {
        return false;
    }
    return strcasecmp($primary, trim($environmentKey)) === 0;
}

/**
 * Geeft auth-configuratie voor een environment.
 */
function auth_get_auth_for_environment(string $environment): array
{
    global $auth_list;

    $environmentKey = trim($environment);
    $list = is_array($auth_list ?? null) ? $auth_list : [];

    if ($environmentKey === '') {
        if (auth_mimir_live()) {
            return [];
        }
        throw new RuntimeException('Environment ontbreekt in auth-configuratie.');
    }

    $entry = $list[$environmentKey] ?? null;
    if (!is_array($entry)) {
        // Mímir-modus zonder BC-auth: leftover callers krijgen lege auth i.p.v. exception.
        // Tijdens de BC-fallback dekt $auth een lege $auth_list of de primaire environment.
        // Een ander environment terwijl $auth_list wél gevuld is, blijft een fout.
        if (auth_mimir_live()) {
            return [];
        }
        if (auth_bc_fallback_active() && auth_environment_may_use_primary_auth($environmentKey)) {
            if (function_exists('odata_bc_restore_fallback_credentials')) {
                odata_bc_restore_fallback_credentials();
            }
            global $auth;
            if (function_exists('odata_auth_is_usable') && odata_auth_is_usable($auth ?? null)) {
                return $auth;
            }
        }
        throw new RuntimeException('Geen auth-configuratie gevonden voor environment: ' . $environmentKey);
    }

    return $entry;
}

/**
 * Stabiele environment-fragment string voor logging/cache keys.
 */
function auth_get_environment_key_fragment(): string
{
    $active = auth_get_active_environments();
    if ($active === []) {
        return '';
    }

    return implode(',', $active);
}

/**
 * OData URLs voor companies binnen een environment.
 */
function auth_build_companies_urls(string $environment): array
{
    global $baseUrl, $base;

    $root = '';
    if (function_exists('odata_bc_base_url')) {
        $resolved = odata_bc_base_url();
        if (is_string($resolved)) {
            $root = trim($resolved);
        }
    }
    if ($root === '' || stripos($root, 'mimir.invalid') !== false) {
        $root = trim((string) ($baseUrl ?? ''));
    }
    if ($root === '' || stripos($root, 'mimir.invalid') !== false) {
        $root = trim((string) ($base ?? ''));
    }
    if ($root === '' || stripos($root, 'mimir.invalid') !== false) {
        throw new RuntimeException('baseUrl ontbreekt in auth-configuratie.');
    }

    $prefix = rtrim($root, '/') . '/' . rawurlencode($environment) . '/ODataV4/';

    return [
        $prefix . 'Companies?$select=Name',
        $prefix . 'Company?$select=Name',
        $prefix . 'Companies',
        $prefix . 'Company',
    ];
}

/**
 * Company-discovery verandert zelden → lang in OData-cache houden.
 */
const AUTH_COMPANIES_ODATA_TTL = 2592000; // 30 dagen

/**
 * Haalt companies op via OData helper of cURL fallback.
 */
function auth_fetch_companies_for_environment(string $environment, int $ttlSeconds = AUTH_COMPANIES_ODATA_TTL): array
{
    $auth = auth_get_auth_for_environment($environment);
    $urls = auth_build_companies_urls($environment);

    $rows = [];
    $lastErrorMessage = '';

    foreach ($urls as $url) {
        try {
            if (function_exists('odata_get_all')) {
                $rows = odata_get_all($url, $auth, $ttlSeconds);
            } else {
                $rows = auth_fetch_companies_for_environment_via_curl($url, $auth);
            }

            if (is_array($rows) && $rows !== []) {
                break;
            }
        } catch (Throwable $error) {
            $lastErrorMessage = $error->getMessage();
        }
    }

    if (!is_array($rows) || $rows === []) {
        $details = $lastErrorMessage !== '' ? (': ' . $lastErrorMessage) : '';
        throw new RuntimeException('Geen bedrijven gevonden voor environment ' . $environment . $details);
    }

    $companies = [];
    $seen = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $name = trim((string) ($row['Name'] ?? $row['Display_Name'] ?? ''));
        if ($name === '') {
            continue;
        }

        $lowerName = strtolower($name);
        if (isset($seen[$lowerName])) {
            continue;
        }

        $seen[$lowerName] = true;
        $companies[] = $name;
    }

    natcasesort($companies);
    return array_values($companies);
}

/**
 * cURL fallback voor company discovery als odata.php nog niet geladen is.
 */
function auth_fetch_companies_for_environment_via_curl(string $url, array $auth): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Accept-Language: nl-NL,nl;q=0.9,en;q=0.8',
        ],
    ]);

    if (($auth['mode'] ?? '') === 'basic') {
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, (string) ($auth['user'] ?? '') . ':' . (string) ($auth['pass'] ?? ''));
    } elseif (($auth['mode'] ?? '') === 'ntlm') {
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_NTLM);
        curl_setopt($ch, CURLOPT_USERPWD, (string) ($auth['user'] ?? '') . ':' . (string) ($auth['pass'] ?? ''));
    }

    $raw = curl_exec($ch);
    if ($raw === false) {
        $error = curl_error($ch);
        throw new RuntimeException('cURL fout bij ophalen companies: ' . $error);
    }

    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($code < 200 || $code >= 300) {
        throw new RuntimeException('HTTP ' . $code . ' bij ophalen companies.');
    }

    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Ongeldige JSON response bij ophalen companies.');
    }

    $rows = $decoded['value'] ?? null;
    return is_array($rows) ? $rows : [];
}

/**
 * Company-discovery via Mímir companies.php (geen BC auth_list/baseUrl).
 */
function auth_discover_companies_via_mimir(): array
{
    auth_ensure_odata_loaded();
    if (!function_exists('odata_mimir_companies_as_rows') && !function_exists('odata_mimir_companies_as_rows_impl')) {
        throw new RuntimeException('Mímir company-discovery vereist odata.php.');
    }

    // Impl, niet de publieke wrapper: die vangt de fout zelf. De aanroeper
    // (auth_discover_companies_across_active_environments) valt dan terug op BC.
    if (function_exists('odata_mimir_companies_as_rows_impl')) {
        $rows = odata_mimir_companies_as_rows_impl(null);
    } else {
        $rows = odata_mimir_companies_as_rows(null);
    }
    $companiesByEnvironment = [];
    $companyToEnvironment = [];
    $duplicates = [];

    foreach ($rows as $row) {
        $companyName = trim((string) ($row['Name'] ?? ''));
        $environment = trim((string) ($row['environment'] ?? ''));
        if ($companyName === '' || $environment === '') {
            continue;
        }

        if (!isset($companiesByEnvironment[$environment])) {
            $companiesByEnvironment[$environment] = [];
        }
        $companiesByEnvironment[$environment][] = $companyName;

        $normalizedCompany = strtolower($companyName);
        if (!isset($companyToEnvironment[$normalizedCompany])) {
            $companyToEnvironment[$normalizedCompany] = [
                'name' => $companyName,
                'environment' => $environment,
            ];
            continue;
        }

        $existingEnvironment = (string) ($companyToEnvironment[$normalizedCompany]['environment'] ?? '');
        if ($existingEnvironment === $environment) {
            continue;
        }

        if (!isset($duplicates[$normalizedCompany])) {
            $duplicates[$normalizedCompany] = [
                'name' => (string) ($companyToEnvironment[$normalizedCompany]['name'] ?? $companyName),
                'environments' => [$existingEnvironment],
            ];
        }
        $duplicates[$normalizedCompany]['environments'][] = $environment;
    }

    if ($duplicates !== []) {
        $duplicateMessages = [];
        foreach ($duplicates as $duplicate) {
            $name = trim((string) ($duplicate['name'] ?? 'onbekend'));
            $envs = is_array($duplicate['environments'] ?? null) ? $duplicate['environments'] : [];
            $envs = array_values(array_unique(array_filter(array_map('strval', $envs), static function (string $env): bool {
                return trim($env) !== '';
            })));
            sort($envs, SORT_NATURAL | SORT_FLAG_CASE);
            $duplicateMessages[] = $name . ' [' . implode(', ', $envs) . ']';
        }
        throw new RuntimeException(
            'Bedrijfsnaam-overlap tussen Mímir-environments. Conflicten: '
            . implode('; ', $duplicateMessages)
        );
    }

    foreach ($companiesByEnvironment as $env => $list) {
        $unique = [];
        $seen = [];
        foreach ($list as $companyName) {
            $key = strtolower(trim((string) $companyName));
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = (string) $companyName;
        }
        natcasesort($unique);
        $companiesByEnvironment[$env] = array_values($unique);
    }

    $map = [];
    foreach ($companyToEnvironment as $item) {
        $name = trim((string) ($item['name'] ?? ''));
        $environment = trim((string) ($item['environment'] ?? ''));
        if ($name === '' || $environment === '') {
            continue;
        }
        $map[$name] = $environment;
    }
    ksort($map, SORT_NATURAL | SORT_FLAG_CASE);

    $activeEnvironments = array_keys($companiesByEnvironment);
    sort($activeEnvironments, SORT_NATURAL | SORT_FLAG_CASE);

    $result = [
        'companies' => array_keys($map),
        'map' => $map,
        'by_environment' => $companiesByEnvironment,
        'errors' => [],
        'active_environments' => $activeEnvironments,
        'primary_environment' => (string) ($activeEnvironments[0] ?? ''),
    ];

    $GLOBALS['demeter_company_environment_map'] = $map;
    $GLOBALS['demeter_companies_by_environment'] = $companiesByEnvironment;
    $GLOBALS['demeter_active_environments'] = $activeEnvironments;

    return $result;
}

/**
 * Ontdekt bedrijven over alle actieve environments en bouwt de map.
 */
function auth_discover_companies_across_active_environments(int $ttlSeconds = AUTH_COMPANIES_ODATA_TTL): array
{
    // Mímir eerst. Faalt die aanroep, dan de pre-Mímir discovery over $auth_list / $baseUrl.
    if (auth_mimir_enabled() && function_exists('odata_mimir_or_direct')) {
        return odata_mimir_or_direct(
            static function (): array {
                return auth_discover_companies_via_mimir();
            },
            static function () use ($ttlSeconds): array {
                return auth_discover_companies_via_bc($ttlSeconds);
            }
        );
    }

    return auth_discover_companies_via_bc($ttlSeconds);
}

/**
 * Pre-Mímir company-discovery: elke actieve environment via directe BC OData.
 */
function auth_discover_companies_via_bc(int $ttlSeconds = AUTH_COMPANIES_ODATA_TTL): array
{
    $activeEnvironments = auth_get_active_environments();
    if ($activeEnvironments === []) {
        throw new RuntimeException('Geen actieve environments geconfigureerd.');
    }

    $companiesByEnvironment = [];
    $companyToEnvironment = [];
    $duplicates = [];
    $environmentErrors = [];

    foreach ($activeEnvironments as $environment) {
        try {
            $companies = auth_fetch_companies_for_environment($environment, $ttlSeconds);
        } catch (Throwable $error) {
            $environmentErrors[] = $environment . ': ' . $error->getMessage();
            continue;
        }

        $companiesByEnvironment[$environment] = $companies;

        foreach ($companies as $companyName) {
            $normalizedCompany = strtolower(trim($companyName));
            if ($normalizedCompany === '') {
                continue;
            }

            if (!isset($companyToEnvironment[$normalizedCompany])) {
                $companyToEnvironment[$normalizedCompany] = [
                    'name' => $companyName,
                    'environment' => $environment,
                ];
                continue;
            }

            $existingEnvironment = (string) ($companyToEnvironment[$normalizedCompany]['environment'] ?? '');
            if ($existingEnvironment === $environment) {
                continue;
            }

            if (!isset($duplicates[$normalizedCompany])) {
                $duplicates[$normalizedCompany] = [
                    'name' => (string) ($companyToEnvironment[$normalizedCompany]['name'] ?? $companyName),
                    'environments' => [$existingEnvironment],
                ];
            }

            $duplicates[$normalizedCompany]['environments'][] = $environment;
        }
    }

    if ($companyToEnvironment === [] && $environmentErrors !== []) {
        throw new RuntimeException('Bedrijven ophalen mislukt voor alle actieve environments. ' . implode(' | ', $environmentErrors));
    }

    if ($duplicates !== []) {
        $duplicateMessages = [];
        foreach ($duplicates as $duplicate) {
            $name = trim((string) ($duplicate['name'] ?? 'onbekend'));
            $envs = is_array($duplicate['environments'] ?? null) ? $duplicate['environments'] : [];
            $envs = array_values(array_unique(array_filter(array_map('strval', $envs), static function (string $env): bool {
                return trim($env) !== '';
            })));
            sort($envs, SORT_NATURAL | SORT_FLAG_CASE);
            $duplicateMessages[] = $name . ' [' . implode(', ', $envs) . ']';
        }

        throw new RuntimeException(
            'Bedrijfsnaam-overlap tussen actieve environments. Kies unieke bedrijfsnamen per environment. Conflicten: '
            . implode('; ', $duplicateMessages)
        );
    }

    $map = [];
    foreach ($companyToEnvironment as $item) {
        $name = trim((string) ($item['name'] ?? ''));
        $environment = trim((string) ($item['environment'] ?? ''));
        if ($name === '' || $environment === '') {
            continue;
        }

        $map[$name] = $environment;
    }

    ksort($map, SORT_NATURAL | SORT_FLAG_CASE);

    $result = [
        'companies' => array_keys($map),
        'map' => $map,
        'by_environment' => $companiesByEnvironment,
        'errors' => $environmentErrors,
        'active_environments' => $activeEnvironments,
        'primary_environment' => auth_get_primary_environment(),
    ];

    $GLOBALS['demeter_company_environment_map'] = $map;
    $GLOBALS['demeter_companies_by_environment'] = $companiesByEnvironment;
    $GLOBALS['demeter_active_environments'] = $activeEnvironments;

    return $result;
}

/**
 * Geeft de company->environment map terug, met lazy discovery.
 */
function auth_get_company_environment_map(int $ttlSeconds = AUTH_COMPANIES_ODATA_TTL, bool $refresh = false): array
{
    $current = $GLOBALS['demeter_company_environment_map'] ?? null;
    if (!$refresh && is_array($current) && $current !== []) {
        return $current;
    }

    $result = auth_discover_companies_across_active_environments($ttlSeconds);
    return is_array($result['map'] ?? null) ? $result['map'] : [];
}

/**
 * Geeft het environment voor een gekozen company.
 */
function auth_get_environment_for_company(string $company, int $ttlSeconds = AUTH_COMPANIES_ODATA_TTL): string
{
    $companyName = trim($company);
    if ($companyName === '') {
        throw new RuntimeException('Geen bedrijf geselecteerd.');
    }

    $map = auth_get_company_environment_map($ttlSeconds, false);
    if (isset($map[$companyName])) {
        return (string) $map[$companyName];
    }

    foreach ($map as $knownCompany => $environment) {
        if (strcasecmp($knownCompany, $companyName) === 0) {
            return (string) $environment;
        }
    }

    throw new RuntimeException('Geen environment gevonden voor bedrijf: ' . $companyName);
}

/**
 * Geeft de auth voor een gekozen company.
 */
function auth_get_auth_for_company(string $company, int $ttlSeconds = AUTH_COMPANIES_ODATA_TTL): array
{
    $environment = auth_get_environment_for_company($company, $ttlSeconds);
    return auth_get_auth_for_environment($environment);
}

/**
 * Stelt globale context in op basis van een gekozen company.
 */
function auth_set_current_company_context(?string $company, int $ttlSeconds = AUTH_COMPANIES_ODATA_TTL): array
{
    global $environment, $auth;

    $companyName = trim((string) $company);

    if (auth_mimir_live()) {
        $targetEnvironment = '';
        if ($companyName !== '') {
            try {
                $targetEnvironment = auth_get_environment_for_company($companyName, $ttlSeconds);
            } catch (Throwable $ignored) {
                $targetEnvironment = auth_get_primary_environment();
            }
        } else {
            $targetEnvironment = auth_get_primary_environment();
        }

        // BC-auth alleen als lokaal geconfigureerd; anders lege sentinel.
        $targetAuth = [];
        if ($targetEnvironment !== '') {
            global $auth_list;
            $list = is_array($auth_list ?? null) ? $auth_list : [];
            if (isset($list[$targetEnvironment]) && is_array($list[$targetEnvironment])) {
                $targetAuth = $list[$targetEnvironment];
            }
        }

        // Lege sentinel mag de oorspronkelijke $auth niet wissen: de BC-fallback heeft die nog nodig.
        if (function_exists('odata_bc_preserve_fallback_credentials')) {
            odata_bc_preserve_fallback_credentials(
                is_array($auth ?? null) ? $auth : [],
                isset($environment) && is_string($environment) ? $environment : ''
            );
        }

        $environment = $targetEnvironment;
        $auth = $targetAuth;

        return [
            'environment' => $targetEnvironment,
            'auth' => $targetAuth,
        ];
    }

    if ($companyName === '') {
        $targetEnvironment = auth_get_primary_environment();
    } else {
        $targetEnvironment = auth_get_environment_for_company($companyName, $ttlSeconds);
    }

    if ($targetEnvironment === '') {
        throw new RuntimeException('Geen geldig environment beschikbaar voor contextbepaling.');
    }

    $targetAuth = auth_get_auth_for_environment($targetEnvironment);

    $environment = $targetEnvironment;
    $auth = $targetAuth;

    return [
        'environment' => $targetEnvironment,
        'auth' => $targetAuth,
    ];
}
