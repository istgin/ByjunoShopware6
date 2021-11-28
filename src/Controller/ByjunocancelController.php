<?php

declare(strict_types=1);

namespace Byjuno\ByjunoPayments\Controller;


use Byjuno\ByjunoPayments\Api\Classes\ByjunoCommunicator;
use Byjuno\ByjunoPayments\Api\Classes\ByjunoRequest;
use Byjuno\ByjunoPayments\Api\Classes\ByjunoResponse;
use Byjuno\ByjunoPayments\Utils\CustomProductsLineItemTypes;
use Exception;
use phpDocumentor\Reflection\Types\Array_;
use RuntimeException;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Exception\OrderNotFoundException;
use Shopware\Core\Checkout\Cart\LineItemFactoryRegistry;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\SalesChannel\AbstractOrderRoute;
use Shopware\Core\Checkout\Order\SalesChannel\OrderService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Routing\Exception\MissingRequestParameterException;
use Shopware\Core\Framework\Uuid\Exception\InvalidUuidException;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepositoryInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\Salutation\SalutationCollection;
use Shopware\Core\System\Salutation\SalutationEntity;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineTransition\StateMachineTransitionActions;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;

class ByjunocancelController extends StorefrontController
{

    public const STATE_HOLD    = 'hold';
    public const ACTION_HOLD   = 'hold';
    public const ACTION_UNHOLD = 'unhold';

    /**
     * @var CartService
     */
    private $cartService;

    /**
     * @var \Shopware\Core\Checkout\Cart\LineItemFactoryRegistry
     */
    private $lineItemFactoryRegistry;

    /**
     * @var \Shopware\Core\Checkout\Order\SalesChannel\AbstractOrderRoute
     */
    private $orderRoute;

    /**
     * @var \Shopware\Core\Checkout\Order\SalesChannel\OrderService
     */
    private $orderService;

    public function __construct(
        CartService $cartService,
        LineItemFactoryRegistry $lineItemFactoryRegistry,
        AbstractOrderRoute $orderRoute,
        OrderService $orderService)
    {
        $this->cartService = $cartService;
        $this->lineItemFactoryRegistry = $lineItemFactoryRegistry;
        $this->orderRoute = $orderRoute;
        $this->orderService = $orderService;
    }

    /**
     * @RouteScope(scopes={"storefront"})
     * @Route("/byjuno/cancel", name="frontend.checkout.byjunocancel", options={"seo"="false"}, methods={"GET"})
     */
    public function cancel(Cart $cart, Request $request, SalesChannelContext $salesChannelContext)
    {
        return $this->recreateCart($cart, $request, $salesChannelContext);
        // exit('aaa');
        // $this->addToCart($salesChannelContext);
        // return $this->forwardToRoute("frontend.checkout.cart.page");
    }

    private function recreateCart(Cart $cart, Request $request, SalesChannelContext $salesChannelContext)
    {
        $orderId = $request->query->get('orderid');

        if (empty($orderId)) {
            throw new MissingRequestParameterException('orderid');
        }

        try {
            // Configuration
            $orderEntity  = $this->getOrder($request, $salesChannelContext);
            $lastTransaction = $orderEntity->getTransactions()->last();
            if ($lastTransaction && !$lastTransaction->getPaymentMethod()->getAfterOrderEnabled()) {
                return $this->redirectToRoute('frontend.home.page');
            }

            $this->addFlash('danger', $this->trans('ByjunoPayment.cdp_error'));

            $orderItems        = $orderEntity->getLineItems();
            $hasCustomProducts = $this->hasCustomProducts($orderItems);

            if ($hasCustomProducts === true) {
                $cart = $this->addCustomProducts($orderItems, $request, $salesChannelContext);
            }

            foreach ($orderItems as $orderLineItemEntity) {
                $type = $orderLineItemEntity->getType();

                if ($type !== CustomProductsLineItemTypes::LINE_ITEM_TYPE_PRODUCT || $orderLineItemEntity->getParentid() !== null) {
                    continue;
                }

                $lineItem = $this->lineItemFactoryRegistry->create([
                    'id'           => $orderLineItemEntity->getId(),
                    'quantity'     => $orderLineItemEntity->getQuantity(),
                    'referencedId' => $orderLineItemEntity->getReferencedId(),
                    'type'         => $type,
                ], $salesChannelContext);

                $cart = $this->cartService->add($cart, $lineItem, $salesChannelContext);
                $this->cancelOrder($orderEntity, $salesChannelContext);
            }
        } catch (\Exception $exception) {
            $this->addFlash('danger', $this->trans('error.addToCartError'));
            return $this->redirectToRoute('frontend.home.page');
        }

        return $this->redirectToRoute('frontend.checkout.confirm.page');
    }

    /**
     * @param \Symfony\Component\HttpFoundation\Request $request
     * @param \Shopware\Core\System\SalesChannel\SalesChannelContext $salesChannelContext
     *
     * @return \Shopware\Core\Checkout\Order\OrderEntity
     */
    private function getOrder(Request $request, SalesChannelContext $salesChannelContext): OrderEntity
    {
        $orderId = $request->get('orderid');
        if (!$orderId) {
            throw new MissingRequestParameterException('orderid', '/orderid');
        }

        $criteria = (new Criteria([$orderId]))
            ->addAssociation('lineItems.cover')
            ->addAssociation('transactions.paymentMethod')
            ->addAssociation('deliveries.shippingMethod');

        $criteria->getAssociation('transactions')->addSorting(new FieldSorting('createdAt'));

        try {
            $searchResult = $this->orderRoute
                ->load(new Request(), $salesChannelContext, $criteria)
                ->getOrders();
        } catch (InvalidUuidException $e) {
            throw new OrderNotFoundException($orderId);
        }

        /** @var OrderEntity|null $order */
        $order = $searchResult->get($orderId);

        if (!$order) {
            throw new OrderNotFoundException($orderId);
        }

        return $order;
    }

    private function hasCustomProducts(OrderLineItemCollection $orderItems): bool
    {
        foreach ($orderItems as $orderItem) {
            if ($orderItem->getType() === CustomProductsLineItemTypes::LINE_ITEM_TYPE_CUSTOMIZED_PRODUCTS) {
                return true;
            }
        }

        return false;
    }

    private function addCustomProducts(OrderLineItemCollection $orderItems, Request $request, SalesChannelContext $salesChannelContext): Cart
    {

        $cart = $this->cartService->getCart($salesChannelContext->getToken(), $salesChannelContext);
        if (!\class_exists('Swag\\CustomizedProducts\\Core\\Checkout\\Cart\\Route\\AddCustomizedProductsToCartRoute')) {
            return $cart;
        }

        $customProductsService = $this->get('Swag\CustomizedProducts\Core\Checkout\Cart\Route\AddCustomizedProductsToCartRoute');

        foreach ($orderItems as $orderItem) {
            if ($orderItem->getType() !== CustomProductsLineItemTypes::LINE_ITEM_TYPE_CUSTOMIZED_PRODUCTS) {
                continue;
            }

            $product = $this->getCustomProduct($orderItems, $orderItem->getId());
            $productOptions = $this->getCustomProductOptions($orderItems, $orderItem->getId());
            $optionValues = $this->getOptionValues($productOptions);

            $params = new RequestDataBag([]);

            if (!empty($optionValues)) {
                $params = new RequestDataBag([
                    'customized-products-template' => new RequestDataBag([
                        'id' => $orderItem->getReferencedId(),
                        'options' => new RequestDataBag($optionValues),
                    ]),
                ]);
            }

            $request->request = new RequestDataBag([
                'lineItems' => [
                    'quantity' => $orderItem->getQuantity(),
                    'id' => $product->getProductId(),
                    'type' => CustomProductsLineItemTypes::LINE_ITEM_TYPE_PRODUCT,
                    'referencedId' => $product->getReferencedId(),
                    'stackable' => $orderItem->getStackable(),
                    'removable' => $orderItem->getRemovable(),
                ],
            ]);

            $customProductsService->add($params, $request, $salesChannelContext, $cart);
            $cart = $this->cartService->getCart($salesChannelContext->getToken(), $salesChannelContext);
        }

        return $cart;
    }

    private function getCustomProduct(OrderLineItemCollection $orderItems, string $parentId): ?OrderLineItemEntity
    {
        foreach ($orderItems as $orderItem) {
            if ($orderItem->getType() === CustomProductsLineItemTypes::LINE_ITEM_TYPE_PRODUCT && $orderItem->getParentId() === $parentId) {
                return $orderItem;
            }
        }
        return null;
    }

    private function getCustomProductOptions(OrderLineItemCollection $orderItems, string $parentId): array
    {
        $options = [];
        foreach ($orderItems as $orderItem) {
            if ($orderItem->getType() === CustomProductsLineItemTypes::LINE_ITEM_TYPE_CUSTOMIZED_PRODUCTS_OPTION && $orderItem->getParentId() === $parentId) {
                $options[] = $orderItem;
            }
        }
        return $options;
    }

    private function getOptionValues(array $productOptions): array
    {
        $optionValues = [];
        foreach ($productOptions as $productOption) {
            $optionType = $productOption->getPayload()['type'] ?: '';

            switch ($optionType) {
                case CustomProductsLineItemTypes::PRODUCT_OPTION_TYPE_IMAGE_UPLOAD:
                case CustomProductsLineItemTypes::PRODUCT_OPTION_TYPE_FILE_UPLOAD:
                    $media = $productOption->getPayload()['media'] ?: [];
                    foreach ($media as $mediaItem) {
                        $optionValues[$productOption->getReferencedId()] = new RequestDataBag([
                            'media' => new RequestDataBag([
                                $mediaItem['filename'] => new RequestDataBag([
                                    'id' => $mediaItem['mediaId'],
                                    'filename' => $mediaItem['filename'],
                                ]),
                            ]),
                        ]);
                    }
                    break;

                default:
                    $optionValues[$productOption->getReferencedId()] = new RequestDataBag([
                        'value' => $productOption->getPayload()['value'] ?: '',
                    ]);
            }
        }

        return $optionValues;
    }

    private function cancelOrder(OrderEntity $orderEntity, SalesChannelContext $context): void
    {
        try {
            $this->orderService->orderStateTransition(
                $orderEntity->getId(),
                StateMachineTransitionActions::ACTION_CANCEL,
                new ParameterBag(),
                $context->getContext()
            );
        } catch (\Exception $exception) {
            //silent
        }
    }

}
