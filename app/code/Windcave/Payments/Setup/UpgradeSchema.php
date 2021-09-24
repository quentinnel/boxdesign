<?php
namespace Windcave\Payments\Setup;

use \Magento\Framework\Setup\UpgradeSchemaInterface;
use \Magento\Framework\Setup\ModuleContextInterface;
use \Magento\Framework\Setup\SchemaSetupInterface;

class UpgradeSchema implements UpgradeSchemaInterface
{
    public function upgrade(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
        // TODO this is a template for future migrations
        // reference: http://magento.stackexchange.com/questions/86085/magento2-how-to-database-schema-upgrade
        /*$setup->startSetup();

        if (version_compare($context->getVersion(), '0.5.31.10') < 0) {
            $this->_createPaymentResultTable($setup);
        }

        $setup->endSetup();*/
    }
}
