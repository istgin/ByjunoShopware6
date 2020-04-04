<?php

declare(strict_types=1);

namespace Byjuno\ByjunoPayments\Controller;


use phpDocumentor\Reflection\Types\Array_;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\SalesChannel\OrderService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
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

    public function __construct(
        CartService $cartService,
        OrderService $orderService,
        SystemConfigService $systemConfigService,
        SalesChannelRepositoryInterface $salutationRepository)
    {
        $this->cartService = $cartService;
        $this->orderService = $orderService;
        $this->systemConfigService = $systemConfigService;
        $this->salutationRepository = $salutationRepository;
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
            $selected = "byjunoinvoice";
        }
        if ($this->systemConfigService->get("ByjunoPayments.config.singleinvoice") == 'enabled') {
            $byjunoinvoice[] = Array("name" => "Byjuno Single Invoice", "id" => "singleinvoice");
            $selected = "singleinvoice";
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

            var_dump($paymentplan);
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
}
