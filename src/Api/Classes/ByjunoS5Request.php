<?php
namespace Byjuno\ByjunoPayments\Api\Classes;


class ByjunoS5Request
{
    private $ClientId;
    private $Version;
    private $RequestId;
    private $RequestEmail;
    private $UserID;
    private $Password;

    private $OrderId;
    private $ClientRef;
    private $TransactionDate;
    private $TransactionAmount;
    private $TransactionCurrency;
    private $TransactionType;
    private $Additional2;
    private $OpenBalance;

    /**
     * @return mixed
     */
    public function getOpenBalance()
    {
        return $this->OpenBalance;
    }

    /**
     * @param mixed $OpenBalance
     */
    public function setOpenBalance($OpenBalance)
    {
        $this->OpenBalance = $OpenBalance;
    }

    /**
     * @return mixed
     */
    public function getAdditional2()
    {
        return $this->Additional2;
    }

    /**
     * @param mixed $Additional2
     */
    public function setAdditional2($Additional2)
    {
        $this->Additional2 = $Additional2;
    }

    /**
     * @return mixed
     */
    public function getClientId()
    {
        return $this->ClientId;
    }

    /**
     * @param mixed $ClientId
     */
    public function setClientId($ClientId)
    {
        $this->ClientId = $ClientId;
    }

    /**
     * @return mixed
     */
    public function getVersion()
    {
        return $this->Version;
    }

    /**
     * @param mixed $Version
     */
    public function setVersion($Version)
    {
        $this->Version = $Version;
    }

    /**
     * @return mixed
     */
    public function getRequestId()
    {
        return $this->RequestId;
    }

    /**
     * @param mixed $RequestId
     */
    public function setRequestId($RequestId)
    {
        $this->RequestId = $RequestId;
    }

    /**
     * @return mixed
     */
    public function getRequestEmail()
    {
        return $this->RequestEmail;
    }

    /**
     * @param mixed $RequestEmail
     */
    public function setRequestEmail($RequestEmail)
    {
        $this->RequestEmail = $RequestEmail;
    }

    /**
     * @return mixed
     */
    public function getUserID()
    {
        return $this->UserID;
    }

    /**
     * @param mixed $UserID
     */
    public function setUserID($UserID)
    {
        $this->UserID = $UserID;
    }

    /**
     * @return mixed
     */
    public function getPassword()
    {
        return $this->Password;
    }

    /**
     * @param mixed $Password
     */
    public function setPassword($Password)
    {
        $this->Password = $Password;
    }

    /**
     * @return mixed
     */
    public function getOrderId()
    {
        return $this->OrderId;
    }

    /**
     * @param mixed $OrderId
     */
    public function setOrderId($OrderId)
    {
        $this->OrderId = $OrderId;
    }

    /**
     * @return mixed
     */
    public function getClientRef()
    {
        return $this->ClientRef;
    }

    /**
     * @param mixed $ClientRef
     */
    public function setClientRef($ClientRef)
    {
        $this->ClientRef = $ClientRef;
    }

    /**
     * @return mixed
     */
    public function getTransactionDate()
    {
        return $this->TransactionDate;
    }

    /**
     * @param mixed $TransactionDate
     */
    public function setTransactionDate($TransactionDate)
    {
        $this->TransactionDate = $TransactionDate;
    }

    /**
     * @return mixed
     */
    public function getTransactionAmount()
    {
        return $this->TransactionAmount;
    }

    /**
     * @param mixed $TransactionAmount
     */
    public function setTransactionAmount($TransactionAmount)
    {
        $this->TransactionAmount = $TransactionAmount;
    }

    /**
     * @return mixed
     */
    public function getTransactionCurrency()
    {
        return $this->TransactionCurrency;
    }

    /**
     * @param mixed $TransactionCurrency
     */
    public function setTransactionCurrency($TransactionCurrency)
    {
        $this->TransactionCurrency = $TransactionCurrency;
    }

    /**
     * @return mixed
     */
    public function getTransactionType()
    {
        return $this->TransactionType;
    }

    /**
     * @param mixed $TransactionType
     */
    public function setTransactionType($TransactionType)
    {
        $this->TransactionType = $TransactionType;
    }



    public function createRequest()
    {
        $xml = new \SimpleXMLElement("<Request></Request>");
        $xml->addAttribute("xmlns:xsi", "http://www.w3.org/2001/XMLSchema-instance");
        $xml->addAttribute("xsi:noNamespaceSchemaLocation", "http://site.byjuno.ch/schema/CreditDecisionRequest140.xsd");
        $xml->addAttribute("ClientId", $this->ClientId);
        $xml->addAttribute("Version", $this->Version);
        $xml->addAttribute("RequestId", $this->RequestId);
        $xml->addAttribute("Email", $this->RequestEmail);
        $xml->addAttribute("UserID", $this->UserID);
        $xml->addAttribute("Password", $this->Password);

        $Transaction = $xml->addChild('Transaction');
        $Transaction->OrderId = $this->OrderId;
        $Transaction->ClientRef = $this->ClientRef;
        $Transaction->TransactionType = $this->TransactionType;
        $Transaction->TransactionDate = $this->TransactionDate;
        $Transaction->TransactionAmount = $this->TransactionAmount;
        $Transaction->TransactionCurrency = $this->TransactionCurrency;
        if ($this->Additional2 != '') {
            $Transaction->Additional2 = $this->Additional2;
        }
        $Transaction->OpenBalance = $this->OpenBalance;

        return $xml->asXML();
    }


}