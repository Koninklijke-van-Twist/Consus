# Consus

Dashboard voor veiligheidsvoorraad, verkopen, werkorderverbruik en omloopsnelheid
van KVT en HVT (sleutels.kvt.nl, Asclepius #1032).

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
- Artikelposten blijven het rolling venster van twaalf maanden (maand, kwartaal,
  jaar en de maandreeks). De snapshot bewaart totalen per leverancier/locatie
  en daarnaast compacte artikelregels (nummer, omschrijving als AppItemCard die
  heeft, voorraad, veiligheidsvoorraad, bestelpunt en WO-verbruik per maand).
  Geen ruwe artikelposten en geen verkoopmatrix per artikel. `$select` laat `Entry_Type` weg en haalt omzet alleen bij
  verkoop en `Document_No` alleen bij negatieve correctie. Die correctie vraagt
  `Document_No` van `WO` tot vóór `WP` (BC weigert `startswith`); PHP controleert
  het prefix daarna nog. Weigert BC het bereik, dan komt de volle set binnen en
  filtert PHP als voorheen. Elke query gebruikt `$top` 20000; een geweigerde
  paginagrootte valt terug op de BC-standaard. Bedrijven blijven na elkaar, niet
  parallel.
- **Koud of warm.** Zonder geldig watermerk (eerste run, snapshotversie 12, of
  `full`) haalt nightly per kalendermaand het hele venster. Een oudere
  snapshotversie is ook koud, net als een bedrijf waarvan elke rij nog een
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
- De pagina filtert eerst op afdeling, daarna op leverancier en locatie uit
  die cache. Perkins kan de eerste leverancierskeuze zijn en is te wissen.
  Een rij zonder kostenplaats of leverancier blijft kiesbaar als
  `(geen afdeling)` en `Geen leverancier`. Alleen een lege `rows`-lijst laat
  beide dropdowns op Alle staan. De catalogus `cost_centers` laat lege
  waarden weg; de keuzes komen uit `rows`.
  Onder de totalen staat een artikeltabel op artikelnummer, met
  veiligheidsvoorraad, voorraad van de gekozen locaties en WO-verbruik
  (maand, kwartaal, jaar).
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
php tests/nightly_debug_test.php
```

## auth.php

Geen `auth.php` in deze repository. Lokaal wordt eerst
`~/Repositories/auth.php` geladen, dezelfde gedeelde file naast Penates en
Aequitas. Bestaat die niet, dan `web/auth.php` op de server. Die staat in
`.gitignore` en wordt bij de FTP-deploy niet overschreven. Variabelen zijn
dezelfde als bij de andere apps: `$baseUrl`, `$environment`, `$auth_list` en
`$allowedUsers`.
