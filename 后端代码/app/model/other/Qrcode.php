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

namespace app\model\other;


use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;
use think\Model;

/**
 * Class Qrcode
 * @package app\model\other
 */
class Qrcode extends BaseModel
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
    protected $name = 'qrcode';

    /**
     * type 搜索器
     * @param Model $query
     * @param $value
     */
    public function searchTypeAttr($query, $value)
    {
        if ($value != '') {
            $query->whereLike('type', $value);
        }
    }

    /**
     * status 搜索器
     * @param Model $query
     * @param $value
     */
    public function searchStatusAttr($query, $value)
    {
        if ($value != '') {
            $query->whereLike('status', $value);
        }
    }

    /**
     * third_type 搜索器
     * @param Model $query
     * @param $value
     */
    public function searchThirdTypeAttr($query, $value)
    {
        if ($value != '') {
            $query->whereLike('third_type', $value);
        }
    }

    /**
     * third_id 搜索器
     * @param Model $query
     * @param $value
     */
    public function searchThirdIdAttr($query, $value)
    {
        if ($value != '') {
            $query->whereLike('third_id', $value);
        }
    }

}
