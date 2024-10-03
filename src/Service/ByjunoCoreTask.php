<?php declare(strict_types=1);

namespace Byjuno\ByjunoPayments\Service;

use Byjuno\ByjunoPayments\Api\CembraPayCheckoutAuthorizationResponse;
use Byjuno\ByjunoPayments\Api\CembraPayCheckoutCreditRequest;
use Byjuno\ByjunoPayments\Api\CembraPayCheckoutCreditResponse;
use Byjuno\ByjunoPayments\Api\CembraPayCheckoutSettleRequest;
use Byjuno\ByjunoPayments\Api\CembraPayCheckoutSettleResponse;
use Byjuno\ByjunoPayments\Api\CembraPayCommunicator;
use Byjuno\ByjunoPayments\Api\CembraPayConstants;
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
use Symfony\Component\HttpFoundation\RedirectResponse;

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
                $requestAUT = $this->byjuno->Byjuno_CreateShopWareShopRequestAuthorization(
                    $context,
                    $fullOrder->getSalesChannelId(),
                    $fullOrder,
                    $fullOrder->getOrderNumber(),
                    $this->systemConfigService->get("ByjunoPayments.config.byjunoS3ActionRepayment", $fullOrder->getSalesChannelId()),
                    $b2b,
                    $this->systemConfigService->get("ByjunoPayments.config.byjunoS3ActionDelivery", $fullOrder->getSalesChannelId()),
                    "",
                    ""
                );


                $CembraPayRequestName = "Authorization request backend";
                if ($requestAUT->custDetails->custType == CembraPayConstants::$CUSTOMER_BUSINESS) {
                    $CembraPayRequestName = "Authorization request backend company";
                }
                $json = $requestAUT->createRequest();
                $cembrapayCommunicator = new CembraPayCommunicator($this->byjuno->cembraPayAzure);
                $mode = $this->systemConfigService->get("ByjunoPayments.config.mode", $fullOrder->getSalesChannelId());
                if (isset($mode) && strtolower($mode) == 'live') {
                    $cembrapayCommunicator->setServer('live');
                } else {
                    $cembrapayCommunicator->setServer('test');
                }
                $accessData = $this->byjuno->getAccessData($fullOrder->getSalesChannelId(), $mode);
                $response = $cembrapayCommunicator->sendAuthRequest($json, $accessData, function ($object, $token, $accessData) {
                    $object->saveToken($token, $accessData);
                });
                $status = "";
                $responseRes = null;
                if (isset($response)) {
                    /* @var $responseRes CembraPayCheckoutAuthorizationResponse */
                    $responseRes = CembraPayConstants::authorizationResponse($response);
                    $status = $responseRes->processingStatus;
                    $this->byjuno->saveCembraLog($context, $json, $response, $responseRes->processingStatus, $CembraPayRequestName,
                        $requestAUT->custDetails->firstName, $requestAUT->custDetails->lastName, $requestAUT->requestMsgId,
                        $requestAUT->billingAddr->postalCode, $requestAUT->billingAddr->town, $requestAUT->billingAddr->country, $requestAUT->billingAddr->addrFirstLine, $responseRes->transactionId, $fullOrder->getOrderNumber());

                } else {
                    $this->byjuno->saveCembraLog($context, $json, $response, "Query error", $CembraPayRequestName,
                        $requestAUT->custDetails->firstName, $requestAUT->custDetails->lastName, $requestAUT->requestMsgId,
                        $requestAUT->billingAddr->postalCode, $requestAUT->billingAddr->town, $requestAUT->billingAddr->country, $requestAUT->billingAddr->addrFirstLine, "-", "-");
                    continue;
                }
                if ($status == CembraPayConstants::$AUTH_OK) {
                    $cembrapayTrx = $responseRes->transactionId;
                    $fields = $fullOrder->getCustomFields();
                    $customFields = $fields ?? [];
                    $customFields = array_merge($customFields, ['chk_transaction_id' => $cembrapayTrx, 'byjuno_s3_sent' => 1]);
                    $update = [
                        'id' => $fullOrder->getId(),
                        'customFields' => $customFields,
                    ];
                    $this->orderRepository->update([$update], $context);

                } else {
                    continue;
                }
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
                if (isset($response)) {
                    /* @var $responseRes CembraPayCheckoutSettleResponse */
                    $responseRes = CembraPayConstants::settleResponse($response);
                    $status = $responseRes->processingStatus;
                    $this->byjuno->saveCembraLog($context, $json, $response, $responseRes->processingStatus, $CembraPayRequestName,
                        "-","-", $request->requestMsgId,
                        "-", "-", "-","-", $responseRes->transactionId, $order->getOrderNumber());
                    if (!empty($status) && $status != CembraPayConstants::$REQUEST_ERROR) {
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
               // $refDoc = $getDoc->getReferencedDocument();
                $getDocInvoice = $this->getInvoiceById($getDoc->getReferencedDocumentId());
                if (!empty($getDocInvoice)) {
                    $customFieldsR = $getDocInvoice->getCustomFields();
                    $customFieldsRef = $customFieldsR ?? [];
                }
                $customFieldsO = $order->getCustomFields();
                $customFieldsOrder = $customFieldsO ?? [];
                $request = $this->CreateShopRequestCreditRefund(
                    $getDoc->getConfig()["documentNumber"],
                    $order->getAmountTotal(),
                    $order->getCurrency()->getIsoCode(),
                    $order->getOrderNumber(),
                    (!empty($customFieldsOrder["chk_transaction_id"])) ? $customFieldsOrder["chk_transaction_id"] : "",
                    (!empty($customFieldsRef["inv_transaction_id"])) ? $customFieldsRef["inv_transaction_id"] : ""
                );
                $CembraPayRequestName = "Refund request";
            } else if ($docName == "invoice") {
                if ($this->systemConfigService->get("ByjunoPayments.config.byjunoS4", $order->getSalesChannelId()) != 'enabled'
                    || $this->systemConfigService->get("ByjunoPayments.config.byjunoS4trigger", $order->getSalesChannelId()) != 'invoice') {
                    return;
                }
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
                    $response = $cembrapayCommunicator->sendCreditRequest($json,
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
                        if (!empty($status) && $status != CembraPayConstants::$REQUEST_ERROR) {
                            $ok = true;
                        }
                    } else if ($CembraPayRequestName == "Refund request") {
                        /* @var $responseRes CembraPayCheckoutCreditResponse */
                        $responseRes = CembraPayConstants::creditResponse($response);
                        $status = $responseRes->processingStatus;
                        if (!empty($status) && $status != CembraPayConstants::$REQUEST_ERROR) {
                            $ok = true;
                        }
                    }

                    $this->byjuno->saveCembraLog($context, $json, $response, $responseRes->processingStatus, $CembraPayRequestName,
                            "-","-", $request->requestMsgId,
                            "-", "-", "-","-", $responseRes->transactionId, $order->getOrderNumber());

                    if ($ok) {
                        if ($CembraPayRequestName == "Settle Request") {
                            $customFields = array_merge($customFields,
                                ['byjuno_doc_retry' => 0,
                                    'byjuno_doc_sent' => 1,
                                    'byjuno_time' => time(),
                                    'inv_transaction_id' => $responseRes->settlementId]);
                        } else {
                            $customFields = array_merge($customFields,
                                ['byjuno_doc_retry' => 0,
                                    'byjuno_doc_sent' => 1,
                                    'byjuno_time' => time()]);
                        }
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
                    }
                    $this->byjuno->saveCembraLog($context, $json, $response, "Query error", $CembraPayRequestName,
                        "-","-", $request->requestMsgId,
                        "-", "-", "-","-", "-", "-");
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

    private function getDocumentByNumber(string $documentId): ?DocumentEntity
    {
        $criteria = (new Criteria([$documentId]));
        $criteria->addAssociation('order');
        $criteria->addAssociation('order.currency');
        return $this->documentRepository->search($criteria, Context::createDefaultContext())->first();
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
        $request->settlementDetails->isFinal = true;
        return $request;

    }


    function CreateShopRequestCreditRefund($doucmentId, $amount, $orderCurrency, $orderId, $tx, $settlementId)
    {
        $request = new CembraPayCheckoutCreditRequest();
        $request->requestMsgType = CembraPayConstants::$MESSAGE_CNL;
        $request->requestMsgId = CembraPayCheckoutCreditRequest::GUID();
        $request->requestMsgDateTime = CembraPayCheckoutCreditRequest::Date();
        $request->transactionId = $tx;
        $request->merchantOrderRef = $orderId;
        $request->amount = number_format($amount, 2, '.', '') * 100;
        $request->currency = $orderCurrency;
        $request->settlementDetails->merchantInvoiceRef = $doucmentId;
        $request->settlementDetails->settlementId = $settlementId;
        return $request;
    }

}
