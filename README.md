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
  jaar en de maandreeks). De snapshot bewaart totalen per leverancier/locatie,
  geen artikelposten. `$select` laat `Entry_Type` weg en haalt omzet alleen bij
  verkoop en `Document_No` alleen bij negatieve correctie. Die correctie vraagt
  `Document_No` van `WO` tot vóór `WP` (BC weigert `startswith`); PHP controleert
  het prefix daarna nog. Weigert BC het bereik, dan komt de volle set binnen en
  filtert PHP als voorheen. Elke query gebruikt `$top` 20000; een geweigerde
  paginagrootte valt terug op de BC-standaard. Bedrijven blijven na elkaar, niet
  parallel.
- **Koud of warm.** Zonder geldig watermerk (eerste run, snapshotversie 6, of
  `full`) haalt nightly per kalendermaand het hele venster. Na een geslaagde
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
