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
  de gekozen locaties. Eigen en EGT delen die noemer. EGT blijft leeg tot
  `CONSUS_LOCATIONS_EGT` gevuld is. Dropship telt niet mee.
- De pagina filtert eerst op afdeling, daarna op leverancier en locatie uit
  die cache. Perkins kan de eerste leverancierskeuze zijn en is te wissen.
- Locatiekolommen (Ariadne): eigen is `KVT` en `HVT`, dropship is `BYKLANT`
  (“Bij klant opgeslagen”). EGT-codes zijn een lege TODO; die worden niet
  verzonnen. Andere locaties vallen in overig. Werkorderverbruik is
  `Negative Adjmt.` met documentnummer `WO…` (bijvoorbeeld WO2601478), plus
  `Assembly Consumption`. Verkoop komt uit artikelposten met Entry_Type Sale.

Lokaal, zodra `web/auth.php` op de machine staat:

```sh
php web/nightly.php
```

Classificatietests, zonder Business Central:

```sh
php tests/consus_data_test.php
```

## auth.php

`web/auth.php` staat alleen op de server en wordt niet ingecheckt. Zelfde
variabelen als Penates en Aequitas: `$baseUrl`, `$environment`, `$auth_list`
en `$allowedUsers`. Geen wachtwoorden in deze repository.
