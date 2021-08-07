<?php declare(strict_types=1);

namespace Byjuno\ByjunoPayments\Service;

use Byjuno\ByjunoPayments\Api\Classes\ByjunoCommunicator;
use Byjuno\ByjunoPayments\Api\Classes\ByjunoRequest;
use Byjuno\ByjunoPayments\Api\Classes\ByjunoResponse;
use Byjuno\ByjunoPayments\Api\Classes\ByjunoS4Request;
use Byjuno\ByjunoPayments\Api\Classes\ByjunoS4Response;
use Byjuno\ByjunoPayments\Api\Classes\ByjunoS5Request;
use Byjuno\ByjunoPayments\ByjunoPayments;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;
use Exception;
use Psr\Container\ContainerInterface;
use phpDocumentor\Reflection\Types\Array_;
use RuntimeException;
use Shopware\Core\Checkout\Cart\Cart;
use Byjuno\ByjunoPayments\Log;
use Shopware\Core\Checkout\Cart\Order\CartConvertedEvent;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Document\DocumentEntity;
use Shopware\Core\Checkout\Document\DocumentGeneratorController;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Validation\Exception\ConstraintViolationException;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\CustomField\CustomFieldTypes;
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\System\NumberRange\Api\NumberRangeController;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Core\System\Salutation\SalutationCollection;
use Shopware\Core\System\Salutation\SalutationEntity;
use Shopware\Core\System\StateMachine\Event\StateMachineTransitionEvent;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Recovery\Install\Struct\Currency;
use Shopware\Storefront\Event\StorefrontRenderEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
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
    /** @var EntityRepositoryInterface */
    private $paymentMethodRepository;
    /** @var EntityRepositoryInterface */
    private $languageRepository;
    /** @var EntityRepositoryInterface */
    private $orderAddressRepository;
    /**
     * @var EntityRepositoryInterface
     */
    private $documentRepository;
    /**
     * @var ByjunoCoreTask
     */
    private $byjunoCoreTask;

    /** @var TranslatorInterface */
    private $translator;
    private static $writeRecursion;


    public function __construct(
        SystemConfigService $systemConfigService,
        EntityRepositoryInterface $paymentMethodRepository,
        EntityRepositoryInterface $languageRepository,
        EntityRepositoryInterface $orderAddressRepository,
        EntityRepositoryInterface $documentRepository,
        ContainerInterface $container,
        TranslatorInterface $translator,
        ByjunoCoreTask $byjunoCoreTask
    )
    {
        $this->systemConfigService = $systemConfigService;
        $this->paymentMethodRepository = $paymentMethodRepository;
        $this->languageRepository = $languageRepository;
        $this->orderAddressRepository = $orderAddressRepository;
        $this->documentRepository = $documentRepository;
        $this->container = $container;
        $this->translator = $translator;
        $this->byjunoCoreTask = $byjunoCoreTask;
        $writeRecursion = Array();
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
        ];
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
                    $name = $doc->getConfig()["name"];
                    switch ($name) {
                        case "storno":
                            if ($this->systemConfigService->get("ByjunoPayments.config.byjunoS5") != 'enabled')
                            {
                                return;
                            }
                            break;
                        case "invoice":
                            if ($this->systemConfigService->get("ByjunoPayments.config.byjunoS4") != 'enabled') {
                                return;
                            }
                            break;
                        default:
                            return;

                    }
                    $order = $this->getOrder($doc->getOrderId());
                    if ($order != null) {
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
        if ($event->getEntityName() == 'order' && $event->getToPlace()->getTechnicalName() == "cancelled") {
            $order = $this->getOrder($event->getEntityId());
            if ($order != null) {
                $paymentMethods = $order->getTransactions();
                $paymentMethodId = '';
                foreach ($paymentMethods as $pm) {
                    $paymentMethodId = $pm->getPaymentMethod()->getHandlerIdentifier();
                    break;
                }
                if ($paymentMethodId == "Byjuno\ByjunoPayments\Service\ByjunoCorePayment" && $this->systemConfigService->get("ByjunoPayments.config.byjunoS5") == 'enabled') {
                    $mode = $this->systemConfigService->get("ByjunoPayments.config.mode");
                    $request = $this->CreateShopRequestS5Cancel($order->getAmountTotal(),
                        $order->getCurrency()->getIsoCode(),
                        $order->getOrderNumber(),
                        $order->getOrderCustomer()->getId(),
                        date("Y-m-d"));
                    $statusLog = "S5 Cancel request";
                    $xml = $request->createRequest();
                    $byjunoCommunicator = new ByjunoCommunicator();
                    if (isset($mode) && strtolower($mode) == 'live') {
                        $byjunoCommunicator->setServer('live');
                    } else {
                        $byjunoCommunicator->setServer('test');
                    }
                    $response = $byjunoCommunicator->sendS4Request($xml, $this->systemConfigService->get("ByjunoPayments.config.byjunotimeout"));
                    if (isset($response)) {
                        $byjunoResponse = new ByjunoS4Response();
                        $byjunoResponse->setRawResponse($response);
                        $byjunoResponse->processResponse();
                        $statusCDP = $byjunoResponse->getProcessingInfoClassification();
                        $this->saveS5Log($event->getContext(), $request, $xml, $response, $statusCDP, $statusLog, "-", "-");
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
            if ($this->systemConfigService->get("ByjunoPayments.config.byjunothreatmetrixenable") == 'enabled') {
                $tmx_enable = true;
            }
            $tmxorgid = $this->systemConfigService->get("ByjunoPayments.config.byjunothreatmetrix");
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
        if ($this->systemConfigService->get("ByjunoPayments.config.byjunousecdp") == 'enabled' && $event->getSalesChannelContext()->getPaymentMethod()->getHandlerIdentifier() == "Byjuno\ByjunoPayments\Service\ByjunoCorePayment") {
            $b2b = $this->systemConfigService->get("ByjunoPayments.config.byjunob2b");
            $mode = $this->systemConfigService->get("ByjunoPayments.config.mode");
            $paymentMethod = "";
            if ($event->getSalesChannelContext()->getPaymentMethod()->getId() == ByjunoPayments::BYJUNO_INVOICE) {
                $paymentMethod = "byjuno_payment_invoice";
            } else if ($event->getSalesChannelContext()->getPaymentMethod()->getId() == ByjunoPayments::BYJUNO_INSTALLMENT) {
                $paymentMethod = "byjuno_payment_installment";
            }
            $request = $this->Byjuno_CreateShopWareShopRequestCDP($event->getContext(), $event->getConvertedCart(), $paymentMethod);
            $statusLog = "CDP request (S1)";
            if ($request->getCompanyName1() != '' && $b2b == 'enabled') {
                $statusLog = "CDP for company (S1)";
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
            $statusCDP = 0;
            if ($response) {
                $intrumResponse = new ByjunoResponse();
                $intrumResponse->setRawResponse($response);
                $intrumResponse->processResponse();
                $statusCDP = (int)$intrumResponse->getCustomerRequestStatus();
                $this->saveLog($event->getContext(), $request, $xml, $response, $statusCDP, $statusLog);
                if (intval($statusCDP) > 15) {
                    $statusCDP = 0;
                }
            }
            if (!$this->isStatusOkCDP($statusCDP)) {
                $violation = new ConstraintViolation(
                    $this->translator->trans('ByjunoPayment.cdp_error'),
                    '',
                    [],
                    '',
                    '',
                    ''
                );
                $violations = new ConstraintViolationList([$violation]);
                $exception = new ConstraintViolationException($violations, []);
                throw new ConstraintViolationException($violations, []);
            }
        }
    }

    function Byjuno_CreateShopWareShopRequestCDP(Context $context, Array $convertedCart, $paymentmethod)
    {
        $request = new ByjunoRequest();
        $request->setClientId($this->systemConfigService->get("ByjunoPayments.config.byjunoclientid"));
        $request->setUserID($this->systemConfigService->get("ByjunoPayments.config.byjunouserid"));
        $request->setPassword($this->systemConfigService->get("ByjunoPayments.config.byjunopassword"));
        $request->setVersion("1.00");
        $request->setRequestEmail($this->systemConfigService->get("ByjunoPayments.config.byjunotechemail"));
        $request->setLanguage($this->getCustomerLanguage($context, $convertedCart["languageId"]));

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
        $billingAddressCountry = $this->getCountry($billingAddress["countryId"], $context);
        $shippingAddress = $this->getOrderShippingAddress($convertedCart["deliveries"]);
        $shippingAddressCountry = $this->getCountry($shippingAddress["countryId"], $context);
        $billingAddressSalutation = $this->getSalutation($billingAddress["salutationId"], $context);
        $currency = $this->getCurrency($convertedCart["currencyId"], $context);
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
        $genderMaleStr = $this->systemConfigService->get("ByjunoPayments.config.byjunogendermale");
        $genderFemaleStr = $this->systemConfigService->get("ByjunoPayments.config.byjunogenderfemale");
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
        $customer = $this->getCustomer($convertedCart["orderCustomer"]["customerId"], $context);
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


        $tmx_enable = $this->systemConfigService->get("ByjunoPayments.config.byjunothreatmetrixenable");
        $tmxorgid = $this->systemConfigService->get("ByjunoPayments.config.byjunothreatmetrix");
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
        $extraInfo["Value"] = 'Byjuno ShopWare 6 module 1.1.0';
        $request->setExtraInfo($extraInfo);
        return $request;
    }

    protected function getPaymentMethod(string $id, Context $context): ?PaymentMethodEntity
    {
        $criteria = new Criteria([$id]);
        $paymentMethod = $this->paymentMethodRepository->search($criteria, $context)->first();
        return $paymentMethod;
    }

    private function getCustomerLanguage(Context $context, string $languages): string
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

    private function getOrderShippingAddress(Array $deliveries)
    {
        foreach ($deliveries as $delivery) {
            if ($delivery["shippingOrderAddress"] === null) {
                continue;
            }

            return $delivery["shippingOrderAddress"];
        }

        return null;
    }

    private function getBillingAddress(String $billingAddressId, $addresses, $deliveries)
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

    private function getCountry(String $countryId, Context $context): CountryEntity
    {
        $criteria = new Criteria([$countryId]);
        $criteria->addAssociation('country');

        /** @var EntityRepositoryInterface $countryRepository */
        $countryRepository = $this->container->get('country.repository');
        $country = $countryRepository->search($criteria, $context)->first();
        return $country;
    }

    private function getSalutation(String $salutationId, Context $context): SalutationEntity
    {
        $criteria = new Criteria([$salutationId]);

        /** @var EntityRepositoryInterface $salutationRepository */
        $salutationRepository = $this->container->get('salutation.repository');
        $salutation = $salutationRepository->search($criteria, $context)->first();
        return $salutation;
    }

    private function getCustomer(string $customerId, Context $context): ?CustomerEntity
    {
        /** @var EntityRepositoryInterface $orderRepo */
        $customerRepo = $this->container->get('customer.repository');

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('id', $customerId));
        return $customerRepo->search($criteria, $context)->first();
    }

    private function getCurrency(string $currencyId, Context $context): ?CurrencyEntity
    {
        /** @var EntityRepositoryInterface $orderRepo */
        $customerRepo = $this->container->get('currency.repository');

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('id', $currencyId));
        return $customerRepo->search($criteria, $context)->first();
    }

    private function Byjuno_getClientIp()
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

    protected function isStatusOkCDP($status)
    {
        try {
            $accepted_CDP = $this->systemConfigService->get("ByjunoPayments.config.allowedcdp");
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

    function CreateShopRequestS5Cancel($amount, $orderCurrency, $orderId, $customerId, $date)
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
        $request->setAdditional2('');
        $request->setTransactionType("EXPIRED");
        $request->setOpenBalance("0");
        return $request;
    }


    private function getOrder(string $orderId): ?OrderEntity
    {
        /** @var EntityRepositoryInterface $orderRepo */
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
        return $orderRepo->search($criteria, Context::createDefaultContext())->get($orderId);
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
            /** @var EntityRepositoryInterface $logRepository */
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
            'ip' => $_SERVER['REMOTE_ADDR'],
            'byjuno_status' => (($status != "") ? $status . '' : 'Error'),
            'xml_request' => $xml_request,
            'xml_response' => $xml_response
        ];

        $context->scope(Context::SYSTEM_SCOPE, function (Context $context) use ($entry): void {
            /** @var EntityRepositoryInterface $logRepository */
            $logRepository = $this->container->get('byjuno_log_entity.repository');
            $logRepository->upsert([$entry], $context);
        });
    }

}
