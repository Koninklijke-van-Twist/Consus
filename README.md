# Consus

Dashboard voor veiligheidsvoorraad, verkopen, werkorderverbruik en omloopsnelheid
van KVT en HVT (sleutels.kvt.nl, Asclepius #1032).

De pagina draait vanuit `web/`. `index.php` leest alleen de nachtelijke snapshot
en doet geen OData-verzoeken. `web/nightly.php` is de enige volledige BC-refresh.

## Werking

- `nightly.php` haalt per KVT/HVT-bedrijf voorraad, leverancier, verkopen en
  werkorderverbruik op en schrijft `web/data/consus_snapshot.json`.
- Omloopsnelheid (maand, kwartaal, jaar) = verkoophoeveelheid ÷ voorraad.
- Leverancier en afdeling filteren op de pagina. De OData-filters zelf zitten
  niet vast op Perkins of één afdeling.
- Locatiecodes, werkorder-entrytypes en de standaardleverancier staan in
  `web/consus_config.php`.

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
