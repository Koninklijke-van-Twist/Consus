<?php

/**
 * Alle knoppen die Tim nog moet bevestigen staan hier.
 * Querylogica leest alleen deze constanten; leverancier en afdeling
 * worden niet in de OData-filters vastgezet.
 */

const CONSUS_SNAPSHOT_VERSION = 14;

/**
 * Jaartabs op de pagina: het huidige kalenderjaar plus dit aantal
 * voorgaande jaren. Het grootboekvenster begint daardoor op 1 januari
 * van (huidig jaar − dit aantal). Een andere waarde dwingt de eerstvolgende
 * nightly koud, omdat de snapshotversie meestijgt als het venster anders
 * moet — pas dan ook CONSUS_SNAPSHOT_VERSION aan.
 */
const CONSUS_HISTORY_PREVIOUS_YEARS = 3;

/**
 * Verbruik in maand, kwartaal en totaal.
 * Sale is de klantafname (BC-teken omgedraaid, retouren trekken af).
 * Intern is Negative Adjmt. met Document_No WO… plus Assembly Consumption.
 * Zet een vlag op false om die bron uit de maandkolommen te laten; de kolom
 * Intern Verbruik blijft altijd het interne jaartotaal.
 */
const CONSUS_USAGE_INCLUDES_SALE = true;
const CONSUS_USAGE_INCLUDES_INTERNAL = true;

/**
 * Te weinig voorraad: huidige voorraad is strikt lager dan het verwachte
 * resterende verbruik van dit jaar. Het artikelnummer wordt dan geel.
 */
const CONSUS_LOW_STOCK_WHEN_INVENTORY_BELOW_EXPECTED = true;

/**
 * Resterend deel van het jaar telt de peildatum mee, tot en met 31 december.
 * 1 januari is dan het hele jaar; 31 december is één dag.
 */
const CONSUS_REMAINING_DAYS_INCLUDE_TODAY = true;

/**
 * Regels per pagina in de jaartabel. Alleen deze waarden worden opgeslagen.
 * De standaard geldt als de voorkeur ontbreekt of ongeldig is.
 */
const CONSUS_PAGE_SIZES = [10, 20, 50, 100, 150, 200, 300, 500];
const CONSUS_DEFAULT_PAGE_SIZE = 20;

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
 * Tabel 32 publiceert geen inkoopcode en geen leveranciersnr. Dropship komt
 * uit Drop_Shipment op PageItemLedgerEntries; de namen hieronder blijven voor
 * regels die ze wel meegeven (tests, oude snapshots).
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
 * Geen optionele namen meer: VoorraadPerBedrijf publiceert alleen Item_No,
 * Company_Name, Inventory, Quantity_Available, Quantity_on_Purchase_Orders,
 * Quantity_in_Reservation, Safety_Stock_Quantity en Reorder_Point
 * ($metadata kvtmdlive_aad, 8 okt 2026). Er is geen locatieveld; voorraad
 * staat zonder locatie en dat is geen melding.
 */
const CONSUS_STOCK_OPTIONAL_FIELDS = [];

const CONSUS_ITEM_ENTITY = 'AppItemCard';
/**
 * Exacte veldnamen uit $metadata (kvtmdlive_aad). AppItemCard heeft geen
 * kostenplaatsveld; de afdeling komt uit DefaultDimensions.
 */
const CONSUS_ITEM_FIELDS = [
    'No',
    'Vendor_No',
    'LVS_Vendor_Name',
    'Description',
    'Safety_Stock_Quantity',
    'Reorder_Point',
];
const CONSUS_ITEM_OPTIONAL_FIELDS = [];

/**
 * PageItemLedgerEntries is dezelfde tabel 32 als ItemLedgerEntries, maar
 * publiceert ook Source_Type, Source_No, Global_Dimension_1_Code en
 * Drop_Shipment. ItemLedgerEntries heeft die velden niet.
 */
const CONSUS_LEDGER_ENTITY = 'PageItemLedgerEntries';
/**
 * Velden die elke artikelpost nodig heeft. Entry_Type zit al in $filter.
 * Omzet en documentnummer komen er alleen bij voor de query die ze gebruikt.
 */
const CONSUS_LEDGER_FIELDS = [
    'Item_No',
    'Quantity',
    'Posting_Date',
    'Location_Code',
    'Global_Dimension_1_Code',
    'Drop_Shipment',
];
/** Inkoopcode en leveranciersnr. staan niet op de artikelpost; Drop_Shipment wel. */
const CONSUS_LEDGER_OPTIONAL_FIELDS = [];

/**
 * Klant op een verkooppost. Source_No is het klantnummer als Source_Type
 * Customer (of Klant) is. Beide staan op PageItemLedgerEntries.
 */
const CONSUS_LEDGER_SOURCE_NO_FIELD = 'Source_No';
const CONSUS_LEDGER_SOURCE_TYPE_FIELD = 'Source_Type';
const CONSUS_CUSTOMER_SOURCE_TYPES = [
    'Customer',
    'Klant',
];

/**
 * Klantcatalogus voor de suggesties (nummer + naam). AppCustomerCard levert
 * No en Name; Customer, Customer_Card en Customers bestaan niet.
 */
const CONSUS_CUSTOMER_ENTITIES = [
    'AppCustomerCard',
];
const CONSUS_CUSTOMER_NO_FIELDS = [
    'No',
];
const CONSUS_CUSTOMER_NAME_FIELDS = [
    'Name',
];

/**
 * Afdeling is kostenplaats: Global Dimension 1, hetzelfde als Demeter.
 * De code komt uit GeneralLedgerSetup (bijvoorbeeld SALES_DEPARTMENT), niet
 * uit een vast nummer. DimensionValueList levert de namen. DefaultDimensions
 * koppelt die code aan het artikel (tabel 27). Globale dimensie 2 niet.
 */
const CONSUS_GL_SETUP_ENTITY = 'GeneralLedgerSetup';
const CONSUS_GL_SETUP_FIELDS = [
    'Global_Dimension_1_Code',
];
const CONSUS_DIMENSION_VALUE_ENTITY = 'DimensionValueList';
const CONSUS_DIMENSION_VALUE_FIELDS = [
    'Dimension_Code',
    'Code',
    'Name',
    'Blocked',
];
const CONSUS_DIMENSION_ENTITY = 'DefaultDimensions';
const CONSUS_DIMENSION_FIELDS = [
    'No',
    'Dimension_Code',
    'Dimension_Value_Code',
];
const CONSUS_DIMENSION_OPTIONAL_FIELDS = [];
const CONSUS_DIMENSION_TABLE_ID = 27;
/** Terugval voor de afdeling als een artikel geen waarde op Globale dimensie 1 heeft. */
const CONSUS_DEPARTMENT_FALLBACK_DIMENSION = 'COST_CENTER';

/**
 * Aantal rijen per OData-pagina ($top). 20000 is de gebruikelijke BC-bovengrens,
 * zodat een pagina niet uit tientallen regels bestaat en ook niet de hele
 * artikelposthistorie in één response stopt. Weigert BC die grootte, dan
 * valt nightly terug op de serverstandaard.
 */
const CONSUS_ODATA_PAGE_SIZE = 20000;


/**
 * Mímir max_age for UI / on-demand / live recheck when $mimirApi is set.
 * Consus index.php doet geen OData; deze TTL geldt als er toch live wordt gehaald.
 * Valt Mímir uit, dan geldt deze max_age niet: de fetch gaat direct naar BC.
 */
const CONSUS_ODATA_TTL = 86400;

/**
 * Mímir max_age for nightly.php snapshot builds (4h — cache sharing, nightly still refreshes).
 */
const CONSUS_NIGHTLY_MAX_AGE = 14400;

