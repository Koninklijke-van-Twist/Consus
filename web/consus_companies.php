<?php

/**
 * Bedrijvenkeuze zoals in Calculus: de UI kiest een BC-bedrijf, de environment
 * volgt uit het bedrijf (auth_get_environment_for_company). De lijst komt uit
 * de company-discovery over alle actieve environments (Mímir companies.php,
 * anders direct BC). KVT en HVT delen kvtmdlive_aad; KVT Germany zit in
 * kvtgermanylive_aad. Test-/FAT-databases (kvtfat_aad, kvtfat2_aad) vallen weg.
 *
 * In OData-paden staat altijd de BC `Name`; het label is de weergavenaam.
 * Nooit afhankelijk van de volgorde van $auth_list.
 *
 * De lijst staat 24 uur in web/data/consus_companies.json (UI-beleid); de
 * nightly ververst hem bij zijn eigen discovery.
 */

require_once __DIR__ . '/consus_data.php';

const CONSUS_COMPANY_CATALOG_TTL = 86400;
/** Fragmenten in een environmentnaam die een test- of FAT-database aanduiden. */
const CONSUS_COMPANY_SKIP_ENVIRONMENT_FRAGMENTS = ['fat', 'test', 'sandbox'];
/** Weergavenamen waar die afwijken van de BC Name (Ariadne, 08-10-2026). Sleutel: Name in kleine letters. */
const CONSUS_COMPANY_DISPLAY_NAMES = [
    'hunter van twist' => 'Hunter & van Twist',
    'koninklijke van twist' => 'Koninklijke Van Twist',
];

function consus_company_catalog_file(): string
{
    $override = getenv('CONSUS_COMPANY_CATALOG_FILE');
    if (is_string($override) && trim($override) !== '') {
        return trim($override);
    }

    return __DIR__ . '/data/consus_companies.json';
}

function consus_company_label_for(string $name): string
{
    $name = trim($name);

    return CONSUS_COMPANY_DISPLAY_NAMES[mb_strtolower($name)] ?? $name;
}

function consus_company_environment_is_test(string $environment): bool
{
    $environment = strtolower(trim($environment));
    foreach (CONSUS_COMPANY_SKIP_ENVIRONMENT_FRAGMENTS as $fragment) {
        if ($environment !== '' && str_contains($environment, $fragment)) {
            return true;
        }
    }

    return false;
}

/**
 * @param array<string, string> $map BC Name => environment
 * @return array<int, array{name:string,environment:string,label:string,key:string}>
 */
function consus_company_catalog_from_map(array $map): array
{
    $out = [];
    foreach ($map as $name => $environment) {
        $name = trim((string) $name);
        $environment = trim((string) $environment);
        if ($name === '' || consus_company_environment_is_test($environment)) {
            continue;
        }
        $out[mb_strtolower($name)] ??= [
            'name' => $name,
            'environment' => $environment,
            'label' => consus_company_label_for($name),
            'key' => consus_company_key_for_name($name),
        ];
    }
    $out = array_values($out);
    usort($out, static fn (array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));

    return $out;
}

/** Terugval zonder discovery: de bedrijven uit de nachtcache (KVT, HVT). */
function consus_company_catalog_static(): array
{
    $map = [];
    foreach (CONSUS_COMPANIES as $company) {
        $name = trim((string) (($company['names'] ?? [])[0] ?? ''));
        if ($name !== '') {
            $map[$name] = '';
        }
    }

    return consus_company_catalog_from_map($map);
}

/** @return array{fetched_at:int,companies:array<int, array>}|null */
function consus_company_catalog_read_cache(): ?array
{
    $path = consus_company_catalog_file();
    $raw = is_file($path) ? json_decode((string) @file_get_contents($path), true) : null;
    if (!is_array($raw) || !is_array($raw['companies'] ?? null) || $raw['companies'] === []) {
        return null;
    }
    $map = [];
    foreach ($raw['companies'] as $row) {
        if (is_array($row) && trim((string) ($row['name'] ?? '')) !== '') {
            $map[(string) $row['name']] = (string) ($row['environment'] ?? '');
        }
    }
    $companies = consus_company_catalog_from_map($map);

    return $companies === [] ? null : ['fetched_at' => (int) ($raw['fetched_at'] ?? 0), 'companies' => $companies];
}

/** @param array<string, string> $map BC Name => environment */
function consus_company_catalog_store(array $map): array
{
    $companies = consus_company_catalog_from_map($map);
    if ($companies !== []) {
        consus_write_json_locked(consus_company_catalog_file(), [
            'fetched_at' => time(),
            'companies' => array_map(static fn (array $row): array => ['name' => $row['name'], 'environment' => $row['environment']], $companies),
        ]);
    }

    return $companies;
}

/**
 * Bedrijvenlijst voor de dropdown. Verse cache (24 uur) eerst; anders één
 * discovery; lukt die niet, dan de oude cache, en anders KVT en HVT.
 *
 * @param callable|null $discover fn(): array<string,string> (Name => environment)
 * @return array<int, array{name:string,environment:string,label:string,key:string}>
 */
function consus_company_catalog(bool $allowLive = true, ?callable $discover = null): array
{
    $memo = $GLOBALS['consus_company_catalog_memo'] ?? null;
    if (is_array($memo) && $discover === null) {
        return $memo;
    }
    $cached = consus_company_catalog_read_cache();
    if ($cached !== null && (time() - $cached['fetched_at']) < CONSUS_COMPANY_CATALOG_TTL) {
        return $GLOBALS['consus_company_catalog_memo'] = $cached['companies'];
    }
    if ($allowLive) {
        $discover ??= static function (): array {
            if (!function_exists('auth_discover_companies_across_active_environments')) {
                return [];
            }
            $result = auth_discover_companies_across_active_environments();

            return is_array($result['map'] ?? null) ? $result['map'] : [];
        };
        try {
            $companies = consus_company_catalog_store($discover());
            if ($companies !== []) {
                return $GLOBALS['consus_company_catalog_memo'] = $companies;
            }
        } catch (Throwable $ignored) {
            // Discovery niet bereikbaar: oude lijst of KVT/HVT.
        }
        $fallback = $cached['companies'] ?? consus_company_catalog_static();
        try {
            // Over een uur opnieuw proberen, niet bij elke paginaweergave.
            consus_write_json_locked(consus_company_catalog_file(), [
                'fetched_at' => time() - CONSUS_COMPANY_CATALOG_TTL + 3600,
                'companies' => array_map(static fn (array $row): array => ['name' => $row['name'], 'environment' => $row['environment']], $fallback),
            ]);
        } catch (Throwable $ignored) {
        }

        return $GLOBALS['consus_company_catalog_memo'] = $fallback;
    }

    return $GLOBALS['consus_company_catalog_memo'] = ($cached['companies'] ?? consus_company_catalog_static());
}

/**
 * Kiest een bedrijf uit de lijst op BC Name (hoofdletterongevoelig), op
 * weergavenaam, of op de oude sleutel uit deeplinks (kvt, hvt).
 *
 * @param array<int, array{name:string,environment:string,label:string,key:string}> $companies
 * @return array{name:string,environment:string,label:string,key:string}|null
 */
function consus_company_resolve(string $value, array $companies): ?array
{
    $needle = mb_strtolower(preg_replace('/\s+/u', ' ', trim($value)) ?? '');
    if ($needle === '') {
        return null;
    }
    foreach ($companies as $company) {
        if (mb_strtolower($company['name']) === $needle || mb_strtolower($company['label']) === $needle) {
            return $company;
        }
    }
    foreach ($companies as $company) {
        if ($company['key'] !== '' && strtolower($company['key']) === $needle) {
            return $company;
        }
    }

    return null;
}

/*
 * Afdelingen per bedrijf. De afdelingen (Globale dimensie 1) verschillen per
 * BC-bedrijf: in Hunter van Twist is 15 "Niet gebruiken", in Koninklijke van
 * Twist is 15 Perkins. Er is dus nooit één gedeelde lijst. KVT en HVT krijgen
 * hun lijst uit de nachtcache (per company_key). Een bedrijf zonder lijst in
 * de nachtcache (zoals KVT Germany) haalt zijn eigen lijst op: de dimensiecode
 * uit GeneralLedgerSetup en de namen uit DimensionValueList, in dát bedrijf
 * (Mímir eerst, anders direct BC). 24 uur bewaard in
 * web/data/consus_departments.json, per BC Name.
 */

const CONSUS_DEPARTMENT_CATALOG_TTL = 86400;

function consus_department_catalog_file(): string
{
    $override = getenv('CONSUS_DEPARTMENT_CATALOG_FILE');
    if (is_string($override) && trim($override) !== '') {
        return trim($override);
    }

    return __DIR__ . '/data/consus_departments.json';
}

/**
 * Afdelingslijst van één bedrijf live uit BC (in dat bedrijf).
 *
 * @return array<int, array{code:string,name:string,label:string}>
 */
function consus_department_catalog_fetch_live(string $company): array
{
    $dimensionCode = '';
    consus_each_entity_rows($company, CONSUS_GL_SETUP_ENTITY, CONSUS_GL_SETUP_FIELDS, [], '', static function (array $row) use (&$dimensionCode): void {
        $code = trim(consus_scalar_string($row['Global_Dimension_1_Code'] ?? ''));
        if ($code !== '') {
            $dimensionCode = $code;
        }
    });
    if ($dimensionCode === '') {
        throw new RuntimeException(CONSUS_GL_SETUP_ENTITY . ' van ' . $company . ' heeft geen Global_Dimension_1_Code.');
    }
    $rowsByPass = [];
    foreach (array_keys(consus_department_dimension_passes($dimensionCode)) as $passCode) {
        $rowsByPass[(string) $passCode] = [];
        try {
            consus_each_dimension_value_rows($company, (string) $passCode, static function (array $row) use (&$rowsByPass, $passCode): void {
                $rowsByPass[(string) $passCode][] = $row;
            });
        } catch (Throwable $error) {
            if ((string) $passCode === $dimensionCode) {
                throw $error;
            }
            // Terugvaldimensie bestaat niet in elk bedrijf.
        }
    }

    return consus_department_catalog_merge_passes($rowsByPass);
}

/**
 * Afdelingslijst van één bedrijf (BC Name). Verse cache (24 uur) eerst; anders
 * één live ophaalactie; mislukt die, dan de oude lijst van dit bedrijf (of
 * leeg) en over een uur opnieuw. Nooit de lijst van een ander bedrijf.
 *
 * @param callable|null $fetch fn(string $company): array<int, array{code:string,name:string,label:string}>
 * @return array<int, array{code:string,name:string,label:string}>
 */
function consus_company_department_catalog(string $company, bool $allowLive = true, ?callable $fetch = null): array
{
    $company = trim($company);
    $slot = mb_strtolower(preg_replace('/\s+/u', ' ', $company) ?? '');
    if ($slot === '') {
        return [];
    }
    $memo = $GLOBALS['consus_department_catalog_memo'][$slot] ?? null;
    if (is_array($memo) && $fetch === null) {
        return $memo;
    }
    $path = consus_department_catalog_file();
    $raw = is_file($path) ? json_decode((string) @file_get_contents($path), true) : null;
    $cached = is_array($raw['companies'][$slot] ?? null) ? $raw['companies'][$slot] : null;
    $cachedList = consus_department_catalog_clean(is_array($cached['departments'] ?? null) ? $cached['departments'] : []);
    if ($cached !== null && (time() - (int) ($cached['fetched_at'] ?? 0)) < CONSUS_DEPARTMENT_CATALOG_TTL) {
        return $GLOBALS['consus_department_catalog_memo'][$slot] = $cachedList;
    }
    if (!$allowLive) {
        return $GLOBALS['consus_department_catalog_memo'][$slot] = $cachedList;
    }
    $fetch ??= $GLOBALS['consus_department_catalog_fetcher'] ?? null;
    $fetch ??= static function (string $company): array {
        if (!function_exists('auth_get_environment_for_company')) {
            throw new RuntimeException('Geen BC-verbinding beschikbaar.');
        }

        return consus_department_catalog_fetch_live($company);
    };
    $list = $cachedList;
    $fetchedAt = time() - CONSUS_DEPARTMENT_CATALOG_TTL + 3600;
    try {
        $fresh = consus_department_catalog_clean((array) $fetch($company));
        if ($fresh !== []) {
            $list = $fresh;
            $fetchedAt = time();
        }
    } catch (Throwable $ignored) {
        // BC of Mímir niet bereikbaar: oude lijst van dit bedrijf, over een uur opnieuw.
    }
    try {
        consus_update_json_locked($path, static function (array $previous) use ($slot, $company, $list, $fetchedAt): array {
            $companies = is_array($previous['companies'] ?? null) ? $previous['companies'] : [];
            $companies[$slot] = ['name' => $company, 'fetched_at' => $fetchedAt, 'departments' => $list];

            return ['version' => 1, 'companies' => $companies];
        });
    } catch (Throwable $ignored) {
    }

    return $GLOBALS['consus_department_catalog_memo'][$slot] = $list;
}

/**
 * @param array<int, mixed> $entries
 * @return array<int, array{code:string,name:string,label:string}>
 */
function consus_department_catalog_clean(array $entries): array
{
    $out = [];
    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $code = trim((string) ($entry['code'] ?? ''));
        $name = trim((string) ($entry['name'] ?? ''));
        if ($code === '') {
            continue;
        }
        $label = trim((string) ($entry['label'] ?? ''));
        $out[] = ['code' => $code, 'name' => $name, 'label' => $label !== '' ? $label : ($name !== '' ? $code . ' - ' . $name : $code)];
    }

    return $out;
}

/**
 * Afdelingskeuzes voor het gekozen bedrijf, alleen uit de lijst van dát
 * bedrijf. Bij "Alle" is er geen afdelingskeuze: dezelfde code betekent per
 * bedrijf iets anders (15 is Perkins bij KvT, "Niet gebruiken" bij HVT).
 *
 * @param array<int, array<string, mixed>> $rows
 * @param array<int, array<string, mixed>> $snapshotCatalog
 * @param callable|null $fetch zie consus_company_department_catalog
 * @return array<int, array{value:string,label:string}>
 */
function consus_page_department_choices(array $rows, array $snapshotCatalog, string $companyName, string $companyKey, ?callable $fetch = null): array
{
    if (trim($companyName) === '') {
        return [];
    }
    $own = [];
    if ($companyKey !== '') {
        foreach ($snapshotCatalog as $entry) {
            if (is_array($entry) && (string) ($entry['company_key'] ?? '') === $companyKey) {
                $own[] = $entry;
            }
        }
    }
    if ($own === []) {
        // Geen lijst in de nachtcache voor dit bedrijf: eigen lijst uit BC.
        foreach (consus_company_department_catalog($companyName, true, $fetch) as $entry) {
            $entry['company_key'] = $companyKey;
            $own[] = $entry;
        }
    }
    if ($companyKey === '') {
        // Buiten de nachtcache: geen verbruiksregels, alleen de namen van dit bedrijf.
        $rows = [];
        foreach ($own as $index => $entry) {
            $own[$index]['company_key'] = '';
        }
    }

    return consus_department_choices($rows, $own, $companyKey);
}
