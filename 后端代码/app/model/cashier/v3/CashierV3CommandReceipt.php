<?php
// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------

namespace app\model\cashier\v3;

use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;

/**
 * 收银 V3 命令回执与幂等表。只有成功命令落最终回执。
 */
class CashierV3CommandReceipt extends BaseModel
{
    use ModelTrait;

    protected $pk = 'id';

    protected $name = 'cashier_v3_command_receipt';

    /** 业务时间字段均为 int unix，关闭 ORM 自动时间戳转换 */
    protected $autoWriteTimestamp = false;
}
