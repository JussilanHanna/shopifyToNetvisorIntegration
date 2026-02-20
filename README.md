# shopifyToNetvisorIntegration

Demo-integraatio: hakee Shopifysta uudet/päivittyneet tilaukset ja lähettää ne Netvisoriin myyntitilauksina  
(`salesinvoice.nv`, `invoicetype=order`).

## Miten ratkaisu toimii (dataflow)

1. **Ajastus / käynnistys**
   - `bin/run.php` on entrypoint (CLI)
   - Integraatio on tarkoitettu ajettavaksi esim. 15 minuutin välein (cron / scheduler)

2. **Checkpoint & idempotenssi**
   - `StateStore` pitää kirjaa:
     - `lastRunIso`: viimeisen ajon checkpoint (Shopifyn `updatedAt`-perusteinen haku)
     - `sent`: lista jo lähetetyistä Shopify-order-id:istä (idempotenssi)
   - Checkpointiin lisätään pieni **overlap** (esim. -30s), jotta reunatapauksissa tilauksia ei huku

3. **Tilausten haku Shopifysta**
   - `ShopifyClient` hakee tilaukset GraphQL:llä:
     - `orders(query: "updated_at:>LAST_RUN")`
     - cursor-paginointi (`pageInfo.hasNextPage`, `endCursor`)
   - Vastaukset normalisoidaan yksinkertaiseen order-rakenteeseen

4. **Mappaus Netvisor-muotoon**
   - `NetvisorSalesOrderMapper` muuntaa orderin Netvisorin XML-muotoon:
     - `InvoiceType=Order`
     - asiakas (demo: env:stä `NETVISOR_DEFAULT_CUSTOMER_CODE`)
     - osoite (delivery*)
     - rivit (SKU → fallback env:stä `NETVISOR_DEFAULT_PRODUCT_CODE`)
     - summat valuutta-attribuutilla

5. **Lähetys Netvisoriin**
   - `NetvisorClient` lähettää XML:n `POST /salesinvoice.nv`
   - Autentikointi: Netvisor-headerit + MAC-laskenta
   - Kevyt retry transient-virheille (429 / 5xx)

## Asennus

1. Kopioi `.env.example` → `.env`
2. Täytä ympäristömuuttujat (Shopify/Netvisor)
3. `composer install`
4. `php bin/run.php`

## Debug

- Shopify token:
php bin/get_shopify_token.php

Netvisor mode:
NETVISOR_MODE=mock kehitys/testaus
NETVISOR_MODE=live tuotannossa

## Netvisor (mock-mode)

Koska Netvisor-tunnuksia ei ole saatavilla tässä harjoituksessa, integraatio tukee `NETVISOR_MODE=mock` -tilaa.

Mock-moodissa:
- integraatio muodostaa Netvisor `salesinvoice` XML:n normaalisti
- “lähetys” kirjoitetaan tiedostoon `./out/netvisor/*_salesinvoice.xml`
- syntyy feikki vastaus `*_response.xml` ja meta `*_meta.json`
- `StateStore` merkitsee tilauksen lähetetyksi (idempotenssi toimii)

Näin voidaan demonstroida end-to-end dataflow: Shopify → Netvisor payload + response ilman oikeaa Netvisor-yhteyttä.

## Esimerkki out-hakemistosta

out/netvisor/
  20260220_093617_abcd1234_salesinvoice.xml
  20260220_093617_abcd1234_response.xml
  20260220_093617_abcd1234_meta.json

## Yksinkertaistukset (demo)

- Ei oikeaa Netvisor live-yhteyttä ilman tunnuksia
- Ei queue/worker retry
- Ei asiakas/tuote-synkronointia
- Ei täysin kattavaa virheiden luokittelua

## Mitä tekisin tuotantoympäristössä

- Checkpoint & idempotenssi Redisissä tai tietokannassa
- Asynkroninen käsittely (queue + worker)
- Tarkempi virheiden luokittelu + retry-politiikka
- Observability: structured logs, metrics, tracing
- Asiakkaiden ja tuotteiden synkronointi Netvisoriin
- Versioitu mappaus + schema-validointi XML:lle

## Koetut haasteet

- Shopify API -autentikaation muutokset (access tokenin luonti ei enää yhtä suoraviivaista)
- Netvisorin autentikointimallin (MAC + headerit) hahmottaminen dokumentaatiosta
- XML-muodon ja kenttien yhteensovittaminen Netvisorin vaatimuksiin
- End-to-end demonstrointi ilman oikeita Netvisor-tunnuksia → ratkaistu mock-moodilla
