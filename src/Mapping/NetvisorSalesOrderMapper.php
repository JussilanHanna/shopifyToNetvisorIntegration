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
        $total = $this->xml((string)($order['totalAmount'] ?? '0'));

        $customerName = $this->xml((string)($order['customerName'] ?? 'Unknown'));
        $ref = $this->xml((string)($order['name'] ?? ''));
        $date = gmdate('Y-m-d'); // demo: tilauspäivä = nyt

        $addr = $order['shippingAddress'] ?? [];
        $address1 = $this->xml((string)($addr['address1'] ?? ''));
        $address2 = $this->xml((string)($addr['address2'] ?? ''));
        $zip = $this->xml((string)($addr['zip'] ?? ''));
        $city = $this->xml((string)($addr['city'] ?? ''));
        $country = $this->xml((string)($addr['country'] ?? ''));

        $customerCode = $this->xml($this->config->netvisorDefaultCustomerCode);
        $paymentTerm = (int)$this->config->netvisorDefaultPaymentTermDays;
        $vat = $this->config->netvisorDefaultVatPercent;
        $defaultProductCode = $this->xml($this->config->netvisorDefaultProductCode);

        $linesXml = '';
        foreach (($order['lines'] ?? []) as $line) {
            $title = $this->xml((string)($line['title'] ?? 'Item'));
            $sku = $this->xml((string)($line['sku'] ?? ''));
            $qty = (int)($line['quantity'] ?? 1);
            $unit = $this->xml((string)($line['unitPrice'] ?? '0'));

            // Demo: jos SKU puuttuu, käytetään default product codea
            $productCode = $sku !== '' ? $sku : $defaultProductCode;

            $linesXml .= <<<XML
    <salesinvoiceline>
      <productcode>{$productCode}</productcode>
      <productname>{$title}</productname>
      <quantity>{$qty}</quantity>
      <unitprice>{$unit}</unitprice>
      <vatpercent>{$vat}</vatpercent>
    </salesinvoiceline>

XML;
        }

        if ($linesXml === '') {
            // aina vähintään yksi rivi (demo)
            $linesXml = <<<XML
    <salesinvoiceline>
      <productcode>{$defaultProductCode}</productcode>
      <productname>Shopify order</productname>
      <quantity>1</quantity>
      <unitprice>{$total}</unitprice>
      <vatpercent>{$vat}</vatpercent>
    </salesinvoiceline>

XML;
        }

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<salesinvoice>
  <invoicetype>order</invoicetype>
  <salesinvoicedate>{$date}</salesinvoicedate>

  <invoicingcustomeridentifier type="customer">{$customerCode}</invoicingcustomeridentifier>
  <invoicingcustomername>{$customerName}</invoicingcustomername>

  <deliveryaddressline1>{$address1}</deliveryaddressline1>
  <deliveryaddressline2>{$address2}</deliveryaddressline2>
  <deliverypostcode>{$zip}</deliverypostcode>
  <deliverycity>{$city}</deliverycity>
  <deliverycountry>{$country}</deliverycountry>

  <referencenumber>{$ref}</referencenumber>

  <paymentterm>{$paymentTerm}</paymentterm>

  <salesinvoiceamount iso4217currencycode="{$currency}">{$total}</salesinvoiceamount>

  <salesinvoicelines>
{$linesXml}  </salesinvoicelines>
</salesinvoice>
XML;
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
