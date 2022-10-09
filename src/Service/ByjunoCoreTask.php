<?php declare(strict_types=1);

namespace Byjuno\ByjunoPayments\Service;

use Byjuno\ByjunoPayments\Api\Classes\ByjunoCommunicator;
use Byjuno\ByjunoPayments\Api\Classes\ByjunoResponse;
use Byjuno\ByjunoPayments\Api\Classes\ByjunoS4Request;
use Byjuno\ByjunoPayments\Api\Classes\ByjunoS4Response;
use Byjuno\ByjunoPayments\Api\Classes\ByjunoS5Request;
use Shopware\Core\Checkout\Document\DocumentEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class ByjunoCoreTask
{
    private static $MAX_RETRY_COUNT = 10;
    /**
     * @var SystemConfigService
     */
    private $systemConfigService;
    /**
     * @var EntityRepositoryInterface
     */
    private $documentRepository;
    /**
     * @var EntityRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var EntityRepositoryInterface
     */
    private $logEntry;
    /**
     * @var ByjunoCDPOrderConverterSubscriber
     */
    private $byjuno;

    public function __construct(
        SystemConfigService $systemConfigService,
        EntityRepositoryInterface $documentRepository,
        EntityRepositoryInterface $orderRepository,
        EntityRepositoryInterface $logEntry,
        ByjunoCDPOrderConverterSubscriber $byjuno)
    {
        $this->systemConfigService = $systemConfigService;
        $this->documentRepository = $documentRepository;
        $this->orderRepository = $orderRepository;
        $this->logEntry = $logEntry;
        $this->byjuno = $byjuno;
    }

    public function TaskRun()
    {

        $context = Context::createDefaultContext();
        if ($this->systemConfigService->get("ByjunoPayments.config.byjunoS3ActionEnable") == 'enabled') {

            $b2b = $this->systemConfigService->get("ByjunoPayments.config.byjunob2b");
            $mode = $this->systemConfigService->get("ByjunoPayments.config.mode");

            $ordersForS3 = $this->orderRepository->search(
                (new Criteria())
                    ->addFilter(new EqualsFilter('customFields.byjuno_s3_sent', 0))
                    ->addSorting(new FieldSorting('createdAt', FieldSorting::ASCENDING))
                , $context);
            foreach ($ordersForS3 as $simpleOrder) {
                $fullOrder = $this->byjuno->getOrder($simpleOrder->getId());
                $lastTransaction = $fullOrder->getTransactions()->last();
                $request = $this->byjuno->Byjuno_CreateShopWareShopRequestUserBilling(
                    $context,
                    $fullOrder,
                    $context,
                    $fullOrder->getOrderNumber(),
                    $this->systemConfigService->get("ByjunoPayments.config.byjunoS3ActionPaymentMethod"),
                    $this->systemConfigService->get("ByjunoPayments.config.byjunoS3ActionRepayment"),
                    "",
                    $this->systemConfigService->get("ByjunoPayments.config.byjunoS3ActionDelivery"),
                    "",
                    "",
                    "",
                    "NO");
                $statusLog = "Order request backend (S1)";
                if ($request->getCompanyName1() != '' && $b2b == 'enabled') {
                    $statusLog = "Order request for company backend (S1)";
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
                $response = $communicator->sendRequest($xml, $this->systemConfigService->get("ByjunoPayments.config.byjunotimeout"));
                $statusS1 = 0;
                $statusS3 = 0;
                $transactionNumber = "";
                if ($response) {
                    $intrumResponse = new ByjunoResponse();
                    $intrumResponse->setRawResponse($response);
                    $intrumResponse->processResponse();
                    $statusS1 = (int)$intrumResponse->getCustomerRequestStatus();
                    $this->byjuno->saveLog($context, $request, $xml, $response, $statusS1, $statusLog);
                    $transactionNumber = $intrumResponse->getTransactionNumber();
                    if (intval($statusS1) > 15) {
                        $statusS1 = 0;
                    }
                } else {
                    $this->byjuno->saveLog($context, $request, $xml, "Empty response backend", $statusS1, $statusLog);
                    continue;
                }
                if ($this->byjuno->isStatusOkS2($statusS1)) {
                    $risk = $this->byjuno->getStatusRisk($statusS1);
                    $requestS3 = $this->byjuno->Byjuno_CreateShopWareShopRequestUserBilling(
                        $context,
                        $fullOrder,
                        $context,
                        $fullOrder->getOrderNumber(),
                        $this->systemConfigService->get("ByjunoPayments.config.byjunoS3ActionPaymentMethod"),
                        $this->systemConfigService->get("ByjunoPayments.config.byjunoS3ActionRepayment"),
                        $risk,
                        $this->systemConfigService->get("ByjunoPayments.config.byjunoS3ActionDelivery"),
                        "",
                        "",
                        $transactionNumber,
                        "YES");
                    $statusLog = "Order complete backend (S3)";
                    if ($requestS3->getCompanyName1() != '' && $b2b == 'enabled') {
                        $statusLog = "Order complete for company backend (S3)";
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
                    $response = $byjunoCommunicator->sendRequest($xml, $this->systemConfigService->get("ByjunoPayments.config.byjunotimeout"));
                    if (isset($response)) {
                        $byjunoResponse = new ByjunoResponse();
                        $byjunoResponse->setRawResponse($response);
                        $byjunoResponse->processResponse();
                        $statusS3 = (int)$byjunoResponse->getCustomerRequestStatus();
                        $this->byjuno->saveLog($context, $request, $xml, $response, $statusS3, $statusLog);
                        if (intval($statusS3) > 15) {
                            $statusS3 = 0;
                        }
                    } else {
                        $this->byjuno->saveLog($context, $request, $xml, "Empty response backend", $statusS3, $statusLog);
                        continue;
                    }
                    if ($this->byjuno->isStatusOkS2($statusS1) && $this->byjuno->isStatusOkS3($statusS3)) {
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


        if ($this->systemConfigService->get("ByjunoPayments.config.byjunoS4trigger") == 'orderstatus' &&
            $this->systemConfigService->get("ByjunoPayments.config.byjunoS4") == 'enabled') {
            $orders = $this->orderRepository->search(
                (new Criteria())
                    ->addFilter(new EqualsFilter('customFields.byjuno_s4_sent', 0))
                    ->addSorting(new FieldSorting('createdAt', FieldSorting::ASCENDING))
                , $context);
            foreach ($orders as $simpleOrder) {
                $order = $this->byjuno->getOrder($simpleOrder->getId());
                $date = $order->getCreatedAt()->format("Y-m-d");
                $request = $this->CreateShopRequestS4($order->getOrderNumber(),
                    $order->getAmountTotal(),
                    $order->getAmountTotal(),
                    $order->getCurrency()->getIsoCode(),
                    $order->getOrderNumber(),
                    $order->getOrderCustomer()->getId(),
                    $date);
                $statusLog = "S4 Request (order status)";

                $mode = $this->systemConfigService->get("ByjunoPayments.config.mode");
                $xml = $request->createRequest();
                $byjunoCommunicator = new ByjunoCommunicator();
                if (isset($mode) && strtolower($mode) == 'live') {
                    $byjunoCommunicator->setServer('live');
                } else {
                    $byjunoCommunicator->setServer('test');
                }
                $response = $byjunoCommunicator->sendS4Request($xml, $this->systemConfigService->get("ByjunoPayments.config.byjunotimeout"));
                $fields = $order->getCustomFields();
                $customFields = $fields ?? [];
                if (isset($response)) {
                    $byjunoResponse = new ByjunoS4Response();
                    $byjunoResponse->setRawResponse($response);
                    $byjunoResponse->processResponse();
                    $statusCDP = $byjunoResponse->getProcessingInfoClassification();
                    $this->saveS4Log($context, $request, $xml, $response, $statusCDP, $statusLog, "-", "-");
                    if ($statusCDP != "ERR") {
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
                        $this->saveS4Log($context, $request, $xml, "no response (network timeout)", 0, $statusLog, "-", "-");
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
            $name = $getDoc->getConfig()["name"];
            $date = $getDoc->getCreatedAt()->format("Y-m-d");
            if ($name == "storno") {
                if ($this->systemConfigService->get("ByjunoPayments.config.byjunoS5") != 'enabled') {
                    return;
                }
                $order = $getDoc->getOrder();
                $request = $this->createShopRequestS5Refund($getDoc->getConfig()["custom"]["invoiceNumber"],
                    $order->getAmountTotal(),
                    $order->getCurrency()->getIsoCode(),
                    $order->getOrderNumber(),
                    $order->getOrderCustomer()->getId(),
                    $date);
                $statusLog = "S5 Refund request";
            } else if ($name == "invoice") {
                if ($this->systemConfigService->get("ByjunoPayments.config.byjunoS4") != 'enabled'
                    || $this->systemConfigService->get("ByjunoPayments.config.byjunoS4trigger") != 'invoice') {
                    return;
                }
                $order = $getDoc->getOrder();
                $request = $this->CreateShopRequestS4($getDoc->getConfig()["documentNumber"],
                    $order->getAmountTotal(),
                    $order->getAmountTotal(),
                    $order->getCurrency()->getIsoCode(),
                    $order->getOrderNumber(),
                    $order->getOrderCustomer()->getId(),
                    $date);
                $statusLog = "S4 Request";
            }
            if ($statusLog != '') {
                $mode = $this->systemConfigService->get("ByjunoPayments.config.mode");
                $xml = $request->createRequest();
                $byjunoCommunicator = new ByjunoCommunicator();
                if (isset($mode) && strtolower($mode) == 'live') {
                    $byjunoCommunicator->setServer('live');
                } else {
                    $byjunoCommunicator->setServer('test');
                }
                $response = $byjunoCommunicator->sendS4Request($xml, $this->systemConfigService->get("ByjunoPayments.config.byjunotimeout"));
                $fields = $getDoc->getCustomFields();
                $customFields = $fields ?? [];
                if (isset($response)) {
                    $byjunoResponse = new ByjunoS4Response();
                    $byjunoResponse->setRawResponse($response);
                    $byjunoResponse->processResponse();
                    $statusCDP = $byjunoResponse->getProcessingInfoClassification();
                    if ($statusLog == "S4 Request") {
                        $this->saveS4Log($context, $request, $xml, $response, $statusCDP, $statusLog, "-", "-");
                    } else if ($statusLog == "S5 Refund request") {
                        $this->saveS5Log($context, $request, $xml, $response, $statusCDP, $statusLog, "-", "-");
                    }
                    if ($statusCDP != "ERR") {
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
                        if ($statusLog == "S4 Request") {
                            $this->saveS4Log($context, $request, $xml, "no response (network timeout)", 0, $statusLog, "-", "-");
                        } else if ($statusLog == "S5 Refund request") {
                            $this->saveS5Log($context, $request, $xml, "no response (network timeout)", 0, $statusLog, "-", "-");
                        }
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

    function CreateShopRequestS4($doucmentId, $amount, $orderAmount, $orderCurrency, $orderId, $customerId, $date)
    {
        $request = new ByjunoS4Request();
        $request->setClientId($this->systemConfigService->get("ByjunoPayments.config.byjunoclientid"));
        $request->setUserID($this->systemConfigService->get("ByjunoPayments.config.byjunouserid"));
        $request->setPassword($this->systemConfigService->get("ByjunoPayments.config.byjunopassword"));
        $request->setVersion("1.00");
        $request->setRequestEmail($this->systemConfigService->get("ByjunoPayments.config.byjunotechemail"));

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

    function CreateShopRequestS5Refund($doucmentId, $amount, $orderCurrency, $orderId, $customerId, $date)
    {
        $request = new ByjunoS5Request();
        $request->setClientId($this->systemConfigService->get("ByjunoPayments.config.byjunoclientid"));
        $request->setUserID($this->systemConfigService->get("ByjunoPayments.config.byjunouserid"));
        $request->setPassword($this->systemConfigService->get("ByjunoPayments.config.byjunopassword"));
        $request->setVersion("1.00");
        $request->setRequestEmail($this->systemConfigService->get("ByjunoPayments.config.byjunotechemail"));

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
