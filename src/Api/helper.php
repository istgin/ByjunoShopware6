<?php
namespace Byjuno\ByjunoPayments\Api;

function Byjuno_mapMethod($method) {
    if ($method == 'byjuno_payment_installment') {
        return "INSTALLMENT";
    } else {
        return "INVOICE";
    }
}

function Byjuno_getClientIp() {
    $ipaddress = '';
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $ipaddress = $_SERVER['HTTP_CLIENT_IP'];
    } else if(!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ipaddress = $_SERVER['HTTP_X_FORWARDED_FOR'];
    } else if(!empty($_SERVER['HTTP_X_FORWARDED'])) {
        $ipaddress = $_SERVER['HTTP_X_FORWARDED'];
    } else if(!empty($_SERVER['HTTP_FORWARDED_FOR'])) {
        $ipaddress = $_SERVER['HTTP_FORWARDED_FOR'];
    } else if(!empty($_SERVER['HTTP_FORWARDED'])) {
        $ipaddress = $_SERVER['HTTP_FORWARDED'];
    } else if(!empty($_SERVER['REMOTE_ADDR'])) {
        $ipaddress = $_SERVER['REMOTE_ADDR'];
    } else {
        $ipaddress = 'UNKNOWN';
    }
    $ipd = explode(",", $ipaddress);
    return trim(end($ipd));
}

function Byjuno_mapRepayment($type) {
    if ($type == 'installment_3') {
        return "10";
    } else if ($type == 'installment_10') {
        return "5";
    } else if ($type == 'installment_12') {
        return "8";
    } else if ($type == 'installment_24') {
        return "9";
    } else if ($type == 'installment_4x12') {
        return "1";
    } else if ($type == 'installment_4x10') {
        return "2";
    } else if ($type == 'sinlge_invoice') {
        return "3";
    } else {
        return "4";
    }
}

function Byjuno_CreateShopRequestS4($doucmentId, $amount, $orderAmount, $orderCurrency, $orderId, $customerId, $date)
{
    $request = new \ByjunoS4Request();
    $request->setClientId(Shopware()->Config()->getByNamespace("ByjunoPayments", "byjuno_clientid"));
    $request->setUserID(Shopware()->Config()->getByNamespace("ByjunoPayments", "byjuno_userid"));
    $request->setPassword(Shopware()->Config()->getByNamespace("ByjunoPayments", "byjuno_password"));
    $request->setVersion("1.00");
    $request->setRequestEmail(Shopware()->Config()->getByNamespace("ByjunoPayments", "byjuno_techemail"));

    $request->setRequestId(uniqid((String)$orderId . "_"));
    $request->setOrderId($orderId);
    $request->setClientRef($customerId);
    $request->setTransactionDate($date);
    $request->setTransactionAmount(number_format($amount, 2, '.', ''));
    $request->setTransactionCurrency($orderCurrency);
    $request->setAdditional1("INVOICE");
    $request->setAdditional2($doucmentId);
    $request->setOpenBalance(number_format($orderAmount, 2, '.', ''));

    return $request;

}

function Byjuno_CreateShopRequestS5Refund($doucmentId, $amount, $orderCurrency, $orderId, $customerId, $date)
{

    $request = new \ByjunoS5Request();
    $request->setClientId(Shopware()->Config()->getByNamespace("ByjunoPayments", "byjuno_clientid"));
    $request->setUserID(Shopware()->Config()->getByNamespace("ByjunoPayments", "byjuno_userid"));
    $request->setPassword(Shopware()->Config()->getByNamespace("ByjunoPayments", "byjuno_password"));
    $request->setVersion("1.00");
    $request->setRequestEmail(Shopware()->Config()->getByNamespace("ByjunoPayments", "byjuno_techemail"));

    $request->setRequestId(uniqid((String)$orderId . "_"));
    $request->setOrderId($orderId);
    $request->setClientRef($customerId);
    $request->setTransactionDate($date);
    $request->setTransactionAmount(number_format($amount, 2, '.', ''));
    $request->setTransactionCurrency($orderCurrency);
    $request->setTransactionType("REFUND");
    $request->setAdditional2($doucmentId);

    return $request;
}

function Byjuno_CreateShopRequestS5Cancel($amount, $orderCurrency, $orderId, $customerId, $date)
{

    $request = new \ByjunoS5Request();
    $request->setClientId(Shopware()->Config()->getByNamespace("ByjunoPayments", "byjuno_clientid"));
    $request->setUserID(Shopware()->Config()->getByNamespace("ByjunoPayments", "byjuno_userid"));
    $request->setPassword(Shopware()->Config()->getByNamespace("ByjunoPayments", "byjuno_password"));
    $request->setVersion("1.00");
    $request->setRequestEmail(Shopware()->Config()->getByNamespace("ByjunoPayments", "byjuno_techemail"));

    $request->setRequestId(uniqid((String)$orderId . "_"));
    $request->setOrderId($orderId);
    $request->setClientRef($customerId);
    $request->setTransactionDate($date);
    $request->setTransactionAmount(number_format($amount, 2, '.', ''));
    $request->setTransactionCurrency($orderCurrency);
    $request->setAdditional2('');
    $request->setTransactionType("EXPIRED");
    $request->setOpenBalance("0");

    return $request;
}

function Byjuno_IsB2bByjuno($billing) {
    if (!empty($billing["company"])) {
        return true;
    }
    return false;
}

/* @var $controller \Shopware_Controllers_Frontend_BasebyjunoController  */
function Byjuno_CreateShopWareShopRequestUserBilling($user, $billing, $shipping, $controller, $paymentmethod, $repayment, $invoiceDelivery, $riskOwner, $orderId = "", $orderClosed = "NO") {

    $sql     = 'SELECT `countryiso` FROM s_core_countries WHERE id = ' . intval($billing["countryID"]);
    $countryBilling = Shopware()->Db()->fetchOne($sql);
    $sql     = 'SELECT `countryiso` FROM s_core_countries WHERE id = ' . intval($shipping["countryID"]);
    $countryShipping = Shopware()->Db()->fetchOne($sql);
    $request = new \ByjunoRequest();
    $request->setClientId(Shopware()->Config()->getByNamespace("ByjunoPayments", "byjuno_clientid"));
    $request->setUserID(Shopware()->Config()->getByNamespace("ByjunoPayments", "byjuno_userid"));
    $request->setPassword(Shopware()->Config()->getByNamespace("ByjunoPayments", "byjuno_password"));
    $request->setVersion("1.00");
    $request->setRequestEmail(Shopware()->Config()->getByNamespace("ByjunoPayments", "byjuno_techemail"));

    $sql     = 'SELECT `locale` FROM s_core_locales WHERE id = ' . intval(Shopware()->Shop()->getLocale()->getId());
    $langName = Shopware()->Db()->fetchRow($sql);
    $lang = 'de';
    if (!empty($langName["locale"]) && strlen($langName["locale"]) > 4) {
        $lang = substr($langName["locale"], 0, 2);
    }
    $request->setLanguage($lang);
    $request->setRequestId(uniqid((String)$billing["id"]."_"));
    $reference = $billing["id"];
    if (empty($reference)) {
        $request->setCustomerReference(uniqid("guest_"));
    } else {
        $request->setCustomerReference($billing["id"]);
    }
    $request->setFirstName((String)$billing['firstname']);
    $request->setLastName((String)$billing['lastname']);
    $addressAdd = '';
    if (!empty($billing['additionalAddressLine1'])) {
        $addressAdd = ' '.trim((String)$billing['additionalAddressLine1']);
    }
    if (!empty($billing['additionalAddressLine2'])) {
        $addressAdd = $addressAdd.' '.trim((String)$billing['additionalAddressLine2']);
    }
    $request->setFirstLine(trim((String)$billing['street'].' '.$billing['streetnumber'].$addressAdd));
    $request->setCountryCode(strtoupper((String)$countryBilling));
    $request->setPostCode((String)$billing['zipcode']);
    $request->setTown((String)$billing['city']);
    $request->setFax((String)$billing['fax']);

    if (!empty($billing["company"])) {
        $request->setCompanyName1($billing["company"]);
    }
    if (!empty($billing["company"]) && !empty($billing["vatId"])) {
        $request->setCompanyVatId($billing["vatId"]);
    }
    if (!empty($shipping["company"])) {
        $request->setDeliveryCompanyName1($shipping["company"]);
    }

    $request->setGender(0);
    $additionalInfo = $user["additional"]["user"];
    if (!empty($additionalInfo['salutation'])) {
        if (strtolower($additionalInfo['salutation']) == 'ms') {
            $request->setGender(2);
        } else if (strtolower($additionalInfo['salutation']) == 'mr') {
            $request->setGender(1);
        }
    }
    if ($controller->custom_gender != null) {
        $request->setGender($controller->custom_gender);
    }

    if (!empty($additionalInfo['birthday']) && substr($additionalInfo['birthday'], 0, 4) != '0000') {
        $request->setDateOfBirth((String)$additionalInfo['birthday']);
    }
    if ($controller->custom_birthday != null) {
        $request->setDateOfBirth($controller->custom_birthday);
    }

    $request->setTelephonePrivate((String)$billing['phone']);
    $request->setEmail((String)$user["additional"]["user"]["email"]);

    $extraInfo["Name"] = 'ORDERCLOSED';
    $extraInfo["Value"] = $orderClosed;
    $request->setExtraInfo($extraInfo);

    $extraInfo["Name"] = 'ORDERAMOUNT';
    $extraInfo["Value"] = $controller->getAmount();
    $request->setExtraInfo($extraInfo);

    $extraInfo["Name"] = 'ORDERCURRENCY';
    $extraInfo["Value"] = $controller->getCurrencyShortName();
    $request->setExtraInfo($extraInfo);

    $extraInfo["Name"] = 'IP';
    $extraInfo["Value"] = Byjuno_getClientIp();
    $request->setExtraInfo($extraInfo);

    $tmx_enable = Shopware()->Config()->getByNamespace("ByjunoPayments", "byjuno_threatmetrixenable");
    $tmxorgid = Shopware()->Config()->getByNamespace("ByjunoPayments", "byjuno_threatmetrix");
    if (isset($tmx_enable) && $tmx_enable == 'Enabled' && isset($tmxorgid) && $tmxorgid != '' && !empty($_SESSION["byjuno_tmx"])) {
        $extraInfo["Name"] = 'DEVICE_FINGERPRINT_ID';
        $extraInfo["Value"] = $_SESSION["byjuno_tmx"];
        $request->setExtraInfo($extraInfo);
    }

    if ($invoiceDelivery == 'postal') {
        $extraInfo["Name"] = 'PAPER_INVOICE';
        $extraInfo["Value"] = 'YES';
        $request->setExtraInfo($extraInfo);
    }

    /* shipping information */
    $extraInfo["Name"] = 'DELIVERY_FIRSTNAME';
    $extraInfo["Value"] = $shipping['firstname'];
    $request->setExtraInfo($extraInfo);

    $extraInfo["Name"] = 'DELIVERY_LASTNAME';
    $extraInfo["Value"] = $shipping['lastname'];
    $request->setExtraInfo($extraInfo);

    $addressShippingAdd = '';
    if (!empty($shipping['additionalAddressLine1'])) {
        $addressShippingAdd = ' '.trim((String)$shipping['additionalAddressLine1']);
    }
    if (!empty($shipping['additionalAddressLine2'])) {
        $addressShippingAdd = $addressShippingAdd.' '.trim((String)$shipping['additionalAddressLine2']);
    }

    $extraInfo["Name"] = 'DELIVERY_FIRSTLINE';
    $extraInfo["Value"] = trim($shipping['street'].' '.$shipping['streetnumber'].$addressShippingAdd);
    $request->setExtraInfo($extraInfo);

    $extraInfo["Name"] = 'DELIVERY_HOUSENUMBER';
    $extraInfo["Value"] = '';
    $request->setExtraInfo($extraInfo);

    $extraInfo["Name"] = 'DELIVERY_COUNTRYCODE';
    $extraInfo["Value"] = $countryShipping;
    $request->setExtraInfo($extraInfo);

    $extraInfo["Name"] = 'DELIVERY_POSTCODE';
    $extraInfo["Value"] = $shipping['zipcode'];
    $request->setExtraInfo($extraInfo);

    $extraInfo["Name"] = 'DELIVERY_TOWN';
    $extraInfo["Value"] = $shipping['city'];
    $request->setExtraInfo($extraInfo);

    if (!empty($orderId)) {
        $extraInfo["Name"] = 'ORDERID';
        $extraInfo["Value"] = $orderId;
        $request->setExtraInfo($extraInfo);
    }
    $extraInfo["Name"] = 'PAYMENTMETHOD';
    $extraInfo["Value"] = Byjuno_mapMethod($paymentmethod);
    $request->setExtraInfo($extraInfo);

    if ($repayment != "") {
        $extraInfo["Name"] = 'REPAYMENTTYPE';
        $extraInfo["Value"] = Byjuno_mapRepayment($repayment);
        $request->setExtraInfo($extraInfo);
    }

    if ($riskOwner != "") {
        $extraInfo["Name"] = 'RISKOWNER';
        $extraInfo["Value"] = $riskOwner;
        $request->setExtraInfo($extraInfo);
    }

    $extraInfo["Name"] = 'CONNECTIVTY_MODULE';
    $extraInfo["Value"] = 'Byjuno ShopWare module 1.1.0';
    $request->setExtraInfo($extraInfo);
    return $request;

}

function Byjuno_SaveS4Log(ByjunoS4Request $request, $xml_request, $xml_response, $status, $type, $firstName, $lastName)
{
    $sql     = '
            INSERT INTO s_plugin_byjuno_transactions (requestid, requesttype, firstname, lastname, ip, status, datecolumn, xml_request, xml_responce)
                    VALUES (?,?,?,?,?,?,?,?,?)
        ';
    Shopware()->Db()->query($sql, Array(
        $request->getRequestId(),
        $type,
        $firstName,
        $lastName,
        $_SERVER['REMOTE_ADDR'],
        (($status != "") ? $status : 'Error'),
        date('Y-m-d\TH:i:sP'),
        $xml_request,
        $xml_response
    ));
}

function Byjuno_SaveS5Log(ByjunoS5Request $request, $xml_request, $xml_response, $status, $type, $firstName, $lastName)
{
    $sql     = '
            INSERT INTO s_plugin_byjuno_transactions (requestid, requesttype, firstname, lastname, ip, status, datecolumn, xml_request, xml_responce)
                    VALUES (?,?,?,?,?,?,?,?,?)
        ';
    Shopware()->Db()->query($sql, Array(
        $request->getRequestId(),
        $type,
        $firstName,
        $lastName,
        $_SERVER['REMOTE_ADDR'],
        (($status != "") ? $status : 'Error'),
        date('Y-m-d\TH:i:sP'),
        $xml_request,
        $xml_response
    ));
}