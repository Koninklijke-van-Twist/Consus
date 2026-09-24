# Consus

Dashboard voor veiligheidsvoorraad, verkopen, werkorderverbruik en omloopsnelheid
van KVT en HVT (sleutels.kvt.nl, Asclepius #1032).

De pagina draait vanuit `web/`. `index.php` leest alleen de nachtelijke snapshot
en doet geen OData-verzoeken. `web/nightly.php` is de enige volledige BC-refresh.

## Werking

- `nightly.php` haalt voor KVT en HVT alle artikelen, locaties, leveranciers,
  voorraad, verkopen en werkorderverbruik op en schrijft
  `web/data/consus_snapshot.json`. Niets wordt vastgezet op Perkins of één locatie.
- Omloopsnelheid (maand, kwartaal, jaar) = verkoophoeveelheid ÷ voorraad van
  de gekozen locaties. Eigen en EGT delen die noemer. Dropship telt niet mee.
- De pagina filtert eerst op afdeling, daarna op leverancier en locatie uit
  die cache. Perkins kan de eerste leverancierskeuze zijn en is te wissen.
- Eigen, EGT en dropship zijn inkooppaden, geen locatiecodes: leeg is magazijn,
  `DROP_SHIP` of leverancier `90052` is dropship, leverancier `90101` is EGT.
  KVT en HVT zijn alleen een hint voor eigen magazijn. Werkorderverbruik is
  een negatieve correctie met documentnummer `WO…`, plus assemblageverbruik.
  OData filtert per Nederlands bijschrift (`Verkoop`, `Negatieve correctie`,
  `Assemblageverbruik`); het WO-prefix past Consus zelf toe.

Lokaal gebruikt Consus `~/Repositories/auth.php` (naast de repo). Op de server
blijft dat `web/auth.php`.

```sh
php web/nightly.php
```

Classificatietests, zonder Business Central:

```sh
php tests/consus_data_test.php
```

## auth.php

Geen `auth.php` in deze repository. Lokaal wordt eerst
`~/Repositories/auth.php` geladen, dezelfde gedeelde file naast Penates en
Aequitas. Bestaat die niet, dan `web/auth.php` op de server. Die staat in
`.gitignore` en wordt bij de FTP-deploy niet overschreven. Variabelen zijn
dezelfde als bij de andere apps: `$baseUrl`, `$environment`, `$auth_list` en
`$allowedUsers`.
