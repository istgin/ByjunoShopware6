<?php declare(strict_types=1);

namespace Byjuno\ByjunoPayments\Service;

use Byjuno\ByjunoPayments\Api\Api\CembraPayAzure;
use Byjuno\ByjunoPayments\Api\Api\CembraPayCheckoutAutRequest;
use Byjuno\ByjunoPayments\Api\Api\CembraPayCheckoutScreeningResponse;
use Byjuno\ByjunoPayments\Api\Api\CembraPayCommunicator;
use Byjuno\ByjunoPayments\Api\Api\CembraPayConstants;
use Byjuno\ByjunoPayments\Api\Api\CembraPayLoginDto;
use Byjuno\ByjunoPayments\Api\Api\CustDetails;
use Byjuno\ByjunoPayments\Api\Api\CustomerConsents;
use Byjuno\ByjunoPayments\Api\Classes\ByjunoCommunicator;
use Byjuno\ByjunoPayments\Api\Classes\ByjunoRequest;
use Byjuno\ByjunoPayments\Api\Classes\ByjunoResponse;
use Byjuno\ByjunoPayments\Api\Classes\ByjunoS4Response;
use Byjuno\ByjunoPayments\Api\Classes\ByjunoS5Request;
use Byjuno\ByjunoPayments\Api\DataHelper;
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
     * @var \Byjuno\ByjunoPayments\Api\Api\CembraPayAzure
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

    public function getAccessData($context, $mode) {
        $accessData = new CembraPayLoginDto();
        $accessData->helperObject = $this;
        $accessData->timeout = (int)$this->systemConfigService->get("ByjunoPayments.config.byjunotimeout", $context->getSalesChannelId());
        if ($mode == 'test') {
            $accessData->mode = 'test';
            $accessData->username = $this->systemConfigService->get("ByjunoPayments.config.cembrapaylogintest", $context->getSalesChannelId());
            $accessData->password = $this->systemConfigService->get("ByjunoPayments.config.cembrapaypasswordtest", $context->getSalesChannelId());
            $accessData->audience = "59ff4c0b-7ce8-42f0-983b-306706936fa1/.default";
            $accessToken = "";//$this->_scopeConfig->getValue('cembrapaycheckoutsettings/cembrapaycheckout_setup/access_token_test') ?? "";
        } else {
            $accessData->mode = 'live';
            $accessData->username = $this->systemConfigService->get("ByjunoPayments.config.cembrapayloginlive", $context->getSalesChannelId());
            $accessData->password = $this->systemConfigService->get("ByjunoPayments.config.cembrapaypasswordlive", $context->getSalesChannelId());
            $accessData->audience = "80d0ac9d-9d5c-499c-876e-71dd57e436f2/.default";
            $accessToken = "";//$this->_scopeConfig->getValue('cembrapaycheckoutsettings/cembrapaycheckout_setup/access_token_live') ?? "";
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
           // $this->_writerInterface->save('cembrapaycheckoutsettings/cembrapaycheckout_setup/access_token_test', $hash.$token);
        } else {
          //  $this->_writerInterface->save('cembrapaycheckoutsettings/cembrapaycheckout_setup/access_token_live', $hash.$token);
        }
       // $this->_reinitableConfig->reinit();
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
                    && !empty($customFields["byjuno_s3_sent"])
                    && $customFields["byjuno_s3_sent"] == 1) {
                    $mode = $this->systemConfigService->get("ByjunoPayments.config.mode", $order->getSalesChannelId());
                    $request = $this->CreateShopRequestS5Cancel($order->getAmountTotal(),
                        $order->getCurrency()->getIsoCode(),
                        $order->getOrderNumber(),
                        $order->getOrderCustomer()->getId(),
                        date("Y-m-d"),
                        $order->getSalesChannelId());
                    $statusLog = "S5 Cancel request";
                    $xml = $request->createRequest();
                    $byjunoCommunicator = new ByjunoCommunicator();
                    if (isset($mode) && strtolower($mode) == 'live') {
                        $byjunoCommunicator->setServer('live');
                    } else {
                        $byjunoCommunicator->setServer('test');
                    }
                    $response = $byjunoCommunicator->sendS4Request($xml, $this->systemConfigService->get("ByjunoPayments.config.byjunotimeout", $order->getSalesChannelId()));
                    if (isset($response)) {
                        $byjunoResponse = new ByjunoS4Response();
                        $byjunoResponse->setRawResponse($response);
                        $byjunoResponse->processResponse();
                        $statusCDP = $byjunoResponse->getProcessingInfoClassification();
                        $this->saveS5Log($event->getContext(), $request, $xml, $response, $statusCDP, $statusLog, "-", "-");
                    } else {
                        $this->saveS5Log($event->getContext(), $request, $xml, "Empty response", 0, $statusLog, "-", "-");
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
            $accessData = $this->getAccessData($event->getSalesChannelContext(), $mode);
            $response = $cembrapayCommunicator->sendScreeningRequest($json, $accessData, function ($object, $token, $accessData) {
                $object->saveToken($token, $accessData);
            });
            $screeningStatus = "";
            if ($response) {
                /* @var $responseRes CembraPayCheckoutScreeningResponse */
                $responseRes = CembraPayConstants::screeningResponse($response);
                $screeningStatus = $responseRes->processingStatus;
                $this->saveLog($event->getContext(), $request, $json, $response, $screeningStatus, $statusLog);
                //$this->saveLog($json, $response, $responseRes->processingStatus, $CembraPayRequestName,
                 //   $request->custDetails->firstName, $request->custDetails->lastName, $request->requestMsgId,
                 //   $request->billingAddr->postalCode, $request->billingAddr->town, $request->billingAddr->country, $request->billingAddr->addrFirstLine, $responseRes->transactionId, "-");
            } else {
                $this->saveLog($event->getContext(), $request, $json, "Empty response", CembraPayConstants::$REQUEST_ERROR, $statusLog);
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
        $request->amount = number_format($convertedCart["price"]->getTotalPrice(), 2, '.', '') * 100;
        $request->currency = $currency->getIsoCode();

        $reference = $convertedCart["orderCustomer"]["customerId"];
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
        $customer = $this->getCustomer($convertedCart["orderCustomer"]["customerId"], $context->getContext());
        $dob = $customer->getBirthday();
        $dob_year = null;
        $dob_month = null;
        $dob_day = null;
        if ($dob != null) {
            $dob_year = intval($dob->format("Y"));
            $dob_month = intval($dob->format("m"));
            $dob_day = intval($dob->format("d"));
        }

        if (!empty($dob_year) && !empty($dob_month) && !empty($dob_day)) {
            $request->custDetails->dateOfBirth = $dob_year . "-" . $dob_month . "-" . $dob_day;
        }
        if (!empty($customDob)) {
            $request->custDetails->dateOfBirth = $customDob;
        }
        $request->custDetails->salutation = CembraPayConstants::$GENTER_UNKNOWN;
        $additionalInfo = $billingAddressSalutation->getDisplayName();
        $genderMaleStr = $this->systemConfigService->get("ByjunoPayments.config.byjunogendermale", $context->getSalesChannelId());
        $genderFemaleStr = $this->systemConfigService->get("ByjunoPayments.config.byjunogenderfemale", $context->getSalesChannelId());
        $genderMale = explode(",", $genderMaleStr);
        $genderFemale = explode(",", $genderFemaleStr);
        if (!empty($additionalInfo)) {
            foreach ($genderMale as $ml) {
                if (strtolower($additionalInfo) == strtolower(trim($ml))) {
                    $request->custDetails->salutation = CembraPayConstants::$GENTER_MALE;
                }
            }
            foreach ($genderFemale as $feml) {
                if (strtolower($additionalInfo) == strtolower(trim($feml))) {
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
        $request->deliveryDetails->deliveryMethod = CembraPayConstants::$DELIVERY_POST;
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
            $request->sessionInfo->fingerPrint = $_SESSION["byjuno_tmx"];
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


    public function Byjuno_CreateShopWareShopRequestCDP(SalesChannelContext $context, Array $convertedCart, $paymentmethod)
    {
        $request = new ByjunoRequest();
        $request->setClientId($this->systemConfigService->get("ByjunoPayments.config.byjunoclientid", $context->getSalesChannelId()));
        $request->setUserID($this->systemConfigService->get("ByjunoPayments.config.byjunouserid",$context->getSalesChannelId()));
        $request->setPassword($this->systemConfigService->get("ByjunoPayments.config.byjunopassword", $context->getSalesChannelId()));
        $request->setVersion("1.00");
        $request->setRequestEmail($this->systemConfigService->get("ByjunoPayments.config.byjunotechemail", $context->getSalesChannelId()));
        $request->setLanguage($this->getCustomerLanguage($context->getContext(), $convertedCart["languageId"]));

        $request->setRequestId(uniqid($convertedCart["billingAddressId"] . "_"));
        $reference = $convertedCart["orderCustomer"]["customerId"];
        if (empty($reference)) {
            $request->setCustomerReference(uniqid("guest_"));
        } else {
            $request->setCustomerReference($reference);
        }
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
        $request->setFirstName($billingAddress["firstName"]);
        $request->setLastName($billingAddress["lastName"]);
        $addressAdd = '';
        if (!empty($billing['additionalAddressLine1'])) {
            $addressAdd = ' ' . trim($billingAddress["additionalAddressLine1"]);
        }
        if (!empty($billing['additionalAddressLine2'])) {
            $addressAdd = $addressAdd . ' ' . trim($billingAddress["additionalAddressLine2"]);
        }
        $request->setFirstLine(trim($billingAddress["street"] . ' ' . $addressAdd));
        $request->setCountryCode($billingAddressCountry->getIso());
        $request->setPostCode($billingAddress["zipcode"]);
        $request->setTown($billingAddress["city"]);

        if (!empty($billingAddress["company"])) {
            $request->setCompanyName1($billingAddress["company"]);
        }
        if (!empty($billingAddress["vatId"]) && !empty($billingAddress["company"])) {
            $request->setCompanyVatId($billingAddress["vatId"]);
        }
        if (!empty($shippingAddress["company"])) {
            $request->setDeliveryCompanyName1($shippingAddress["company"]);
        }
        $request->setGender(0);
        $additionalInfo = $billingAddressSalutation->getDisplayName();
        $genderMaleStr = $this->systemConfigService->get("ByjunoPayments.config.byjunogendermale", $context->getSalesChannelId());
        $genderFemaleStr = $this->systemConfigService->get("ByjunoPayments.config.byjunogenderfemale", $context->getSalesChannelId());
        $genderMale = explode(",", $genderMaleStr);
        $genderFemale = explode(",", $genderFemaleStr);
        if (!empty($additionalInfo)) {
            foreach ($genderMale as $ml) {
                if (strtolower($additionalInfo) == strtolower(trim($ml))) {
                    $request->setGender(1);
                }
            }
            foreach ($genderFemale as $feml) {
                if (strtolower($additionalInfo) == strtolower(trim($feml))) {
                    $request->setGender(2);
                }
            }
        }
        $customer = $this->getCustomer($convertedCart["orderCustomer"]["customerId"], $context->getContext());
        $dob = $customer->getBirthday();
        $dob_year = null;
        $dob_month = null;
        $dob_day = null;
        if ($dob != null) {
            $dob_year = intval($dob->format("Y"));
            $dob_month = intval($dob->format("m"));
            $dob_day = intval($dob->format("d"));
        }

        if (!empty($dob_year) && !empty($dob_month) && !empty($dob_day)) {
            $request->setDateOfBirth($dob_year . "-" . $dob_month . "-" . $dob_day);
        }
        if (!empty($customDob)) {
            $request->setDateOfBirth($customDob);
        }

        if (!empty($billingAddress["phoneNumber"])) {
            $request->setTelephonePrivate($billingAddress["phoneNumber"]);
        }
        $request->setEmail($convertedCart["orderCustomer"]["email"]);

        $extraInfo["Name"] = 'ORDERCLOSED';
        $extraInfo["Value"] = "NO";
        $request->setExtraInfo($extraInfo);
        $extraInfo["Name"] = 'ORDERAMOUNT';
        $extraInfo["Value"] = $convertedCart["price"]->getTotalPrice();
        $request->setExtraInfo($extraInfo);

        $extraInfo["Name"] = 'ORDERCURRENCY';
        $extraInfo["Value"] = $currency->getIsoCode();
        $request->setExtraInfo($extraInfo);

        $extraInfo["Name"] = 'IP';
        $extraInfo["Value"] = $this->Byjuno_getClientIp();
        $request->setExtraInfo($extraInfo);


        $tmx_enable = $this->systemConfigService->get("ByjunoPayments.config.byjunothreatmetrixenable", $context->getSalesChannelId());
        $tmxorgid = $this->systemConfigService->get("ByjunoPayments.config.byjunothreatmetrix", $context->getSalesChannelId());
        if (isset($tmx_enable) && $tmx_enable == 'enabled' && isset($tmxorgid) && $tmxorgid != '' && !empty($_SESSION["byjuno_tmx"])) {
            $extraInfo["Name"] = 'DEVICE_FINGERPRINT_ID';
            $extraInfo["Value"] = $_SESSION["byjuno_tmx"];
            $request->setExtraInfo($extraInfo);
        }
        // shipping information
        $extraInfo["Name"] = 'DELIVERY_FIRSTNAME';
        $extraInfo["Value"] = $shippingAddress["firstName"];
        $request->setExtraInfo($extraInfo);

        $extraInfo["Name"] = 'DELIVERY_LASTNAME';
        $extraInfo["Value"] = $shippingAddress["lastName"];
        $request->setExtraInfo($extraInfo);

        $addressShippingAdd = '';
        if (!empty($shippingAddress['additionalAddressLine1'])) {
            $addressShippingAdd = ' ' . trim($shippingAddress['additionalAddressLine1']);
        }
        if (!empty($shippingAddress['additionalAddressLine2'])) {
            $addressShippingAdd = $addressShippingAdd . ' ' . trim($shippingAddress['additionalAddressLine2']);
        }

        $extraInfo["Name"] = 'DELIVERY_FIRSTLINE';
        $extraInfo["Value"] = trim($shippingAddress["street"] . ' ' . $addressShippingAdd);
        $request->setExtraInfo($extraInfo);

        $extraInfo["Name"] = 'DELIVERY_HOUSENUMBER';
        $extraInfo["Value"] = '';
        $request->setExtraInfo($extraInfo);

        $extraInfo["Name"] = 'DELIVERY_COUNTRYCODE';
        $extraInfo["Value"] = $shippingAddressCountry->getIso();
        $request->setExtraInfo($extraInfo);

        $extraInfo["Name"] = 'DELIVERY_POSTCODE';
        $extraInfo["Value"] = $shippingAddress["zipcode"];
        $request->setExtraInfo($extraInfo);

        $extraInfo["Name"] = 'DELIVERY_TOWN';
        $extraInfo["Value"] = $shippingAddress["city"];
        $request->setExtraInfo($extraInfo);

        $extraInfo["Name"] = 'PAYMENTMETHOD';
        $extraInfo["Value"] = $this->Byjuno_mapMethod($paymentmethod);
        $request->setExtraInfo($extraInfo);

        $extraInfo["Name"] = 'MESSAGETYPESPEC';
        $extraInfo["Value"] = 'CREDITCHECK';
        $request->setExtraInfo($extraInfo);

        $extraInfo["Name"] = 'CONNECTIVTY_MODULE';
        $extraInfo["Value"] = 'Byjuno ShopWare 6 module 3.1.1';
        $request->setExtraInfo($extraInfo);
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

    function Byjuno_mapMethod($method)
    {
        if ($method == 'byjuno_payment_installment') {
            return "INSTALLMENT";
        } else {
            return "INVOICE";
        }
    }

    protected function isStatusOkCDP($status, SalesChannelContext $context)
    {
        try {
            $accepted_CDP = $this->systemConfigService->get("ByjunoPayments.config.allowedcdp", $context->getSalesChannelId());
            $ijStatus = Array();
            if (!empty(trim((String)$accepted_CDP))) {
                $ijStatus = explode(",", trim((String)$accepted_CDP));
                foreach ($ijStatus as $key => $val) {
                    $ijStatus[$key] = intval($val);
                }
            }
            if (!empty($accepted_CDP) && count($ijStatus) > 0 && in_array($status, $ijStatus)) {
                return true;
            }
            return false;

        } catch (Exception $e) {
            return false;
        }
    }

    function CreateShopRequestS5Cancel($amount, $orderCurrency, $orderId, $customerId, $date, $salesChannelId)
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
        $request->setAdditional2('');
        $request->setTransactionType("EXPIRED");
        $request->setOpenBalance("0");
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

    private function saveS5Log(Context $context, ByjunoS5Request $request, $xml_request, $xml_response, $status, $type, $firstName, $lastName)
    {
        $entry = [
            'id' => Uuid::randomHex(),
            'request_id' => $request->getRequestId(),
            'request_type' => $type,
            'firstname' => $firstName,
            'lastname' => $lastName,
            'ip' => $_SERVER['REMOTE_ADDR'],
            'byjuno_status' => (($status != "") ? $status . '' : 'Error'),
            'xml_request' => $xml_request,
            'xml_response' => $xml_response
        ];

        $context->scope(Context::SYSTEM_SCOPE, function (Context $context) use ($entry): void {
            /** @var EntityRepository $logRepository */
            $logRepository = $this->container->get('byjuno_log_entity.repository');
            $logRepository->upsert([$entry], $context);
        });
    }

    public function saveLog(Context $context, ByjunoRequest $request, $xml_request, $xml_response, $status, $type)
    {
        $entry = [
            'id' => Uuid::randomHex(),
            'request_id' => $request->getRequestId(),
            'request_type' => $type,
            'firstname' => $request->getFirstName(),
            'lastname' => $request->getLastName(),
            'ip' => empty($_SERVER['REMOTE_ADDR']) ? "no ip" : $_SERVER['REMOTE_ADDR'],
            'byjuno_status' => (($status != "") ? $status . '' : 'Error'),
            'xml_request' => $xml_request,
            'xml_response' => $xml_response
        ];

        $context->scope(Context::SYSTEM_SCOPE, function (Context $context) use ($entry): void {
            /** @var EntityRepository $logRepository */
            $logRepository = $this->container->get('byjuno_log_entity.repository');
            $logRepository->upsert([$entry], $context);
        });
    }

    public function sendMailOrder(Context $context, OrderEntity $order, String $salesChanhhelId): void
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
        }
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

    public function Byjuno_CreateShopWareShopRequestUserBilling(Context $context, $salesChannelId, OrderEntity $order, $orderId, $paymentmethod, $repayment, $riskOwner, $invoiceDelivery, $customGender = "", $customDob = "", $transactionNumber = "", $orderClosed = "NO")
    {
        $request = new ByjunoRequest();
        $request->setClientId($this->systemConfigService->get("ByjunoPayments.config.byjunoclientid", $salesChannelId));
        $request->setUserID($this->systemConfigService->get("ByjunoPayments.config.byjunouserid", $salesChannelId));
        $request->setPassword($this->systemConfigService->get("ByjunoPayments.config.byjunopassword", $salesChannelId));
        $request->setVersion("1.00");
        $request->setRequestEmail($this->systemConfigService->get("ByjunoPayments.config.byjunotechemail", $salesChannelId));
        $request->setLanguage($this->getCustomerLanguage($context, $order->getLanguageId()));

        $request->setRequestId(uniqid($order->getBillingAddressId() . "_"));
        $reference = $order->getOrderCustomer()->getCustomerId();
        if (empty($reference)) {
            $request->setCustomerReference(uniqid("guest_"));
        } else {
            $request->setCustomerReference($reference);
        }
        $shippingAddress = $this->getOrderShippingAddressOrder($order);
        $billingAddress = $this->getBillingAddressOrder($order, $context);

        $request->setFirstName($billingAddress->getFirstName());
        $request->setLastName($billingAddress->getLastName());
        $addressAdd = '';
        if (!empty($billingAddress->getAdditionalAddressLine1())) {
            $addressAdd = ' ' . trim($billingAddress->getAdditionalAddressLine1());
        }
        if (!empty($billingAddress->getAdditionalAddressLine2())) {
            $addressAdd = $addressAdd . ' ' . trim($billingAddress->getAdditionalAddressLine2());
        }
        $request->setFirstLine(trim($billingAddress->getStreet() . ' ' . $addressAdd));
        $request->setCountryCode($billingAddress->getCountry()->getIso());
        $request->setPostCode($billingAddress->getZipcode());
        $request->setTown($billingAddress->getCity());

        if (!empty($billingAddress->getCompany())) {
            $request->setCompanyName1($billingAddress->getCompany());
        }
        if (!empty($billingAddress->getVatId()) && !empty($billingAddress->getCompany())) {
            $request->setCompanyVatId($billingAddress->getVatId());
        }
        if (!empty($shippingAddress->getCompany())) {
            $request->setDeliveryCompanyName1($shippingAddress->getCompany());
        }

        $request->setGender(0);

        /* @var $additionalInfoSalutation \Shopware\Core\System\Salutation\SalutationEntity */
        $additionalInfoSalutation = $billingAddress->getSalutation();

        $genderMaleStr = $this->systemConfigService->get("ByjunoPayments.config.byjunogendermale", $salesChannelId);
        $genderFemaleStr = $this->systemConfigService->get("ByjunoPayments.config.byjunogenderfemale", $salesChannelId);
        $genderMale = explode(",", $genderMaleStr);
        $genderFemale = explode(",", $genderFemaleStr);
        $customer = $this->getCustomer($order->getOrderCustomer()->getCustomerId(), $context);
        $sal = $customer->getSalutation();
        if ($sal != null) {
            $salName = $sal->getDisplayName();
            if (!empty($salName)) {
                foreach ($genderMale as $ml) {
                    if (strtolower($salName) == strtolower(trim($ml))) {
                        $request->setGender(1);
                    }
                }
                foreach ($genderFemale as $feml) {
                    if (strtolower($salName) == strtolower(trim($feml))) {
                        $request->setGender(2);
                    }
                }
            }
        }
        if (!empty($additionalInfoSalutation)) {
            $name = $additionalInfoSalutation->getSalutationKey();
            if (!empty($name)) {
                foreach ($genderMale as $ml) {
                    if (strtolower($name) == strtolower(trim($ml))) {
                        $request->setGender(1);
                    }
                }
                foreach ($genderFemale as $feml) {
                    if (strtolower($name) == strtolower(trim($feml))) {
                        $request->setGender(2);
                    }
                }
            }
        }
        if (!empty($customGender)) {
            foreach ($genderMale as $ml) {
                if (strtolower($customGender) == strtolower(trim($ml))) {
                    $request->setGender(1);
                }
            }
            foreach ($genderFemale as $feml) {
                if (strtolower($customGender) == strtolower(trim($feml))) {
                    $request->setGender(2);
                }
            }
        }

        $dob = $customer->getBirthday();
        $dob_year = null;
        $dob_month = null;
        $dob_day = null;
        if ($dob != null) {
            $dob_year = intval($dob->format("Y"));
            $dob_month = intval($dob->format("m"));
            $dob_day = intval($dob->format("d"));
        }

        if (!empty($dob_year) && !empty($dob_month) && !empty($dob_day)) {
            $request->setDateOfBirth($dob_year . "-" . $dob_month . "-" . $dob_day);
        }
        if (!empty($customDob)) {
            $request->setDateOfBirth($customDob);
        }

        $request->setTelephonePrivate($billingAddress->getPhoneNumber());
        $request->setEmail($order->getOrderCustomer()->getEmail());

        if ($transactionNumber != "") {
            $extraInfo["Name"] = 'TRANSACTIONNUMBER';
            $extraInfo["Value"] = $transactionNumber;
            $request->setExtraInfo($extraInfo);
        }

        $extraInfo["Name"] = 'ORDERCLOSED';
        $extraInfo["Value"] = $orderClosed;
        $request->setExtraInfo($extraInfo);

        $extraInfo["Name"] = 'ORDERAMOUNT';
        $extraInfo["Value"] = $order->getAmountTotal();
        $request->setExtraInfo($extraInfo);

        $extraInfo["Name"] = 'ORDERCURRENCY';
        $extraInfo["Value"] = $order->getCurrency()->getIsoCode();
        $request->setExtraInfo($extraInfo);

        $extraInfo["Name"] = 'IP';
        $extraInfo["Value"] = $this->Byjuno_getClientIp();
        $request->setExtraInfo($extraInfo);

        $tmx_enable = $this->systemConfigService->get("ByjunoPayments.config.byjunothreatmetrixenable", $salesChannelId);
        $tmxorgid = $this->systemConfigService->get("ByjunoPayments.config.byjunothreatmetrix", $salesChannelId);
        if (isset($tmx_enable) && $tmx_enable == 'enabled' && isset($tmxorgid) && $tmxorgid != '' && !empty($_SESSION["byjuno_tmx"])) {
            $extraInfo["Name"] = 'DEVICE_FINGERPRINT_ID';
            $extraInfo["Value"] = $_SESSION["byjuno_tmx"];
            $request->setExtraInfo($extraInfo);
        }

        if ($invoiceDelivery == 'postal') {
            $extraInfo["Name"] = 'PAPER_INVOICE';
            $extraInfo["Value"] = 'YES';
            $request->setExtraInfo($extraInfo);
        }

        // shipping information
        $extraInfo["Name"] = 'DELIVERY_FIRSTNAME';
        $extraInfo["Value"] = $shippingAddress->getFirstName();
        $request->setExtraInfo($extraInfo);

        $extraInfo["Name"] = 'DELIVERY_LASTNAME';
        $extraInfo["Value"] = $shippingAddress->getLastName();
        $request->setExtraInfo($extraInfo);

        $addressShippingAdd = '';
        if (!empty($shippingAddress->getAdditionalAddressLine1())) {
            $addressShippingAdd = ' ' . trim($shippingAddress->getAdditionalAddressLine1());
        }
        if (!empty($shippingAddress->getAdditionalAddressLine2())) {
            $addressShippingAdd = $addressShippingAdd . ' ' . trim($shippingAddress->getAdditionalAddressLine2());
        }

        $extraInfo["Name"] = 'DELIVERY_FIRSTLINE';
        $extraInfo["Value"] = trim($shippingAddress->getStreet() . ' ' . $addressShippingAdd);
        $request->setExtraInfo($extraInfo);

        $extraInfo["Name"] = 'DELIVERY_HOUSENUMBER';
        $extraInfo["Value"] = '';
        $request->setExtraInfo($extraInfo);

        $extraInfo["Name"] = 'DELIVERY_COUNTRYCODE';
        $extraInfo["Value"] = $shippingAddress->getCountry()->getIso();
        $request->setExtraInfo($extraInfo);

        $extraInfo["Name"] = 'DELIVERY_POSTCODE';
        $extraInfo["Value"] = $shippingAddress->getZipcode();
        $request->setExtraInfo($extraInfo);

        $extraInfo["Name"] = 'DELIVERY_TOWN';
        $extraInfo["Value"] = $shippingAddress->getCity();
        $request->setExtraInfo($extraInfo);

        if (!empty($orderId)) {
            $extraInfo["Name"] = 'ORDERID';
            $extraInfo["Value"] = $orderId;
            $request->setExtraInfo($extraInfo);
        }
        $extraInfo["Name"] = 'PAYMENTMETHOD';
        $extraInfo["Value"] = $this->Byjuno_mapMethod($paymentmethod);
        $request->setExtraInfo($extraInfo);

        if ($repayment != "") {
            $extraInfo["Name"] = 'REPAYMENTTYPE';
            $extraInfo["Value"] = $this->Byjuno_mapRepayment($repayment);
            $request->setExtraInfo($extraInfo);
        }

        if ($riskOwner != "") {
            $extraInfo["Name"] = 'RISKOWNER';
            $extraInfo["Value"] = $riskOwner;
            $request->setExtraInfo($extraInfo);
        }

        $extraInfo["Name"] = 'CONNECTIVTY_MODULE';
        $extraInfo["Value"] = 'Byjuno ShopWare 6 module 3.1.1';
        $request->setExtraInfo($extraInfo);
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
            return "10";
        } else if ($type == 'installment_10') {
            return "5";
        } else if ($type == 'installment_12') {
            return "8";
        } else if ($type == 'installment_24') {
            return "9";
        } else if ($type == 'installment_4x12') {
            return "1";
        } else if ($type == 'installment_36') {
            return "11";
        } else if ($type == 'single_invoice') {
            return "3";
        } else {
            return "4";
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

    public function isStatusOkS2($status, $salesChannhelId)
    {
        try {
            $accepted_S2_ij = $this->systemConfigService->get("ByjunoPayments.config.alloweds2", $salesChannhelId);
            $accepted_S2_merhcant = $this->systemConfigService->get("ByjunoPayments.config.alloweds2merchant", $salesChannhelId);

            $ijStatus = Array();
            if (!empty(trim((String)$accepted_S2_ij))) {
                $ijStatus = explode(",", trim((String)$accepted_S2_ij));
                foreach ($ijStatus as $key => $val) {
                    $ijStatus[$key] = intval($val);
                }
            }
            $merchantStatus = Array();
            if (!empty(trim((String)$accepted_S2_merhcant))) {
                $merchantStatus = explode(",", trim((String)$accepted_S2_merhcant));
                foreach ($merchantStatus as $key => $val) {
                    $merchantStatus[$key] = intval($val);
                }
            }
            if (!empty($accepted_S2_ij) && count($ijStatus) > 0 && in_array($status, $ijStatus)) {
                return true;
            } else if (!empty($accepted_S2_merhcant) && count($merchantStatus) > 0 && in_array($status, $merchantStatus)) {
                return true;
            }
            return false;

        } catch (Exception $e) {
            return false;
        }
    }

    public function isStatusOkS3($status, $salesChannhelId)
    {
        try {
            $accepted_S3 = $this->systemConfigService->get("ByjunoPayments.config.alloweds3", $salesChannhelId);
            $ijStatus = Array();
            if (!empty(trim((String)$accepted_S3))) {
                $ijStatus = explode(",", trim((String)$accepted_S3));
                foreach ($ijStatus as $key => $val) {
                    $ijStatus[$key] = intval($val);
                }
            }
            if (!empty($accepted_S3) && count($ijStatus) > 0 && in_array($status, $ijStatus)) {
                return true;
            }
            return false;

        } catch (Exception $e) {
            return false;
        }
    }

    public function getStatusRisk($status, $salesChannhelId)
    {
        try {
            $accepted_S2_ij = $this->systemConfigService->get("ByjunoPayments.config.alloweds2", $salesChannhelId);
            $accepted_S2_merhcant = $this->systemConfigService->get("ByjunoPayments.config.alloweds2merchant", $salesChannhelId);
            $ijStatus = Array();
            if (!empty(trim((String)$accepted_S2_ij))) {
                $ijStatus = explode(",", trim((String)$accepted_S2_ij));
                foreach ($ijStatus as $key => $val) {
                    $ijStatus[$key] = intval($val);
                }
            }
            $merchantStatus = Array();
            if (!empty(trim((String)$accepted_S2_merhcant))) {
                $merchantStatus = explode(",", trim((String)$accepted_S2_merhcant));
                foreach ($merchantStatus as $key => $val) {
                    $merchantStatus[$key] = intval($val);
                }
            }
            if (!empty($accepted_S2_ij) && count($ijStatus) > 0 && in_array($status, $ijStatus)) {
                return "IJ";
            } else if (!empty($accepted_S2_merhcant) && count($merchantStatus) > 0 && in_array($status, $merchantStatus)) {
                return "CLIENT";
            }
            return "No owner";

        } catch (Exception $e) {
            return "INTERNAL ERROR";
        }
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
        //var_dump($orderEntity->getShippingAddress());
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
