<?php

declare(strict_types=1);

namespace Byjuno\ByjunoPayments\Controller;


use Byjuno\ByjunoPayments\Api\Classes\ByjunoCommunicator;
use Byjuno\ByjunoPayments\Api\Classes\ByjunoRequest;
use Byjuno\ByjunoPayments\Api\Classes\ByjunoResponse;
use phpDocumentor\Reflection\Types\Array_;
use RuntimeException;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\SalesChannel\OrderService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepositoryInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\Salutation\SalutationCollection;
use Shopware\Core\System\Salutation\SalutationEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use function Byjuno\ByjunoPayments\Api\Byjuno_getClientIp;
use function Byjuno\ByjunoPayments\Api\Byjuno_mapMethod;
use function Byjuno\ByjunoPayments\Api\Byjuno_mapRepayment;

class ByjunodataController extends StorefrontController
{
    /**
     * @var CartService
     */
    private $cartService;

    /**
     * @var OrderService
     */
    private $orderService;

    /**
     * @var SystemConfigService
     */
    private $systemConfigService;

    /**
     * @var SalesChannelRepositoryInterface
     */
    private $salutationRepository;

    /** @var EntityRepositoryInterface */
    private $languageRepository;

    /** @var EntityRepositoryInterface */
    private $orderAddressRepository;

    public function __construct(
        CartService $cartService,
        OrderService $orderService,
        SystemConfigService $systemConfigService,
        SalesChannelRepositoryInterface $salutationRepository,
        EntityRepositoryInterface $languageRepository,
        EntityRepositoryInterface $orderAddressRepository)
    {
        $this->cartService = $cartService;
        $this->orderService = $orderService;
        $this->systemConfigService = $systemConfigService;
        $this->salutationRepository = $salutationRepository;
        $this->languageRepository = $languageRepository;
        $this->orderAddressRepository = $orderAddressRepository;
    }
    /**
     * @RouteScope(scopes={"storefront"})
     * @Route("/byjuno/byjunodata", name="frontend.checkout.byjunodata", options={"seo"="false"}, methods={"GET"})
     */
    public function submitData(Request $request, SalesChannelContext $context)
    {
        $_SESSION["_byjuno_key"] = "ok";
        $order = $this->getOrder($request->query->get("orderid"));
        $customer = $this->getCustomer($order->getOrderCustomer()->getCustomerId(), $context->getContext());
        $dob = $customer->getBirthday();
        $dob_year = intval($dob->format("Y"));
        $dob_month = intval($dob->format("m"));
        $dob_day = intval($dob->format("d"));
        $byjunoinvoice = Array();
        $selected = "";
        if ($this->systemConfigService->get("ByjunoPayments.config.byjunoinvoice") == 'enabled') {
            $byjunoinvoice[] = Array("name" => "Byjuno Invoice", "id" => "byjunoinvoice");
            $selected = "byjuno_invoice";
        }
        if ($this->systemConfigService->get("ByjunoPayments.config.singleinvoice") == 'enabled') {
            $byjunoinvoice[] = Array("name" => "Byjuno Single Invoice", "id" => "singleinvoice");
            $selected = "sinlge_invoice";
        }
        $custom_bd_enable = false;
        if ($this->systemConfigService->get("ByjunoPayments.config.byjunobirthday") == 'enabled') {
            $custom_bd_enable = true;
        }
        $custom_gender_enable = false;
        if ($this->systemConfigService->get("ByjunoPayments.config.byjunogender") == 'enabled') {
            $custom_gender_enable = true;
        }

        $this->systemConfigService->get("ByjunoPayments.config.singleinvoice");
        $params = Array(
            "returnurl" => urlencode($request->query->get("returnurl")),
            "orderid" => $request->query->get("orderid"),
            "custom_gender_enable" => $custom_gender_enable,
            "custom_bd_enable" => $custom_bd_enable,
            "salutations" => $this->getSalutations($context),
            "byjunoinvoice" => $byjunoinvoice,
            "selected" => $selected,
            "current_salutation" => $order->getOrderCustomer()->getSalutationId(),
            "current_year" => $dob_year,
            "current_month" => $dob_month,
            "current_day" => $dob_day
        );
        return $this->renderStorefront('@Storefront/storefront/page/checkout/cart/byjunodata.html.twig', ["page" => $params]);
    }

    /**
     * @RouteScope(scopes={"storefront"})
     * @Route("/byjuno/byjunosubmit", name="frontend.checkout.byjunosubmit", options={"seo"="false"}, methods={"POST"})
     */
    public function finalizeTransaction(Request $request, SalesChannelContext $salesChannelContext): RedirectResponse
    {
        if (!empty($_SESSION["_byjuno_key"])) {
            // empty session
            //$_SESSION["_byjuno_key"] = '';
            $orderid = $request->query->get("orderid");
            $returnUrl = $request->query->get("returnurl")."&status=completed";

            $customSalutationId = $request->get("customSalutationId");
            $customBirthdayDay = $request->get("customBirthdayDay");
            $customBirthdayMonth = $request->get("customBirthdayMonth");
            $customBirthdayYear = $request->get("customBirthdayYear");
            $paymentplan = $request->get("paymentplan");
            $order = $this->getOrder($orderid);
            $request = $this->Byjuno_CreateShopWareShopRequestUserBilling(
                $salesChannelContext->getContext(),
                $order,
                $salesChannelContext->getContext(),
                "",
                "byjuno_payment_installment",
                $paymentplan,
                "",
                "",
                "",
                "",
                "NO");
            $xml = $request->createRequest();
            $communicator = new ByjunoCommunicator();
            $communicator->setServer($this->systemConfigService->get("ByjunoPayments.config.mode"));
            $response = $communicator->sendRequest($xml);
            if ($response) {
                $intrumResponse = new ByjunoResponse();
                $intrumResponse->setRawResponse($response);
                $intrumResponse->processResponse();
                $status = (int)$intrumResponse->getCustomerRequestStatus();
                $statusLog = "Intrum status";
                if (!empty($_SESSION["intrum"]["mustupdate"])) {
                    $statusLog .= " ".$_SESSION["intrum"]["mustupdate"];
                } else {
                    $statusLog .= " GetPaymentMeans";
                }
                var_dump($status);
                exit('bbb');
                if (intval($status) > 15) {
                    $status = 0;
                }
            }
            exit('aaa');
            return new RedirectResponse($returnUrl);
        }

    }

    private function getOrder(string $orderId): ?OrderEntity
    {
        /** @var EntityRepositoryInterface $orderRepo */
        $orderRepo = $this->container->get('order.repository');

        $criteria = new Criteria([$orderId]);
        $criteria->addAssociation('transactions');
        $criteria->addAssociation('addresses');
        $criteria->addAssociation('deliveries');
        $criteria->addAssociation('deliveries.shippingOrderAddress.country');
        $criteria->addAssociation('language');
        $criteria->addAssociation('currency');
        $criteria->addAssociation('customFields');
        $criteria->addAssociation('tags');
        return $orderRepo->search($criteria, Context::createDefaultContext())->get($orderId);
    }

    private function getCustomer(string $customerId, Context $context): ?CustomerEntity
    {
        /** @var EntityRepositoryInterface $orderRepo */
        $customerRepo = $this->container->get('customer.repository');

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('id', $customerId));
        return $customerRepo->search($criteria, $context)->first();
    }



    /**
     * @throws InconsistentCriteriaIdsException
     */
    private function getSalutations(SalesChannelContext $salesChannelContext): SalutationCollection
    {
        /** @var SalutationCollection $salutations */
        $salutations = $this->salutationRepository->search(new Criteria(), $salesChannelContext)->getEntities();

        $salutations->sort(function (SalutationEntity $a, SalutationEntity $b) {
            return $b->getSalutationKey() <=> $a->getSalutationKey();
        });

        return $salutations;
    }

    function Byjuno_CreateShopWareShopRequestUserBilling(Context $context, OrderEntity $order, Context $salesChannelContext, $orderId, $paymentmethod, $repayment, $riskOwner, $invoiceDelivery, $customGender = "", $customDob = "", $orderClosed = "NO") {
        $request = new ByjunoRequest();
        $request->setClientId($this->systemConfigService->get("ByjunoPayments.config.byjunoclientid"));
        $request->setUserID($this->systemConfigService->get("ByjunoPayments.config.byjunouserid"));
        $request->setPassword($this->systemConfigService->get("ByjunoPayments.config.byjunopassword"));
        $request->setVersion("1.00");
        $request->setRequestEmail($this->systemConfigService->get("ByjunoPayments.config.byjunotechemail"));
        $request->setLanguage($this->getCustomerLanguage($salesChannelContext, $order->getLanguageId()));

        $request->setRequestId(uniqid($order->getBillingAddressId()."_"));
        $reference = $order->getOrderCustomer()->getCustomerId();
        if (empty($reference)) {
            $request->setCustomerReference(uniqid("guest_"));
        } else {
            $request->setCustomerReference($reference);
        }
        $shippingAddress = $this->getOrderShippingAddress($order);
        $billingAddress = $this->getBillingAddress($order, $salesChannelContext);

        $request->setFirstName($billingAddress->getFirstName());
        $request->setLastName($billingAddress->getLastName());
        $addressAdd = '';
        if (!empty($billing['additionalAddressLine1'])) {
            $addressAdd = ' '.trim($billingAddress->getAdditionalAddressLine1());
        }
        if (!empty($billing['additionalAddressLine2'])) {
            $addressAdd = $addressAdd.' '.trim($billingAddress->getAdditionalAddressLine2());
        }
        $request->setFirstLine(trim($billingAddress->getStreet().' '.$addressAdd));
        $request->setCountryCode($billingAddress->getCountry()->getIso());
        $request->setPostCode($billingAddress->getZipcode());
        $request->setTown($billingAddress->getCity());

        if (!empty($billingAddress->getCompany())) {
            $request->setCompanyName1($billingAddress->getCompany());
        }
        if (!empty($billingAddress->getVatId()) && !empty($billingAddress->getCompany())) {
            $request->setCompanyVatId($billingAddress->getVatId());
        }
        if (!empty($shippingAddress->getVatId())) {
            $request->setDeliveryCompanyName1($shippingAddress->getVatId());
        }

        $request->setGender(0);
        $additionalInfo = $billingAddress->getSalutation();
        if (!empty($additionalInfo)) {
            if (strtolower($additionalInfo) == 'ms') {
                $request->setGender(2);
            } else if (strtolower($additionalInfo) == 'mr') {
                $request->setGender(1);
            }
        }
        if (!empty($customGender)) {
            $request->setGender($customGender);
        }

        $customer = $this->getCustomer($order->getOrderCustomer()->getCustomerId(), $context);
        $dob = $customer->getBirthday();
        $dob_year = intval($dob->format("Y"));
        $dob_month = intval($dob->format("m"));
        $dob_day = intval($dob->format("d"));

        if (!empty($dob_year) && !empty($dob_month) && !empty($dob_day) ) {
            $request->setDateOfBirth($dob_year."-".$dob_month."-".$dob_day);
        }
        if (!empty($customDob)) {
            $request->setDateOfBirth($customDob);
        }

        $request->setTelephonePrivate($billingAddress->getPhoneNumber());
        $request->setEmail($order->getOrderCustomer()->getEmail());

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

        $tmx_enable = $this->systemConfigService->get("ByjunoPayments.config.byjunothreatmetrixenable");
        $tmxorgid = $this->systemConfigService->get("ByjunoPayments.config.byjunothreatmetrix");
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
        if (!empty($billing['additionalAddressLine1'])) {
            $addressShippingAdd = ' '.trim($shippingAddress->getAdditionalAddressLine1());
        }
        if (!empty($billing['additionalAddressLine2'])) {
            $addressShippingAdd = $addressShippingAdd.' '.trim($shippingAddress->getAdditionalAddressLine2());
        }

        $extraInfo["Name"] = 'DELIVERY_FIRSTLINE';
        $extraInfo["Value"] = trim($shippingAddress->getStreet().' '.$addressShippingAdd);
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
        $extraInfo["Value"] = 'Byjuno ShopWare 6 module 1.0.0';
        $request->setExtraInfo($extraInfo);
        return $request;

    }

    private function getCustomerLanguage(Context $context, string $languages): string
    {
        $criteria  = new Criteria([$languages]);
        $criteria->addAssociation('locale');

        /** @var null|LanguageEntity $language */
        $language = $this->languageRepository->search($criteria, $context)->first();

        if (null === $language || null === $language->getLocale()) {
            return 'en';
        }

        return substr($language->getLocale()->getCode(), 0, 2);
    }

    private function getOrderShippingAddress(OrderEntity $orderEntity): ?OrderAddressEntity
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

    private function getBillingAddress(OrderEntity $order, Context $context): OrderAddressEntity
    {
        $criteria = new Criteria([$order->getBillingAddressId()]);
        $criteria->addAssociation('country');

        /** @var null|OrderAddressEntity $address */
        $address = $this->orderAddressRepository->search($criteria, $context)->first();

        if (null === $address) {
            throw new RuntimeException('missing order customer billing address');
        }

        return $address;
    }

    private function Byjuno_getClientIp() {
        $ipaddress = '';
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ipaddress = $_SERVER['HTTP_CLIENT_IP'];
        } else if(!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ipaddress = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else if(!empty($_SERVER['HTTP_X_FORWARDED'])) {
            $ipaddress = $_SERVER['HTTP_X_FORWARDED'];
        } else if(!empty($_SERVER['HTTP_FORWARDED_FOR'])) {
            $ipaddress = $_SERVER['HTTP_FORWARDED_FOR'];
        } else if(!empty($_SERVER['HTTP_FORWARDED'])) {
            $ipaddress = $_SERVER['HTTP_FORWARDED'];
        } else if(!empty($_SERVER['REMOTE_ADDR'])) {
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

    function Byjuno_mapRepayment($type) {
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
        } else if ($type == 'installment_4x10') {
            return "2";
        } else if ($type == 'sinlge_invoice') {
            return "3";
        } else {
            return "4";
        }
    }
}
