<?php
/**
 * Created by PhpStorm.
 * User: User
 * Date: 09.11.2016
 * Time: 15:48
 */
namespace Byjuno\ByjunoPayments\Api;

use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender;
use Magento\Sales\Model\ResourceModel\Order as OrderResource;

/**
 * Class OrderSender
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class CembraPayOrderSender extends OrderSender
{
    private $email;
    protected function checkAndSend(Order $order)
    {
        $this->identityContainer->setStore($order->getStore());
        if (!$this->identityContainer->isEnabled()) {
            return false;
        }
        $this->prepareTemplate($order);

        /** @var \Magento\Sales\Model\Order\Email\SenderBuilder $sender */
        $this->identityContainer->setCustomerName("CembraPay");
        $this->identityContainer->setCustomerEmail($this->email);
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $objectManagerInterface = $objectManager->get('\Magento\Framework\ObjectManagerInterface');
        $this->senderBuilderFactory = new \Magento\Sales\Model\Order\Email\SenderBuilderFactory($objectManagerInterface, '\\Byjuno\\ByjunoCore\\Helper\\CembraPaySenderBuilder');
        $sender = $this->getSender();
        try {
            $sender->send();
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
        }

        return true;
    }

    public function sendOrder(Order $order, $email)
    {
        CembraPaySenderBuilder::$orderId = $order->getIncrementId();
        $this->email = $email;
        if ($this->checkAndSend($order)) {
                return true;
        }
        return false;
    }

}
