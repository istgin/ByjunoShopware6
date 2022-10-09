<?php declare(strict_types=1);

namespace Byjuno\ByjunoPayments\Core\Content\Flow\Dispatching\Action;

use Byjuno\ByjunoPayments\Core\Framework\Event\ByjunoAuthAware;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\Flow\Dispatching\Action\FlowAction;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\Event\FlowEvent;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class CreateByjunoAuthAction extends FlowAction
{
    private $orderRepository;
    /**
     * @var SystemConfigService
     */
    private $systemConfigService;

    public function __construct(EntityRepositoryInterface $orderRepository, SystemConfigService $systemConfigService)
    {
        $this->orderRepository = $orderRepository;
        $this->systemConfigService = $systemConfigService;
    }

    public static function getName(): string
    {
        // your own action name
        return 'action.create.byjunoauth';
    }

    public static function getSubscribedEvents(): array
    {
        return [
            self::getName() => 'handle',
        ];
    }

    public function requirements(): array
    {
        return [ByjunoAuthAware::class];
    }

    public function handle(FlowEvent $event): void
    {
        if ($this->systemConfigService->get("ByjunoPayments.config.byjunoS3ActionEnable") != 'enabled') {
            return;
        }
        $event = $event->getEvent();
        if (!method_exists($event, 'getOrder')) {
            return;
        }
        /* @var $order OrderEntity */
        $order = $event->getOrder();
        if (!$order instanceof OrderEntity) {
            return;
        }
        $fields = $order->getCustomFields();
        if (!empty($fields["byjuno_s3_sent"])) {
            return;
        }
        $paymentMethods = $order->getTransactions();
        $paymentMethodId = '';
        foreach ($paymentMethods as $pm) {
            $paymentMethodId = $pm->getPaymentMethod()->getHandlerIdentifier();
            break;
        }
        if ($paymentMethodId == "Byjuno\ByjunoPayments\Service\ByjunoCorePayment") {
            $customFields = $fields ?? [];
            $customFields = array_merge($customFields, ['byjuno_s3_sent' => 0]);
            $update = [
                'id' => $order->getId(),
                'customFields' => $customFields,
            ];
            $this->orderRepository->update([$update], $event->getContext());
        }
    }
}
