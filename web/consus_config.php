<?php

/**
 * Alle knoppen die Tim nog moet bevestigen staan hier.
 * Querylogica leest alleen deze constanten; leverancier en afdeling
 * worden niet in de OData-filters vastgezet.
 */

const CONSUS_SNAPSHOT_VERSION = 10;

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
 * ItemLedgerEntries op deze on-prem ODataV4 wil de Engelse optienaam.
 * Live: Entry_Type eq 'Sale' is HTTP 200; 'Verkoop' is HTTP 400
 * ("is not an option"). Geldige opties: Purchase, Sale, Positive Adjmt.,
 * Negative Adjmt., Transfer, Consumption, Output, Assembly Consumption,
 * Assembly Output. Nooit meerdere Entry_Types met OR in één $filter.
 */
const CONSUS_SALES_ENTRY_TYPES = [
    'Sale',
];

/**
 * Werkorderverbruik via artikelposten.
 * Primair: Negative Adjmt. waarvan Document_No met WO begint.
 * Daarnaast Assembly Consumption. Kale Consumption blijft buiten de query.
 *
 * BC antwoordt HTTP 501 op OR over verschillende velden en op startswith.
 * Daarom één Entry_Type per verzoek; het WO-prefix filtert PHP.
 * Nederlandse bijschriften (Negatieve correctie, Assemblageverbruik) zijn
 * op deze pagina geen optie.
 */
const CONSUS_WO_PRIMARY_ENTRY_TYPES = [
    'Negative Adjmt.',
];
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
/**
 * Meenemen als de pagina het veld heeft. Location_Code ontbreekt op
 * VoorraadPerBedrijf; een andere locatienaam vult die dan. Bestelpunt komt
 * soms niet in Reorder_Point maar in een tweede kolom.
 */
const CONSUS_STOCK_OPTIONAL_FIELDS = [
    'Location_Code',
    'LocationCode',
    'Locatiecode',
    'Locatie',
    'Location_No',
    'Bestelpunt',
    'ReorderPoint',
    'Veiligheidsvoorraad',
    'SafetyStockQuantity',
];

const CONSUS_ITEM_ENTITY = 'AppItemCard';
/**
 * Verplicht zijn alleen nummer en leverancier. LVS_Vendor_Name is een
 * maatwerkveld: als BC het weigert, mag dat de leveranciersdropdown niet
 * leeg trekken. Omschrijving komt mee als de pagina het veld heeft.
 */
const CONSUS_ITEM_FIELDS = [
    'No',
    'Vendor_No',
];
/** Optioneel; een geweigerd veld wordt uit $select gehaald en de query opnieuw gedaan. */
const CONSUS_ITEM_OPTIONAL_FIELDS = [
    'LVS_Vendor_Name',
    'COST_CENTER',
    'Cost_Center',
    'Kostenplaats',
    'Afdeling',
    'Global_Dimension_1_Code',
    'Shortcut_Dimension_1_Code',
    'Safety_Stock_Quantity',
    'Veiligheidsvoorraad',
    'SafetyStockQuantity',
    'Reorder_Point',
    'Bestelpunt',
    'ReorderPoint',
    'Vendor_Name',
    'Description',
];

const CONSUS_LEDGER_ENTITY = 'ItemLedgerEntries';
/**
 * Velden die elke artikelpost nodig heeft. Entry_Type zit al in $filter.
 * Omzet en documentnummer komen er alleen bij voor de query die ze gebruikt.
 */
const CONSUS_LEDGER_FIELDS = [
    'Item_No',
    'Quantity',
    'Posting_Date',
    'Location_Code',
];
/** Inkooppad op de artikelpost. Beperkt de opgehaalde set niet. */
const CONSUS_LEDGER_OPTIONAL_FIELDS = [
    CONSUS_ILE_PURCHASING_CODE_FIELD,
    'PurchasingCode',
    CONSUS_ILE_VENDOR_NO_FIELD,
    'Buy_from_Vendor_No',
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
/** Andere namen voor het artikelnummer en de dimensiewaarde. Een geweigerd veld valt uit $select. */
const CONSUS_DIMENSION_OPTIONAL_FIELDS = [
    'Item_No',
    'Dimension_Value',
    'Value_Code',
];
const CONSUS_DIMENSION_TABLE_ID = 27;
const CONSUS_COST_CENTER_DIMENSION_CODE = '15';

/**
 * Aantal rijen per OData-pagina ($top). 20000 is de gebruikelijke BC-bovengrens,
 * zodat een pagina niet uit tientallen regels bestaat en ook niet de hele
 * artikelposthistorie in één response stopt. Weigert BC die grootte, dan
 * valt nightly terug op de serverstandaard.
 */
const CONSUS_ODATA_PAGE_SIZE = 20000;
