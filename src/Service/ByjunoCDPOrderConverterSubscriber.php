<?php declare(strict_types=1);

namespace Byjuno\ByjunoPayments\Service;

use Byjuno\ByjunoPayments\Api\CembraPayAzure;
use Byjuno\ByjunoPayments\Api\CembraPayCheckoutAutRequest;
use Byjuno\ByjunoPayments\Api\CembraPayCheckoutCancelRequest;
use Byjuno\ByjunoPayments\Api\CembraPayCheckoutCancelResponse;
use Byjuno\ByjunoPayments\Api\CembraPayCheckoutChkRequest;
use Byjuno\ByjunoPayments\Api\CembraPayCheckoutScreeningResponse;
use Byjuno\ByjunoPayments\Api\CembraPayCommunicator;
use Byjuno\ByjunoPayments\Api\CembraPayConstants;
use Byjuno\ByjunoPayments\Api\CembraPayLoginDto;
use Byjuno\ByjunoPayments\Api\CembraPayConfirmRequest;
use Byjuno\ByjunoPayments\Api\CustDetails;
use Byjuno\ByjunoPayments\Api\CustomerConsents;
use Byjuno\ByjunoPayments\ByjunoPayments;
use Exception;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Shopware\Core\Checkout\Cart\Order\CartConvertedEvent;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Document\DocumentEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Content\Mail\Service\AbstractMailService;
use Shopware\Core\Content\MailTemplate\MailTemplateEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\DataBag\DataBag;
use Shopware\Core\Framework\Validation\Exception\ConstraintViolationException;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\Salutation\SalutationCollection;
use Shopware\Core\System\Salutation\SalutationEntity;
use Shopware\Core\System\StateMachine\Event\StateMachineTransitionEvent;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Event\StorefrontRenderEvent;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Contracts\Translation\TranslatorInterface;

class ByjunoCDPOrderConverterSubscriber implements EventSubscriberInterface
{
    /**
     * @var ContainerInterface
     */
    private $container;
    /**
     * @var SystemConfigService
     */
    private $systemConfigService;
    /** @var EntityRepository */
    private $paymentMethodRepository;
    /** @var EntityRepository */
    private $languageRepository;
    /** @var EntityRepository */
    private $orderAddressRepository;
    /**
     * @var EntityRepository
     */
    private $documentRepository;

    /**
     * @var SalesChannelRepository
     */
    private $salutationRepository;

    /**
     * @var EntityRepository
     */
    private $mailTemplateRepository;
    /**
     * @var AbstractMailService
     */
    private $mailService;
    /**
     * @var OrderTransactionStateHandler
     */
    public $transactionStateHandler;

    /** @var TranslatorInterface */
    private $translator;
    private static $writeRecursion;

    /**
     * @var \Byjuno\ByjunoPayments\Api\CembraPayAzure
     */
    public $cembraPayAzure;


    public function __construct(
        SystemConfigService $systemConfigService,
        EntityRepository $paymentMethodRepository,
        EntityRepository $languageRepository,
        EntityRepository $orderAddressRepository,
        EntityRepository $documentRepository,
        ContainerInterface $container,
        TranslatorInterface $translator,
        SalesChannelRepository $salutationRepository,
        EntityRepository $mailTemplateRepository,
        AbstractMailService $mailService,
        OrderTransactionStateHandler $transactionStateHandler
    )
    {
        $this->systemConfigService = $systemConfigService;
        $this->paymentMethodRepository = $paymentMethodRepository;
        $this->languageRepository = $languageRepository;
        $this->orderAddressRepository = $orderAddressRepository;
        $this->documentRepository = $documentRepository;
        $this->container = $container;
        $this->translator = $translator;
        $this->salutationRepository = $salutationRepository;
        $this->mailTemplateRepository = $mailTemplateRepository;
        $this->mailService = $mailService;
        $this->transactionStateHandler = $transactionStateHandler;
        $writeRecursion = Array();
        $this->cembraPayAzure = new CembraPayAzure();
    }

    public function getAccessData($salesChannelId, $mode) {
        $accessData = new CembraPayLoginDto();
        $accessData->helperObject = $this;
        $accessData->timeout = (int)$this->systemConfigService->get("ByjunoPayments.config.byjunotimeout", $salesChannelId);
        if ($mode == 'test') {
            $accessData->mode = 'test';
            $accessData->username = $this->systemConfigService->get("ByjunoPayments.config.cembrapaylogintest", $salesChannelId);
            $accessData->password = $this->systemConfigService->get("ByjunoPayments.config.cembrapaypasswordtest", $salesChannelId);
            $accessData->audience = "59ff4c0b-7ce8-42f0-983b-306706936fa1/.default";
            $accessToken = $this->systemConfigService->get('ByjunoPayments.config.accesstokentest') ?? "";
        } else {
            $accessData->mode = 'live';
            $accessData->username = $this->systemConfigService->get("ByjunoPayments.config.cembrapayloginlive", $salesChannelId);
            $accessData->password = $this->systemConfigService->get("ByjunoPayments.config.cembrapaypasswordlive", $salesChannelId);
            $accessData->audience = "80d0ac9d-9d5c-499c-876e-71dd57e436f2/.default";
            $accessToken = $this->systemConfigService->get('ByjunoPayments.config.accesstokenlive') ?? "";
        }
        $tkn = explode(CembraPayConstants::$tokenSeparator, $accessToken);
        $hash = $accessData->username.$accessData->password.$accessData->audience;
        if ($hash == $tkn[0] && !empty($tkn[1])) {
            $accessData->accessToken = $tkn[1];
        }
        return $accessData;
    }
    public function saveToken($token, $accessData) {
        /* @var $accessData CembraPayLoginDto */
        $hash = $accessData->username.$accessData->password.$accessData->audience.CembraPayConstants::$tokenSeparator;
        if ($accessData->mode == 'test') {
            $this->systemConfigService->set('ByjunoPayments.config.accesstokentest', $hash.$token);
        } else {
            $this->systemConfigService->set('ByjunoPayments.config.accesstokenlive', $hash.$token);
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            StorefrontRenderEvent::class => 'onByjunoRender',
            CartConvertedEvent::class => 'converter',
            StateMachineTransitionEvent::class => 'onByjunoStateMachine',
            'document.written' => [
                ['documentWritten', 0],
            ],
            CheckoutConfirmPageLoadedEvent::class => ['onCheckoutConfirmLoaded', 1]
        ];
    }

    public function onCheckoutConfirmLoaded(CheckoutConfirmPageLoadedEvent $event): void
    {
        if ($event->getRequest()->attributes->get("_controller") != "Shopware\Storefront\Controller\CheckoutController::confirmPage") {
            return;
        }
        $confirmPage = $event->getPage();
        $cart = $confirmPage->getCart();
        if ($cart == null) {
            return;
        }
        $totalPrice = $cart->getPrice()->getTotalPrice();
        if ($totalPrice == 0) {
            return;
        }
        $paymentMethods = $confirmPage->getPaymentMethods();
        if ($paymentMethods == null) {
            return;
        }
        $event->getSalesChannelContext()->getSalesChannelId();
        /* @var PaymentMethodEntity $paymentMethod */
        foreach ($paymentMethods as $key => $paymentMethod) {
            if ($paymentMethod->getHandlerIdentifier() == "Byjuno\ByjunoPayments\Service\ByjunoCorePayment" &&
                ($totalPrice < $this->systemConfigService->get("ByjunoPayments.config.byjunominimum", $event->getSalesChannelContext()->getSalesChannelId())
                    || $totalPrice > $this->systemConfigService->get("ByjunoPayments.config.byjunomaximum", $event->getSalesChannelContext()->getSalesChannelId())
                )) {
                $paymentMethods->remove($key);
            }
        }
        $confirmPage->setPaymentMethods($paymentMethods);
    }

    public function documentWritten(EntityWrittenEvent $event): void
    {
        foreach ($event->getWriteResults() as $writeResult) {
            $id = $writeResult->getPrimaryKey();
            if (is_array($id)) $id = $id['id'];
            /** @var DocumentEntity $document */
            $doc = $this->getInvoiceById($id);
            if ($doc != null) {
                if (!isset(self::$writeRecursion[$doc->getId()])) {
                    $shopwareDocName = $doc->getConfig()["name"];
                    $order = $this->getOrder($doc->getOrderId());

                    if ($order != null) {
                        $docName = $this->Byjuno_MapDocument($shopwareDocName, $order->getSalesChannelId());
                        switch ($docName) {
                            case "storno":
                                if ($this->systemConfigService->get("ByjunoPayments.config.byjunoS5", $order->getSalesChannelId()) != 'enabled') {
                                    return;
                                }
                                break;
                            case "invoice":
                                if ($this->systemConfigService->get("ByjunoPayments.config.byjunoS4", $order->getSalesChannelId()) != 'enabled') {
                                    return;
                                }
                                if ($this->systemConfigService->get("ByjunoPayments.config.byjunoS4trigger", $order->getSalesChannelId()) != 'invoice') {
                                    return;
                                }
                                break;
                            default:
                                return;
                        }
                        $paymentMethods = $order->getTransactions();
                        $paymentMethodId = '';
                        foreach ($paymentMethods as $pm) {
                            $paymentMethodId = $pm->getPaymentMethod()->getHandlerIdentifier();
                            break;
                        }
                        if ($paymentMethodId == "Byjuno\ByjunoPayments\Service\ByjunoCorePayment") {
                            $fields = $doc->getCustomFields();
                            if (isset($fields["byjuno_doc_retry"]) && isset($fields["byjuno_doc_sent"]) && isset($fields["byjuno_time"])) {
                                return;
                            }
                            $customFields = $fields ?? [];
                            $customFields = array_merge($customFields, ['byjuno_doc_retry' => 0, 'byjuno_doc_sent' => 0, 'byjuno_time' => 0]);
                            $update = [
                                'id' => $doc->getId(),
                                'customFields' => $customFields,
                            ];
                            self::$writeRecursion[$doc->getId()] = true;
                            $this->documentRepository->update([$update], $event->getContext());
                        }
                    }
                }
            }
        }
    }

    public function onByjunoStateMachine(StateMachineTransitionEvent $event)
    {
        $order = $this->getOrder($event->getEntityId());
        if ($order != null) {
            if ($this->systemConfigService->get("ByjunoPayments.config.byjunoS4trigger", $order->getSalesChannelId()) == 'orderstatus') {
                if ($event->getEntityName() == 'order' && $event->getToPlace()->getTechnicalName() == $this->systemConfigService->get("ByjunoPayments.config.byjunoS4triggername", $order->getSalesChannelId())) {
                        $fields = $order->getCustomFields();
                        if (empty($fields["byjuno_s4_sent"])) {
                            $paymentMethods = $order->getTransactions();
                            $paymentMethodId = '';
                            foreach ($paymentMethods as $pm) {
                                $paymentMethodId = $pm->getPaymentMethod()->getHandlerIdentifier();
                                break;
                            }
                            if ($paymentMethodId == "Byjuno\ByjunoPayments\Service\ByjunoCorePayment") {
                                $customFields = $fields ?? [];
                                $customFields = array_merge($customFields, ['byjuno_s4_sent' => 0, 'byjuno_s4_retry' => 0, 'byjuno_time' => 0]);
                                $update = [
                                    'id' => $order->getId(),
                                    'customFields' => $customFields,
                                ];
                                $orderRepo = $this->container->get('order.repository');
                                $orderRepo->update([$update], $event->getContext());
                        }
                    }
                }
            }
        }
        if ($event->getEntityName() == 'order' && $event->getToPlace()->getTechnicalName() == "cancelled") {
            $order = $this->getOrder($event->getEntityId());
            if ($order != null) {
                $customFields = $order->getCustomFields();
                $paymentMethods = $order->getTransactions();
                $paymentMethodId = '';
                foreach ($paymentMethods as $pm) {
                    $paymentMethodId = $pm->getPaymentMethod()->getHandlerIdentifier();
                    break;
                }
                if ($paymentMethodId == "Byjuno\ByjunoPayments\Service\ByjunoCorePayment" && $this->systemConfigService->get("ByjunoPayments.config.byjunoS5", $order->getSalesChannelId()) == 'enabled'
                    && !empty($customFields["byjuno_s3_sent"]) && $customFields["byjuno_s3_sent"] == 1) {
                    $mode = $this->systemConfigService->get("ByjunoPayments.config.mode", $order->getSalesChannelId());

                    $txId = null;
                    if (!empty($customFields["chk_transaction_id"])) {
                        $txId = $customFields["chk_transaction_id"];
                    }

                    $request = $this->CreateShopRequestBCDPCancel($order->getAmountTotal(),
                        $order->getCurrency()->getIsoCode(),
                        $order->getOrderNumber(), $txId);


                    $CembraPayRequestName = "Order Cancel request";
                    $json = $request->createRequest();
                    $cembrapayCommunicator = new CembraPayCommunicator($this->cembraPayAzure);
                    if (isset($mode) && strtolower($mode) == 'live') {
                        $cembrapayCommunicator->setServer('live');
                    } else {
                        $cembrapayCommunicator->setServer('test');
                    }

                    $accessData = $this->getAccessData($order->getSalesChannelId(), $mode);
                    $response = $cembrapayCommunicator->sendCancelRequest($json, $accessData, function ($object, $token, $accessData) {
                        $object->saveToken($token, $accessData);
                    });
                    if ($response) { /* @var $responseRes CembraPayCheckoutCancelResponse */
                        $responseRes = CembraPayConstants::cancelResponse($response);
                        $this->saveCembraLog($event->getContext(), $json, $response, $responseRes->processingStatus, $CembraPayRequestName,
                            "-","-", $request->requestMsgId,
                            "-", "-", "-","-", $responseRes->transactionId, $order->getOrderNumber());
                    } else {
                            $this->saveCembraLog($event->getContext(), $json, $response, "Query error", $CembraPayRequestName,
                                "-","-", $request->requestMsgId,
                                "-", "-", "-","-", "-", "-");
                    }
                }
            }
        }
    }

    public function onByjunoRender(StorefrontRenderEvent $event): void
    {
        if ($event->getRequest() != null
            && $event->getRequest()->attributes != null
            && $event->getRequest()->attributes->get("_controller") == 'Shopware\Storefront\Controller\CheckoutController::confirmPage') {
            $byjuno_tmx = array();
            $tmx_enable = false;
            if ($this->systemConfigService->get("ByjunoPayments.config.byjunothreatmetrixenable", $event->getSalesChannelContext()->getSalesChannelId()) == 'enabled') {
                $tmx_enable = true;
            }
            $tmxorgid = $this->systemConfigService->get("ByjunoPayments.config.byjunothreatmetrix", $event->getSalesChannelContext()->getSalesChannelId());
            if (isset($tmx_enable) && $tmx_enable == true && isset($tmxorgid) && $tmxorgid != '' && empty($_SESSION["byjuno_tmx"])) {
                $_SESSION["byjuno_tmx"] = session_id();
                $byjuno_tmx["tmx_orgid"] = $tmxorgid;
                $byjuno_tmx["tmx_session"] = $_SESSION["byjuno_tmx"];
                $byjuno_tmx["tmx_enable"] = 'true';
            } else {
                $byjuno_tmx["tmx_orgid"] = "";
                $byjuno_tmx["tmx_session"] = "";
                $byjuno_tmx["tmx_enable"] = 'false';
            }
            $event->setParameter('byjuno_tmx', $byjuno_tmx);
        }
    }

    public function converter(CartConvertedEvent $event)
    {
        if ($event->getSalesChannelContext()->getPaymentMethod()->getHandlerIdentifier() != "Byjuno\ByjunoPayments\Service\ByjunoCorePayment") {
            return;
        }
        $convertedCart = $event->getConvertedCart();
        if ($convertedCart["price"] == null) {
            return;
        }
        $totalPrice = $convertedCart["price"]->getTotalPrice();
        if ($totalPrice < $this->systemConfigService->get("ByjunoPayments.config.byjunominimum", $event->getSalesChannelContext()->getSalesChannelId())
            || $totalPrice > $this->systemConfigService->get("ByjunoPayments.config.byjunomaximum", $event->getSalesChannelContext()->getSalesChannelId())
        ) {
            $violation = new ConstraintViolation(
                $this->translator->trans('ByjunoPayment.cdp_error'),
                '',
                [],
                '',
                '',
                ''
            );
            $violations = new ConstraintViolationList([$violation]);
            throw new ConstraintViolationException($violations, []);
        }
        if ($this->systemConfigService->get("ByjunoPayments.config.byjunousecdp", $event->getSalesChannelContext()->getSalesChannelId()) == 'enabled') {
            $b2b = $this->systemConfigService->get("ByjunoPayments.config.byjunob2b", $event->getSalesChannelContext()->getSalesChannelId());
            $mode = $this->systemConfigService->get("ByjunoPayments.config.mode", $event->getSalesChannelContext()->getSalesChannelId());
            $paymentMethod = "";
            if ($event->getSalesChannelContext()->getPaymentMethod()->getId() == ByjunoPayments::BYJUNO_INVOICE) {
                $paymentMethod = "byjuno_payment_invoice";
            } else if ($event->getSalesChannelContext()->getPaymentMethod()->getId() == ByjunoPayments::BYJUNO_INSTALLMENT) {
                $paymentMethod = "byjuno_payment_installment";
            }
            $request = $this->Byjuno_CreateShopWareShopRequestScreening($event->getSalesChannelContext(), $event->getConvertedCart(), $paymentMethod);
            $statusLog = "Screening request";
            if ($request->custDetails->custType == CembraPayConstants::$CUSTOMER_BUSINESS && $b2b == 'enabled') {
                $statusLog = "Screening request company";
            }
            $json = $request->createRequest();
            $cembrapayCommunicator = new CembraPayCommunicator($this->cembraPayAzure);
            if (isset($mode) && strtolower($mode) == 'live') {
                $cembrapayCommunicator->setServer('live');
            } else {
                $cembrapayCommunicator->setServer('test');
            }
            $accessData = $this->getAccessData($event->getSalesChannelContext()->getSalesChannelId(), $mode);
            $response = $cembrapayCommunicator->sendScreeningRequest($json, $accessData, function ($object, $token, $accessData) {
                $object->saveToken($token, $accessData);
            });
            $screeningStatus = "";
            if ($response) {
                /* @var $responseRes CembraPayCheckoutScreeningResponse */
                $responseRes = CembraPayConstants::screeningResponse($response);
                $screeningStatus = $responseRes->processingStatus;
                $this->saveCembraLog($event->getContext(), $json, $response, $responseRes->processingStatus, $statusLog,
                    $request->custDetails->firstName, $request->custDetails->lastName, $request->requestMsgId,
                    $request->billingAddr->postalCode, $request->billingAddr->town, $request->billingAddr->country, $request->billingAddr->addrFirstLine, $responseRes->transactionId, "-");
            } else {
                $this->saveCembraLog($event->getContext(), $json, $response, "Query error", $statusLog,
                     $request->custDetails->firstName, $request->custDetails->lastName, $request->requestMsgId,
                     $request->billingAddr->postalCode, $request->billingAddr->town, $request->billingAddr->country, $request->billingAddr->addrFirstLine, "-", "-");
            }
            $allowed = false;
            if ($screeningStatus == CembraPayConstants::$SCREENING_OK) {
                $allowed = true;
            }
            if (!$allowed) {
                $violation = new ConstraintViolation(
                    $this->translator->trans('ByjunoPayment.cdp_error'),
                    '',
                    [],
                    '',
                    '',
                    ''
                );
                $violations = new ConstraintViolationList([$violation]);
                throw new ConstraintViolationException($violations, []);
            }
        }
    }

    public function Byjuno_CreateShopWareShopRequestScreening(SalesChannelContext $context, Array $convertedCart, $paymentmethod)
    {
        $addresses = null;
        if (!empty($convertedCart["addresses"])) {
            $addresses = $convertedCart["addresses"];
        }
        $deliveries = null;
        if (!empty($convertedCart["deliveries"])) {
            $deliveries = $convertedCart["deliveries"];
        }
        $billingAddress = $this->getBillingAddress($convertedCart["billingAddressId"], $addresses, $deliveries);
        $billingAddressCountry = $this->getCountry($billingAddress["countryId"], $context->getContext());
        $shippingAddress = $this->getOrderShippingAddress($convertedCart["deliveries"]);
        $shippingAddressCountry = $this->getCountry($shippingAddress["countryId"], $context->getContext());
        $billingAddressSalutation = $this->getSalutation($billingAddress["salutationId"], $context->getContext());
        $currency = $this->getCurrency($convertedCart["currencyId"], $context->getContext());
        $addressAdd = '';
        if (!empty($billing['additionalAddressLine1'])) {
            $addressAdd = ' ' . trim($billingAddress["additionalAddressLine1"]);
        }
        if (!empty($billing['additionalAddressLine2'])) {
            $addressAdd = $addressAdd . ' ' . trim($billingAddress["additionalAddressLine2"]);
        }
        $b2b = $this->systemConfigService->get("ByjunoPayments.config.byjunob2b", $context->getSalesChannelId());

        $request = new CembraPayCheckoutAutRequest();
        $request->requestMsgType = CembraPayConstants::$MESSAGE_SCREENING;
        $request->requestMsgId = CembraPayCheckoutAutRequest::GUID();
        $request->requestMsgDateTime = CembraPayCheckoutAutRequest::Date();
        $request->merchantOrderRef = null;
        $request->amount = round(number_format($convertedCart["price"]->getTotalPrice(), 2, '.', '') * 100);
        $request->currency = $currency->getIsoCode();

        $reference = "";
        if (!empty($convertedCart["orderCustomer"]["customer"]["id"])) {
            $reference = $convertedCart["orderCustomer"]["customer"]["id"];
        }
        if (empty($reference)) {
            $request->custDetails->merchantCustRef = uniqid("guest_");
            $request->custDetails->loggedIn = false;
        } else {
            $request->custDetails->merchantCustRef = (string)$reference;
            $request->custDetails->loggedIn = true;
        }
        if (!empty($billingAddress["company"]) && $b2b == 'enabled') {
            $request->custDetails->custType = CembraPayConstants::$CUSTOMER_BUSINESS;
            $request->custDetails->companyName = $billingAddress["company"];
        } else {
            $request->custDetails->custType = CembraPayConstants::$CUSTOMER_PRIVATE;
        }

        $request->custDetails->firstName = (string)$billingAddress["firstName"];
        $request->custDetails->lastName = (string)$billingAddress["lastName"];
        $request->custDetails->language = (string)$this->getCustomerLanguage($context->getContext(), $convertedCart["languageId"]);
        $customerId = "";
        if (!empty($convertedCart["orderCustomer"]["customerId"])) {
            $customerId = $convertedCart["orderCustomer"]["customerId"];
        }
        $customer = $this->getCustomer($customerId, $context->getContext());
        $dob = null;
        $sal = null;
        if (!empty($customer)) {
            $dob = $customer->getBirthday();
            $sal = $customer->getSalutation();
        }
        $dob_year = null;
        $dob_month = null;
        $dob_day = null;
        if ($dob != null) {
            $dob_year = sprintf('%02d', intval($dob->format("Y")));
            $dob_month = sprintf('%02d', intval($dob->format("m")));
            $dob_day = sprintf('%02d', intval($dob->format("d")));
        }

        if (!empty($dob_year) && !empty($dob_month) && !empty($dob_day)) {
            $request->custDetails->dateOfBirth = $dob_year . "-" . $dob_month . "-" . $dob_day;
        }
        $request->custDetails->salutation = CembraPayConstants::$GENTER_UNKNOWN;
        $additionalInfoSalutation = $billingAddressSalutation->getDisplayName();
        $genderMaleStr = $this->systemConfigService->get("ByjunoPayments.config.byjunogendermale", $context->getSalesChannelId());
        $genderFemaleStr = $this->systemConfigService->get("ByjunoPayments.config.byjunogenderfemale", $context->getSalesChannelId());
        $genderMale = explode(",", $genderMaleStr);
        $genderFemale = explode(",", $genderFemaleStr);
        if ($sal != null) {
            $salName = $sal->getDisplayName();
            if (!empty($salName)) {
                foreach ($genderMale as $ml) {
                    if (strtolower($salName) == strtolower(trim($ml))) {
                        $request->custDetails->salutation = CembraPayConstants::$GENTER_MALE;
                    }
                }
                foreach ($genderFemale as $feml) {
                    if (strtolower($salName) == strtolower(trim($feml))) {
                        $request->custDetails->salutation = CembraPayConstants::$GENTER_FEMALE;
                    }
                }
            }
        }
        if (!empty($additionalInfoSalutation)) {
            foreach ($genderMale as $ml) {
                if (strtolower($additionalInfoSalutation) == strtolower(trim($ml))) {
                    $request->custDetails->salutation = CembraPayConstants::$GENTER_MALE;
                }
            }
            foreach ($genderFemale as $feml) {
                if (strtolower($additionalInfoSalutation) == strtolower(trim($feml))) {
                    $request->custDetails->salutation = CembraPayConstants::$GENTER_FEMALE;
                }
            }
        }

        $addressAdd = '';
        if (!empty($billing['additionalAddressLine1'])) {
            $addressAdd = ' ' . trim($billingAddress["additionalAddressLine1"]);
        }
        if (!empty($billing['additionalAddressLine2'])) {
            $addressAdd = $addressAdd . ' ' . trim($billingAddress["additionalAddressLine2"]);
        }
        $request->billingAddr->addrFirstLine = (string)trim($billingAddress["street"] . ' ' . $addressAdd);
        $request->billingAddr->postalCode = (string)$billingAddress["zipcode"];
        $request->billingAddr->town = (string)$billingAddress["city"];
        $request->billingAddr->country = strtoupper($billingAddressCountry->getIso() ?? "");
        $request->custContacts->email = (string)$convertedCart["orderCustomer"]["email"];

        $request->deliveryDetails->deliveryDetailsDifferent = true;
        $request->deliveryDetails->deliveryFirstName = $shippingAddress["firstName"];
        $request->deliveryDetails->deliverySecondName =  $shippingAddress["lastName"];
        if (!empty($shippingAddress["company"]) && $b2b == 'enabled') {
            $request->deliveryDetails->deliveryCompanyName = $shippingAddress["company"];
        }
        $request->deliveryDetails->deliverySalutation = null;

        $addressShippingAdd = '';
        if (!empty($shippingAddress['additionalAddressLine1'])) {
            $addressShippingAdd = ' ' . trim($shippingAddress['additionalAddressLine1']);
        }
        if (!empty($shippingAddress['additionalAddressLine2'])) {
            $addressShippingAdd = $addressShippingAdd . ' ' . trim($shippingAddress['additionalAddressLine2']);
        }

        $request->deliveryDetails->deliveryAddrFirstLine = trim($shippingAddress["street"] . ' ' . $addressShippingAdd);
        $request->deliveryDetails->deliveryAddrPostalCode = $shippingAddress["zipcode"];
        $request->deliveryDetails->deliveryAddrTown = $shippingAddress["city"];
        $request->deliveryDetails->deliveryAddrCountry = strtoupper($shippingAddressCountry->getIso() ?? "");

        $tmx_enable = $this->systemConfigService->get("ByjunoPayments.config.byjunothreatmetrixenable", $context->getSalesChannelId());
        $tmxorgid = $this->systemConfigService->get("ByjunoPayments.config.byjunothreatmetrix", $context->getSalesChannelId());
        if (isset($tmx_enable) && $tmx_enable == 'enabled' && isset($tmxorgid) && $tmxorgid != '' && !empty($_SESSION["byjuno_tmx"])) {
            $request->sessionInfo->tmxSessionId = $_SESSION["byjuno_tmx"];
        }

        $request->sessionInfo->sessionIp = $this->Byjuno_getClientIp();

        $customerConsents = new CustomerConsents();
        $customerConsents->consentType = "SCREENING";
        $customerConsents->consentProvidedAt = "MERCHANT";
        $customerConsents->consentDate = CembraPayCheckoutAutRequest::Date();
        $customerConsents->consentReference = "MERCHANT DATA PRIVACY";
        $request->customerConsents = array($customerConsents);

        $request->merchantDetails->transactionChannel = "WEB";
        $request->merchantDetails->integrationModule = "CembraPay Shopware 6 module 4.0.0";

        return $request;
    }


    public function getPaymentMethod(string $id, Context $context): ?PaymentMethodEntity
    {
        $criteria = new Criteria([$id]);
        $paymentMethod = $this->paymentMethodRepository->search($criteria, $context)->first();
        return $paymentMethod;
    }

    public function getCustomerLanguage(Context $context, string $languages): string
    {
        $criteria = new Criteria([$languages]);
        $criteria->addAssociation('locale');

        /** @var null|LanguageEntity $language */
        $language = $this->languageRepository->search($criteria, $context)->first();

        if (null === $language || null === $language->getLocale()) {
            return 'en';
        }

        return substr($language->getLocale()->getCode(), 0, 2);
    }

    public function getOrderShippingAddress(Array $deliveries)
    {
        foreach ($deliveries as $delivery) {
            if ($delivery["shippingOrderAddress"] === null) {
                continue;
            }

            return $delivery["shippingOrderAddress"];
        }

        return null;
    }

    public function getBillingAddress(?String $billingAddressId, $addresses, $deliveries)
    {
        if ($addresses != null) {
            foreach ($addresses as $addres) {
                if ($addres["id"] !== $billingAddressId) {
                    continue;
                }

                return $addres;
            }
        }
        if ($deliveries != null) {
            foreach ($deliveries as $delivery) {
                if ($delivery["shippingOrderAddress"]["id"] !== $billingAddressId) {
                    continue;
                }
                return $delivery["shippingOrderAddress"];
            }
        }
        return null;
    }

    public function getCountry(String $countryId, Context $context): CountryEntity
    {
        $criteria = new Criteria([$countryId]);
        $criteria->addAssociation('country');

        /** @var EntityRepository $countryRepository */
        $countryRepository = $this->container->get('country.repository');
        $country = $countryRepository->search($criteria, $context)->first();
        return $country;
    }

    public function getSalutation(String $salutationId, Context $context): SalutationEntity
    {
        $criteria = new Criteria([$salutationId]);

        /** @var EntityRepository $salutationRepository */
        $salutationRepository = $this->container->get('salutation.repository');
        $salutation = $salutationRepository->search($criteria, $context)->first();
        return $salutation;
    }

    public function getCustomer(string $customerId, Context $context): ?CustomerEntity
    {
        /** @var EntityRepository $orderRepo */
        $customerRepo = $this->container->get('customer.repository');

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('id', $customerId));
        return $customerRepo->search($criteria, $context)->first();
    }

    public function getCurrency(string $currencyId, Context $context): ?CurrencyEntity
    {
        /** @var EntityRepository $orderRepo */
        $customerRepo = $this->container->get('currency.repository');

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('id', $currencyId));
        return $customerRepo->search($criteria, $context)->first();
    }

    public function Byjuno_getClientIp()
    {
        $ipaddress = '';
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ipaddress = $_SERVER['HTTP_CLIENT_IP'];
        } else if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ipaddress = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else if (!empty($_SERVER['HTTP_X_FORWARDED'])) {
            $ipaddress = $_SERVER['HTTP_X_FORWARDED'];
        } else if (!empty($_SERVER['HTTP_FORWARDED_FOR'])) {
            $ipaddress = $_SERVER['HTTP_FORWARDED_FOR'];
        } else if (!empty($_SERVER['HTTP_FORWARDED'])) {
            $ipaddress = $_SERVER['HTTP_FORWARDED'];
        } else if (!empty($_SERVER['REMOTE_ADDR'])) {
            $ipaddress = $_SERVER['REMOTE_ADDR'];
        } else {
            $ipaddress = 'UNKNOWN';
        }
        $ipd = explode(",", $ipaddress);
        return trim(end($ipd));
    }

    public function Byjuno_mapMethod($method)
    {
        if ($method == 'byjuno_payment_installment') {
            return "INSTALLMENT";
        } else {
            return "INVOICE";
        }
    }


    function CreateShopRequestBCDPCancel($amount, $orderCurrency, $orderId, $tx)
    {
        $request = new CembraPayCheckoutCancelRequest();
        $request->requestMsgType = CembraPayConstants::$MESSAGE_CAN;
        $request->requestMsgId = CembraPayCheckoutAutRequest::GUID();
        $request->requestMsgDateTime = CembraPayCheckoutAutRequest::Date();
        $request->transactionId = $tx;
        $request->merchantOrderRef = $orderId;
        $request->amount = number_format($amount, 2, '.', '') * 100;
        $request->currency = $orderCurrency;
        $request->isFullCancelation = true;
        return $request;
    }

    private function getOrderByDelivery(string $deliveryId): ?OrderEntity
    {
        /** @var EntityRepository $orderDeliveryRepo */
        $orderDeliveryRepo = $this->container->get('order_delivery.repository');

        $criteria = new Criteria([$deliveryId]);
        /* @var $orderDeliveryEntity OrderDeliveryEntity */
        $orderDeliveryEntity = $orderDeliveryRepo->search($criteria, Context::createDefaultContext())->get($deliveryId);
        if ($orderDeliveryEntity == null) {
            return null;
        }
        $orderId = $orderDeliveryEntity->getOrderId();
        return $this->getOrder($orderId);
    }

    private function getInvoiceById(string $documentId): ?DocumentEntity
    {
        $criteria = (new Criteria([$documentId]));
        $criteria->addAssociation('order');
        $criteria->addAssociation('order.currency');
        return $this->documentRepository->search($criteria, Context::createDefaultContext())->first();
    }

    public function saveCembraLog(Context $context, $request, $response, $status, $type,
                                  $firstName, $lastName, $requestId,
                                  $postcode, $town, $country, $street1, $transactionId, $orderId)
    {

        $json_string1 = json_decode($request);
        if ($json_string1 == null) {
            $json_string11 = $request;
        } else {
            $json_string11 = json_encode($json_string1, JSON_PRETTY_PRINT);
        }
        $json_string2 = json_decode($response);
        if ($json_string2 == null) {
            $json_string22 = $response;
        } else {
            $json_string22 = json_encode($json_string2, JSON_PRETTY_PRINT);
        }
        if (empty($json_string11)) {
            $json_string11 = "no request";
        }
        if (empty($json_string22)) {
            $json_string22 = "no response";
        }
        $entry = [
            'id' => Uuid::randomHex(),
            'request_id' => (string)$requestId,
            'request_type' => (string)$type,
            'firstname' => (string)$firstName,
            'lastname' => (string)$lastName,
            'town' => (string)$town,
            'postcode' => (string)$postcode,
            'street' => (string)$street1,
            'country' => (string)$country,
            'ip' => empty($_SERVER['REMOTE_ADDR']) ? "no ip" : $_SERVER['REMOTE_ADDR'],
            'cembra_status' => (string)$status,
            'order_id' => (string)$orderId,
            'transaction_id' => (string)$transactionId,
            'request' => (string)$json_string11,
            'response' => (string)$json_string22
        ];

        $context->scope(Context::SYSTEM_SCOPE, function (Context $context) use ($entry): void {
            /** @var EntityRepository $logRepository */
            $logRepository = $this->container->get('cembra_log_entity.repository');
            $logRepository->upsert([$entry], $context);
        });
    }

    public function sendMailOrder(Context $context, OrderEntity $order, String $salesChanhhelId): bool
    {

        $mailTemplate = $this->getMailTemplate($context, "order_confirmation_mail", $order);
        if ($mailTemplate !== null) {
            $data = new DataBag();
            $mode = $this->systemConfigService->get("ByjunoPayments.config.mode", $salesChanhhelId);
            if (isset($mode) && $mode == 'live') {
                $recipients = Array($this->systemConfigService->get("ByjunoPayments.config.byjunoprodemail", $salesChanhhelId) => "Byjuno order confirmation");
            } else {
                $recipients = Array($this->systemConfigService->get("ByjunoPayments.config.byjunotestemail", $salesChanhhelId) => "Byjuno order confirmation");
            }
            $data->set('recipients', $recipients);
            $data->set('senderName', $mailTemplate->getTranslation('senderName'));
            $data->set('salesChannelId', $salesChanhhelId);

            $data->set('templateId', $mailTemplate->getId());
            $data->set('customFields', $mailTemplate->getCustomFields());
            $data->set('contentHtml', $mailTemplate->getTranslation('contentHtml'));
            $data->set('contentPlain', $mailTemplate->getTranslation('contentPlain'));
            $data->set('subject', $mailTemplate->getTranslation('subject'));
            $data->set('mediaIds', []);

            $templateData["order"] = $order;
            $this->mailService->send(
                $data->all(),
                $context,
                $templateData
            );
            return true;
        }
        return false;
    }

    public function getOrder(string $orderId): ?OrderEntity
    {
        /** @var EntityRepository $orderRepo */
        $orderRepo = $this->container->get('order.repository');

        $criteria = new Criteria([$orderId]);
        $criteria->addAssociation('transactions');
        $criteria->addAssociation('addresses');
        $criteria->addAssociation('addresses.country');
        $criteria->addAssociation('deliveries');
        $criteria->addAssociation('deliveries.shippingMethod');
        $criteria->addAssociation('deliveries.shippingOrderAddress.country');
        $criteria->addAssociation('language');
        $criteria->addAssociation('currency');
        $criteria->addAssociation('salesChannel');
        $criteria->addAssociation('billingAddress');
        $criteria->addAssociation('billingAddress.country');
        $criteria->addAssociation('salesChannel.paymentMethod');
        $criteria->addAssociation('orderCustomer.customer');
        $criteria->addAssociation('orderCustomer.salutation');
        $criteria->addAssociation('transactions');
        $criteria->addAssociation('transactions.paymentMethod');
        $criteria->addAssociation('tags');
        $criteria->addAssociation('transactions.paymentMethod');
        $criteria->addAssociation('addresses');
        $criteria->addAssociation('lineItems');
        return $orderRepo->search($criteria, Context::createDefaultContext())->get($orderId);
    }

    /**
     * @throws InconsistentCriteriaIdsException
     */
    public function getSalutations(SalesChannelContext $salesChannelContext): SalutationCollection
    {
        /** @var SalutationCollection $salutations */
        $salutations = $this->salutationRepository->search(new Criteria(), $salesChannelContext)->getEntities();

        $salutations->sort(function (SalutationEntity $a, SalutationEntity $b) {
            return $b->getSalutationKey() <=> $a->getSalutationKey();
        });

        return $salutations;
    }

    public function Byjuno_CreateShopWareShopCheckoutRequestUserBilling(Context $context, $salesChannelId, OrderEntity $order, $successUrl, $cancelUrl, $errorUrl)
    {
        $request = new CembraPayCheckoutChkRequest();
        $request->requestMsgType = CembraPayConstants::$MESSAGE_CHK;
        $request->requestMsgId = CembraPayCheckoutChkRequest::GUID();
        $request->requestMsgDateTime = CembraPayCheckoutChkRequest::Date();
        $request->merchantOrderRef = $order->getOrderNumber();
        $request->amount = number_format($order->getAmountTotal(), 2, '.', '') * 100;
        $request->currency = $order->getCurrency()->getIsoCode();
        $reference = $order->getOrderCustomer()->getCustomerId();
        if (empty($reference)) {
            $request->custDetails->merchantCustRef = (string)uniqid("guest_");
            $request->custDetails->loggedIn = false;
        } else {
            $request->custDetails->merchantCustRef = (string)$reference;
            $request->custDetails->loggedIn = true;
        }
        $shippingAddress = $this->getOrderShippingAddressOrder($order);
        $billingAddress = $this->getBillingAddressOrder($order, $context);
        $b2b = $this->systemConfigService->get("ByjunoPayments.config.byjunob2b", $salesChannelId) == 'enabled';
        if ($b2b && !empty($billingAddress->getCompany())) {
            $request->custDetails->custType = CembraPayConstants::$CUSTOMER_BUSINESS;
            $request->custDetails->companyName = $order->getBillingAddress()->getCompany();
        } else {
            $request->custDetails->custType = CembraPayConstants::$CUSTOMER_PRIVATE;
        }
        $request->custDetails->firstName = (string)$billingAddress->getFirstName();
        $request->custDetails->lastName = (string)$billingAddress->getLastName();
        $request->custDetails->language = (string)$this->getCustomerLanguage($context, $order->getLanguageId());

        $customer = $this->getCustomer($order->getOrderCustomer()->getCustomerId(), $context);
        $dob = null;
        $sal = null;
        if (!empty($customer)) {
            $dob = $customer->getBirthday();
            $sal = $customer->getSalutation();
        }
        $dob_year = null;
        $dob_month = null;
        $dob_day = null;
        if ($dob != null) {
            $dob_year = sprintf('%02d', intval($dob->format("Y")));
            $dob_month = sprintf('%02d', intval($dob->format("m")));
            $dob_day = sprintf('%02d', intval($dob->format("d")));
        }

        if (!empty($dob_year) && !empty($dob_month) && !empty($dob_day)) {
            $request->custDetails->dateOfBirth = $dob_year . "-" . $dob_month . "-" . $dob_day;
        }

        $request->custDetails->salutation = CembraPayConstants::$GENTER_UNKNOWN;

        /* @var $additionalInfoSalutation \Shopware\Core\System\Salutation\SalutationEntity */
        $additionalInfoSalutation = $billingAddress->getSalutation();

        $genderMaleStr = $this->systemConfigService->get("ByjunoPayments.config.byjunogendermale", $salesChannelId);
        $genderFemaleStr = $this->systemConfigService->get("ByjunoPayments.config.byjunogenderfemale", $salesChannelId);
        $genderMale = explode(",", $genderMaleStr);
        $genderFemale = explode(",", $genderFemaleStr);
        if ($sal != null) {
            $salName = $sal->getDisplayName();
            if (!empty($salName)) {
                foreach ($genderMale as $ml) {
                    if (strtolower($salName) == strtolower(trim($ml))) {
                        $request->custDetails->salutation = CembraPayConstants::$GENTER_MALE;
                    }
                }
                foreach ($genderFemale as $feml) {
                    if (strtolower($salName) == strtolower(trim($feml))) {
                        $request->custDetails->salutation = CembraPayConstants::$GENTER_FEMALE;
                    }
                }
            }
        }
        if (!empty($additionalInfoSalutation)) {
            $salutationKey = $additionalInfoSalutation->getSalutationKey();
            if (!empty($salutationKey)) {
                foreach ($genderMale as $ml) {
                    if (strtolower($salutationKey) == strtolower(trim($ml))) {
                        $request->custDetails->salutation = CembraPayConstants::$GENTER_MALE;
                    }
                }
                foreach ($genderFemale as $feml) {
                    if (strtolower($salutationKey) == strtolower(trim($feml))) {
                        $request->custDetails->salutation = CembraPayConstants::$GENTER_FEMALE;
                    }
                }
            }
        }

        $addressAdd = '';
        if (!empty($billingAddress->getAdditionalAddressLine1())) {
            $addressAdd = ' ' . trim($billingAddress->getAdditionalAddressLine1());
        }
        if (!empty($billingAddress->getAdditionalAddressLine2())) {
            $addressAdd = $addressAdd . ' ' . trim($billingAddress->getAdditionalAddressLine2());
        }

        $request->billingAddr->addrFirstLine = (string)trim($billingAddress->getStreet() . ' ' . $addressAdd);
        $request->billingAddr->postalCode = (string)$billingAddress->getZipcode();
        $request->billingAddr->town = (string)$billingAddress->getCity();
        $request->billingAddr->country = strtoupper($billingAddress->getCountry()->getIso() ?? "");

        $request->custContacts->phoneMobile = (string)$billingAddress->getPhoneNumber();
        $request->custContacts->phonePrivate = (string)$billingAddress->getPhoneNumber();
        $request->custContacts->phoneBusiness = (string)$billingAddress->getPhoneNumber();
        $request->custContacts->email = (string)$order->getOrderCustomer()->getEmail();

        $request->deliveryDetails->deliveryDetailsDifferent = true;

        $request->deliveryDetails->deliveryFirstName = (String)($shippingAddress->getFirstname() ?? "");
        $request->deliveryDetails->deliverySecondName = (String)($shippingAddress->getLastname() ?? "");
        if (!empty($shippingAddress->getCompany()) && $b2b) {
            $request->deliveryDetails->deliveryCompanyName = (String)($shippingAddress->getCompany() ?? "");
        }
        $request->deliveryDetails->deliverySalutation = null;


        $addressShippingAdd = '';
        if (!empty($shippingAddress->getAdditionalAddressLine1())) {
            $addressShippingAdd = ' ' . trim($shippingAddress->getAdditionalAddressLine1() ?? "");
        }
        if (!empty($shippingAddress->getAdditionalAddressLine2())) {
            $addressShippingAdd = $addressShippingAdd . ' ' . trim($shippingAddress->getAdditionalAddressLine2() ?? "");
        }

        $request->deliveryDetails->deliveryAddrFirstLine = trim($shippingAddress->getStreet() ?? "" . ' ' . $addressShippingAdd);
        $request->deliveryDetails->deliveryAddrPostalCode = $shippingAddress->getZipcode() ?? "";
        $request->deliveryDetails->deliveryAddrTown = $shippingAddress->getCity() ?? "";
        $request->deliveryDetails->deliveryAddrCountry = strtoupper($shippingAddress->getCountry()->getIso() ?? "");

        $request->order->basketItemsGoogleTaxonomies = array();
        $request->order->basketItemsPrices = array();

        $tmx_enable = $this->systemConfigService->get("ByjunoPayments.config.byjunothreatmetrixenable", $salesChannelId);
        $tmxorgid = $this->systemConfigService->get("ByjunoPayments.config.byjunothreatmetrix", $salesChannelId);
        if (isset($tmx_enable) && $tmx_enable == 'enabled' && isset($tmxorgid) && $tmxorgid != '' && !empty($_SESSION["byjuno_tmx"])) {
            $request->sessionInfo->tmxSessionId = $_SESSION["byjuno_tmx"];
        }
        $request->sessionInfo->sessionIp = $this->Byjuno_getClientIp();

        $request->cembraPayDetails->cembraPayPaymentMethod = null;
        $request->merchantDetails->returnUrlSuccess = base64_encode($successUrl);
        $request->merchantDetails->returnUrlCancel = base64_encode($cancelUrl);
        $request->merchantDetails->returnUrlError = base64_encode($errorUrl);

        $request->merchantDetails->transactionChannel = "WEB";
        $request->merchantDetails->integrationModule = "CembraPay Shopware 6 module 4.0.0";

        return $request;
    }

    public function Byjuno_createShopRequestConfirmTransaction($transactionId)
    {
        $request = new CembraPayConfirmRequest();
        $request->requestMsgId = CembraPayCheckoutChkRequest::GUID();
        $request->requestMsgDateTime = CembraPayCheckoutChkRequest::Date();
        $request->transactionId = $transactionId;
        return $request;
    }

    public function Byjuno_CreateShopWareShopRequestAuthorization(Context $context, $salesChannelId, OrderEntity $order, $orderId, $repayment, $b2b, $invoiceDelivery, $customGender = "", $customDob = "")
    {
        $shippingAddress = $this->getOrderShippingAddressOrder($order);
        $billingAddress = $this->getBillingAddressOrder($order, $context);

        $request = new CembraPayCheckoutAutRequest();
        $request->requestMsgType = CembraPayConstants::$MESSAGE_AUTH;
        $request->requestMsgId = CembraPayCheckoutAutRequest::GUID();
        $request->requestMsgDateTime = CembraPayCheckoutAutRequest::Date();
        $request->merchantOrderRef = $orderId;
        $request->amount = number_format($order->getAmountTotal(), 2, '.', '') * 100;
        $request->currency = $order->getCurrency()->getIsoCode();


        $reference = $order->getOrderCustomer()->getCustomerId();
        if (empty($reference)) {
            $request->custDetails->merchantCustRef = "guest_" . $order->getId();
            $request->custDetails->loggedIn = false;
        } else {
            $request->custDetails->merchantCustRef = (string)$reference;
            $request->custDetails->loggedIn = true;
        }

        $isB2B = $b2b;
        if ($billingAddress->getCompany() && $b2b == 'enabled') {
            $request->custDetails->custType = CembraPayConstants::$CUSTOMER_BUSINESS;
            $request->custDetails->companyName = $billingAddress->getCompany();
            $request->custDetails->companyRegNum = $billingAddress->getVatId();
            $isB2B = true;
        } else {
            $request->custDetails->custType = CembraPayConstants::$CUSTOMER_PRIVATE;
        }
        $request->custDetails->firstName = (string)$billingAddress->getFirstName();
        $request->custDetails->lastName = (string)$billingAddress->getLastName();
        $request->custDetails->language = (string)$this->getCustomerLanguage($context, $order->getLanguageId());


        $genderMaleStr = $this->systemConfigService->get("ByjunoPayments.config.byjunogendermale", $salesChannelId);
        $genderFemaleStr = $this->systemConfigService->get("ByjunoPayments.config.byjunogenderfemale", $salesChannelId);
        $genderMale = explode(",", $genderMaleStr);
        $genderFemale = explode(",", $genderFemaleStr);
        $customer = $this->getCustomer($order->getOrderCustomer()->getCustomerId(), $context);

        /* @var $additionalInfoSalutation \Shopware\Core\System\Salutation\SalutationEntity */
        $additionalInfoSalutation = $billingAddress->getSalutation();

        $dob = $customer->getBirthday();
        $dob_year = null;
        $dob_month = null;
        $dob_day = null;
        if ($dob != null) {
            $dob_year = sprintf('%02d', intval($dob->format("Y")));
            $dob_month = sprintf('%02d', intval($dob->format("m")));
            $dob_day = sprintf('%02d', intval($dob->format("d")));
        }

        if (!empty($dob_year) && !empty($dob_month) && !empty($dob_day)) {
            $request->custDetails->dateOfBirth =  $dob_year . "-" . $dob_month . "-" . $dob_day;
        }
        if (!empty($customDob)) {
            $request->custDetails->dateOfBirth = $customDob;
        }

        $sal = $customer->getSalutation();
        if ($sal != null) {
            $salName = $sal->getDisplayName();
            if (!empty($salName)) {
                foreach ($genderMale as $ml) {
                    if (strtolower($salName) == strtolower(trim($ml))) {
                        $request->custDetails->salutation = CembraPayConstants::$GENTER_MALE;
                    }
                }
                foreach ($genderFemale as $feml) {
                    if (strtolower($salName) == strtolower(trim($feml))) {
                        $request->custDetails->salutation = CembraPayConstants::$GENTER_FEMALE;
                    }
                }
            }
        }
        if (!empty($additionalInfoSalutation)) {
            $salutationKey = $additionalInfoSalutation->getSalutationKey();
            if (!empty($salutationKey)) {
                foreach ($genderMale as $ml) {
                    if (strtolower($salutationKey) == strtolower(trim($ml))) {
                        $request->custDetails->salutation = CembraPayConstants::$GENTER_MALE;
                    }
                }
                foreach ($genderFemale as $feml) {
                    if (strtolower($salutationKey) == strtolower(trim($feml))) {
                        $request->custDetails->salutation = CembraPayConstants::$GENTER_FEMALE;
                    }
                }
            }
        }
        if (!empty($customGender)) {
            foreach ($genderMale as $ml) {
                if (strtolower($customGender) == strtolower(trim($ml))) {
                    $request->custDetails->salutation = CembraPayConstants::$GENTER_MALE;
                }
            }
            foreach ($genderFemale as $feml) {
                if (strtolower($customGender) == strtolower(trim($feml))) {
                    $request->custDetails->salutation = CembraPayConstants::$GENTER_FEMALE;
                }
            }
        }

        $addressAdd = '';
        if (!empty($billingAddress->getAdditionalAddressLine1())) {
            $addressAdd = ' ' . trim($billingAddress->getAdditionalAddressLine1());
        }
        if (!empty($billingAddress->getAdditionalAddressLine2())) {
            $addressAdd = $addressAdd . ' ' . trim($billingAddress->getAdditionalAddressLine2());
        }
        $request->billingAddr->addrFirstLine = trim($billingAddress->getStreet() . ' ' . $addressAdd);
        $request->billingAddr->country = $billingAddress->getCountry()->getIso();
        $request->billingAddr->postalCode = $billingAddress->getZipcode();
        $request->billingAddr->town = $billingAddress->getCity();

        $request->custContacts->phoneMobile = (string)$billingAddress->getPhoneNumber();
        $request->custContacts->phonePrivate = (string)$billingAddress->getPhoneNumber();
        $request->custContacts->phoneBusiness = (string)$billingAddress->getPhoneNumber();
        $request->custContacts->email = (string)$order->getOrderCustomer()->getEmail();


        $request->deliveryDetails->deliveryFirstName = (String)($shippingAddress->getFirstname() ?? "");
        $request->deliveryDetails->deliverySecondName = (String)($shippingAddress->getLastname() ?? "");
        if (!empty($shippingAddress->getCompany()) && $b2b == 'enabled') {
            $request->deliveryDetails->deliveryCompanyName = (String)($shippingAddress->getCompany() ?? "");
        }
        $request->deliveryDetails->deliverySalutation = null;

        $addressShippingAdd = '';
        if (!empty($shippingAddress->getAdditionalAddressLine1())) {
            $addressShippingAdd = ' ' . trim($shippingAddress->getAdditionalAddressLine1() ?? "");
        }
        if (!empty($shippingAddress->getAdditionalAddressLine2())) {
            $addressShippingAdd = $addressShippingAdd . ' ' . trim($shippingAddress->getAdditionalAddressLine2() ?? "");
        }

        $request->deliveryDetails->deliveryAddrFirstLine = trim($shippingAddress->getStreet() ?? "" . ' ' . $addressShippingAdd);
        $request->deliveryDetails->deliveryAddrPostalCode = $shippingAddress->getZipcode() ?? "";
        $request->deliveryDetails->deliveryAddrTown = $shippingAddress->getCity() ?? "";
        $request->deliveryDetails->deliveryAddrCountry = strtoupper($shippingAddress->getCountry()->getIso() ?? "");

        $request->order->basketItemsGoogleTaxonomies = array();
        $request->order->basketItemsPrices = array();

        $tmx_enable = $this->systemConfigService->get("ByjunoPayments.config.byjunothreatmetrixenable", $salesChannelId);
        $tmxorgid = $this->systemConfigService->get("ByjunoPayments.config.byjunothreatmetrix", $salesChannelId);
        if (isset($tmx_enable) && $tmx_enable == 'enabled' && isset($tmxorgid) && $tmxorgid != '' && !empty($_SESSION["byjuno_tmx"])) {
            $request->sessionInfo->tmxSessionId = $_SESSION["byjuno_tmx"];
        }
        $request->sessionInfo->sessionIp = $this->Byjuno_getClientIp();


        $request->cembraPayDetails->cembraPayPaymentMethod =  $this->Byjuno_mapRepayment($repayment);
        if ($invoiceDelivery == 'postal') {
            $request->cembraPayDetails->invoiceDeliveryType = "POSTAL";
        } else {
            $request->cembraPayDetails->invoiceDeliveryType = "EMAIL";
        }

        $customerConsents = new CustomerConsents();
        $customerConsents->consentType = "CEMBRAPAY-TC";
        $customerConsents->consentProvidedAt = "MERCHANT";
        $customerConsents->consentDate = CembraPayCheckoutAutRequest::Date();
        $link = $this->Byjuno_mapToc($repayment);
        $exLink = explode("/", $link);
        $consentReference = end($exLink);
        if (empty($consentReference) && isset($exLink[count($exLink) - 1])) {
            $consentReference = $exLink[count($exLink) - 2];
        }
        $customerConsents->consentReference = base64_encode($consentReference);
        $request->customerConsents = array($customerConsents);

        $request->merchantDetails->transactionChannel = "WEB";
        $request->merchantDetails->integrationModule = "CembraPay Shopware 6 module 4.0.0";

        return $request;
    }


    public function getBillingAddressOrder(OrderEntity $order, Context $context): OrderAddressEntity
    {
        $criteria = new Criteria([$order->getBillingAddressId()]);
        $criteria->addAssociation('country');
        $criteria->addAssociation('salutation');

        /** @var null|OrderAddressEntity $address */
        $address = $this->orderAddressRepository->search($criteria, $context)->first();

        if (null === $address) {
            throw new RuntimeException('missing order customer billing address');
        }

        return $address;
    }

    public function Byjuno_mapRepayment($type)
    {
        if ($type == 'installment_3') {
            return CembraPayConstants::$INSTALLMENT_3;
        } else if ($type == 'installment_4') {
            return CembraPayConstants::$INSTALLMENT_4;
        } else if ($type == 'installment_6') {
            return CembraPayConstants::$INSTALLMENT_6;
        } else if ($type == 'installment_12') {
            return CembraPayConstants::$INSTALLMENT_12;
        } else if ($type == 'installment_24') {
            return CembraPayConstants::$INSTALLMENT_24;
        } else if ($type == 'installment_36') {
            return CembraPayConstants::$INSTALLMENT_36;
        } else if ($type == 'installment_48') {
            return CembraPayConstants::$INSTALLMENT_48;
        } else if ($type == 'single_invoice') {
            return CembraPayConstants::$SINGLEINVOICE;
        } else {
            return CembraPayConstants::$CEMBRAPAYINVOICE;
        }
    }

    public function Byjuno_mapToc($type)
    {
        if ($type == 'installment_3') {
            return $this->translator->trans('ByjunoPayment.installment_3_toc_url');
        } else if ($type == 'installment_4') {
            return $this->translator->trans('ByjunoPayment.installment_4_toc_url');
        } else if ($type == 'installment_6') {
            return $this->translator->trans('ByjunoPayment.installment_6_toc_url');
        } else if ($type == 'installment_12') {
            return $this->translator->trans('ByjunoPayment.installment_12_toc_url');
        } else if ($type == 'installment_24') {
            return $this->translator->trans('ByjunoPayment.installment_24_toc_url');
        } else if ($type == 'installment_36') {
            return $this->translator->trans('ByjunoPayment.installment_36_toc_url');
        } else if ($type == 'installment_48') {
            return $this->translator->trans('ByjunoPayment.installment_48_toc_url');
        } else if ($type == 'single_invoice') {
            return $this->translator->trans('ByjunoPayment.invoice_single_toc_url');
        } else {
            return $this->translator->trans('ByjunoPayment.invoice_byjuno_toc_url');
        }
    }

    public function Byjuno_MapDocument($name, $salesChannhelId)
    {
        $s4Names = explode(",", $this->systemConfigService->get("ByjunoPayments.config.byjunoS4techname", $salesChannhelId));
        $s5Names = explode(",", $this->systemConfigService->get("ByjunoPayments.config.byjunoS5techname", $salesChannhelId));
        foreach ($s4Names as $s4name) {
            if ($s4name == $name) {
                return "invoice";
            }
        }
        foreach ($s5Names as $s5name) {
            if ($s5name == $name) {
                return "storno";
            }
        }
        return "undefined";
    }

    public function getMailTemplate(Context $context, string $technicalName, OrderEntity $order): ?MailTemplateEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('mailTemplateType.technicalName', $technicalName));
        $criteria->addAssociation('mailTemplateType');
        $criteria->addAssociation('mailTemplateType.technicalName');
        $mailTemplate = $this->mailTemplateRepository->search($criteria, $context)->first();
        return $mailTemplate;
    }

    private function getOrderShippingAddressOrder(OrderEntity $orderEntity): ?OrderAddressEntity
    {
        /** @var OrderDeliveryEntity[] $deliveries */
        $deliveries = $orderEntity->getDeliveries();
        // TODO: Only one shipping address is supported currently, this could change in the future
        foreach ($deliveries as $delivery) {
            if ($delivery->getShippingOrderAddress() === null) {
                continue;
            }

            return $delivery->getShippingOrderAddress();
        }

        return null;
    }
}
