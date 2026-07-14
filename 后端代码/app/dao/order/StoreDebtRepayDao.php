<?php

declare(strict_types=1);

namespace app\dao\order;

use app\dao\BaseDao;
use app\model\order\StoreDebtRepay;

class StoreDebtRepayDao extends BaseDao
{
    protected function setModel(): string
    {
        return StoreDebtRepay::class;
    }

    /**
     * 还款记录搜索
     */
    public function search(array $where = [])
    {
        return parent::search($where);
    }
}
