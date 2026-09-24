<?php

/**
 * Alle knoppen die Tim nog moet bevestigen staan hier.
 * Querylogica leest alleen deze constanten; leverancier en afdeling
 * worden niet in de OData-filters vastgezet.
 */

const CONSUS_SNAPSHOT_VERSION = 4;

/**
 * Bedrijven in scope. Nachtelijke refresh slaat andere BC-bedrijven over.
 * `names` zijn de BC-bedrijfsnamen (en Company_Name op VoorraadPerBedrijf).
 */
const CONSUS_COMPANIES = [
    'kvt' => [
        'label' => 'KVT',
        'names' => [
            'Koninklijke van Twist',
        ],
    ],
    'hvt' => [
        'label' => 'HVT',
        'names' => [
            'Hunter van Twist',
        ],
    ],
];

/**
 * Standaard leveranciersfilter in de UI (naam of nummer, deelstring).
 * De cache bevat alle leveranciers; alleen de eerste paginaweergave kiest deze.
 */
const CONSUS_DEFAULT_VENDOR_MATCH = 'Perkins';

/**
 * Hint voor eigen magazijn in de UI. Geen cachefilter: nightly laadt elke locatie.
 */
const CONSUS_EIGEN_LOCATION_HINTS = ['KVT', 'HVT'];

/**
 * Splitsing verkoop en omloopsnelheid (Joost). Labels, geen OData-filter en geen scope.
 *
 * - leeg inkooppad = levering magazijn (eigen)
 * - dropship = inkoopcode DROP_SHIP en/of leverancier 90052
 * - EGT = leverancier 90101
 *
 * Tabel 32 publiceert standaard geen inkoopcode en geen leveranciersnr.
 * Nightly vraagt de namen hieronder mee en laat ze weg als BC ze weigert.
 * AppItemCard.Vendor_No blijft de artikelleverancier voor het filter.
 */
const CONSUS_ILE_PURCHASING_CODE_FIELD = 'Purchasing_Code';
const CONSUS_ILE_VENDOR_NO_FIELD = 'Vendor_No';
const CONSUS_DROPSHIP_PURCHASING_CODE = 'DROP_SHIP';
const CONSUS_DROPSHIP_VENDOR_NO = '90052';
const CONSUS_EGT_VENDOR_NO = '90101';

const CONSUS_BUCKETS = [
    'eigen' => 'Eigen',
    'egt' => 'EGT',
    'dropship' => 'Dropship',
];

/** Omloopsnelheid eigen versus EGT. Dropship heeft geen eigen voorraad. */
const CONSUS_TURNOVER_BUCKETS = ['eigen', 'egt'];

/**
 * Artikelposten verkoop. BC zet uitgaande hoeveelheid negatief;
 * de cache draait het teken om zodat verkoop positief is en retouren aftrekken.
 *
 * Deze on-prem ODataV4 spreekt Nederlandse optiebijschriften (nl-NL).
 * De Engelse enumnaam is alleen een aparte terugval als BC het bijschrift weigert.
 * Nooit samen in één $filter met OR.
 */
const CONSUS_SALES_ENTRY_TYPES = [
    'Verkoop',
    'Sale',
];

/**
 * Werkorderverbruik via artikelposten.
 * Primair: negatieve correctie waarvan Document_No met WO begint.
 * Daarnaast assemblageverbruik. Kale consumption (Verbruik / Gebruik /
 * Consumption) blijft buiten de query.
 *
 * BC antwoordt HTTP 501 op OR over verschillende velden en op startswith.
 * Daarom één Entry_Type per verzoek; het WO-prefix filtert PHP.
 * Eerste waarde is het Nederlandse bijschrift, de tweede de Engelse terugval.
 */
const CONSUS_WO_PRIMARY_ENTRY_TYPES = [
    'Negatieve correctie',
    'Negative Adjmt.',
];
const CONSUS_WO_DOCUMENT_PREFIX = 'WO';
const CONSUS_WO_ALSO_ENTRY_TYPES = [
    'Assemblageverbruik',
    'Assembly Consumption',
];

const CONSUS_STOCK_ENTITY = 'VoorraadPerBedrijf';
const CONSUS_STOCK_FIELDS = [
    'Item_No',
    'Company_Name',
    'Inventory',
    'Safety_Stock_Quantity',
    'Reorder_Point',
];
/** Meenemen als de pagina het veld heeft; anders blijft voorraad zonder locatie. */
const CONSUS_STOCK_OPTIONAL_FIELDS = [
    'Location_Code',
];

const CONSUS_ITEM_ENTITY = 'AppItemCard';
const CONSUS_ITEM_FIELDS = [
    'No',
    'Vendor_No',
    'LVS_Vendor_Name',
];
/** Optioneel; nightly probeert ze en valt terug op de verplichte velden. */
const CONSUS_ITEM_OPTIONAL_FIELDS = [
    'COST_CENTER',
    'Vendor_Name',
];

const CONSUS_LEDGER_ENTITY = 'ItemLedgerEntries';
const CONSUS_LEDGER_FIELDS = [
    'Item_No',
    'Entry_Type',
    'Quantity',
    'Sales_Amount_Actual',
    'Posting_Date',
    'Location_Code',
    'Document_No',
];
/** Inkooppad op de artikelpost. Beperkt de opgehaalde set niet. */
const CONSUS_LEDGER_OPTIONAL_FIELDS = [
    CONSUS_ILE_PURCHASING_CODE_FIELD,
    CONSUS_ILE_VENDOR_NO_FIELD,
];

/**
 * Kostenplaats voor de latere afdelingsdropdown.
 * Beste gok: dimensiecode 15 op artikel (tabel 27). De dimensiewaarde is de
 * afdeling en wordt niet gefilterd — Perkins loopt via de leverancier.
 * Een COST_CENTER-veld op AppItemCard wint van deze dimensie als het gevuld is.
 */
const CONSUS_DIMENSION_ENTITY = 'DefaultDimensions';
const CONSUS_DIMENSION_FIELDS = [
    'No',
    'Dimension_Code',
    'Dimension_Value_Code',
];
const CONSUS_DIMENSION_TABLE_ID = 27;
const CONSUS_COST_CENTER_DIMENSION_CODE = '15';

const CONSUS_ODATA_BATCH_SIZE = 12;
