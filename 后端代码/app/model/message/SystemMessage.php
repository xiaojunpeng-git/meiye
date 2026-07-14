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

namespace app\model\message;

use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;
use think\Model;

/**
 * 站内信
 * Class SystemMessage
 * @package app\model\message
 */
class SystemMessage extends BaseModel
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
    protected $name = 'system_message';


    protected $insert = ['add_time'];

    /**
     * ID搜索器
     * @param Model $query
     * @param $value
     * @param $data
     */
    public function searchIdAttr($query, $value, $data)
    {
        $query->where('id', $value);
    }

    /**
     * UID搜索器
     * @param Model $query
     * @param $value
     * @param $data
     */
    public function searchUidAttr($query, $value, $data)
    {
        $query->where('uid', $value);
    }

    /**
     * Look搜索器
     * @param Model $query
     * @param $value
     * @param $data
     */
    public function searchLookAttr($query, $value, $data)
    {
        $query->where('look', $value);
    }

    /**
     * del搜索器
     * @param Model $query
     * @param $value
     * @param $data
     */
    public function searchIsDelAttr($query, $value, $data)
    {
        $query->where('is_del', $value);
    }

}
