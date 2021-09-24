<?php
namespace Windcave\Payments\Setup;

use \Magento\Framework\Setup\InstallSchemaInterface;
use \Magento\Framework\Setup\ModuleContextInterface;
use \Magento\Framework\Setup\SchemaSetupInterface;
use \Magento\Framework\DB\Ddl\Table;

class InstallSchema implements InstallSchemaInterface
{
    private function createBillingTokenTable(SchemaSetupInterface $setup)
    {
        $tableName = $setup->getTable('windcave_billingtoken');
        // if exists, then this module should be installed before, just skip it. Use upgrade command to updata the table.
        if ($setup->getConnection()->isTableExists($tableName)) {
            return;
        }
        $table = $setup->getConnection()
            ->newTable($tableName)
            ->addColumn(
                'entity_id',
                Table::TYPE_INTEGER,
                null,
                [
                    'identity' => true,
                    'unsigned' => true,
                    'nullable' => false,
                    'primary' => true
                ],
                'ID'
            )
            ->addColumn(
                'customer_id',
                Table::TYPE_INTEGER,
                null,
                [
                    'nullable' => false,
                    'unsigned' => true
                ],
                'Customer Id'
            )
            ->addColumn(
                'order_id',
                Table::TYPE_INTEGER,
                null,
                [
                    'nullable' => false,
                    'unsigned' => true
                ],
                'Order Id'
            )
            ->addColumn(
                'store_id',
                Table::TYPE_INTEGER,
                null,
                [
                    'nullable' => false,
                    'unsigned' => true
                ],
                'Store Id'
            )
            ->addColumn(
                'masked_card_number',
                Table::TYPE_TEXT,
                32,
                [
                    'nullable' => false,
                    'default' => ''
                ],
                'Masked Card Number'
            )
            ->addColumn(
                'cc_expiry_date',
                Table::TYPE_TEXT,
                5,
                [
                    'nullable' => false,
                    'default' => ''
                ],
                'Credit Card Expiry Date'
            )
            ->addColumn(
                'dps_billing_id',
                Table::TYPE_TEXT,
                32,
                [
                    'nullable' => false,
                    'default' => ''
                ],
                'DPS Billing Id'
            )
            ->setComment('Windcave BillingToken');
        $setup->getConnection()->createTable($table);
    }

    private function createPaymentResultTable(SchemaSetupInterface $setup)
    {
        $tableName = $setup->getTable('windcave_paymentresult');
        // if exists, then this module should be installed before, just skip it. Use upgrade command to updata the table.
        if ($setup->getConnection()->isTableExists($tableName)) {
            return;
        }
        $table = $setup->getConnection()
            ->newTable($tableName)
            ->addColumn(
                'entity_id',
                Table::TYPE_INTEGER,
                null,
                [
                    'identity' => true,
                    'unsigned' => true,
                    'nullable' => false,
                    'primary' => true
                ],
                'ID'
            )
            ->addColumn(
                'quote_id',
                Table::TYPE_INTEGER,
                null,
                [
                    'nullable' => false,
                    'unsigned' => true
                ],
                'Quote Id'
            )
            ->addColumn(
                'reserved_order_id',
                Table::TYPE_TEXT,
                64,
                [
                    'nullable' => false,
                    'unsigned' => true
                ],
                'Order Increment Id'
            )
            ->addColumn(
                'method',
                Table::TYPE_TEXT,
                64,
                [
                    'nullable' => false,
                    'default' => ''
                ],
                'Payment Method'
            )
            ->addColumn(
                'updated_time',
                Table::TYPE_DATETIME,
                null,
                [
                    'nullable' => false
                ],
                'PaymentResponse'
            )
            ->addColumn(
                'dps_transaction_type',
                Table::TYPE_TEXT,
                16,
                [
                    'nullable' => false,
                    'default' => ''
                ],
                'Transaction Type'
            )
            ->addColumn(
                'dps_txn_ref',
                Table::TYPE_TEXT,
                128,
                [
                    'nullable' => false,
                    'default' => ''
                ],
                'DpsTxnRef'
            )
            ->addColumn(
                'user_name',
                Table::TYPE_TEXT,
                255,
                [
                    'nullable' => true
                ],
                'PxPay/PxFusion user'
            )
            ->addColumn(
                'token',
                Table::TYPE_TEXT,
                255,
                [
                    'nullable' => true
                ],
                'PxPay/PxFusion token'
            )
            ->addColumn(
                'raw_xml',
                Table::TYPE_TEXT,
                2048,
                [
                    'nullable' => false,
                    'default' => ''
                ],
                'PaymentResponse'
            )
            ->setComment('Windcave Payment Result');
        $setup->getConnection()->createTable($table);
    }

    private function addPaymentStatuses(SchemaSetupInterface $setup)
    {
        try {
            $data[] = ['status' => 'windcave_authorized', 'label' => 'Payment Authorized'];
            $data[] = ['status' => 'windcave_failed', 'label' => 'Payment Failed'];
            $sqlConnection = $setup->getConnection();
            $sqlConnection->insertArray($setup->getTable('sales_order_status'), ['status', 'label'], $data);
        } catch (\Exception $ex) {
            // This part of the installation must have been executed previously
        }

        try {
            $sqlConnection->insertArray(
                $setup->getTable('sales_order_status_state'),
                ['status', 'state', 'is_default','visible_on_front'],
                [
                    ['windcave_authorized','pending_payment', '0', '1'],
                    ['windcave_failed','pending_payment', '0', '1'],
                ]
            );
        } catch (\Exception $ex) {
            // This part of the installation must have been executed previously
        }
    }

    public function install(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $migrationHandler = $objectManager->create('\Windcave\Payments\Helper\MigrationHandler');
        $appState = $objectManager->get('Magento\Framework\App\State');
        $appState->setAreaCode(\Magento\Framework\App\Area::AREA_GLOBAL);
        $setup->startSetup();

        $this->createBillingTokenTable($setup);
        $this->createPaymentResultTable($setup);
        $this->addPaymentStatuses($setup);

        // This is to migrate all the data from the old PaymentExpress module
        //$migrationHandler->migrate($setup);

        $setup->endSetup();
    }
}
