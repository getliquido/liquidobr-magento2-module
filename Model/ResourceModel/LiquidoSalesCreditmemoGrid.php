<?php

declare(strict_types=1);

namespace Liquido\PayIn\Model\ResourceModel;

use \Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class LiquidoSalesCreditmemoGrid extends AbstractDb
{
    /** @var string Main table name */
    const MAIN_TABLE = 'liquido_payin_sales_creditmemo_grid';

    /** @var string Main table primary key field name */
    const ID_FIELD_NAME = 'id';

    protected function _construct(): void
    {
        $this->_init(self::MAIN_TABLE, self::ID_FIELD_NAME);
    }
}
