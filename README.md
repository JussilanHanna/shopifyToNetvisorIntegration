# shopifyToNetvisorIntegration

Demo-integraatio: hakee Shopifysta uudet/päivittyneet tilaukset ja lähettää ne Netvisoriin myyntitilauksina (`salesinvoice.nv`, `invoicetype=order`).

## Ajo
1. Kopioi `.env.example` → `.env` ja täytä arvot
2. `composer install`
3. `composer run run` (tai `php bin/run.php`)

## Rakenne
- `ShopifyClient`: GraphQL orders-haku + cursor-paginointi
- `NetvisorClient`: POST `salesinvoice.nv` + header-auth + MAC (demo-canonical, helppo vaihtaa)
- `NetvisorSalesOrderMapper`: Shopify-order → Netvisor XML
- `OrderSyncService`: orkestroi (fetch → map → send) + checkpoint + idempotenssi
- `StateStore`: tiedostopohjainen checkpoint + “lähetetty jo” -muisti

## Tietoiset yksinkertaistukset
- MAC “canonical string” on demoversio: vaihda Netvisor-dokumentaation täsmämuotoon tarvittaessa
- Asiakas/tuote oletetaan löytyvän Netvisorista (käytetään default codeja env:stä)
- Ei queue/DLQ:ta; transient-retry on kevyt

## Tuotannossa tekisin
- Webhookit + polling fallback
- DLQ + retry worker + observability (metrics/tracing)
- State store esim. Redis/SQL
- Mapperille yksikkötestit
