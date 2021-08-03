<?php
namespace WeSupply\Toolbox\Observer;

use Magento\Framework\App\Request\Http;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use WeSupply\Toolbox\Api\OrderInfoBuilderInterface;
use WeSupply\Toolbox\Api\OrderRepositoryInterface as WeSupplyOrderRepositoryInterface;
use WeSupply\Toolbox\Helper\Data as WeSupplyHelper;
use WeSupply\Toolbox\Logger\Logger as Logger;
use WeSupply\Toolbox\Model\Order;

class UpdateOrderInfo implements ObserverInterface
{
    /**
     * @var OrderRepositoryInterface
     */
    protected $orderRepositoryInterface;

    /**
     * @var WeSupplyOrderRepositoryInterface
     */
    protected $weSupplyOrderRepository;

    /**
     * @var Order
     */
    protected $weSupplyOrderFactory;

    /**
     * @var OrderInfoBuilderInterface
     */
    protected $orderInfoBuilder;

    /**
     * @var Http
     */
    protected $request;

    /**
     * @var Json
     */
    protected $json;

    /**
     * @var WeSupplyHelper
     */
    protected $helper;

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var TimezoneInterface
     */
    protected $timezone;

    /**
     * UpdateOrderInfo constructor.
     *
     * @param OrderRepositoryInterface                    $orderRepositoryInterface
     * @param WeSupplyOrderRepositoryInterface            $weSupplyOrderRepository
     * @param Order                                       $weSupplyOrderFactory
     * @param OrderInfoBuilderInterface                   $orderInfoBuilder
     * @param Http                                        $request
     * @param Json                                        $json
     * @param WeSupplyHelper                              $helper
     * @param Logger                                      $logger
     * @param TimezoneInterface                           $timezone
     */
    public function __construct(
        OrderRepositoryInterface $orderRepositoryInterface,
        WeSupplyOrderRepositoryInterface $weSupplyOrderRepository,
        Order $weSupplyOrderFactory,
        OrderInfoBuilderInterface $orderInfoBuilder,
        Http $request,
        Json $json,
        WeSupplyHelper $helper,
        Logger $logger,
        TimezoneInterface $timezone
    )
    {
        $this->orderRepositoryInterface = $orderRepositoryInterface;
        $this->weSupplyOrderRepository = $weSupplyOrderRepository;
        $this->weSupplyOrderFactory = $weSupplyOrderFactory;
        $this->orderInfoBuilder = $orderInfoBuilder;
        $this->request = $request;
        $this->json = $json;
        $this->helper = $helper;
        $this->logger = $logger;
        $this->timezone = $timezone;
    }

    /**
     * @param Observer $observer
     * @return $this|void
     */
    public function execute(Observer $observer)
    {
        $orderId = $observer->getData('orderId');
        try {
            $order = $this->orderRepositoryInterface->get($orderId);
        } catch (\Exception $ex) {
            $this->logger->error("WeSupply Error: Order with id $orderId not found. Error: " . $ex->getMessage());
            return $this;
        }

        if ($this->helper->shouldIgnoreOrder($order)) {
            return $this;
        }

        try {
            $weSupplyOrder = $this->weSupplyOrderRepository->getByOrderId($orderId);
            if ($this->request->getFrontName() === 'admin' /** check only for updates from magento admin */
                && !$weSupplyOrder->getId() && (
                    /** in case of pending order update for order that was excluded at the time when it was placed */
                    ($order->getStatus() === 'pending' && !$order->getExcludeImportPending()) ||
                    /** in case of complete order update for order that was excluded at the time when it was placed */
                    ($order->getStatus() === 'complete' && $order->getExcludeImportComplete())
                    /** backwards compatibility for order already saved in the past */
                    || $weSupplyOrder->isExcluded()
                )
            ) {
                return $this;
            }

            $existingOrderXml = simplexml_load_string($weSupplyOrder->getInfo(), 'SimpleXMLElement');
            $jsonOrderData = $this->json->serialize($existingOrderXml);
            $existingOrderData = $this->json->unserialize($jsonOrderData);

            $orderData = $this->orderInfoBuilder->gatherInfo($order, $existingOrderData ?? null);
            if (empty($orderData)) {
                $this->logger->error("WeSupply Error: OrderInfo gathering with order id $orderId is empty");
                return $this;
            }

            $orderInfo = $this->orderInfoBuilder->prepareForStorage($orderData);

            $weSupplyOrder->setOrderId($orderId);
            $weSupplyOrder->setOrderNumber($this->orderInfoBuilder->getOrderNumber($orderData));
            $weSupplyOrder->setStoreId($this->orderInfoBuilder->getStoreId($orderData));
            $weSupplyOrder->setInfo($orderInfo);

            /** updated at in default Magento 2 UTC */
            $weSupplyOrder->setUpdatedAt(date("Y-m-d H:i:s"));
            $this->weSupplyOrderRepository->save($weSupplyOrder);
        } catch (\Exception $ex) {
            $this->logger->error("WeSupply Error: " . $ex->getMessage());
        }

        return $this;
    }
}
