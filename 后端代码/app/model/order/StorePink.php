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
namespace app\model\order;

use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;
use think\Model;


/**
 * Class StorePink
 * @package app\model\order
 */
class StorePink extends BaseModel
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
    protected $name = 'store_pink';

    protected $insert = ['add_time'];

    /**
     * 创建时间修改器
     * @return int
     */
    protected function setAddTimeAttr()
    {
        return time();
    }
    /**
     * 拼团订单id搜索器
     * @param Model $query
     * @param $value
     * @param $data
     */
    public function searchOrderIdKeyAttr($query, $value, $data)
    {
        $query->whereIn('order_id_key', $value);
    }


}
