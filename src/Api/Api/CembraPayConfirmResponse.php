<?php

namespace Byjuno\ByjunoPayments\Api\Api;

class CembraPayConfirmAuthorization
{
    public $authorizationValidTill; //String
    public $authorizedRemainingAmount; //int
    public $authorizationCurrency; //String
}
class CembraPayConfirmTransactionStatus
{
    public $transactionId; //String
    public $transactionStatus; //int
    public $transactionMessages; //Array
}

class CembraPayConfirmResponse
{
    public $requestMerchantId; //String
    public $requestMsgId; //String
    public $requestMsgDateTime; //String
    public $replyMsgId; //String
    public $replyMsgDateTime; //String
    public $token; //String
    public $merchantCustRef; //String
    public $isTokenDeleted; //String
    public $processingStatus; //String
    public $authorization; //String
    public $transactionStatus; //String

    public function __construct()
    {
        $this->authorization = new CembraPayConfirmAuthorization();
        $this->transactionStatus = new CembraPayConfirmTransactionStatus();
    }
}
