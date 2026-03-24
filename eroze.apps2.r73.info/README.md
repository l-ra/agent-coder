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

## Tajemství pro zápis

Formulář i endpoint `save.php` vyžadují pole `secret`.
Očekávaná hodnota se čte ze souboru:

`/working/eroze.apps2.r73.info/ingest_secret.txt`

Bez tohoto souboru endpoint vrátí HTTP 500 a zápis neproběhne.

## Admin endpointy

### Mazání

Endpoint: `POST /admin_delete.php`

Povinné pole:
- `secret` (admin tajemství ze souboru `/working/eroze.apps2.r73.info/admin_secret.txt`)

Alespoň jedno z polí:
- `id` (ID záznamu)
- `url` (konkrétní URL)

Odpověď je JSON, např. `{ "ok": true, "deleted": 1 }`.

### Výpis

Endpoint: `POST /admin_list.php`

Povinné pole:
- `secret` (stejné admin tajemství jako výše)

Volitelné pole:
- `limit` (1–100, default 20)

Odpověď je JSON, např. `{ "ok": true, "count": 20, "items": [...] }`.

## Poznámka

Až pošleš DNS název cíle, připravím strukturu i pro deploy přes `php-k8s-app-installer` (repo `git@github.com:l-ra/agent-coder.git`, složka podle DNS).
