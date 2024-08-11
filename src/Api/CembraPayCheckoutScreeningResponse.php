<?php
/**
 * Created by CembraPay.
 */
namespace Byjuno\ByjunoPayments\Api;

class CembraPayCheckoutScreeningDetails {
    public $allowedCembraPayPaymentMethods;  //array( String )
    public function __construct() {
        $this->allowedCembraPayPaymentMethods = Array();
    }

}
class CembraPayCheckoutScreeningResponse {
    public $requestMsgId; //String
    public $requestMsgDateTime; //Date
    public $replyMsgId; //String
    public $replyMsgDateTime; //Date
    public $transactionId; //String
    public $merchantCustRef; //String
    public $processingStatus; //String
    public $screeningDetails; //ScreeningDetails

    public function __construct() {
        $this->screeningDetails = new CembraPayCheckoutScreeningDetails();
    }

}
