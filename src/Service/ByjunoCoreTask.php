<?php declare(strict_types=1);

namespace Byjuno\ByjunoPayments\Service;

use Byjuno\ByjunoPayments\Api\Api\CembraPayCheckoutSettleRequest;
use Byjuno\ByjunoPayments\Api\Api\CembraPayCheckoutSettleResponse;
use Byjuno\ByjunoPayments\Api\Api\CembraPayCommunicator;
use Byjuno\ByjunoPayments\Api\Api\CembraPayConstants;
use Byjuno\ByjunoPayments\Api\Classes\ByjunoCommunicator;
use Byjuno\ByjunoPayments\Api\Classes\ByjunoResponse;
use Byjuno\ByjunoPayments\Api\Classes\ByjunoS4Request;
use Byjuno\ByjunoPayments\Api\Classes\ByjunoS4Response;
use Byjuno\ByjunoPayments\Api\Classes\ByjunoS5Request;
use Shopware\Core\Checkout\Document\DocumentEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class ByjunoCoreTask
{
    private static $MAX_RETRY_COUNT = 10;
    /**
     * @var SystemConfigService
     */
    private $systemConfigService;
    /**
     * @var SalesChannelRepository
     */
    private $salesChannelReposiotry;
    /**
     * @var EntityRepository
     */
    private $documentRepository;
    /**
     * @var EntityRepository
     */
    private $orderRepository;

    /**
     * @var EntityRepository
     */
    private $logEntry;
    /**
     * @var ByjunoCDPOrderConverterSubscriber
     */
    private $byjuno;

    public function __construct(
        SystemConfigService $systemConfigService,
        EntityRepository $salesChannelReposiotry,
        EntityRepository $documentRepository,
        EntityRepository $orderRepository,
        EntityRepository $logEntry,
        ByjunoCDPOrderConverterSubscriber $byjuno)
    {
        $this->systemConfigService = $systemConfigService;
        $this->salesChannelReposiotry = $salesChannelReposiotry;
        $this->documentRepository = $documentRepository;
        $this->orderRepository = $orderRepository;
        $this->logEntry = $logEntry;
        $this->byjuno = $byjuno;
    }

    public function TaskRun()
    {
        $criteria = new Criteria();
        $context = Context::createDefaultContext();
        $salesChannelIds = $this->salesChannelReposiotry->search($criteria, $context);
        /** @var SalesChannelEntity $salesChannel */
        $byjunoS3ActionEnable = false;
        $byjunoS4trigger = false;
        $byjunoS4 = false;
        foreach($salesChannelIds->getEntities()->getElements() as $key => $salesChannel){
           // var_dump($salesChannel->getId());
            if ($this->systemConfigService->get("ByjunoPayments.config.byjunoS3ActionEnable", $salesChannel->getId()) == 'enabled')  {
                $byjunoS3ActionEnable = true;
            }
            if ($this->systemConfigService->get("ByjunoPayments.config.byjunoS4trigger", $salesChannel->getId()) == 'orderstatus')  {
                $byjunoS4trigger = true;
            }
            if ($this->systemConfigService->get("ByjunoPayments.config.byjunoS4", $salesChannel->getId()) == 'enabled')  {
                $byjunoS4 = true;
            }
        }
        if ($byjunoS3ActionEnable) {
            $ordersForS3 = $this->orderRepository->search(
                (new Criteria())
                    ->addFilter(new EqualsFilter('customFields.byjuno_s3_sent', 0))
                    ->addSorting(new FieldSorting('createdAt', FieldSorting::ASCENDING))
                , $context);
            foreach ($ordersForS3 as $simpleOrder) {
                $fullOrder = $this->byjuno->getOrder($simpleOrder->getId());
                $b2b = $this->systemConfigService->get("ByjunoPayments.config.byjunob2b", $fullOrder->getSalesChannelId());
                $mode = $this->systemConfigService->get("ByjunoPayments.config.mode", $fullOrder->getSalesChannelId());
                $lastTransaction = $fullOrder->getTransactions()->last();
                $request = $this->byjuno->Byjuno_CreateShopWareShopRequestUserBilling(
                    $context,
                    $fullOrder->getSalesChannelId(),
                    $fullOrder,
                    $fullOrder->getOrderNumber(),
                    $this->systemConfigService->get("ByjunoPayments.config.byjunoS3ActionPaymentMethod", $fullOrder->getSalesChannelId()),
                    $this->systemConfigService->get("ByjunoPayments.config.byjunoS3ActionRepayment", $fullOrder->getSalesChannelId()),
                    "",
                    $this->systemConfigService->get("ByjunoPayments.config.byjunoS3ActionDelivery", $fullOrder->getSalesChannelId()),
                    "",
                    "",
                    "",
                    "NO");
                $CembraPayRequestName = "Order request backend (S1)";
                if ($request->getCompanyName1() != '' && $b2b == 'enabled') {
                    $CembraPayRequestName = "Order request for company backend (S1)";
                    $xml = $request->createRequestCompany();
                } else {
                    $xml = $request->createRequest();
                }
                $communicator = new ByjunoCommunicator();
                if (isset($mode) && strtolower($mode) == 'live') {
                    $communicator->setServer('live');
                } else {
                    $communicator->setServer('test');
                }
                $response = $communicator->sendRequest($xml, $this->systemConfigService->get("ByjunoPayments.config.byjunotimeout", $fullOrder->getSalesChannelId()));
                $statusS1 = 0;
                $statusS3 = 0;
                $transactionNumber = "";
                if ($response) {
                    $intrumResponse = new ByjunoResponse();
                    $intrumResponse->setRawResponse($response);
                    $intrumResponse->processResponse();
                    $statusS1 = (int)$intrumResponse->getCustomerRequestStatus();
                    $this->byjuno->saveLog($context, $request, $xml, $response, $statusS1, $CembraPayRequestName);
                    $transactionNumber = $intrumResponse->getTransactionNumber();
                    if (intval($statusS1) > 15) {
                        $statusS1 = 0;
                    }
                } else {
                    $this->byjuno->saveLog($context, $request, $xml, "Empty response backend", $statusS1, $CembraPayRequestName);
                    continue;
                }
                if ($this->byjuno->isStatusOkS2($statusS1, $fullOrder->getSalesChannelId())) {
                    $risk = $this->byjuno->getStatusRisk($statusS1, $fullOrder->getSalesChannelId());
                    $requestS3 = $this->byjuno->Byjuno_CreateShopWareShopRequestUserBilling(
                        $context,
                        $fullOrder->getSalesChannelId(),
                        $fullOrder,
                        $fullOrder->getOrderNumber(),
                        $this->systemConfigService->get("ByjunoPayments.config.byjunoS3ActionPaymentMethod", $fullOrder->getSalesChannelId()),
                        $this->systemConfigService->get("ByjunoPayments.config.byjunoS3ActionRepayment", $fullOrder->getSalesChannelId()),
                        $risk,
                        $this->systemConfigService->get("ByjunoPayments.config.byjunoS3ActionDelivery", $fullOrder->getSalesChannelId()),
                        "",
                        "",
                        $transactionNumber,
                        "YES");
                    $CembraPayRequestName = "Order complete backend (S3)";
                    if ($requestS3->getCompanyName1() != '' && $b2b == 'enabled') {
                        $CembraPayRequestName = "Order complete for company backend (S3)";
                        $xml = $requestS3->createRequestCompany();
                    } else {
                        $xml = $requestS3->createRequest();
                    }
                    $byjunoCommunicator = new ByjunoCommunicator();
                    if (isset($mode) && strtolower($mode) == 'live') {
                        $byjunoCommunicator->setServer('live');
                    } else {
                        $byjunoCommunicator->setServer('test');
                    }
                    $response = $byjunoCommunicator->sendRequest($xml, $this->systemConfigService->get("ByjunoPayments.config.byjunotimeout", $fullOrder->getSalesChannelId()));
                    if (isset($response)) {
                        $byjunoResponse = new ByjunoResponse();
                        $byjunoResponse->setRawResponse($response);
                        $byjunoResponse->processResponse();
                        $statusS3 = (int)$byjunoResponse->getCustomerRequestStatus();
                        $this->byjuno->saveLog($context, $request, $xml, $response, $statusS3, $CembraPayRequestName);
                        if (intval($statusS3) > 15) {
                            $statusS3 = 0;
                        }
                    } else {
                        $this->byjuno->saveLog($context, $request, $xml, "Empty response backend", $statusS3, $CembraPayRequestName);
                        continue;
                    }
                    if ($this->byjuno->isStatusOkS2($statusS1, $fullOrder->getSalesChannelId()) && $this->byjuno->isStatusOkS3($statusS3, $fullOrder->getSalesChannelId())) {
                        $this->byjuno->transactionStateHandler->paid($lastTransaction->getId(), $context);
                    } else {
                        $this->byjuno->transactionStateHandler->cancel($lastTransaction->getId(), $context);
                    }
                } else {
                    $this->byjuno->transactionStateHandler->cancel($lastTransaction->getId(), $context);
                }
                $fields = $fullOrder->getCustomFields();
                $customFields = $fields ?? [];
                $customFields = array_merge($customFields, ['byjuno_s3_sent' => 1]);
                $update = [
                    'id' => $fullOrder->getId(),
                    'customFields' => $customFields,
                ];
                $this->orderRepository->update([$update], $context);
            }
        }


        if ($byjunoS4trigger &&
            $byjunoS4) {
            $orders = $this->orderRepository->search(
                (new Criteria())
                    ->addFilter(new EqualsFilter('customFields.byjuno_s4_sent', 0))
                    ->addSorting(new FieldSorting('createdAt', FieldSorting::ASCENDING))
                , $context);
            foreach ($orders as $simpleOrder) {
                $order = $this->byjuno->getOrder($simpleOrder->getId());
                $customFieldsO= $order->getCustomFields();
                $customFieldsOrder = $customFieldsO ?? [];
                $request = $this->CreateShopRequestSettle(
                    $order->getOrderNumber(),
                    $order->getAmountTotal(),
                    $order->getCurrency()->getIsoCode(),
                    $order->getOrderNumber(),
                    (!empty($customFieldsOrder["chk_transaction_id"])) ? $customFieldsOrder["chk_transaction_id"] : ""
                );
                $CembraPayRequestName = "Settle Request (order status)";

                $mode = $this->systemConfigService->get("ByjunoPayments.config.mode", $order->getSalesChannelId());
                $json = $request->createRequest();
                $cembrapayCommunicator = new CembraPayCommunicator($this->byjuno->cembraPayAzure);
                if (isset($mode) && strtolower($mode) == 'live') {
                    $cembrapayCommunicator->setServer('live');
                } else {
                    $cembrapayCommunicator->setServer('test');
                }
                $accessData = $this->byjuno->getAccessData($order->getSalesChannelId(), $mode);
                $response = $cembrapayCommunicator->sendSettleRequest($json,
                    $accessData,
                    function ($object, $token, $accessData) {
                        $object->saveToken($token, $accessData);
                    });

                $fields = $order->getCustomFields();
                $customFields = $fields ?? [];
                if (!empty($response)) {
                    /* @var $responseRes CembraPayCheckoutSettleResponse */
                    $responseRes = CembraPayConstants::settleResponse($response);
                    $status = $responseRes->processingStatus;
                    $this->byjuno->saveCembraLog($context, $json, $response, $responseRes->processingStatus, $CembraPayRequestName,
                        "-","-", $request->requestMsgId,
                        "-", "-", "-","-", $responseRes->transactionId, $order->getOrderNumber());
                    if (!empty($status) && in_array($status, CembraPayConstants::$SETTLE_STATUSES)) {
                        $customFields = array_merge($customFields, ['byjuno_s4_retry' => 0, 'byjuno_s4_sent' => 1, 'byjuno_time' => time()]);
                    } else {
                        if ($fields["byjuno_s4_retry"] < self::$MAX_RETRY_COUNT) {
                            $customFields = array_merge($customFields, ['byjuno_s4_retry' => ++$fields["byjuno_s4_retry"], 'byjuno_s4_sent' => 0, 'byjuno_time' => time() + 60 * 30]);
                        } else {
                            $customFields = array_merge($customFields, ['byjuno_s4_retry' => ++$fields["byjuno_s4_retry"], 'byjuno_s4_sent' => 1, 'byjuno_time' => time()]);
                        }
                    }
                } else {
                    if ($fields["byjuno_s4_retry"] < self::$MAX_RETRY_COUNT) {
                        $customFields = array_merge($customFields, ['byjuno_s4_retry' => ++$fields["byjuno_s4_retry"], 'byjuno_s4_sent' => 0, 'byjuno_time' => time() + 60 * 30]);
                    } else {
                        $customFields = array_merge($customFields, ['byjuno_s4_retry' => ++$fields["byjuno_s4_retry"], 'byjuno_s4_sent' => 1, 'byjuno_time' => time()]);
                        $this->byjuno->saveCembraLog($context, $json, $response, "Query error", $CembraPayRequestName,
                            "-","-", $request->requestMsgId,
                            "-", "-", "-","-", "-", "-");
                    }
                }
                $update = [
                    'id' => $order->getId(),
                    'customFields' => $customFields,
                ];
                $this->orderRepository->update([$update], $context);
            }
        }

        $docs = $this->documentRepository->search(
            (new Criteria())->addFilter(new EqualsFilter('customFields.byjuno_doc_sent', 0))->addSorting(new FieldSorting('createdAt', FieldSorting::ASCENDING))
            , $context);
        foreach ($docs as $doc) {
            /* @var $doc \Shopware\Core\Checkout\Document\DocumentEntity */
            $flds = $doc->getCustomFields();
            if (!isset($flds["byjuno_time"]) || $flds["byjuno_time"] > time()) {
                continue;
            }
            $getDoc = $this->getInvoiceById($doc->getId());
            $shopwareDocName = $getDoc->getConfig()["name"];
            $date = $getDoc->getCreatedAt()->format("Y-m-d");
            $order = $getDoc->getOrder();

            $docName = $this->byjuno->Byjuno_MapDocument($shopwareDocName, $order->getSalesChannelId());
            if ($docName == "storno") {
                if ($this->systemConfigService->get("ByjunoPayments.config.byjunoS5", $order->getSalesChannelId()) != 'enabled') {
                    return;
                }
                $request = $this->createShopRequestS5Refund($order->getSalesChannelId(),
                    $getDoc->getConfig()["custom"]["invoiceNumber"],
                    $order->getAmountTotal(),
                    $order->getCurrency()->getIsoCode(),
                    $order->getOrderNumber(),
                    $order->getOrderCustomer()->getId(),
                    $date);
                $CembraPayRequestName = "Refund request";
            } else if ($docName == "invoice") {
                if ($this->systemConfigService->get("ByjunoPayments.config.byjunoS4", $order->getSalesChannelId()) != 'enabled'
                    || $this->systemConfigService->get("ByjunoPayments.config.byjunoS4trigger", $order->getSalesChannelId()) != 'invoice') {
                    return;
                }
                $order = $getDoc->getOrder();
                $customFieldsO= $order->getCustomFields();
                $customFieldsOrder = $customFieldsO ?? [];
                $request = $this->CreateShopRequestSettle(
                    $getDoc->getConfig()["documentNumber"],
                    $order->getAmountTotal(),
                    $order->getCurrency()->getIsoCode(),
                    $order->getOrderNumber(),
                    (!empty($customFieldsOrder["chk_transaction_id"])) ? $customFieldsOrder["chk_transaction_id"] : ""
                );
                $CembraPayRequestName = "Settle Request";

            }
            if ($CembraPayRequestName != '') {
                $mode = $this->systemConfigService->get("ByjunoPayments.config.mode", $order->getSalesChannelId());
                $json = $request->createRequest();
                $cembrapayCommunicator = new CembraPayCommunicator($this->byjuno->cembraPayAzure);
                if (isset($mode) && strtolower($mode) == 'live') {
                    $cembrapayCommunicator->setServer('live');
                } else {
                    $cembrapayCommunicator->setServer('test');
                }

                $accessData = $this->byjuno->getAccessData($order->getSalesChannelId(), $mode);
                if ($CembraPayRequestName == "Settle Request") {
                    $response = $cembrapayCommunicator->sendSettleRequest($json,
                        $accessData,
                        function ($object, $token, $accessData) {
                            $object->saveToken($token, $accessData);
                        });
                } else if ($CembraPayRequestName == "Refund request") {
                    $response = $cembrapayCommunicator->sendRefundRequest($json,
                        $accessData,
                        function ($object, $token, $accessData) {
                            $object->saveToken($token, $accessData);
                        });
                }

                $fields = $getDoc->getCustomFields();
                $customFields = $fields ?? [];
                if (isset($response)) {
                    $ok = false;
                    if ($CembraPayRequestName == "Settle Request") {
                        /* @var $responseRes CembraPayCheckoutSettleResponse */
                        $responseRes = CembraPayConstants::settleResponse($response);
                        $status = $responseRes->processingStatus;
                        if (!empty($status) && in_array($status, CembraPayConstants::$SETTLE_STATUSES)) {
                            $ok = true;
                        }
                    } else if ($CembraPayRequestName == "Refund request") {

                    }


                    $this->byjuno->saveCembraLog($context, $json, $response, $responseRes->processingStatus, $CembraPayRequestName,
                            "-","-", $request->requestMsgId,
                            "-", "-", "-","-", $responseRes->transactionId, $order->getOrderNumber());

                    if ($ok) {
                        $customFields = array_merge($customFields, ['byjuno_doc_retry' => 0, 'byjuno_doc_sent' => 1, 'byjuno_time' => time()]);
                    } else {
                        if ($fields["byjuno_doc_retry"] < self::$MAX_RETRY_COUNT) {
                            $customFields = array_merge($customFields, ['byjuno_doc_retry' => ++$fields["byjuno_doc_retry"], 'byjuno_doc_sent' => 0, 'byjuno_time' => time() + 60 * 30]);
                        } else {
                            $customFields = array_merge($customFields, ['byjuno_doc_retry' => ++$fields["byjuno_doc_retry"], 'byjuno_doc_sent' => 1, 'byjuno_time' => time()]);
                        }
                    }
                } else {
                    if ($fields["byjuno_doc_retry"] < self::$MAX_RETRY_COUNT) {
                        $customFields = array_merge($customFields, ['byjuno_doc_retry' => ++$fields["byjuno_doc_retry"], 'byjuno_doc_sent' => 0, 'byjuno_time' => time() + 60 * 30]);
                    } else {
                        $customFields = array_merge($customFields, ['byjuno_doc_retry' => ++$fields["byjuno_doc_retry"], 'byjuno_doc_sent' => 1, 'byjuno_time' => time()]);
                        $this->byjuno->saveCembraLog($context, $json, $response, "Query error", $CembraPayRequestName,
                            "-","-", $request->requestMsgId,
                            "-", "-", "-","-", "-", "-");

                    }
                }
                $update = [
                    'id' => $doc->getId(),
                    'customFields' => $customFields,
                ];
                $this->documentRepository->update([$update], $context);
            }
        }
    }

    private function getInvoiceById(string $documentId): ?DocumentEntity
    {
        $criteria = (new Criteria([$documentId]));
        $criteria->addAssociation('order');
        $criteria->addAssociation('order.currency');
        return $this->documentRepository->search($criteria, Context::createDefaultContext())->first();
    }

    function CreateShopRequestS4($salesChannelId, $doucmentId, $amount, $orderAmount, $orderCurrency, $orderId, $customerId, $date)
    {
        $request = new ByjunoS4Request();
        $request->setClientId($this->systemConfigService->get("ByjunoPayments.config.byjunoclientid", $salesChannelId));
        $request->setUserID($this->systemConfigService->get("ByjunoPayments.config.byjunouserid", $salesChannelId));
        $request->setPassword($this->systemConfigService->get("ByjunoPayments.config.byjunopassword", $salesChannelId));
        $request->setVersion("1.00");
        $request->setRequestEmail($this->systemConfigService->get("ByjunoPayments.config.byjunotechemail", $salesChannelId));

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

    function CreateShopRequestSettle($doucmentId, $amount, $orderCurrency, $orderId, $tx)
    {

        $request = new CembraPayCheckoutSettleRequest();
        $request->requestMsgType = CembraPayConstants::$MESSAGE_SET;
        $request->requestMsgId = CembraPayCheckoutSettleRequest::GUID();
        $request->requestMsgDateTime = CembraPayCheckoutSettleRequest::Date();
        $request->transactionId = $tx;
        $request->merchantOrderRef = $orderId;
        $request->amount = number_format($amount, 2, '.', '') * 100;
        $request->currency = $orderCurrency;
        $request->settlementDetails->merchantInvoiceRef = $doucmentId;
        return $request;

    }

    function CreateShopRequestS5Refund($salesChannelId, $doucmentId, $amount, $orderCurrency, $orderId, $customerId, $date)
    {
        $request = new ByjunoS5Request();
        $request->setClientId($this->systemConfigService->get("ByjunoPayments.config.byjunoclientid", $salesChannelId));
        $request->setUserID($this->systemConfigService->get("ByjunoPayments.config.byjunouserid", $salesChannelId));
        $request->setPassword($this->systemConfigService->get("ByjunoPayments.config.byjunopassword", $salesChannelId));
        $request->setVersion("1.00");
        $request->setRequestEmail($this->systemConfigService->get("ByjunoPayments.config.byjunotechemail", $salesChannelId));

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

    private function saveS4Log(Context $context, ByjunoS4Request $request, $xml_request, $xml_response, $status, $type, $firstName, $lastName)
    {
        $entry = [
            'id' => Uuid::randomHex(),
            'request_id' => $request->getRequestId(),
            'request_type' => $type,
            'firstname' => $firstName,
            'lastname' => $lastName,
            'ip' => (isset($_SERVER['REMOTE_ADDR'])) ? $_SERVER['REMOTE_ADDR'] : "127.0.0.1",
            'byjuno_status' => (($status != "") ? $status . '' : 'Error'),
            'xml_request' => $xml_request,
            'xml_response' => $xml_response
        ];
        $context->scope(Context::SYSTEM_SCOPE, function (Context $context) use ($entry): void {
            $this->logEntry->upsert([$entry], $context);
        });
    }

    private function saveS5Log(Context $context, ByjunoS5Request $request, $xml_request, $xml_response, $status, $type, $firstName, $lastName)
    {
        $entry = [
            'id' => Uuid::randomHex(),
            'request_id' => $request->getRequestId(),
            'request_type' => $type,
            'firstname' => $firstName,
            'lastname' => $lastName,
            'ip' => (isset($_SERVER['REMOTE_ADDR'])) ? $_SERVER['REMOTE_ADDR'] : "127.0.0.1",
            'byjuno_status' => (($status != "") ? $status . '' : 'Error'),
            'xml_request' => $xml_request,
            'xml_response' => $xml_response
        ];

        $context->scope(Context::SYSTEM_SCOPE, function (Context $context) use ($entry): void {
            $this->logEntry->upsert([$entry], $context);
        });
    }

}
