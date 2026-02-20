<?php
declare(strict_types=1);

namespace Demo\Mapping;

use Demo\Config;
use Demo\Logger;

final class NetvisorSalesOrderMapper
{
    public function __construct(
        private readonly Logger $logger,
        private readonly Config $config
    ) {}

    public function toSalesOrderXml(array $order): string
    {
        $currency = $this->xml((string)($order['currency'] ?? 'EUR'));

        // Money formatting (2 decimals)
        $totalMoney = $this->money($order['totalAmount'] ?? 0);
        $total = $this->xml($totalMoney);

        $customerName = $this->xml((string)($order['customerName'] ?? 'Unknown'));
        $ref          = $this->xml((string)($order['name'] ?? ''));
        $date         = gmdate('Y-m-d'); // demo: import date

        $addr = $order['shippingAddress'] ?? [];
        $delivery1 = $this->xml((string)($addr['address1'] ?? ''));
        $delivery2 = $this->xml((string)($addr['address2'] ?? ''));
        $zip       = $this->xml((string)($addr['zip'] ?? ''));
        $city      = $this->xml((string)($addr['city'] ?? ''));
        $country   = $this->xml((string)($addr['country'] ?? ''));

        // Defaults from config
        $customerCode       = $this->xml($this->config->netvisorDefaultCustomerCode);
        $paymentTermDays    = (int)$this->config->netvisorDefaultPaymentTermDays;
        $vatPercent         = (float)$this->config->netvisorDefaultVatPercent;
        $vatCode            = $this->xml($this->config->netvisorDefaultVatCode);
        $defaultProductCode = $this->xml($this->config->netvisorDefaultProductCode);

        // Lines
        $linesXml = '';
        foreach (($order['lines'] ?? []) as $line) {
            $title = $this->xml((string)($line['title'] ?? 'Item'));
            $sku   = $this->xml((string)($line['sku'] ?? ''));
            $qty   = (string)((float)($line['quantity'] ?? 1));

            // Unit price formatted to 2 decimals
            $unitMoney = $this->money($line['unitPrice'] ?? 0);
            $unit = $this->xml($unitMoney);

            $productCode = ($sku !== '') ? $sku : $defaultProductCode;

            $linesXml .= <<<XML
    <invoiceline>
      <salesinvoiceproductline>
        <productidentifier type="customer">{$productCode}</productidentifier>
        <productname>{$title}</productname>
        <productunitprice type="gross">{$unit}</productunitprice>
        <productvatpercentage vatcode="{$vatCode}">{$vatPercent}</productvatpercentage>
        <salesinvoiceproductlinequantity>{$qty}</salesinvoiceproductlinequantity>
      </salesinvoiceproductline>
    </invoiceline>

XML;
        }

        // Always at least one line (demo)
        if ($linesXml === '') {
            $fallbackUnit = $this->xml($this->money($totalMoney));

            $linesXml = <<<XML
    <invoiceline>
      <salesinvoiceproductline>
        <productidentifier type="customer">{$defaultProductCode}</productidentifier>
        <productname>Shopify order</productname>
        <productunitprice type="gross">{$fallbackUnit}</productunitprice>
        <productvatpercentage vatcode="{$vatCode}">{$vatPercent}</productvatpercentage>
        <salesinvoiceproductlinequantity>1</salesinvoiceproductlinequantity>
      </salesinvoiceproductline>
    </invoiceline>

XML;
        }

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<salesinvoice>
  <invoicetype>order</invoicetype>
  <salesinvoicedate>{$date}</salesinvoicedate>
  <salesinvoicestatus type="netvisor">undelivered</salesinvoicestatus>

  <invoicingcustomeridentifier type="customer">{$customerCode}</invoicingcustomeridentifier>
  <invoicingcustomername>{$customerName}</invoicingcustomername>

  <deliveryaddressline1>{$delivery1}</deliveryaddressline1>
  <deliveryaddressline2>{$delivery2}</deliveryaddressline2>
  <deliverypostcode>{$zip}</deliverypostcode>
  <deliverycity>{$city}</deliverycity>
  <deliverycountry>{$country}</deliverycountry>

  <salesinvoicereferencenumber>{$ref}</salesinvoicereferencenumber>

  <paymenttermnetdays>{$paymentTermDays}</paymenttermnetdays>

  <salesinvoiceamount iso4217currencycode="{$currency}">{$total}</salesinvoiceamount>

  <invoicelines>
{$linesXml}  </invoicelines>
</salesinvoice>
XML;
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /**
     * Formats money to "0.00" with dot as decimal separator.
     * Accepts float/int or strings like "721.9" or "721,9".
     */
    private function money(float|int|string $value): string
    {
        if (is_string($value)) {
            $value = str_replace(',', '.', trim($value));
            $num = (float)$value;
        } else {
            $num = (float)$value;
        }

        return number_format($num, 2, '.', '');
    }
}