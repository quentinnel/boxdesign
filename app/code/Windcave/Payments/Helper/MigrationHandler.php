<?php

namespace Windcave\Payments\Helper;

use \Magento\Framework\Setup\SchemaSetupInterface;

class MigrationHandler
{
    /**
     * @var \Magento\Sales\Model\ResourceModel\Order\Payment\Collection
     */
    private $orderPaymentsCollection = null;

    /**
     * @var \Magento\Sales\Model\ResourceModel\Order\Collection
     */
    private $ordersCollection = null;

    /**
     * @var \Windcave\Payments\Model\ResourceModel\PaymentResult\Collection
     */
    private $paymentResultsCollection = null;

    public function __construct(
        \Magento\Sales\Model\ResourceModel\Order\Payment\Collection $orderPaymentsCollection,
        \Magento\Sales\Model\ResourceModel\Order\Collection $ordersCollection,
        \Windcave\Payments\Model\ResourceModel\PaymentResult\Collection $paymentResultsCollection
    ) {
        $this->orderPaymentsCollection = $orderPaymentsCollection;
        $this->ordersCollection = $ordersCollection;
        $this->paymentResultsCollection = $paymentResultsCollection;
        $this->objectManager = \Magento\Framework\App\ObjectManager::getInstance();
    }


    private function migratePayments()
    {
        $oldToNewMethodNames = [
            "paymentexpress_pxpay2" => "windcave_pxpay2",
            "paymentexpress_pxpay2_iframe" => "windcave_pxpay2_iframe",
            "paymentexpress_pxfusion" => "windcave_pxfusion"
        ];

        // Migrating existing order payments
        $payments = $this->orderPaymentsCollection->addFieldToFilter("method", ["in" => array_keys($oldToNewMethodNames)]);
        foreach ($payments as $payment) {
            $newMethodName = $oldToNewMethodNames[$payment->getMethod()];
            $payment->setMethod($newMethodName);
            $payment->save();
        }
    }

    private function migrateOrderStatuses()
    {
        $oldToNewStatuses = [
            "paymentexpress_authorized" => "windcave_authorized",
            "paymentexpress_failed" => "windcave_failed"
        ];

        // Migrating existing orders
        $orders = $this->ordersCollection->addFieldToFilter("status", ["in" => array_keys($oldToNewStatuses)]);
        foreach ($orders as $order) {
            $newStatus = $oldToNewStatuses[$order->getStatus()];
            $order->setStatus($newStatus);
            $order->save();
        }
    }

    private function migrateBillingTokens(SchemaSetupInterface $setup)
    {
        if (!$setup->tableExists('paymentexpress_billingtoken')) {
            return;
        }

        $oldTableName = $setup->getTable('paymentexpress_billingtoken');

        $sqlConnection = $setup->getConnection();
        $select = $sqlConnection->select()->from($oldTableName);
        $columnsToInsert = [
            'entity_id',
            'customer_id',
            'order_id',
            'store_id',
            'masked_card_number',
            'cc_expiry_date',
            'dps_billing_id'
        ];
        $sqlQuery = $select->insertIgnoreFromSelect($setup->getTable('windcave_billingtoken'), $columnsToInsert, false);
        $sqlConnection->query($sqlQuery);
    }

    private function migratePaymentResults(SchemaSetupInterface $setup)
    {
        if (!$setup->tableExists('paymentexpress_paymentresult')) {
            return;
        }

        $oldTableName = $setup->getTable('paymentexpress_paymentresult');

        $sqlConnection = $setup->getConnection();
        $select = $sqlConnection->select()->from($oldTableName);
        $columnsToInsert = [
            'entity_id',
            'quote_id',
            'reserved_order_id',
            'method',
            'updated_time',
            'dps_transaction_type',
            'dps_txn_ref',
            'raw_xml',
            'user_name',
            'token'
        ];
        $sqlQuery = $select->insertIgnoreFromSelect($setup->getTable('windcave_paymentresult'), $columnsToInsert, false);
        $sqlConnection->query($sqlQuery);
    }

    private function updatePaymentResultMethods()
    {
        $oldToNewMethodNames = [
            "paymentexpress_pxpay2" => "windcave_pxpay2",
            "paymentexpress_pxpay2_iframe" => "windcave_pxpay2_iframe",
            "paymentexpress_pxfusion" => "windcave_pxfusion"
        ];

        $payments = $this->paymentResultsCollection->addFieldToFilter("method", ["in" => array_keys($oldToNewMethodNames)]);
        foreach ($payments as $payment) {
            $newMethodName = $oldToNewMethodNames[$payment->getMethod()];
            $payment->setMethod($newMethodName);
            $payment->save();
        }
    }

    public function migrate(SchemaSetupInterface $setup)
    {
        $this->migratePayments();
        $this->migrateOrderStatuses();
        $this->migrateBillingTokens($setup);
        $this->migratePaymentResults($setup);
        $this->updatePaymentResultMethods();
    }
}
