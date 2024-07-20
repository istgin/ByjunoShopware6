<?php

declare(strict_types=1);

namespace Byjuno\ByjunoPayments\Storefront\Controller;
use Byjuno\ByjunoPayments\Api\Api\CembraPayAzure;
use Byjuno\ByjunoPayments\Api\Api\CembraPayCheckoutChkResponse;
use Byjuno\ByjunoPayments\Api\Api\CembraPayCheckoutScreeningResponse;
use Byjuno\ByjunoPayments\Api\Api\CembraPayCommunicator;
use Byjuno\ByjunoPayments\Api\Api\CembraPayConstants;
use Byjuno\ByjunoPayments\Api\Classes\ByjunoCommunicator;
use Byjuno\ByjunoPayments\Api\Classes\ByjunoRequest;
use Byjuno\ByjunoPayments\Api\Classes\ByjunoResponse;
use Byjuno\ByjunoPayments\ByjunoPayments;
use Byjuno\ByjunoPayments\Service\ByjunoCDPOrderConverterSubscriber;
use Exception;
use Magento\Sales\Model\Order\Payment\Transaction;
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
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\DataBag\DataBag;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\Salutation\SalutationCollection;
use Shopware\Core\System\Salutation\SalutationEntity;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route(defaults: ['_routeScope' => ['storefront']])]
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

    /** @var EntityRepository */
    private $languageRepository;

    /** @var EntityRepository */
    private $orderAddressRepository;

    /** @var EntityRepository */
    private $orderRepository;

    /** @var TranslatorInterface */
    private $translator;

    /**
     * @var EntityRepository
     */
    private $mailTemplateRepository;

    /**
     * @var ByjunoCDPOrderConverterSubscriber
     */
    private $byjuno;

    /**
     * @var \Byjuno\ByjunoPayments\Api\Api\CembraPayAzure
     */
    public $cembraPayAzure;

    public function __construct(
        CartService $cartService,
        OrderService $orderService,
        SystemConfigService $systemConfigService,
        SalesChannelRepository $salutationRepository,
        EntityRepository $languageRepository,
        EntityRepository $orderAddressRepository,
        EntityRepository $orderRepository,
        TranslatorInterface $translator,
        EntityRepository $mailTemplateRepository,
        ByjunoCDPOrderConverterSubscriber $byjuno)
    {
        $this->cartService = $cartService;
        $this->orderService = $orderService;
        $this->systemConfigService = $systemConfigService;
        $this->salutationRepository = $salutationRepository;
        $this->languageRepository = $languageRepository;
        $this->orderAddressRepository = $orderAddressRepository;
        $this->orderRepository = $orderRepository;
        $this->translator = $translator;
        $this->mailTemplateRepository = $mailTemplateRepository;
        $this->byjuno = $byjuno;
        $this->cembraPayAzure = new CembraPayAzure();

    }

    #[Route(path: '/byjunodata', name: 'frontend.checkout.byjunodata', methods: ['GET'])]
    public function submitData(Request $request, SalesChannelContext $context)
    {
        $order = $this->byjuno->getOrder($request->query->get("orderid"));
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
        $request = $this->byjuno->Byjuno_CreateShopWareShopCheckoutRequestUserBilling(
            $context->getContext(),
            $context->getSalesChannelId(),
            $order,
            $url = $this->container->get('router')->generate("frontend.checkout.byjunocheckoutok", ["orderid" => $order->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
            $url = $this->container->get('router')->generate("frontend.checkout.byjunocancel", ["orderid" => $order->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
            $url = $this->container->get('router')->generate("frontend.checkout.byjunocancel", ["orderid" => $order->getId()], UrlGeneratorInterface::ABSOLUTE_URL)
        );
        $CembraPayRequestName = "Checkout request";
        if ($request->custDetails->custType == CembraPayConstants::$CUSTOMER_BUSINESS) {
            $CembraPayRequestName = "Checkout request company";
        }
        $json = $request->createRequest();
        $cembrapayCommunicator = new CembraPayCommunicator($this->cembraPayAzure);
        $mode = $this->systemConfigService->get("ByjunoPayments.config.mode", $order->getSalesChannelId());
        if (isset($mode) && strtolower($mode) == 'live') {
            $cembrapayCommunicator->setServer('live');
        } else {
            $cembrapayCommunicator->setServer('test');
        }
        $accessData = $this->byjuno->getAccessData($context, $mode);
        $response = $cembrapayCommunicator->sendCheckoutRequest($json, $accessData, function ($object, $token, $accessData) {
            $object->saveToken($token, $accessData);
        });
        $status = "";
        $responseRes = null;
        if ($response) {
            /* @var $responseRes CembraPayCheckoutChkResponse */
            $responseRes = CembraPayConstants::checkoutResponse($response);
            $status = $responseRes->processingStatus;
            $this->byjuno->saveCembraLog($context->getContext(), $json, $response, $responseRes->processingStatus, $CembraPayRequestName,
                $request->custDetails->firstName, $request->custDetails->lastName, $request->requestMsgId,
                $request->billingAddr->postalCode, $request->billingAddr->town, $request->billingAddr->country, $request->billingAddr->addrFirstLine, $responseRes->transactionId, $order->getOrderNumber());

        } else {
            $this->byjuno->saveCembraLog($context->getContext(), $json, $response, "Query error", $CembraPayRequestName,
                $request->custDetails->firstName, $request->custDetails->lastName, $request->requestMsgId,
                $request->billingAddr->postalCode, $request->billingAddr->town, $request->billingAddr->country, $request->billingAddr->addrFirstLine, "-", "-");
        }
        if ($status == CembraPayConstants::$CHK_OK) {
            $redirectUrl = $responseRes->redirectUrlCheckout;
            return new RedirectResponse($redirectUrl);
        } else {
            $returnUrlFail = $request->query->get("returnurl") . "&status=fail";
            return new RedirectResponse($returnUrlFail);
        }
        exit();
        $_SESSION["_byjuno_key"] = "ok";
        $_SESSION["_byjuno_single_payment"] = '';
        $order = $this->byjuno->getOrder($request->query->get("orderid"));
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
        $b2b = $this->systemConfigService->get("ByjunoPayments.config.byjunob2b", $context->getSalesChannelId());
        $customer = $this->byjuno->getCustomer($order->getOrderCustomer()->getCustomerId(), $context->getContext());
        $billingAddress = $this->byjuno->getBillingAddressOrder($order, $context->getContext());
        $prefix_b2b = "";
        if ($b2b == 'enabled' && !empty($billingAddress->getCompany())) {
            $prefix_b2b = "b2b";
        }
        $dob = $customer->getBirthday();
        $dob_year = null;
        $dob_month = null;
        $dob_day = null;
        $customer_dob_provided = false;
        if ($dob != null) {
            $dob_year = intval($dob->format("Y"));
            $dob_month = intval($dob->format("m"));
            $dob_day = intval($dob->format("d"));
            $customer_dob_provided = true;
        }
        $paymentplans = Array();
        $selected = "";
        $payment_method = "";
        $send_invoice = "";
        if ($paymentMethod == "byjuno_payment_invoice") {
            $_SESSION["_byjyno_payment_method"] = "byjuno_payment_invoice";
            $payment_method = $this->translator->trans('ByjunoPayment.invoice');
            $send_invoice = $this->translator->trans('ByjunoPayment.send_invoice');
            if ($this->systemConfigService->get("ByjunoPayments.config.byjunoinvoice" . $prefix_b2b, $context->getSalesChannelId()) == 'enabled') {
                $paymentplans[] = Array("name" => $this->translator->trans('ByjunoPayment.invoice_byjuno'), "id" => "byjuno_invoice", "toc" => $this->translator->trans('ByjunoPayment.invoice_byjuno_toc_url'));
                if ($selected == "") {
                    $selected = "byjuno_invoice";
                }
            }
            if ($this->systemConfigService->get("ByjunoPayments.config.singleinvoice" . $prefix_b2b, $context->getSalesChannelId()) == 'enabled') {
                $paymentplans[] = Array("name" => $this->translator->trans('ByjunoPayment.invoice_single'), "id" => "single_invoice", "toc" => $this->translator->trans('ByjunoPayment.invoice_single_toc_url'));
                if ($selected == "") {
                    $selected = "single_invoice";
                }
            }
        } else if ($paymentMethod == "byjuno_payment_installment") {
            $_SESSION["_byjyno_payment_method"] = "byjuno_payment_installment";
            $payment_method = $this->translator->trans('ByjunoPayment.installment');
            $send_invoice = $this->translator->trans('ByjunoPayment.send_installment');
            if ($this->systemConfigService->get("ByjunoPayments.config.installment3" . $prefix_b2b, $context->getSalesChannelId()) == 'enabled') {
                $paymentplans[] = Array("name" => $this->translator->trans('ByjunoPayment.installment_3'), "id" => "installment_3", "toc" => $this->translator->trans('ByjunoPayment.installment_3_toc_url'));
                if ($selected == "") {
                    $selected = "installment_3";
                }
            }
            if ($this->systemConfigService->get("ByjunoPayments.config.installment10" . $prefix_b2b, $context->getSalesChannelId()) == 'enabled') {
                $paymentplans[] = Array("name" => $this->translator->trans('ByjunoPayment.installment_10'), "id" => "installment_10", "toc" => $this->translator->trans('ByjunoPayment.installment_10_toc_url'));
                if ($selected == "") {
                    $selected = "installment_10";
                }
            }
            if ($this->systemConfigService->get("ByjunoPayments.config.installment12" . $prefix_b2b, $context->getSalesChannelId()) == 'enabled') {
                $paymentplans[] = Array("name" => $this->translator->trans('ByjunoPayment.installment_12'), "id" => "installment_12", "toc" => $this->translator->trans('ByjunoPayment.installment_12_toc_url'));
                if ($selected == "") {
                    $selected = "installment_12";
                }
            }
            if ($this->systemConfigService->get("ByjunoPayments.config.installment24" . $prefix_b2b, $context->getSalesChannelId()) == 'enabled') {
                $paymentplans[] = Array("name" => $this->translator->trans('ByjunoPayment.installment_24'), "id" => "installment_24", "toc" => $this->translator->trans('ByjunoPayment.installment_24_toc_url'));
                if ($selected == "") {
                    $selected = "installment_24";
                }
            }
            if ($this->systemConfigService->get("ByjunoPayments.config.installment4x12" . $prefix_b2b, $context->getSalesChannelId()) == 'enabled') {
                $paymentplans[] = Array("name" => $this->translator->trans('ByjunoPayment.installment_4x12'), "id" => "installment_4x12", "toc" => $this->translator->trans('ByjunoPayment.installment_4x12_toc_url'));
                if ($selected == "") {
                    $selected = "installment_4x12";
                }
            }
            if ($this->systemConfigService->get("ByjunoPayments.config.installment36" . $prefix_b2b, $context->getSalesChannelId()) == 'enabled') {
                $paymentplans[] = Array("name" => $this->translator->trans('ByjunoPayment.installment_36'), "id" => "installment_36", "toc" => $this->translator->trans('ByjunoPayment.installment_36_toc_url'));
                if ($selected == "") {
                    $selected = "installment_36";
                }
            }
        }
        $custom_bd_enable = false;
        if ($this->systemConfigService->get("ByjunoPayments.config.byjunobirthday", $context->getSalesChannelId()) == 'enabled') {
            $custom_bd_enable = true;
        }
        $custom_gender_enable = false;
        if ($this->systemConfigService->get("ByjunoPayments.config.byjunogender", $context->getSalesChannelId()) == 'enabled') {
            $custom_gender_enable = true;
        }

        $invoiceDeliveryEnabled = false;
        if ($this->systemConfigService->get("ByjunoPayments.config.byjunoallowpostal", $context->getSalesChannelId()) == 'enabled') {
            $invoiceDeliveryEnabled = true;
        }

        $this->systemConfigService->get("ByjunoPayments.config.singleinvoice", $context->getSalesChannelId());
        $customerSalutationSpecified = false;
        $customerSalutationId = $order->getOrderCustomer()->getSalutationId();
        $customerSalutationObj = $order->getOrderCustomer()->getSalutation();
        $genderMaleStr = $this->systemConfigService->get("ByjunoPayments.config.byjunogendermale", $context->getSalesChannelId());
        $genderFemaleStr = $this->systemConfigService->get("ByjunoPayments.config.byjunogenderfemale", $context->getSalesChannelId());
        $genderMale = explode(",", $genderMaleStr);
        $genderFemale = explode(",", $genderFemaleStr);
        if ($customerSalutationObj != null) {
            $salName = $customerSalutationObj->getDisplayName();
            if (!empty($salName)) {
                foreach ($genderMale as $ml) {
                    if (strtolower($salName) == strtolower(trim($ml))) {
                        $customerSalutationSpecified = true;
                        break;
                    }
                }
                foreach ($genderFemale as $feml) {
                    if (strtolower($salName) == strtolower(trim($feml))) {
                        $customerSalutationSpecified = true;
                        break;
                    }
                }
            }
        }
        $params = Array(
            "payment_method" => $payment_method,
            "send_invoice" => $send_invoice,
            "returnurl" => urlencode($request->query->get("returnurl")),
            "orderid" => $request->query->get("orderid"),
            "custom_gender_enable" => $custom_gender_enable,
            "custom_bd_enable" => $custom_bd_enable,
            "invoiceDeliveryEnabled" => $invoiceDeliveryEnabled,
            "salutations" => $this->byjuno->getSalutations($context),
            "paymentplans" => $paymentplans,
            "selected" => $selected,
            "current_salutation" => $customerSalutationId,
            "current_year" => $dob_year,
            "current_month" => $dob_month,
            "current_day" => $dob_day
        );
        if (!$invoiceDeliveryEnabled && !$custom_gender_enable && !$custom_bd_enable && count($paymentplans) == 1) {
            $_SESSION["_byjuno_single_payment"] = $selected;
            $url = $this->container->get('router')->generate("frontend.checkout.byjunosubmit", [], UrlGeneratorInterface::ABSOLUTE_PATH);
            $singleReturnUrl = $url . '?returnurl=' . urlencode($request->query->get("returnurl")) . '&orderid=' . $request->query->get("orderid");
            return new RedirectResponse($singleReturnUrl);
        }
        if (!$invoiceDeliveryEnabled
            && count($paymentplans) == 1
            && ($customer_dob_provided || !$custom_bd_enable)
            && ($customerSalutationSpecified || !$custom_gender_enable)) {
            $_SESSION["_byjuno_single_payment"] = $selected;
            $url = $this->container->get('router')->generate("frontend.checkout.byjunosubmit", [], UrlGeneratorInterface::ABSOLUTE_PATH);
            $singleReturnUrl = $url . '?returnurl=' . urlencode($request->query->get("returnurl")) . '&orderid=' . $request->query->get("orderid");
            return new RedirectResponse($singleReturnUrl);
        }
        if (count($paymentplans) == 0) {
            $returnUrlFail = $request->query->get("returnurl") . "&status=fail";
            return new RedirectResponse($returnUrlFail);
        }
        return $this->renderStorefront('@Storefront/storefront/page/checkout/cart/byjunodata.html.twig', ["page" => $params]);
    }

    #[Route(path: '/byjunocheckoutok', name: 'frontend.checkout.byjunocheckoutok', methods: ['POST', 'GET'])]
    public function finalizeTransactionChkOk(Request $request, SalesChannelContext $salesChannelContext): RedirectResponse
    {
        exit('finalizeTransactionChkOk');
    }

    #[Route(path: '/byjunosubmit', name: 'frontend.checkout.byjunosubmit', methods: ['POST', 'GET'])]
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
                $cs = $this->byjuno->getSalutation($request->get("customSalutationId"), $salesChannelContext->getContext());
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
            $order = $this->byjuno->getOrder($orderid);
            $b2b = $this->systemConfigService->get("ByjunoPayments.config.byjunob2b", $order->getSalesChannelId());
            $mode = $this->systemConfigService->get("ByjunoPayments.config.mode", $order->getSalesChannelId());
            $request = $this->byjuno->Byjuno_CreateShopWareShopRequestUserBilling(
                $salesChannelContext->getContext(),
                $salesChannelContext->getSalesChannelId(),
                $order,
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
            $response = $communicator->sendRequest($xml, $this->systemConfigService->get("ByjunoPayments.config.byjunotimeout", $salesChannelContext->getSalesChannelId()));
            $statusS1 = 0;
            $statusS3 = 0;
            $transactionNumber = "";
            if ($response) {
                $intrumResponse = new ByjunoResponse();
                $intrumResponse->setRawResponse($response);
                $intrumResponse->processResponse();
                $statusS1 = (int)$intrumResponse->getCustomerRequestStatus();
                $this->byjuno->saveLog($salesChannelContext->getContext(), $request, $xml, $response, $statusS1, $statusLog);
                $transactionNumber = $intrumResponse->getTransactionNumber();
                if (intval($statusS1) > 15) {
                    $statusS1 = 0;
                }
            } else {
                $this->byjuno->saveLog($salesChannelContext->getContext(), $request, $xml, "Empty response", $statusS1, $statusLog);
            }
            if ($this->byjuno->isStatusOkS2($statusS1, $salesChannelContext->getSalesChannelId())) {
                $risk = $this->byjuno->getStatusRisk($statusS1, $salesChannelContext->getSalesChannelId());
                $requestS3 = $this->byjuno->Byjuno_CreateShopWareShopRequestUserBilling(
                    $salesChannelContext->getContext(),
                    $salesChannelContext->getSalesChannelId(),
                    $order,
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
                $response = $byjunoCommunicator->sendRequest($xml, $this->systemConfigService->get("ByjunoPayments.config.byjunotimeout", $salesChannelContext->getSalesChannelId()));
                if (isset($response)) {
                    $byjunoResponse = new ByjunoResponse();
                    $byjunoResponse->setRawResponse($response);
                    $byjunoResponse->processResponse();
                    $statusS3 = (int)$byjunoResponse->getCustomerRequestStatus();
                    $this->byjuno->saveLog($salesChannelContext->getContext(), $request, $xml, $response, $statusS3, $statusLog);
                    if (intval($statusS3) > 15) {
                        $statusS3 = 0;
                    }
                } else {
                    $this->byjuno->saveLog($salesChannelContext->getContext(), $request, $xml, "Empty response", $statusS3, $statusLog);
                }

                $fields = $order->getCustomFields();
                $customFields = $fields ?? [];
                $customFields = array_merge($customFields, ['byjuno_s3_sent' => 1]);
                $update = [
                    'id' => $order->getId(),
                    'customFields' => $customFields,
                ];
                $this->orderRepository->update([$update], $salesChannelContext->getContext());
            } else {
                return new RedirectResponse($returnUrlFail);
            }
            $_SESSION["byjuno_tmx"] = '';
            if ($this->byjuno->isStatusOkS2($statusS1, $salesChannelContext->getSalesChannelId()) && $this->byjuno->isStatusOkS3($statusS3, $salesChannelContext->getSalesChannelId())) {
                $this->byjuno->sendMailOrder($salesChannelContext->getContext(), $order, $salesChannelContext->getSalesChannel()->getId());
                return new RedirectResponse($returnUrlSuccess);
            } else {
                return new RedirectResponse($returnUrlFail);
            }
        } else {
            exit();
        }

    }
}
