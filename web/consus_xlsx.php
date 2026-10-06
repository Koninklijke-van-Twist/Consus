<?php

require_once __DIR__ . '/consus_usage.php';
require_once __DIR__ . '/consus_prefs.php';

function consus_xlsx_column_name(int $index): string
{
    $index++;
    $name = '';
    while ($index > 0) {
        $index--;
        $name = chr(65 + ($index % 26)) . $name;
        $index = intdiv($index, 26);
    }

    return $name;
}

function consus_xlsx_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
}

function consus_xlsx_number(float $value): string
{
    if (!is_finite($value)) {
        return '0';
    }
    $text = rtrim(rtrim(sprintf('%.8F', $value), '0'), '.');
    if ($text === '' || $text === '-0') {
        return '0';
    }

    return $text;
}

/**
 * @param array<int, array<int, float|string|null>> $rows
 */
function consus_xlsx_sheet_xml(array $rows): string
{
    $lastRow = max(1, count($rows));
    $width = 1;
    foreach ($rows as $row) {
        $width = max($width, count($row));
    }
    $lastColumn = consus_xlsx_column_name($width - 1);
    $ref = 'A1:' . $lastColumn . $lastRow;
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<dimension ref="' . $ref . '"/>'
        . '<sheetData>';
    $rowNumber = 1;
    foreach ($rows as $row) {
        $xml .= '<row r="' . $rowNumber . '">';
        $column = 0;
        foreach ($row as $value) {
            $cell = consus_xlsx_column_name($column) . $rowNumber;
            if ($rowNumber === 1 || is_string($value) || $value === null) {
                $style = $rowNumber === 1 ? ' s="1"' : '';
                $text = $value === null ? '' : (string) $value;
                $xml .= '<c r="' . $cell . '"' . $style . ' t="inlineStr"><is><t>' . consus_xlsx_escape($text) . '</t></is></c>';
            } else {
                $xml .= '<c r="' . $cell . '"><v>' . consus_xlsx_number((float) $value) . '</v></c>';
            }
            $column++;
        }
        $xml .= '</row>';
        $rowNumber++;
    }
    $xml .= '</sheetData><autoFilter ref="' . $ref . '"/></worksheet>';

    return $xml;
}

function consus_xlsx_styles_xml(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
        . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="2">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '</cellXfs></styleSheet>';
}

/**
 * @param array<int, array{name:string,rows:array<int, array<int, float|string|null>>}> $sheets
 */
function consus_xlsx_binary(array $sheets): string
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Export naar Excel lukt niet: de PHP-extensie ZipArchive ontbreekt.');
    }
    if ($sheets === []) {
        throw new RuntimeException('Export naar Excel lukt niet: er is geen werkblad.');
    }

    $path = tempnam(sys_get_temp_dir(), 'consus-xlsx-');
    if (!is_string($path) || $path === '') {
        throw new RuntimeException('Tijdelijk Excel-bestand kon niet worden aangemaakt.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        @unlink($path);
        throw new RuntimeException('Excel-bestand kon niet worden geopend.');
    }

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
    $workbookSheets = '';
    $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    $index = 1;
    foreach ($sheets as $sheet) {
        $name = consus_xlsx_sheet_name((string) ($sheet['name'] ?? ('Blad' . $index)));
        $sheetPath = 'xl/worksheets/sheet' . $index . '.xml';
        $zip->addFromString($sheetPath, consus_xlsx_sheet_xml(is_array($sheet['rows'] ?? null) ? $sheet['rows'] : []));
        $contentTypes .= '<Override PartName="/' . $sheetPath . '" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $workbookSheets .= '<sheet name="' . consus_xlsx_escape($name) . '" sheetId="' . $index . '" r:id="rId' . $index . '"/>';
        $workbookRels .= '<Relationship Id="rId' . $index . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $index . '.xml"/>';
        $index++;
    }
    $styleId = $index;
    $workbookRels .= '<Relationship Id="rId' . $styleId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
    $workbookRels .= '</Relationships>';
    $contentTypes .= '</Types>';
    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets>' . $workbookSheets . '</sheets></workbook>';
    $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';

    $zip->addFromString('[Content_Types].xml', $contentTypes);
    $zip->addFromString('_rels/.rels', $rootRels);
    $zip->addFromString('xl/workbook.xml', $workbook);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
    $zip->addFromString('xl/styles.xml', consus_xlsx_styles_xml());
    $zip->close();

    $binary = file_get_contents($path);
    @unlink($path);
    if (!is_string($binary) || $binary === '') {
        throw new RuntimeException('Excel-bestand kon niet worden gelezen.');
    }

    return $binary;
}

function consus_xlsx_sheet_name(string $name): string
{
    $name = trim(str_replace(['\\', '/', '?', '*', '[', ']', ':'], ' ', $name));
    if ($name === '') {
        $name = 'Blad';
    }
    if (strlen($name) > 31) {
        $name = substr($name, 0, 31);
    }

    return $name;
}

/**
 * @param array<string, mixed> $snapshot
 * @param array{customers?:array<int, string>,items?:array<int, string>} $prefs
 * @param array<string, mixed> $query
 * @return array<int, array{name:string,rows:array<int, array<int, float|string|null>>}>
 */
function consus_xlsx_sheets(array $snapshot, array $prefs, array $query): array
{
    $windows = consus_snapshot_windows($snapshot);
    $companyKey = trim((string) ($query['company'] ?? ''));
    if (!isset(CONSUS_COMPANIES[$companyKey])) {
        $companyKey = '';
    }
    $costCenter = trim((string) ($query['cost_center'] ?? ''));
    $excludedCustomers = consus_prefs_normalize_list($prefs['customers'] ?? []);
    $excludedItems = consus_prefs_normalize_list($prefs['items'] ?? []);
    $ready = (int) ($snapshot['version'] ?? 0) === CONSUS_SNAPSHOT_VERSION
        && trim((string) ($snapshot['generated_at'] ?? '')) !== '';
    $facts = $ready
        ? consus_usage_facts($snapshot, $companyKey, $costCenter, $excludedItems)
        : [];
    $headers = consus_usage_column_labels();
    $sheets = [];
    foreach (consus_history_years((string) ($windows['as_of'] ?? '')) as $year) {
        $rows = [$headers];
        foreach (consus_usage_year_rows($facts, $year, $windows, $excludedCustomers) as $row) {
            $values = consus_usage_row_values($row);
            if ($companyKey === '') {
                $label = trim((string) ($row['company_label'] ?? ''));
                if ($label !== '') {
                    $values[0] .= ' (' . $label . ')';
                }
            }
            $rows[] = $values;
        }
        $sheets[] = [
            'name' => (string) $year,
            'rows' => $rows,
        ];
    }

    $customerLabels = [];
    $suggestions = consus_customer_suggestions($snapshot, $companyKey);
    $names = [];
    foreach ($suggestions as $suggestion) {
        $names[strtolower((string) $suggestion['no'])] = (string) $suggestion['label'];
    }
    foreach ($excludedCustomers as $customer) {
        $key = strtolower($customer);
        $customerLabels[] = $names[$key] ?? $customer;
    }
    $filterRows = [['Gefilterde klanten', 'Gefilterde artikels']];
    $height = max(count($customerLabels), count($excludedItems));
    for ($index = 0; $index < $height; $index++) {
        $filterRows[] = [
            $customerLabels[$index] ?? '',
            $excludedItems[$index] ?? '',
        ];
    }
    $sheets[] = [
        'name' => 'Filters',
        'rows' => $filterRows,
    ];

    return $sheets;
}

/**
 * @param array<string, mixed> $snapshot
 * @param array{customers?:array<int, string>,items?:array<int, string>} $prefs
 * @param array<string, mixed> $query
 */
function consus_xlsx_download(array $snapshot, array $prefs, array $query): void
{
    try {
        $binary = consus_xlsx_binary(consus_xlsx_sheets($snapshot, $prefs, $query));
    } catch (Throwable $error) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
        }
        echo $error->getMessage();

        return;
    }

    $asOf = consus_parse_date((string) (consus_snapshot_windows($snapshot)['as_of'] ?? ''));
    $filename = 'consus-verbruik' . ($asOf !== '' ? '-' . $asOf : '') . '.xlsx';
    if (!headers_sent()) {
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . (string) strlen($binary));
        header('Cache-Control: no-store');
    }
    echo $binary;
}
