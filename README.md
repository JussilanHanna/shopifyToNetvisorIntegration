# shopifyToNetvisorIntegration

Demo-integraatio: hakee Shopifysta uudet/päivittyneet tilaukset ja lähettää ne Netvisoriin myyntitilauksina (`salesinvoice.nv`, `invoicetype=order`).

## Miten ratkaisu toimii (dataflow)

1. **Ajastus / käynnistys**
   - `bin/run.php` on entrypoint (CLI).
   - Integraatio on tarkoitettu ajettavaksi esim. 15 minuutin välein (cron / scheduler).

2. **Checkpoint & idempotenssi**
   - `StateStore` pitää kirjaa:
     - `lastRunIso`: viimeisen ajon checkpoint (Shopifyn `updatedAt`-perusteinen haku)
     - `sent`: lista jo lähetetyistä Shopify-order-id:istä (idempotenssi)
   - Checkpointiin lisätään pieni **overlap** (esim. -30s), jotta reunatapauksissa tilauksia ei huku.

3. **Tilauksien haku Shopifysta**
   - `ShopifyClient` hakee tilaukset GraphQL:llä:
     - `orders(query: "updated_at:>LAST_RUN")`
     - cursor-paginointi (`pageInfo.hasNextPage`, `endCursor`)
   - Vastaukset normalisoidaan yksinkertaiseen order-rakenteeseen.

4. **Mappaus Netvisor-muotoon**
   - `NetvisorSalesOrderMapper` muuntaa orderin Netvisorin `salesinvoice`-XML:ksi:
     - `invoicetype=order`
     - asiakastunniste (demo: env:stä `NETVISOR_DEFAULT_CUSTOMER_CODE`)
     - osoite (delivery*)
     - rivit (tuotekoodi/SKU → fallback env:stä `NETVISOR_DEFAULT_PRODUCT_CODE`)
     - `salesinvoiceamount` valuutta-attribuutilla

5. **Lähetys Netvisoriin**
   - `NetvisorClient` lähettää XML:n `POST /salesinvoice.nv` ja muodostaa autentikointiheaderit + MAC.
   - Mukana kevyt retry transient-virheille (429/5xx).



