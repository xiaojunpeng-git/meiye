<?php
namespace app\dao\store;

use app\dao\BaseDao;
use app\model\store\StoreStaffTransferApply;

class StoreStaffTransferApplyDao extends BaseDao
{
    protected function setModel(): string
    {
        return StoreStaffTransferApply::class;
    }
}
