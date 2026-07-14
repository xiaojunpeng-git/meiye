<?php

declare(strict_types=1);

namespace app\dao\order;

use app\dao\BaseDao;
use app\model\order\StoreDebt;

class StoreDebtDao extends BaseDao
{
    protected function setModel(): string
    {
        return StoreDebt::class;
    }

    /**
     * 欠款搜索
     */
    public function search(array $where = [])
    {
        return parent::search($where);
    }
}
