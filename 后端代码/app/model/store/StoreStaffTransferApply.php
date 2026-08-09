<?php
namespace app\model\store;

use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;

/**
 * 员工调店申请
 */
class StoreStaffTransferApply extends BaseModel
{
    use ModelTrait;

    protected $pk = 'id';
    protected $name = 'store_staff_transfer_apply';
    protected $autoWriteTimestamp = false;
    protected $createTime = false;
    protected $updateTime = false;
}
