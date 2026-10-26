<?php

declare(strict_types=1);

namespace app\dao\order;

use app\dao\BaseDao;
use app\model\order\StoreDebtItem;

class StoreDebtItemDao extends BaseDao
{
    protected function setModel(): string
    {
        return StoreDebtItem::class;
    }

    /**
     * 欠款明细搜索
     */
    public function search(array $where = [])
    {
        return parent::search($where);
    }
}
