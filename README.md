# Consus

Dashboard voor verbruik per artikel en per jaar van KVT en HVT
(sleutels.kvt.nl, Asclepius #1032).

De pagina draait vanuit `web/`. `index.php` leest alleen de nachtelijke snapshot
en doet geen OData-verzoeken. `web/nightly.php` is de enige volledige BC-refresh.

## Werking

- `nightly.php` haalt voor KVT en HVT alle artikelen, locaties, leveranciers,
  voorraad, verkopen en werkorderverbruik op en schrijft
  `web/data/consus_snapshot.json`. Niets wordt vastgezet op Perkins of één locatie.
  OData-pagina's gaan per query naar een tijdelijk bestand en worden daarna
  regel voor regel verwerkt. Per bedrijf wordt meteen opgerold; de artikel-
  feiten gaan weg vóór het volgende bedrijf. Na elk bedrijf (gelukt of mislukt)
  wordt de snapshot atomair bijgewerkt, zodat de pagina al verse bedrijven
  toont terwijl het andere bedrijf nog loopt. Zolang een bedrijf nog niet
  aan de beurt is geweest, staat daarbij de melding dat de verversing nog
  bezig is; de pagina houdt dan de vorige cijfers van dat bedrijf. Voorraad die een query voor een
  ander bedrijf teruggeeft, blijft op schijf tot dat bedrijf zelf slaagt.
  Alleen als het stale blijft én de vorige snapshot geen rijen voor dat
  bedrijf heeft, gebruikt nightly die voorraad. Zijn er wel vorige rijen,
  dan blijven die staan en worden de tijdelijke bestanden verwijderd.
- Artikelposten lopen van 1 januari van (huidig jaar − `CONSUS_HISTORY_PREVIOUS_YEARS`)
  tot de peildatum. Standaard is dat het huidige jaar plus drie voorgaande
  jaren (`CONSUS_HISTORY_PREVIOUS_YEARS = 3`). De snapshot bewaart nog steeds
  totalen per leverancier/locatie (voor de warme merge en de voorraadlogica)
  en compacte artikelregels. Daarnaast staat `item_usage`: per bedrijf en
  artikelnummer, per maand, het interne verbruik en de verkoop per klant
  (`customers`, alleen klanten met een hoeveelheid; een lege sleutel is
  verkoop zonder klant). `days` bewaart alleen de overlapdag. Geen ruwe
  artikelposten. `$select` laat `Entry_Type` weg, haalt omzet alleen bij
  verkoop, `Source_No` en `Source_Type` alleen bij verkoop (optioneel; weigert
  BC ze, dan blijft de query staan) en `Document_No` alleen bij negatieve
  correctie. Die correctie vraagt
  `Document_No` van `WO` tot vóór `WP` (BC weigert `startswith`); PHP controleert
  het prefix daarna nog. Weigert BC het bereik, dan komt de volle set binnen en
  filtert PHP als voorheen. Elke query gebruikt `$top` 20000; een geweigerde
  paginagrootte valt terug op de BC-standaard. Bedrijven blijven na elkaar, niet
  parallel.
- **Koud of warm.** Zonder geldig watermerk (eerste run, snapshotversie 13, of
  `full`) haalt nightly per kalendermaand het hele venster. Een oudere
  snapshotversie is ook koud, net als een ontbrekend of later
  `windows.history_start` (het venster is groter geworden zonder
  versiewissel) en een bedrijf waarvan elke rij nog een
  lege leverancier heeft: anders blijft de historie op die lege groep staan.
  Na een geslaagde
  run staat op het bedrijf `ledger_through` (de peildatum) en
  `ledger_overlap_from` (dezelfde dag). Een latere nacht vraagt alleen
  `Posting_Date ge ledger_overlap_from`: die ene overlapdag vangt late
  boekingen, de dagen erna zijn nieuw. De overlapdag wordt van de bestaande
  maandtotalen afgetrokken en de verse query wordt erbij opgeteld. Oudere
  maanden blijven. Maand, kwartaal en jaar worden daarna opnieuw uit de
  maandtotalen berekend, zodat een nieuwe maand niet op het oude totaal wordt
  gestapeld. Voorraad, artikelen en kostenplaats gaan elke nacht volledig.
  Wisselt een artikel van leverancier of kostenplaats, dan blijft oude omzet
  op de vorige groep staan; `--full` rekent de historie opnieuw toe.
- **Hervatten binnen het bedrijf.** Na voorraad, na elke grootboekmaand
  (verkoop, negatieve correctie `WO`, assemblageverbruik) en na artikelen of
  kostenplaats schrijft nightly een tussenstand naast de snapshot
  (`consus_snapshot.json.checkpoint.json` plus `.checkpoint/`). Een maand komt
  daar pas in als de hele paginaketen binnen is; een afgebroken maand telt niet
  mee en laat geen half totaal achter. Dezelfde peildatum en hetzelfde venster
  slaan afgeronde stappen over en gaan verder bij de eerste open maand.
  `refreshed_on` komt pas als het bedrijf helemaal af is. De snapshot zelf
  publiceert pas dan de nieuwe totalen van dat bedrijf (een halve maand zou de
  omloop scheef trekken). Een warme run bewaart ook een tussenstand, maar alleen
  voor de korte reeks vanaf het watermerk. Een volgende kalenderdag laat de tussenstand
  vallen en gebruikt het watermerk op de gepubliceerde snapshot.
- Een bedrijf dat vandaag al vers in de snapshot staat (zelfde peildatum en
  snapshotversie) wordt bij een volgende start overgeslagen.
  `nightly.php?force=1`, `php nightly.php --force` of `CONSUS_NIGHTLY_FORCE=1`
  wist de tussenstand en draait afgeronde bedrijven opnieuw; het watermerk
  blijft gelden. Het hele venster opnieuw: daarnaast `?full=1`,
  `php nightly.php --full` of `CONSUS_FULL_LEDGER=1`. Alleen `full` zonder
  `force` slaat een bedrijf dat vandaag al af is nog over. Voortgang tijdens
  de run staat in `web/data/consus_snapshot.json.progress.json` (stap, maand,
  pagina's, regels). Op de server is die map `/var/www/html/consus/data/`
  (`web/` staat live als `consus/`).
- Een mislukt bedrijf houdt de vorige rijen én het watermerk. Alleen-voorraad
  (geen vorige rijen, wel voorraad uit een ander bedrijf) wist het watermerk.
- Omloopsnelheid (maand, kwartaal, jaar) = verkoophoeveelheid ÷ voorraad van
  de gekozen locaties. Eigen en EGT delen die noemer. Dropship telt niet mee.
  De pagina toont die snelheid niet meer; de snapshot bewaart de onderliggende
  totalen per leverancier en locatie voor de warme merge.
- De pagina filtert op bedrijf (KVT, HVT of Alle) en daarna op afdeling.
  Een rij zonder kostenplaats blijft kiesbaar als `(geen afdeling)`.
  Er is geen leverancier- of locatiefilter meer. Van de pagina zijn ook
  verdwenen: de totalen per leverancier en locatie, de omloopsnelheid, de
  verkopen per maand per inkooppad, het werkorderverbruik per periode en de
  oude artikeltabel (omschrijving, voorraadkolom, WO maand/kwartaal/jaar).
  In plaats daarvan staat één tabel met een tab per kalenderjaar.
- VoorraadPerBedrijf mag hetzelfde artikel op dezelfde locatie twee keer
  sturen. De eerste niet-nul van voorraad, veiligheidsvoorraad en bestelpunt
  wint; een eerdere nulregel blokkeert die waarde niet en een tweede niet-nul
  telt niet dubbel. `Company_Name` mag de lange bedrijfsnaam of het label
  `KVT` / `HVT` zijn, ook als `K.V.T.` of `KVT B.V.`. `KVT Gas` blijft erbuiten.
  Een lege `Company_Name` kijkt nog naar `Bedrijfsnaam` of `Company`. Wijst
  `Company_Name` alleen naar het bedrijf van de query en een ander veld naar
  KVT of HVT, dan wint dat andere veld. Een gevulde naam die bij het andere
  bedrijf hoort wordt niet aan de query-bron gehangen; die regel gaat mee in
  de checkpoint en bij een hervatting opnieuw naar dat bedrijf. Een checkpoint
  met alleen nullen wordt opnieuw opgehaald. Levert de eigen query geen
  aantallen, dan gebruikt nightly die regels als ze in de query van het
  andere bedrijf stonden. Blijft de hoeveelheid daarna toch op het andere
  bedrijf staan, dan verplaatst nightly die hoeveelheid per artikelnummer,
  en alleen als alle drie gelden: dit bedrijf heeft van dat nummer geen
  voorraad maar wel werkorderverbruik, veiligheidsvoorraad of bestelpunt;
  het andere bedrijf heeft de hoeveelheid; het andere bedrijf heeft van dat
  nummer geen werkorderverbruik. Verkoop alleen telt niet mee, omdat
  dropship geen eigen voorraad is. Veiligheidsvoorraad en bestelpunt
  verhuizen niet mee. Het totaal over alle bedrijven blijft gelijk: de
  hoeveelheid gaat van de ene regel af en op de andere.
  De rest blijft liggen. Dat is voorraad van een nummer dat alleen op het
  andere bedrijf voorkomt, dat daar ook verbruik heeft, of dat hier alleen
  verkocht wordt. Een klein totaal op KVT naast een groot totaal op Alle is
  die rest, niet een afgekapte verplaatsing. De opmerking noemt het
  verplaatste aantal. Ze zegt niet dat de rest ook in aanmerking kwam; de
  opmerking dat er niets verplaatst kon worden verschijnt alleen als het
  totaal van dit bedrijf op 0 blijft.
  Een locatiecode bepaalt het bedrijf niet. `KVT`, `HVT`, `M001` en `M7xx`
  zijn magazijnlocaties en kunnen in beide bedrijven voorkomen. Nightly laadt
  elke locatie. `KVT` en `HVT` in de voetnoot zijn alleen een hint voor eigen
  magazijn, geen sleutel om voorraad van bedrijf te wisselen.
  Voorraad leest `Inventory`. Ontbreekt dat veld, dan `Voorraad` of
  `Quantity_on_Hand`. Een aanwezige 0 blijft 0.
  Leverancier en afdeling van die voorraad komen op de bestaande regel als
  die nog leeg was. Bestelpunt leest `Reorder_Point`, en anders `Bestelpunt`.
  Veiligheidsvoorraad en bestelpunt mogen op de artikelkaart staan als
  VoorraadPerBedrijf alleen de voorraad vult. Die waarde gaat één keer naar
  de locatie die al voorraad heeft. Blijft een van die twee 0 terwijl er wel
  voorraad is, of ontbreekt de locatie terwijl er wel voorraad is, dan zet
  nightly een opmerking in de snapshot. Ontbreekt de afdeling, ook als de
  voorraad nog 0 is, dan eveneens. De pagina toont die onder de kop “De
  nachtrun is afgerond, met opmerkingen.” Afdeling is kostenplaats en is
  Global Dimension 1, hetzelfde als Demeter. Nightly leest
  `GeneralLedgerSetup.Global_Dimension_1_Code` (bijvoorbeeld
  `SALES_DEPARTMENT`, niet een vast nummer 15) en daarna
  `DimensionValueList` voor die code, alleen ongeblokkeerde numerieke codes
  onder 100 met een tekstnaam. De dropdown toont `code - naam`. Op de regel
  staat de genormaliseerde code (`05` en `5` zijn gelijk); een lege afdeling
  blijft `(geen afdeling)` met waarde `__none__`. De code op het artikel komt
  van `Global_Dimension_1_Code` of `LVS_Global_Dimension_1_Code` op de
  artikelkaart, de voorraad of de artikelpost, en anders van
  DefaultDimensions voor diezelfde setupcode. Weigert BC het tabelfilter,
  dan blijft het filter op die code staan. Globale dimensie 2 wordt niet
  gelezen.
  Een geweigerd veld op AppItemCard (bijvoorbeeld
  omschrijving of `LVS_Vendor_Name`) wordt uit `$select` gehaald; nummer en
  leverancier blijven. Een lege tussenstap voor voorraad of artikelen wordt
  opnieuw opgehaald, niet als klaar overgeslagen.
- Eigen, EGT en dropship zijn inkooppaden, geen locatiecodes: leeg is magazijn,
  `DROP_SHIP` of leverancier `90052` is dropship, leverancier `90101` is EGT.
  KVT en HVT zijn alleen een hint voor eigen magazijn. Werkorderverbruik is
  `Negative Adjmt.` met documentnummer `WO…`, plus `Assembly Consumption`.
  OData filtert per Engelse optienaam (`Sale`, `Negative Adjmt.`,
  `Assembly Consumption`) en bij negatieve correctie op documentnummers
  vanaf `WO` tot vóór `WP`. Consus controleert dat prefix daarna nog eens.

## Jaartabel

De pagina toont één tabel. Tab 1 is het huidige kalenderjaar, daarna de
voorgaande jaren uit `CONSUS_HISTORY_PREVIOUS_YEARS` (nu 3, dus vier tabs).
Kolommen, in deze volgorde: Artikelnummer, Veiligheidsvoorraad, Totaal
verbruik Jaar, Januari tot en met December, Kwartaal 1 tot en met 4, Intern
Verbruik. Elke kolomkop sorteert (tekst met artikelnummer, getallen numeriek).

**Verbruik** in een maand, een kwartaal of het jaar is `Sale` plus intern
verbruik. `Sale` komt uit `ItemLedgerEntries` (`Entry_Type eq 'Sale'`), velden
`Item_No`, `Quantity`, `Posting_Date`. BC zet uitgaand negatief; Consus draait
het teken om, zodat een retour aftrekt. Intern verbruik telt in die kolommen
mee zolang `CONSUS_USAGE_INCLUDES_INTERNAL` aan staat, verkoop zolang
`CONSUS_USAGE_INCLUDES_SALE` aan staat. Het totaal is de som van de twaalf
maanden en daardoor ook de som van de vier kwartalen.

**Intern verbruik** (eigen kolom, altijd het jaartotaal) is `Negative Adjmt.`
met `Document_No` dat met `WO` begint, plus `Assembly Consumption`. Zelfde
queries als de nachtrun. Velden: `Item_No`, `Quantity`, `Posting_Date`, en bij
de negatieve correctie `Document_No`.

Verkoop bewaart `Source_No` per maand als `Source_Type` `Customer` is (of als
dat veld ontbreekt). Een ander brontype telt wel in het verbruik, maar niet
bij een klant. De klantcatalogus probeert `Customer`, dan `Customer_Card`,
dan `Customers`, met `No` en `Name`. Lukt geen entiteit, dan blijven de
nummers uit de posten over en komt er een opmerking in de snapshot.

Bovenaan staan twee uitsluitingen, per ingelogde gebruiker:

- klanten (combobox, nummer of naam, meerdere waarden kommagescheiden);
- artikelnummers (zelfde soort combobox).

Uitgesloten klanten vallen uit het verbruik van de tabel, de modal, de gele
markering en de export. Uitgesloten artikelen verdwijnen uit tabel en export.
Opslag: `web/data/prefs/<sha1 van het e-mailadres in lowercase>.json`, atomair
via een tijdelijk bestand en een file lock. In hetzelfde bestand staat
`page_size` (10, 20, 50, 100, 150, 200, 300 of 500; standaard 20). Een
opslag van alleen de paginagrootte laat de uitsluitingen staan, en omgekeerd.
`POST prefs.php` eist een ingelogde sessie, een CSRF-token en dezelfde host
in `Origin` of `Referer`. Die map gaat niet mee in git en niet mee in de
FTP-deploy (`data/` blijft staan).

De tabel toont alleen de rijen van de huidige pagina. De volledige, al
gefilterde set staat als JSON in de pagina; sorteren en de jaartabs lopen
daarover en springen terug naar pagina 1. Er staat één tabel in de DOM, niet
een gevulde tabel per jaar. Rechtsboven staat “Regels per pagina”. Onder en
boven de tabel: vorige, volgende, paginanummers en “x–y van N artikelen”.

**xlsx.** `export.php` schrijft een echt werkboek met `ZipArchive` en
SpreadsheetML, zonder Composer. Ontbreekt de extensie, dan komt een
Nederlandse foutmelding. Eén werkblad per jaar (zelfde kolommen, header met
autofilter, getallen als numerieke cellen) en als laatste blad `Filters` met
de kolommen Gefilterde klanten en Gefilterde artikels. Sheetnamen blijven
binnen 31 tekens. De export volgt bedrijf, afdeling, beide uitsluitingen en
de gekozen sortering (`sort` is de kolomindex, `dir` is `asc` of `desc`).
Hij bevat alle gefilterde rijen, niet alleen de zichtbare pagina.

**Modal.** Een klik op het artikelnummer toont veiligheidsvoorraad, het
gemiddelde jaarverbruik, dit jaar al verbruikt en het verwachte resterende
verbruik. Het gemiddelde loopt over de volledige voorgaande kalenderjaren die
in het venster liggen (nu 3 jaren; het lopende jaar telt niet mee, ook niet
op 31 december). Zonder zo'n jaar staat er “geen historie”: geen gemiddelde,
geen restant, en het nummer wordt niet geel. Verwacht resterend = gemiddelde
× (dagen tot en met 31 december / dagen in het jaar). 1 januari is het hele
jaar, 31 december is één dag (`CONSUS_REMAINING_DAYS_INCLUDE_TODAY`).

**Te weinig voorraad** (`CONSUS_LOW_STOCK_WHEN_INVENTORY_BELOW_EXPECTED`):
huidige voorraad is strikt lager dan dat restant. Het artikelnummer krijgt
dan een gele achtergrond. De legenda onder de tabs zegt hetzelfde. Gelijke
voorraad is niet geel.

Een wijziging van `CONSUS_HISTORY_PREVIOUS_YEARS` hoort samen met een hogere
`CONSUS_SNAPSHOT_VERSION`. Versie 13 maakt de eerstvolgende nachtrun koud:
het watermerk van versie 12 geldt niet, checkpoints per maand lopen het
venster vanaf 1 januari opnieuw, en pas daarna is de jaartabel gevuld.

Lokaal gebruikt Consus `~/Repositories/auth.php` (naast de repo). Op de server
blijft dat `web/auth.php`.

```sh
php web/nightly.php
```

Ingelogde diagnose bij een kale HTTP 500: open `nightly.php?log_debug=1`
(`true` en `yes` mogen ook). Alleen ná de bestaande logincheck; anoniem
blijft het 403, zonder JSON-debug. Het verzoek zet foutrapportage aan en
probeert bij een fatal of timeout alsnog JSON te sturen (`ok`, `error`,
`debug.type`, `message`, `file`, `line`). Een nog steeds lege 500 komt dan
van de proxy of van Apache die de worker stopt, niet van een PHP-fatal die
nog kon schrijven. Zonder parameter en op de CLI verandert er niets.
Geen tokens, geen inhoud van `auth.php`, geen volledige `$_SERVER`.

Classificatietests, zonder Business Central:

```sh
php tests/consus_data_test.php
php tests/consus_usage_test.php
php tests/consus_prefs_test.php
php tests/consus_xlsx_test.php
php tests/consus_page_test.php
php tests/nightly_debug_test.php
php tests/consus_retour_test.php
```


## Retourlijst (Asclepius #1159)

Onder de jaartabel staat de **Retourlijst**: onderdelen die nog terug kunnen
naar de leverancier. De lijst is afdelingsonafhankelijk.

**Afdeling kiezen.** Bovenaan de sectie kies je een afdeling (zelfde codes als
het afdelingsfilter, Global Dimension 1 op de factuurregel; Perkins = 15). Pas
dan verschijnen de retourkandidaten. Zonder keuze neemt de sectie de afdeling
uit het filter bovenaan over (`?retour_afdeling=15` kiest direct).

**Regels per afdeling** (knop **Regels beheren**), gedeeld voor alle gebruikers.
Een regel is:

| Veld | Betekenis |
| --- | --- |
| Leverancier (leveranciersnr.) | bijv. `90101` |
| Type | optioneel, standaard **Alle**; de lijst komt uit de nightly (`types_by_vendor`) |
| Termijn (dagen) | 1–365, gerekend vanaf de factuurdatum (`Document_Date`) |
| Minimaal bedrag (€) | per artikel per factuur |

Een afdeling kan meerdere regels hebben. Bij livegang heeft elke afdeling
**geen** regels ("Nog geen regels voor deze afdeling"); de oude instellingen
(termijnen en startdatum per afdeling) worden genegeerd. Voorbeeld Perkins (15):
`90101 / Spoed (57401) / 30 dagen / € 50` en `90101 / Voorraad (57420) / 90 dagen / € 50`.

**Types.** Eén type-provider (`consus_retour_vendor_types`) bepaalt welke types
een leverancier kent. Nu: Perkins (`90101`) kent 57401/57420 uit de PO-vlaggen
`KVT_Export_Status_Perkins_EGT` / `_CSV` (EGT aan en CSV uit = spoed 57401,
elke andere combinatie = voorraad 57420; Ivan, 08-10-2026). Andere leveranciers
kennen alleen **Alle**. Een regel met een type dat de leverancier niet kent,
vindt niets; de editor toont dan een hint. Vóór de eerste nightly biedt de
editor **Alle** plus de types uit opgeslagen regels.

**Bron.** `nightly.php` haalt na de snapshot de retourdata op voor de
leveranciers uit alle regels en schrijft `web/data/consus_retour.json` (los
bestand; een fout raakt de snapshot niet). Zonder regels wordt niets opgehaald.
Via Mímir met BC-fallback, `max_age` 14400 zoals de rest van nightly. Venster:
180 dagen (of de langste termijn als die langer is), zodat een gewijzigde
termijn of minimum direct werkt. Een regel waarvan de leverancier nog niet is
opgehaald toont "Gegevens volgen na de volgende nightly".

- `GeboekteInkoopfacturen` (pagina 146): leveranciers uit de regels,
  `Document_Date` binnen het venster. `Vendor_Invoice_No` is het
  leveranciersfactuurnummer.
- `GeboekteInkoopfactuurRegels` (pagina 529): alleen artikelregels
  (`Item`/`Artikel`). `Order_No` geeft het PO, anders de voorgaande tekstregel
  ("Order No. PO…:").
- `AppPurchaseOrder` (fallback `GearchiveerdeInkooporders`): PO-vlaggen, alleen
  voor leveranciers met types. Een PO dat niet meer in BC staat telt als voorraad.
- `AppItemCard`, `ItemLedgerEntries` (`Open eq true`), `ReservationEntries`
  (reservering naar inkoopretourorder 39/5 = al op retour).
- Bincontrole (#1165): `BinContent` (anders `Magazijnposten`) op `HVT`.
  Bin-inhoud lager dan boekvoorraad → **Controleren**. Voorraad alleen op
  M5xx/M6xx → **Geen bincontrole**.

**Berekening** (bij elke weergave):

1. Regels van hetzelfde artikel op dezelfde factuur worden opgeteld.
2. Een regel van de afdeling met dezelfde leverancier en type Alle of hetzelfde
   type, waarvan de termijn nog loopt.
3. Geen veiligheidsvoorraad. Vrije voorraad = boekvoorraad − reserveringen;
   de nieuwste factuur krijgt de vrije voorraad eerst.
4. Retourwaarde = retouraantal × gemiddelde inkoopprijs ≥ minimaal bedrag.

**Details en garantie.** Klik op een regel voor een modal met factuur, PO,
factuurdatum, artikel, aantallen, bedragen, voorraad/reserveringen/bins en
waarom hij op de lijst staat. Daar markeer je een regel als **garantie** (voor
alle gebruikers); garantieregels staan onderaan en zijn geen kandidaat. De
garantielijst per PO blijft bestaan (standaard `PO52600987`).

Opslag: `web/data/retour_settings.json` (`rules`, `garantie_orders`,
`garantie_lines`; atomair, file lock, CSRF + same-origin).

**Export.** `retour_export.php?afdeling=15` geeft één werkblad (zonder
afdeling: alle afdelingen met regels). `export.php` krijgt hetzelfde blad
`Retourkandidaten` vóór `Filters`.

```sh
php tests/consus_retour_test.php
```

## Mímir (optional)

Zet in `web/auth.php` of `~/Repositories/auth.php` (niet in git):

```php
$mimirApi  = 'mimir_…';
// optioneel:
$mimirBase = 'https://sleutels.kvt.nl/mimir/api';
// $baseUrl, $environment, $auth en $auth_list blijven in dit bestand staan.
```

Met `$mimirApi` gezet proberen company-discovery en alle OData-fetches (nightly snapshot via `consus_each_url_live` / `odata_get_all`, ook als CLI) eerst Mímir. Faalt die aanroep (verbinding/timeout, non-2xx, ongeldige JSON of een Mímir-foutpayload), dan haalt Consus dezelfde data op via het oude directe Business Central-pad (`$baseUrl`, `$auth` / `$auth_list`, `$environment`, lokale odata-filecache) en slaat Mímir voor de rest van dat PHP-verzoek over. Laat die BC-gegevens in `auth.php` naast `$mimirApi` staan; ontbreken ze, dan komt de oorspronkelijke Mímir-fout terug. Zonder `$mimirApi` blijft alleen het directe BC-pad actief.

**max_age-beleid**

| Soort fetch | `max_age` naar Mímir |
| --- | --- |
| `nightly.php` snapshot-build | **14400** (`CONSUS_NIGHTLY_MAX_AGE`, 4u) |
| UI / on-demand / live | **86400** (`CONSUS_ODATA_TTL`) — `index.php` doet zelf geen OData |
| `hourly.php` | niet aanwezig in Consus |

Tim moet `$mimirApi` (en optioneel `$mimirBase`) lokaal/op de server zetten, en de BC-credentials (`$baseUrl`, `$environment`, `$auth` / `$auth_list`) in hetzelfde `auth.php` laten staan voor de automatische fallback. `auth.php` wordt niet gecommit. Zie [Mímir Implementatie](https://wiki.kvt.nl/books/mimir/page/implementatie).

## auth.php

Geen `auth.php` in deze repository. Lokaal wordt eerst
`~/Repositories/auth.php` geladen, dezelfde gedeelde file naast Penates en
Aequitas. Bestaat die niet, dan `web/auth.php` op de server. Die staat in
`.gitignore` en wordt bij de FTP-deploy niet overschreven. Variabelen zijn
dezelfde als bij de andere apps: `$baseUrl`, `$environment`, `$auth_list` en
`$allowedUsers`. Die BC-gegevens blijven naast `$mimirApi` staan; ze zijn de
fallback als Mímir niet bereikbaar is.
