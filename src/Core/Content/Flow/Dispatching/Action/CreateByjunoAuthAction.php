<?php declare(strict_types=1);

namespace Byjuno\ByjunoPayments\Core\Content\Flow\Dispatching\Action;

use Byjuno\ByjunoPayments\Core\Framework\Event\ByjunoAuthAware;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\Flow\Dispatching\Action\FlowAction;
use Shopware\Core\Content\Flow\Dispatching\StorableFlow;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Event\OrderAware;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class CreateByjunoAuthAction extends FlowAction
{
    private $orderRepository;
    /**
     * @var SystemConfigService
     */
    private $systemConfigService;

    public function __construct(EntityRepository$orderRepository, SystemConfigService $systemConfigService)
    {
        $this->orderRepository = $orderRepository;
        $this->systemConfigService = $systemConfigService;
    }

    public static function getName(): string
    {
        // your own action name
        return 'action.create.byjunoauth';
    }

    public function requirements(): array
    {
        return [ByjunoAuthAware::class];
    }

    public function handleFlow(StorableFlow $flow): void
    {
        if (!$flow->hasData(OrderAware::ORDER)) {
            return;
        }
        /* @var $order OrderEntity */
        $order = $flow->getData(OrderAware::ORDER);
        if (!$order instanceof OrderEntity) {
            return;
        }
        $fields = $order->getCustomFields();
        if (!empty($fields["byjuno_s3_sent"])) {
            return;
        }
        if ($this->systemConfigService->get("ByjunoPayments.config.byjunoS3ActionEnable", $order->getSalesChannelId()) != 'enabled') {
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
            $this->orderRepository->update([$update], $flow->getContext());
        }
    }
}
