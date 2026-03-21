# Eroze právního státu – mini PHP app

Jednoduchá appka pro evidenci odkazů + shrnutí + kategorií.

## Spuštění lokálně

```bash
cd eroze.apps2.r73.info
APP_DNS=eroze.apps2.r73.info php -S 0.0.0.0:8080
```

Pak otevři `http://localhost:8080`.

## Data

V produkčním kontejnerovém prostředí se používá `/working/<dns-name>/app.sqlite`.
Pro tuto appku tedy standardně `/working/eroze.apps2.r73.info/app.sqlite`.

## Poznámka

Až pošleš DNS název cíle, připravím strukturu i pro deploy přes `php-k8s-app-installer` (repo `git@github.com:l-ra/agent-coder.git`, složka podle DNS).
