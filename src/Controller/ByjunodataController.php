<?php

declare(strict_types=1);

namespace Byjuno\ByjunoPayments\Controller;


use Byjuno\ByjunoPayments\Api\Classes\ByjunoCommunicator;
use Byjuno\ByjunoPayments\Api\Classes\ByjunoRequest;
use Byjuno\ByjunoPayments\Api\Classes\ByjunoResponse;
use Byjuno\ByjunoPayments\ByjunoPayments;
use Exception;
use Mollie\Api\Resources\Order;
use phpDocumentor\Reflection\Types\Array_;
use RuntimeException;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\SalesChannel\OrderService;
use Shopware\Core\Content\MailTemplate\Exception\MailEventConfigurationException;
use Shopware\Core\Content\MailTemplate\MailTemplateEntity;
use Shopware\Core\Content\Mail\Service\MailService;;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Event\MailActionInterface;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\DataBag\DataBag;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepositoryInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\Salutation\SalutationCollection;
use Shopware\Core\System\Salutation\SalutationEntity;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use function Byjuno\ByjunoPayments\Api\Byjuno_getClientIp;
use function Byjuno\ByjunoPayments\Api\Byjuno_mapMethod;
use function Byjuno\ByjunoPayments\Api\Byjuno_mapRepayment;
use Symfony\Contracts\Translation\TranslatorInterface;

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

    /** @var TranslatorInterface */
    private $translator;
    /**
     * @var MailService
     */
    private $mailService;

    /**
     * @var EntityRepositoryInterface
     */
    private $mailTemplateRepository;

    public function __construct(
        CartService $cartService,
        OrderService $orderService,
        SystemConfigService $systemConfigService,
        SalesChannelRepositoryInterface $salutationRepository,
        EntityRepositoryInterface $languageRepository,
        EntityRepositoryInterface $orderAddressRepository,
        TranslatorInterface $translator,
        MailService $mailService,
        EntityRepositoryInterface $mailTemplateRepository)
    {
        $this->cartService = $cartService;
        $this->orderService = $orderService;
        $this->systemConfigService = $systemConfigService;
        $this->salutationRepository = $salutationRepository;
        $this->languageRepository = $languageRepository;
        $this->orderAddressRepository = $orderAddressRepository;
        $this->translator = $translator;
        $this->mailService = $mailService;
        $this->mailTemplateRepository = $mailTemplateRepository;
    }

    /**
     * @RouteScope(scopes={"storefront"})
     * @Route("/byjuno/byjunodata", name="frontend.checkout.byjunodata", options={"seo"="false"}, methods={"GET"})
     */
    public function submitData(Request $request, SalesChannelContext $context)
    {
        $_SESSION["_byjuno_key"] = "ok";
        $_SESSION["_byjuno_single_payment"] = '';
        $order = $this->getOrder($request->query->get("orderid"));
        $paymentMethod = "";
        $paymentMethodId = "";
        $paymentMethods = $order->getTransactions()->getPaymentMethodIds();
        foreach ($paymentMethods as $pm) {
            $paymentMethodId = $pm;
            break;
        }
        if ($paymentMethodId == ByjunoPayments::BYJUNO_INVOICE) {
            $paymentMethod = "byjuno_payment_invoice";
        } else if ($paymentMethodId == ByjunoPayments::BYJUNO_INSTALLMENT) {
            $paymentMethod = "byjuno_payment_installment";
        }
        if ($paymentMethod == '') {
            exit();
        }
        $b2b = $this->systemConfigService->get("ByjunoPayments.config.byjunob2b");
        $customer = $this->getCustomer($order->getOrderCustomer()->getCustomerId(), $context->getContext());
        $billingAddress = $this->getBillingAddress($order, $context->getContext());
        $prefix_b2b = "";
        if ($b2b == 'enabled' && !empty($billingAddress->getCompany())) {
            $prefix_b2b = "b2b";
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
        $paymentplans = Array();
        $selected = "";
        $payment_method = "";
        $send_invoice = "";
        if ($paymentMethod == "byjuno_payment_invoice") {
            $_SESSION["_byjyno_payment_method"] = "byjuno_payment_invoice";
            $payment_method = $this->translator->trans('ByjunoPayment.invoice');
            $send_invoice =  $this->translator->trans('ByjunoPayment.send_invoice');
            if ($this->systemConfigService->get("ByjunoPayments.config.byjunoinvoice".$prefix_b2b) == 'enabled') {
                $paymentplans[] = Array("name" => $this->translator->trans('ByjunoPayment.invoice_byjuno'), "id" => "byjuno_invoice", "toc" => $this->translator->trans('ByjunoPayment.invoice_byjuno_toc_url'));
                if ($selected == "") {
                    $selected = "byjuno_invoice";
                }
            }
            if ($this->systemConfigService->get("ByjunoPayments.config.singleinvoice".$prefix_b2b) == 'enabled') {
                $paymentplans[] = Array("name" => $this->translator->trans('ByjunoPayment.invoice_single'), "id" => "single_invoice", "toc" => $this->translator->trans('ByjunoPayment.invoice_single_toc_url'));
                if ($selected == "") {
                    $selected = "single_invoice";
                }
            }
        } else if ($paymentMethod == "byjuno_payment_installment") {
            $_SESSION["_byjyno_payment_method"] = "byjuno_payment_installment";
            $payment_method = $this->translator->trans('ByjunoPayment.installment');
            $send_invoice =  $this->translator->trans('ByjunoPayment.send_installment');
            if ($this->systemConfigService->get("ByjunoPayments.config.installment3".$prefix_b2b) == 'enabled') {
                $paymentplans[] = Array("name" => $this->translator->trans('ByjunoPayment.installment_3'), "id" => "installment_3", "toc" => $this->translator->trans('ByjunoPayment.installment_3_toc_url'));
                if ($selected == "") {
                    $selected = "installment_3";
                }
            }
            if ($this->systemConfigService->get("ByjunoPayments.config.installment10".$prefix_b2b) == 'enabled') {
                $paymentplans[] = Array("name" => $this->translator->trans('ByjunoPayment.installment_10'), "id" => "installment_10", "toc" => $this->translator->trans('ByjunoPayment.installment_10_toc_url'));
                if ($selected == "") {
                    $selected = "installment_10";
                }
            }
            if ($this->systemConfigService->get("ByjunoPayments.config.installment12".$prefix_b2b) == 'enabled') {
                $paymentplans[] = Array("name" => $this->translator->trans('ByjunoPayment.installment_12'), "id" => "installment_12", "toc" => $this->translator->trans('ByjunoPayment.installment_12_toc_url'));
                if ($selected == "") {
                    $selected = "installment_12";
                }
            }
            if ($this->systemConfigService->get("ByjunoPayments.config.installment24".$prefix_b2b) == 'enabled') {
                $paymentplans[] = Array("name" => $this->translator->trans('ByjunoPayment.installment_24'), "id" => "installment_24", "toc" => $this->translator->trans('ByjunoPayment.installment_24_toc_url'));
                if ($selected == "") {
                    $selected = "installment_24";
                }
            }
            if ($this->systemConfigService->get("ByjunoPayments.config.installment4x12".$prefix_b2b) == 'enabled') {
                $paymentplans[] = Array("name" => $this->translator->trans('ByjunoPayment.installment_4x12'), "id" => "installment_4x12", "toc" => $this->translator->trans('ByjunoPayment.installment_4x12_toc_url'));
                if ($selected == "") {
                    $selected = "installment_4x12";
                }
            }
            if ($this->systemConfigService->get("ByjunoPayments.config.installment36".$prefix_b2b) == 'enabled') {
                $paymentplans[] = Array("name" => $this->translator->trans('ByjunoPayment.installment_36'), "id" => "installment_36", "toc" => $this->translator->trans('ByjunoPayment.installment_36_toc_url'));
                if ($selected == "") {
                    $selected = "installment_36";
                }
            }
        }
        $custom_bd_enable = false;
        if ($this->systemConfigService->get("ByjunoPayments.config.byjunobirthday") == 'enabled') {
            $custom_bd_enable = true;
        }
        $custom_gender_enable = false;
        if ($this->systemConfigService->get("ByjunoPayments.config.byjunogender") == 'enabled') {
            $custom_gender_enable = true;
        }

        $invoiceDeliveryEnabled = false;
        if ($this->systemConfigService->get("ByjunoPayments.config.byjunoallowpostal") == 'enabled') {
            $invoiceDeliveryEnabled = true;
        }

        $this->systemConfigService->get("ByjunoPayments.config.singleinvoice");
        $params = Array(
            "payment_method" => $payment_method,
            "send_invoice" => $send_invoice,
            "returnurl" => urlencode($request->query->get("returnurl")),
            "orderid" => $request->query->get("orderid"),
            "custom_gender_enable" => $custom_gender_enable,
            "custom_bd_enable" => $custom_bd_enable,
            "invoiceDeliveryEnabled" => $invoiceDeliveryEnabled,
            "salutations" => $this->getSalutations($context),
            "paymentplans" => $paymentplans,
            "selected" => $selected,
            "current_salutation" => $order->getOrderCustomer()->getSalutationId(),
            "current_year" => $dob_year,
            "current_month" => $dob_month,
            "current_day" => $dob_day
        );
        if (!$invoiceDeliveryEnabled && !$custom_gender_enable && !$custom_bd_enable && count($paymentplans) == 1) {
            $_SESSION["_byjuno_single_payment"] = $selected;
            $url = $this->container->get('router')->generate("frontend.checkout.byjunosubmit", [], UrlGeneratorInterface::ABSOLUTE_PATH);
            $singleReturnUrl = $url.'?returnurl='.urlencode($request->query->get("returnurl")).'&orderid='.$request->query->get("orderid");
            return new RedirectResponse($singleReturnUrl);
        }
        if (count($paymentplans) == 0) {
            $returnUrlFail = $request->query->get("returnurl") . "&status=fail";
            return new RedirectResponse($returnUrlFail);
        }
        return $this->renderStorefront('@Storefront/storefront/page/checkout/cart/byjunodata.html.twig', ["page" => $params]);
    }

    /**
     * @RouteScope(scopes={"storefront"})
     * @Route("/byjuno/byjunosubmit", name="frontend.checkout.byjunosubmit", options={"seo"="false"}, methods={"POST", "GET"})
     */
    public function finalizeTransaction(Request $request, SalesChannelContext $salesChannelContext): RedirectResponse
    {
        if (!empty($_SESSION["_byjuno_key"]) && !empty($_SESSION["_byjyno_payment_method"])) {
            //$_SESSION["_byjuno_key"] = '';
            $orderid = $request->query->get("orderid");
            $returnUrlSuccess = $request->query->get("returnurl") . "&status=completed";
            $returnUrlFail = $request->query->get("returnurl") . "&status=fail";
            $invoiceDelivery = "";
            if (!empty($request->get("invoicedelivery")) && $request->get("invoicedelivery") == 'postal') {
                $invoiceDelivery = 'postal';
            }
            $customSalutation = "";
            if (!empty($request->get("customSalutationId"))) {
                $cs = $this->getSalutation($request->get("customSalutationId"), $salesChannelContext->getContext());
                if ($cs != null) {
                    $customSalutation = $cs->getDisplayName();
                }
            }
            $customBirthday = "";
            if (!empty($request->get("customBirthdayDay"))
                && !empty($request->get("customBirthdayMonth"))
                && !empty($request->get("customBirthdayYear"))) {
                $customBirthdayDay = $request->get("customBirthdayDay");
                $customBirthdayMonth = $request->get("customBirthdayMonth");
                $customBirthdayYear = $request->get("customBirthdayYear");
                $customBirthday = $customBirthdayYear . "-" . $customBirthdayMonth . "-" . $customBirthdayDay;
            }
            $paymentplan = '';
            if (!empty($_SESSION["_byjuno_single_payment"])) {
                $paymentplan = $_SESSION["_byjuno_single_payment"];
            } else {
                $paymentplan = $request->get("paymentplan");
            }
            $b2b = $this->systemConfigService->get("ByjunoPayments.config.byjunob2b");
            $mode = $this->systemConfigService->get("ByjunoPayments.config.mode");
            $order = $this->getOrder($orderid);
            $request = $this->Byjuno_CreateShopWareShopRequestUserBilling(
                $salesChannelContext->getContext(),
                $order,
                $salesChannelContext->getContext(),
                $order->getOrderNumber(),
                $_SESSION["_byjyno_payment_method"],
                $paymentplan,
                "",
                "",
                $customSalutation,
                $customBirthday,
                "",
                "NO");
            $statusLog = "Order request (S1)";
            if ($request->getCompanyName1() != '' && $b2b == 'enabled') {
                $statusLog = "Order request for company (S1)";
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
                $this->saveLog($salesChannelContext->getContext(),$request, $xml, $response, $statusS1, $statusLog);
                $transactionNumber = $intrumResponse->getTransactionNumber();
                if (intval($statusS1) > 15) {
                    $statusS1 = 0;
                }
            }
            if ($this->isStatusOkS2($statusS1)) {
                $risk = $this->getStatusRisk($statusS1);
                $requestS3 = $this->Byjuno_CreateShopWareShopRequestUserBilling(
                    $salesChannelContext->getContext(),
                    $order,
                    $salesChannelContext->getContext(),
                    $order->getOrderNumber(),
                    $_SESSION["_byjyno_payment_method"],
                    $paymentplan,
                    $risk,
                    $invoiceDelivery,
                    $customSalutation,
                    $customBirthday,
                    $transactionNumber,
                    "YES");
                $statusLog = "Order complete (S3)";
                if ($requestS3->getCompanyName1() != '' && $b2b == 'enabled') {
                    $statusLog = "Order complete for company (S3)";
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
                    $this->saveLog($salesChannelContext->getContext(), $request, $xml, $response, $statusS3, $statusLog);
                    if (intval($statusS3) > 15) {
                        $statusS3 = 0;
                    }
                }
            } else {
                return new RedirectResponse($returnUrlFail);
            }
            $_SESSION["byjuno_tmx"] = '';
            if ($this->isStatusOkS2($statusS1) && $this->isStatusOkS3($statusS3)) {
                $this->sendMailOrder($salesChannelContext->getContext(), $order, $salesChannelContext->getSalesChannel()->getId());
                return new RedirectResponse($returnUrlSuccess);
            } else {
                return new RedirectResponse($returnUrlFail);
            }
        } else {
            exit();
        }

    }

    private function sendMailOrder(Context $context, OrderEntity $order, String $salesChanhhelId): void {

        $mailTemplate = $this->getMailTemplate($context, "order_confirmation_mail", $order);
        if ($mailTemplate !== null) {
            $data = new DataBag();
            $mode = $this->systemConfigService->get("ByjunoPayments.config.mode");
            if (isset($mode) && $mode == 'live') {
                $recipients = Array($this->systemConfigService->get("ByjunoPayments.config.byjunoprodemail") => "Byjuno order confirmation");
            } else {
                $recipients = Array($this->systemConfigService->get("ByjunoPayments.config.byjunotestemail") => "Byjuno order confirmation");
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

    private function getCustomer(string $customerId, Context $context): ?CustomerEntity
    {
        /** @var EntityRepositoryInterface $orderRepo */
        $customerRepo = $this->container->get('customer.repository');

        $criteria = new Criteria();
        $criteria->addAssociation('salutation');
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

    function Byjuno_CreateShopWareShopRequestUserBilling(Context $context, OrderEntity $order, Context $salesChannelContext, $orderId, $paymentmethod, $repayment, $riskOwner, $invoiceDelivery, $customGender = "", $customDob = "", $transactionNumber = "", $orderClosed = "NO")
    {
        $request = new ByjunoRequest();
        $request->setClientId($this->systemConfigService->get("ByjunoPayments.config.byjunoclientid"));
        $request->setUserID($this->systemConfigService->get("ByjunoPayments.config.byjunouserid"));
        $request->setPassword($this->systemConfigService->get("ByjunoPayments.config.byjunopassword"));
        $request->setVersion("1.00");
        $request->setRequestEmail($this->systemConfigService->get("ByjunoPayments.config.byjunotechemail"));
        $request->setLanguage($this->getCustomerLanguage($salesChannelContext, $order->getLanguageId()));

        $request->setRequestId(uniqid($order->getBillingAddressId() . "_"));
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

        $genderMaleStr = $this->systemConfigService->get("ByjunoPayments.config.byjunogendermale");
        $genderFemaleStr = $this->systemConfigService->get("ByjunoPayments.config.byjunogenderfemale");
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
        $extraInfo["Value"] = 'Byjuno ShopWare 6 module 1.2.0';
        $request->setExtraInfo($extraInfo);
        return $request;

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
        $criteria->addAssociation('salutation');

        /** @var null|OrderAddressEntity $address */
        $address = $this->orderAddressRepository->search($criteria, $context)->first();

        if (null === $address) {
            throw new RuntimeException('missing order customer billing address');
        }

        return $address;
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

    function Byjuno_mapRepayment($type)
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

    protected function isStatusOkS2($status)
    {
        try {
            $accepted_S2_ij = $this->systemConfigService->get("ByjunoPayments.config.alloweds2");
            $accepted_S2_merhcant = $this->systemConfigService->get("ByjunoPayments.config.alloweds2merchant");

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

    protected function isStatusOkS3($status)
    {
        try {
            $accepted_S3 = $this->systemConfigService->get("ByjunoPayments.config.alloweds3");
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

    protected function getStatusRisk($status)
    {
        try {
            $accepted_S2_ij = $this->systemConfigService->get("ByjunoPayments.config.alloweds2");
            $accepted_S2_merhcant = $this->systemConfigService->get("ByjunoPayments.config.alloweds2merchant");
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

    private function getSalutation(String $salutationId, Context $context): SalutationEntity
    {
        $criteria = new Criteria([$salutationId]);

        /** @var EntityRepositoryInterface $salutationRepository */
        $salutationRepository = $this->container->get('salutation.repository');
        $salutation = $salutationRepository->search($criteria, $context)->first();
        return $salutation;
    }

    private function getMailTemplate(Context $context, string $technicalName, OrderEntity $order): ?MailTemplateEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('mailTemplateType.technicalName', $technicalName));
        $criteria->addAssociation('mailTemplateType');
        $criteria->addAssociation('mailTemplateType.technicalName');
        $mailTemplate = $this->mailTemplateRepository->search($criteria, $context)->first();
        return $mailTemplate;
    }

    public function saveLog(Context $context, ByjunoRequest $request, $xml_request, $xml_response, $status, $type) {
        $entry = [
            'id'             => Uuid::randomHex(),
            'request_id' => $request->getRequestId(),
            'request_type' => $type,
            'firstname' => $request->getFirstName(),
            'lastname' => $request->getLastName(),
            'ip' => $_SERVER['REMOTE_ADDR'],
            'byjuno_status' => (($status != "") ? $status.'' : 'Error'),
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
