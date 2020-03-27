<?php

declare(strict_types=1);

namespace Byjuno\ByjunoPayments\Controller;


use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\SalesChannel\OrderService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
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

    public function __construct(
        CartService $cartService,
        OrderService $orderService)
    {
        $this->cartService = $cartService;
        $this->orderService = $orderService;
    }
    /**
     * @RouteScope(scopes={"storefront"})
     * @Route("/byjuno/byjunodata", name="frontend.checkout.byjunodata", options={"seo"="false"}, methods={"GET"})
     */
    public function submitData(Request $request, SalesChannelContext $context)
    {
        $params = Array(
            "returnurl" => $request->query->get("returnurl"),
            "orderid" => $request->query->get("orderid")
        );
        return $this->renderStorefront('@Storefront/storefront/page/checkout/cart/byjunodata.html.twig', ["page" => $params]);
    }

    /**
     * @RouteScope(scopes={"storefront"})
     * @Route("/byjuno/byjunosubmit", name="frontend.checkout.byjunosubmit", options={"seo"="false"}, methods={"GET"})
     */
    public function finalizeTransaction(Request $request, SalesChannelContext $salesChannelContext): RedirectResponse
    {
        $orderid = $request->query->get("orderid");
        var_dump($this->getOrder($orderid));
        exit('aaa');
    }

    private function getOrder(string $orderId): ?OrderEntity
    {
        /** @var EntityRepositoryInterface $orderRepo */
        $orderRepo = $this->container->get('order.repository');

        $criteria = new Criteria([$orderId]);
        $criteria->addAssociation('transactions');

        return $orderRepo->search($criteria, Context::createDefaultContext())->get($orderId);
    }
}
