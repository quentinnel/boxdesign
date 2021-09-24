<?php
namespace Windcave\Payments\Controller\PxFusion;

use Windcave\Payments\Helper\FileLock;

class Result extends CommonAction
{
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
        \Magento\Sales\Model\Order\Status\HistoryFactory $orderHistoryFactory,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Quote\Model\QuoteFactory $quoteFactory,
        \Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface $txnBuilder,
        \Magento\Framework\Api\SearchCriteriaBuilder $searchCriteriaBuilder,
        \Magento\Sales\Model\Order\Email\Sender\OrderSender $orderSender,
        \Magento\Framework\Notification\NotifierInterface $notifierInterface
    ) {
        parent::__construct(
            $context,
            $orderRepository,
            $orderHistoryFactory,
            $checkoutSession,
            $quoteFactory,
            $txnBuilder,
            $searchCriteriaBuilder,
            $orderSender,
            $notifierInterface
        );
    }

    public function execute()
    {
        parent::handlePaymentResponse();
    }
}
