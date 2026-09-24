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
  `Negative Adjmt.` met documentnummer `WO…`, plus `Assembly Consumption`.

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
