<?php
// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2020 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------
namespace app\model\activity\collage;

use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;
use app\model\order\StoreOrder;
use app\model\activity\table\TableQrcode;

/**
 *  拼单Model
 * Class UserCollage
 * @package app\model\collage
 */
class UserCollageCode extends BaseModel
{
    use ModelTrait;

    /**
     * 数据表主键
     * @var string
     */
    protected $pk = 'id';

    /**
     * 模型名称
     * @var string
     */
    protected $name = 'user_collage_code';

    /**
     * 添加时间获取器
     * @param $value
     * @return false|string
     */
    protected function getAddTimeAttr($value)
    {
        if ($value) return date('Y-m-d H:i:s', (int)$value);
        return '';
    }

    /**一对一关联
     * 关联订单信息
     * @return \think\model\relation\HasOne
     */
    public function orderId()
    {
        return $this->hasOne(StoreOrder::class, 'id', 'oid')->field(['id', 'order_id', 'pay_type', 'total_num', 'total_price', 'pay_price', 'paid', 'refund_status', 'staff_id', 'is_del', 'is_system_del']);
    }

    /**一对一关联
     * 关联桌码信息
     * @return \think\model\relation\HasOne
     */
    public function qrcode()
    {
        return $this->hasOne(TableQrcode::class, 'id', 'qrcode_id')->field(['id','cate_id','table_number','is_using','is_del']);
    }
}
