<?php declare(strict_types=1);

namespace Byjuno\ByjunoPayments\Service;

use Byjuno\ByjunoPayments\Api\Classes\ByjunoCommunicator;
use Byjuno\ByjunoPayments\Api\Classes\ByjunoS4Request;
use Byjuno\ByjunoPayments\Api\Classes\ByjunoS4Response;
use Byjuno\ByjunoPayments\Api\Classes\ByjunoS5Request;
use Shopware\Core\Checkout\Document\DocumentEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class ByjunoCoreTask
{
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
    private $logEntry;

    public function __construct(
        SystemConfigService $systemConfigService,
        EntityRepositoryInterface $documentRepository,
        EntityRepositoryInterface $logEntry)
    {
        $this->systemConfigService = $systemConfigService;
        $this->documentRepository = $documentRepository;
        $this->logEntry = $logEntry;
    }

    public function TaskRun() {

        $context = Context::createDefaultContext();
        $docs = $this->documentRepository->search(
            (new Criteria())->addFilter(new EqualsFilter('customFields.byjuno_doc_sent', 0))
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
                $request = $this->createShopRequestS5Refund($getDoc->getReferencedDocumentId(),
                    $order->getAmountTotal(),
                    $order->getCurrency()->getIsoCode(),
                    $order->getOrderNumber(),
                    $order->getOrderCustomer()->getId(),
                    $date);
                $statusLog = "S5 Refund request";
            } else if ($name == "invoice") {
                if ($this->systemConfigService->get("ByjunoPayments.config.byjunoS4") != 'enabled') {
                    return;
                }
                $order = $getDoc->getOrder();
                $request = $this->CreateShopRequestS4($getDoc->getReferencedDocumentId(),
                    $order->getAmountTotal(),
                    $order->getAmountTotal(),
                    $order->getCurrency()->getIsoCode(),
                    $order->getOrderNumber(),
                    $order->getOrderCustomer()->getId(),
                    $date);
                $statusLog = "S4 Request";
            }
            if ($statusLog != '') {
                $xml = $request->createRequest();
                $byjunoCommunicator = new ByjunoCommunicator();
                if (isset($mode) && $mode == 'Live') {
                    $byjunoCommunicator->setServer('live');
                } else {
                    $byjunoCommunicator->setServer('test');
                }
                $response = $byjunoCommunicator->sendS4Request($xml);
                $fields = $getDoc->getCustomFields();
                $customFields = $fields ?? [];
                if (isset($response)) {
                    $customFields = array_merge($customFields, ['byjuno_doc_retry' => 0, 'byjuno_doc_sent' => 1, 'byjuno_time' => time()]);
                    $byjunoResponse = new ByjunoS4Response();
                    $byjunoResponse->setRawResponse($response);
                    $byjunoResponse->processResponse();
                    $statusCDP = $byjunoResponse->getProcessingInfoClassification();
                    if ($statusLog == "S4 Request") {
                        $this->saveS4Log($context, $request, $xml, $response, $statusCDP, $statusLog, "-", "-");
                    } else if ($statusLog == "S5 Refund request") {
                        $this->saveS5Log($context, $request, $xml, $response, $statusCDP, $statusLog, "-", "-");
                    }
                } else {
                    if ($fields["byjuno_doc_retry"] < 10) {
                        $customFields = array_merge($customFields, ['byjuno_doc_retry' => $fields["byjuno_doc_retry"]++, 'byjuno_doc_sent' => 0, 'byjuno_time' => time() + 30 * 60]);
                    } else {
                        $customFields = array_merge($customFields, ['byjuno_doc_retry' => $fields["byjuno_doc_retry"]++, 'byjuno_doc_sent' => 1, 'byjuno_time' => time()]);
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
