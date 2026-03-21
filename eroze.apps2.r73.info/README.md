# Eroze právního státu – mini PHP app

Jednoduchá appka pro evidenci odkazů + shrnutí + kategorií.

## Spuštění lokálně

```bash
cd eroze-pravniho-statu/public
php -S 0.0.0.0:8080
```

Pak otevři `http://localhost:8080`.

## Data

SQLite DB je v `data/app.sqlite`.

## Poznámka

Až pošleš DNS název cíle, připravím strukturu i pro deploy přes `php-k8s-app-installer` (repo `git@github.com:l-ra/agent-coder.git`, složka podle DNS).
