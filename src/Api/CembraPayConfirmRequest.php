<?php

namespace Byjuno\ByjunoPayments\Api;

/*
"requestMsgId": "ed58eb92-8424-487e-bb7c-fcb43066dcac",
"requestMsgDateTime": "2023-10-27T14:21:51Z",
"transactionId": "210728105911212199"
 */
class CembraPayConfirmRequest
{
    public $requestMsgId; //String
    public $requestMsgDateTime; //String
    public $transactionId; //String

    public function createRequest() {
        return json_encode($this);
    }
}
