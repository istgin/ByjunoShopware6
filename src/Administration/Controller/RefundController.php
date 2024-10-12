<?php declare(strict_types=1);

namespace Byjuno\ByjunoPayments\Administration\Controller;

use Byjuno\ByjunoPayments\Api\Classes\ByjunoCommunicator;
use Byjuno\ByjunoPayments\Api\Classes\ByjunoS4Response;
use Byjuno\ByjunoPayments\Api\Classes\ByjunoS5Request;
use Shopware\Core\Checkout\Cart\Order\OrderConverter;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;


#[Route(defaults: ['_routeScope' => ['api']])]
class RefundController extends AbstractController
{
    public function __construct(
        private readonly EntityRepository $logEntryReposity,
        private readonly EntityRepository $orderRepository,
        private readonly SystemConfigService $systemConfigService,
        private readonly OrderConverter $orderConverter
    ) {
    }

    #[Route('/api/_action/byjuno_payments/refund/create-refund-by-amount/', name: 'api.action.byjuno_payments.refund.create_refund_by_amount', methods: ['POST'])]
    public function createRefundByAmount(Request $request, Context $context): Response
    {
        $orderId = $request->request->get('orderId');
        $amount  = $request->request->get('refundableAmount');

        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('id', $orderId));

        /** @var $order OrderEntity */
        if ( ! $order = $this->orderRepository->search($criteria, $context)->first()) {
            return new Response(null, Response::HTTP_BAD_REQUEST);
        }

        $s5Request = $this->createS5CancelRequest($amount, $order->getCurrency()->getIsoCode(), $orderId, $order->getOrderCustomer()?->getCustomerNumber(), $order->getSalesChannelId());
        $this->sendS5Request($order, $s5Request, $context);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    private function createS5CancelRequest(float $amount, string $orderCurrency, string $orderId, string $customerId, string $salesChannelId): ByjunoS5Request
    {
        $request = new ByjunoS5Request();

        $request->setClientId($this->systemConfigService->get("ByjunoPayments.config.byjunoclientid", $salesChannelId));
        $request->setUserID($this->systemConfigService->get("ByjunoPayments.config.byjunouserid", $salesChannelId));
        $request->setPassword($this->systemConfigService->get("ByjunoPayments.config.byjunopassword", $salesChannelId));
        $request->setVersion("1.00");
        $request->setRequestEmail($this->systemConfigService->get("ByjunoPayments.config.byjunotechemail", $salesChannelId));

        $request->setRequestId(uniqid((string)$orderId."_", true));
        $request->setOrderId($orderId);
        $request->setClientRef($customerId);
        $request->setTransactionDate(date("Y-m-d"));
        $request->setTransactionAmount(number_format($amount, 2, '.', ''));
        $request->setTransactionCurrency($orderCurrency);
        $request->setAdditional2('');
        $request->setTransactionType("EXPIRED");
        $request->setOpenBalance("0");

        return $request;
    }

    private function sendS5Request(OrderEntity $order, ByjunoS5Request $request, Context $context): void
    {
        $xml                 = $request->createRequest();
        $communicator        = new ByjunoCommunicator();
        $mode                = $this->systemConfigService->get("ByjunoPayments.config.mode", $order->getSalesChannelId()) ?? 'live';
        $salesChannelContext = $this->orderConverter->assembleSalesChannelContext($order, $context);

        $communicator->setServer(
            match (strtolower($mode)) {
                'live' => 'live',
                default => 'test',
            }
        );

        if ($response = $communicator->sendS4Request($xml, $this->systemConfigService->get("ByjunoPayments.config.byjunotimeout", $order->getSalesChannelId()))) {
            $byjunoResponse = new ByjunoS4Response();
            $byjunoResponse->setRawResponse($response);
            $byjunoResponse->processResponse();
            $statusCDP = $byjunoResponse->getProcessingInfoClassification();

            $this->saveS5Log($salesChannelContext->getContext(), $request, $xml, $response, $statusCDP, 'S5 Cancel request', $order->getOrderCustomer()?->getFirstName(), $order->getOrderCustomer()?->getLastName());
        } else {
            $this->saveS5Log($salesChannelContext->getContext(), $request, $xml, "Empty response", 0, 'S5 Cancel request', $order->getOrderCustomer()?->getFirstName(), $order->getOrderCustomer()?->getLastName());
        }
    }

    private function saveS5Log(Context $context, ByjunoS5Request $request, string $xmlRequest, string $xmlResponse, int|string $status, string $type, string $firstName, string $lastName): void
    {
        $entry = [
            'id'            => Uuid::randomHex(),
            'request_id'    => $request->getRequestId(),
            'request_type'  => $type,
            'firstname'     => $firstName,
            'lastname'      => $lastName,
            'ip'            => $_SERVER['REMOTE_ADDR'] ?? "127.0.0.1",
            'byjuno_status' => $status !== '' ? $status : 'Error',
            'xml_request'   => $xmlRequest,
            'xml_response'  => $xmlResponse,
        ];

        $context->scope(Context::SYSTEM_SCOPE, function (Context $context) use ($entry): void {
            $this->logEntryReposity->upsert([$entry], $context);
        });
    }
}
