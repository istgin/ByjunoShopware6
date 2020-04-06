<?php

declare(strict_types=1);

namespace Byjuno\ByjunoPayments\Controller;


use Byjuno\ByjunoPayments\Api\Classes\ByjunoCommunicator;
use Byjuno\ByjunoPayments\Api\Classes\ByjunoRequest;
use Byjuno\ByjunoPayments\Api\Classes\ByjunoResponse;
use Exception;
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

class ByjunocancelController extends StorefrontController
{

    public function __construct()
    {
    }
    /**
     * @RouteScope(scopes={"storefront"})
     * @Route("/byjuno/cancel", name="frontend.checkout.byjunocancel", options={"seo"="false"}, methods={"GET"})
     */
    public function submitData(Request $request, SalesChannelContext $context)
    {
        $params = Array();
        return $this->renderStorefront('@Storefront/storefront/page/checkout/cart/byjunocancel.html.twig', ["page" => $params]);
    }

}
