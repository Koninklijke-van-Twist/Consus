<?php

/**
 * Alle knoppen die Tim nog moet bevestigen staan hier.
 * Querylogica leest alleen deze constanten; leverancier en afdeling
 * worden niet in de OData-filters vastgezet.
 */

const CONSUS_SNAPSHOT_VERSION = 1;

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
 * Location_Code → bucket (egt | dropship | eigen).
 *
 * Dit zijn gokken. Codes die hier niet staan vallen in "onbekend", zodat een
 * foute gok zichtbaar blijft in plaats van stilletjes in de verkeerde kolom.
 * Tim vervangt deze map door de echte BC-locaties.
 *
 * - KVT / HVT / MAGAZIJN / HOOFDMAGAZIJN: eigen magazijn
 * - EGT: locatie die letterlijk EGT heet
 * - DROP / DROPSHIP: dropshipment (ligt niet op voorraad)
 */
const CONSUS_LOCATION_BUCKETS = [
    'KVT' => 'eigen',
    'HVT' => 'eigen',
    'MAGAZIJN' => 'eigen',
    'HOOFDMAGAZIJN' => 'eigen',
    'EGT' => 'egt',
    'DROP' => 'dropship',
    'DROPSHIP' => 'dropship',
];

const CONSUS_BUCKETS = [
    'eigen' => 'Eigen',
    'egt' => 'EGT',
    'dropship' => 'Dropship',
    'onbekend' => 'Onbekend',
];

/** Omloopsnelheid toont eigen voorraad versus EGT. Dropship telt niet mee. */
const CONSUS_TURNOVER_BUCKETS = ['eigen', 'egt'];

/**
 * Artikelposten verkoop. BC zet uitgaande hoeveelheid negatief;
 * de cache draait het teken om zodat verkoop positief is en retouren aftrekken.
 */
const CONSUS_SALES_ENTRY_TYPES = [
    'Sale',
];

/**
 * Werkorderverbruik via artikelposten.
 * Beste gok: Assembly Consumption. Het kale type Consumption is vaak leeg.
 * Extra types (bijvoorbeeld Consumption) hier toevoegen om ze mee te nemen.
 */
const CONSUS_WO_ENTRY_TYPES = [
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
