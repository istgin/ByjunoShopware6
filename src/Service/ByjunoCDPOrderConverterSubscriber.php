<?php declare(strict_types=1);

namespace Byjuno\ByjunoPayments\Service;

use Byjuno\ByjunoPayments\Api\Classes\ByjunoCommunicator;
use Byjuno\ByjunoPayments\Api\Classes\ByjunoRequest;
use Byjuno\ByjunoPayments\Api\Classes\ByjunoResponse;
use Byjuno\ByjunoPayments\ByjunoPayments;
use Exception;
use Psr\Container\ContainerInterface;
use phpDocumentor\Reflection\Types\Array_;
use RuntimeException;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Order\CartConvertedEvent;
use Shopware\Core\Checkout\Customer\CustomerEntity;
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
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Core\System\Salutation\SalutationCollection;
use Shopware\Core\System\Salutation\SalutationEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Recovery\Install\Struct\Currency;
use Shopware\Storefront\Event\StorefrontRenderEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

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

    public function __construct(
        SystemConfigService $systemConfigService,
        EntityRepositoryInterface $paymentMethodRepository,
        EntityRepositoryInterface $languageRepository,
        EntityRepositoryInterface $orderAddressRepository,
        ContainerInterface $container
    )
    {
        $this->systemConfigService = $systemConfigService;
        $this->paymentMethodRepository = $paymentMethodRepository;
        $this->languageRepository = $languageRepository;
        $this->orderAddressRepository = $orderAddressRepository;
        $this->container = $container;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            StorefrontRenderEvent::class => 'onByjunoRender',
            CartConvertedEvent::class => 'converter'
        ];
    }

    public function onByjunoRender(StorefrontRenderEvent $event): void
    {
        $byjuno_tmx = array();
        $tmx_enable = false;
        if ($this->systemConfigService->get("ByjunoPayments.config.byjunothreatmetrixenable") == 'enabled') {
            $tmx_enable = true;
        }
        $tmxorgid = $this->systemConfigService->get("ByjunoPayments.config.byjunothreatmetrix");
        if (isset($tmx_enable) && $tmx_enable == true && isset($tmxorgid) && $tmxorgid != '' && !isset($_SESSION["byjuno_tmx"])) {
            $_SESSION["byjuno_tmx"] = session_id();
            $byjuno_tmx["tmx_orgid"] = $tmxorgid;
            $byjuno_tmx["tmx_session"] = $_SESSION["byjuno_tmx"];
            $byjuno_tmx["tmx_enable"] = true;
        } else {
            $byjuno_tmx["tmx_orgid"] = "";
            $byjuno_tmx["tmx_session"] = "";
            $byjuno_tmx["tmx_enable"] = false;
        }
        $event->setParameter('byjuno_tmx', $byjuno_tmx);
    }

    public function converter(CartConvertedEvent $event)
    {
        if ($this->systemConfigService->get("ByjunoPayments.config.byjunousecdp") == 'enabled' && $event->getSalesChannelContext()->getPaymentMethod()->getHandlerIdentifier() == "Byjuno\ByjunoPayments\Service\ByjunoCorePayment") {
            $b2b = $this->systemConfigService->get("ByjunoPayments.config.byjunob2b");
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
            $communicator->setServer($this->systemConfigService->get("ByjunoPayments.config.mode"));
            $response = $communicator->sendRequest($xml);
            $statusCDP = 0;
            if ($response) {
                $intrumResponse = new ByjunoResponse();
                $intrumResponse->setRawResponse($response);
                $intrumResponse->processResponse();
                $statusCDP = (int)$intrumResponse->getCustomerRequestStatus();
                if (intval($statusCDP) > 15) {
                    $statusCDP = 0;
                }
            }
            if (!$this->isStatusOkCDP($statusCDP)) {
                $violation = new ConstraintViolation(
                    "You are not allowed to pay with this payment method. Please try different.",
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
        $billingAddress = $this->getBillingAddress($convertedCart["billingAddressId"], $convertedCart["addresses"], $convertedCart["deliveries"]);
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
        if (!empty($shippingAddress["vatId"])) {
            $request->setDeliveryCompanyName1($shippingAddress["vatId"]);
        }
        $request->setGender(0);
        $additionalInfo = $billingAddressSalutation->getDisplayName();
        if (!empty($additionalInfo)) {
            if (strtolower($additionalInfo) == 'mrs.') {
                $request->setGender(2);
            } else if (strtolower($additionalInfo) == 'mr.') {
                $request->setGender(1);
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

        $request->setTelephonePrivate($billingAddress["phoneNumber"]);
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
           $addressShippingAdd = ' '.trim($shippingAddress['additionalAddressLine1']);
        }
        if (!empty($shippingAddress['additionalAddressLine2'])) {
           $addressShippingAdd = $addressShippingAdd.' '.trim($shippingAddress['additionalAddressLine2']);
        }

        $extraInfo["Name"] = 'DELIVERY_FIRSTLINE';
        $extraInfo["Value"] = trim($shippingAddress["street"].' '.$addressShippingAdd);
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
        $extraInfo["Value"] = 'Byjuno ShopWare 6 module 1.0.0';
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

    private function getCurrency(string $customerId, Context $context): ?CurrencyEntity
    {
        /** @var EntityRepositoryInterface $orderRepo */
        $customerRepo = $this->container->get('currency.repository');

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('id', $customerId));
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

    function Byjuno_mapMethod($method) {
        if ($method == 'byjuno_payment_installment') {
            return "INSTALLMENT";
        } else {
            return "INVOICE";
        }
    }

    protected function isStatusOkCDP($status) {
        try {
            $accepted_CDP = $this->systemConfigService->get("ByjunoPayments.config.allowedcdp");
            $ijStatus = Array();
            if (!empty(trim((String)$accepted_CDP))) {
                $ijStatus = explode(",", trim((String)$accepted_CDP));
                foreach($ijStatus as $key => $val) {
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
}