<?php declare(strict_types=1);

namespace Byjuno\ByjunoPayments\Service;

use Psr\Container\ContainerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentProcessException;
use Shopware\Core\Checkout\Payment\Exception\CustomerCanceledAsyncPaymentException;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class ByjunoCorePayment implements AsynchronousPaymentHandlerInterface
{
    /**
     * @var OrderTransactionStateHandler
     */
    private $transactionStateHandler;

    /**
     * @var ContainerInterface
     */
    private $container;

    public function __construct(
        OrderTransactionStateHandler $transactionStateHandler,
        ContainerInterface $container)
    {
        $this->transactionStateHandler = $transactionStateHandler;
        $this->container = $container;
    }

    /**
     * @throws AsyncPaymentProcessException
     */
    public function pay(
        AsyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext
    ): RedirectResponse {
        $customer = $salesChannelContext->getCustomer();
        var_dump($customer->getId());
      //  exit();
        // Method that sends the return URL to the external gateway and gets a redirect URL back
        try {
            $redirectUrl = $this->sendReturnUrlToExternalGateway($transaction);
        } catch (\Exception $e) {
            throw new AsyncPaymentProcessException(
                $transaction->getOrderTransaction()->getId(),
                'An error occurred during the communication with external payment gateway' . PHP_EOL . $e->getMessage()
            );
        }

        // Redirect to external gateway
        return new RedirectResponse($redirectUrl);
    }

    /**
     * @throws CustomerCanceledAsyncPaymentException
     */
    public function finalize(
        AsyncPaymentTransactionStruct $transaction,
        Request $request,
        SalesChannelContext $salesChannelContext
    ): void {
        $transactionId = $transaction->getOrderTransaction()->getId();
        $paymentState = $request->query->getAlpha('status');

        $context = $salesChannelContext->getContext();
        if ($paymentState === 'completed') {
            // Payment completed, set transaction status to "paid"
            $this->transactionStateHandler->pay($transaction->getOrderTransaction()->getId(), $context);
        } else {
            // Payment not completed, set transaction status to "cancel"
            $this->transactionStateHandler->cancel($transaction->getOrderTransaction()->getId(), $context);
            throw new CustomerCanceledAsyncPaymentException(
                $transactionId,
                'Customer canceled the payment on the PayPal page'
            );
        }
    }

    private function sendReturnUrlToExternalGateway(AsyncPaymentTransactionStruct $transaction): string
    {
        $url = $this->container->get('router')->generate("frontend.checkout.byjunodata", [], UrlGeneratorInterface::ABSOLUTE_PATH);
        $paymentProviderUrl = $url.'?returnurl='.urlencode($transaction->getReturnUrl())."&orderid=".$transaction->getOrder()->getId();
        return $paymentProviderUrl;
    }
}
