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
  feiten gaan weg vóór het volgende bedrijf. Voorraad die een query voor een
  ander bedrijf teruggeeft, blijft op schijf tot dat bedrijf zelf slaagt of
  als stale terugvalt op die voorraad. Het live snapshotbestand wordt pas
  aan het eind atomair vervangen.
- Omloopsnelheid (maand, kwartaal, jaar) = verkoophoeveelheid ÷ voorraad van
  de gekozen locaties. Eigen en EGT delen die noemer. Dropship telt niet mee.
- De pagina filtert eerst op afdeling, daarna op leverancier en locatie uit
  die cache. Perkins kan de eerste leverancierskeuze zijn en is te wissen.
- Eigen, EGT en dropship zijn inkooppaden, geen locatiecodes: leeg is magazijn,
  `DROP_SHIP` of leverancier `90052` is dropship, leverancier `90101` is EGT.
  KVT en HVT zijn alleen een hint voor eigen magazijn. Werkorderverbruik is
  `Negative Adjmt.` met documentnummer `WO…`, plus `Assembly Consumption`.
  OData filtert per Engelse optienaam (`Sale`, `Negative Adjmt.`,
  `Assembly Consumption`); het WO-prefix past Consus zelf toe.

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
