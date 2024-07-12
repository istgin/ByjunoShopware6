<?php

namespace Byjuno\ByjunoPayments\Api\Api;

class CembraPayCommunicator
{

    /**
     * @var CembraPayAzure
     */
    public $cembraPayAzure;

    public function __construct(
        CembraPayAzure $cembraPayAzure
    )
    {
        $this->cembraPayAzure = $cembraPayAzure;
    }
    private $server;

    /**
     * @param mixed $server
     */
    public function setServer($server)
    {
        $this->server = $server;
    }

    /**
     * @return mixed
     */
    public function getServer()
    {
        return $this->server;
    }

    public function sendScreeningRequest($xmlRequest, CembraPayLoginDto $accessData, $cb) {
        return $this->sendRequest($xmlRequest, 'v1.0/screening', $accessData, $cb);
    }

    public function sendAuthRequest($xmlRequest, CembraPayLoginDto $accessData, $cb) {
        return $this->sendRequest($xmlRequest, 'v1.0/transactions/authorize', $accessData, $cb);
    }

    public function sendCheckoutRequest($xmlRequest, CembraPayLoginDto $accessData, $cb) {
        return $this->sendRequest($xmlRequest, 'v1.0/checkout', $accessData, $cb);
    }

    public function sendSettleRequest($xmlRequest, CembraPayLoginDto $accessData, $cb) {
        return $this->sendRequest($xmlRequest, 'v1.0/transactions/settle', $accessData, $cb);
    }

    public function sendCreditRequest($xmlRequest, CembraPayLoginDto $accessData, $cb) {
        return $this->sendRequest($xmlRequest, 'v1.0/transactions/credit', $accessData, $cb);
    }

    public function sendCancelRequest($xmlRequest, CembraPayLoginDto $accessData, $cb) {
        return $this->sendRequest($xmlRequest, 'v1.0/transactions/cancel', $accessData, $cb);
    }

    public function sendGetTransactionRequest($xmlRequest, CembraPayLoginDto $accessData, $cb) {
        return $this->sendRequest($xmlRequest, 'v1.0/transactions/status', $accessData, $cb);
    }

    public function sendConfirmTransactionRequest($xmlRequest, CembraPayLoginDto $accessData, $cb) {
        return $this->sendRequest($xmlRequest, 'v1.0/transactions/confirm', $accessData, $cb);
    }

    private function sendRequest($xmlRequest, $endpoint, CembraPayLoginDto $accessData, $cb) {
        $token = $accessData->accessToken;
        if (!CembraPayAzure::validToken($token)) {
            $token = $this->cembraPayAzure->getToken($accessData);
        }
        if (empty($token)) {
            $cb($accessData->helperObject, $token, $accessData);
            return "";
        }
        $response = "";
        if (intval($accessData->timeout) < 0) {
            $timeout = 30;
        } else {
            $timeout = $accessData->timeout;
        }
        if ($this->server == 'test') {
            $url = 'https://ext-test.api.cembrapay.ch/'.$endpoint;
        } else {
            $url = 'https://api.cembrapay.ch/'.$endpoint;
        }
        $request_data = $xmlRequest;
        $data = json_decode($request_data, true);
        $prettyJson = json_encode($data, JSON_PRETTY_PRINT);
        //echo $prettyJson;
        //exit();

        $headers = [
            "Content-type: application/json",
            "accept: text/plain",
            "Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiIsIng1dCI6Ik1HTHFqOThWTkxvWGFGZnBKQ0JwZ0I0SmFLcyIsImtpZCI6Ik1HTHFqOThWTkxvWGFGZnBKQ0JwZ0I0SmFLcyJ9.eyJhdWQiOiI1OWZmNGMwYi03Y2U4LTQyZjAtOTgzYi0zMDY3MDY5MzZmYTEiLCJpc3MiOiJodHRwczovL3N0cy53aW5kb3dzLm5ldC80YzZhNmIzNC0wYmNmLTRkZmYtYjNhNy05NDlkY2I0M2EwN2UvIiwiaWF0IjoxNzIwNzkzODE1LCJuYmYiOjE3MjA3OTM4MTUsImV4cCI6MTcyMDc5NzcxNSwiYWlvIjoiRTJkZ1lEaFVMNlcxOTVhdm9XbnRoQlg3ajFzOEFBQT0iLCJhcHBpZCI6IjhiZGY0MDM2LTk3YzctNDdkMC1iNmNlLWU0NDVlYWE3ODIxOCIsImFwcGlkYWNyIjoiMSIsImlkcCI6Imh0dHBzOi8vc3RzLndpbmRvd3MubmV0LzRjNmE2YjM0LTBiY2YtNGRmZi1iM2E3LTk0OWRjYjQzYTA3ZS8iLCJvaWQiOiIzYzJkZjgxOC0wMzRkLTRjOTQtODM3MC1jMzQ0N2MzMjMxZTIiLCJyaCI6IjAuQVNRQU5HdHFUTThMXzAyenA1U2R5ME9nZmd0TV8xbm9mUEJDbURzd1p3YVRiNkVrQUFBLiIsInJvbGVzIjpbIk1lcmNoYW50Il0sInN1YiI6IjNjMmRmODE4LTAzNGQtNGM5NC04MzcwLWMzNDQ3YzMyMzFlMiIsInRpZCI6IjRjNmE2YjM0LTBiY2YtNGRmZi1iM2E3LTk0OWRjYjQzYTA3ZSIsInV0aSI6InlFV2RDdFFHQTBLdkRQOV9peGtTQUEiLCJ2ZXIiOiIxLjAifQ.bCwu06pef6NUlIViP2yjTAVbhYWPEjB1wfpkOH2WaOKi-LREVjP-UkUD91HGfhj2iIgM1tGh4u_aLrhgQVD_c_iTQTlPunZtofnFIsIvWrZlouQKMpNkxBXhmLjiyyoOjemI2sKBNb9ZnabIctontqQRM3UWhM8nJ3U--9UgezQMfQ41c5h87y_A1g9bhsna6XFPRj04Fy4U8nV5zwGHiPczzWr3AYyhlbASsGqSW4si9co114R-38nTtqcam95gAMImKwy0juYRSeSjRZcZlYoIV4KBp8SClg4vCSygfbNBhLKSg-CYq8giqLLSMx5zmcdyazVPBY9ANfm5uKOEwA"
        ];
        //var_dump($url, $request_data);
        //exit('RRR');
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_0);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($curl, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $request_data);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        $response = @curl_exec($curl);
        @curl_close($curl);

        var_dump($response);
        exit('RRR');
        $response = trim($response);
        $cb($accessData->helperObject, $token, $accessData);
        return $response;
    }

}
