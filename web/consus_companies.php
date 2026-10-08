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
