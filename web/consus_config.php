<?php

/**
 * Alle knoppen die Tim nog moet bevestigen staan hier.
 * Querylogica leest alleen deze constanten; leverancier en afdeling
 * worden niet in de OData-filters vastgezet.
 */

const CONSUS_SNAPSHOT_VERSION = 3;

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
 * Location_Code → kolom voor verkoop en omloopsnelheid (Ariadne, beste gok).
 * Nightly laadt élke locatie; dit is geen OData-filter en geen scope.
 *
 * - eigen: KVT (KVT-verkopen), HVT (HVT-verkopen)
 * - dropship: BYKLANT (“Bij klant opgeslagen”), dichtstbijzijnde AppLocations-code
 * - egt: leeg tot Joost de echte codes aanlevert. Geen verzonnen EGT-code.
 *   De EGT-kolom blijft daardoor leeg; de PR is compleet zonder die split.
 *
 * Elke andere code, inclusief M-locaties, valt in overig. Dat is geen EGT.
 */
const CONSUS_LOCATIONS_EIGEN = ['KVT', 'HVT'];
const CONSUS_LOCATIONS_DROPSHIP = ['BYKLANT'];
const CONSUS_LOCATIONS_EGT = [];

const CONSUS_BUCKETS = [
    'eigen' => 'Eigen',
    'egt' => 'EGT',
    'dropship' => 'Dropship',
    'overig' => 'Overig',
];

/**
 * Omloopsnelheid eigen versus EGT. EGT blijft leeg zolang CONSUS_LOCATIONS_EGT leeg is.
 * Dropship en overig hebben geen eigen omloopsnelheid.
 */
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
 * Primair: Negative Adjmt. waarvan Document_No met WO begint.
 * Daarnaast Assembly Consumption. Niet alleen Assembly Consumption:
 * kale Consumption blijft buiten de query.
 */
const CONSUS_WO_PRIMARY_ENTRY_TYPE = 'Negative Adjmt.';
const CONSUS_WO_DOCUMENT_PREFIX = 'WO';
const CONSUS_WO_ALSO_ENTRY_TYPES = [
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
