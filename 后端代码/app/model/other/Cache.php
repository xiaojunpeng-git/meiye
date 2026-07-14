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

use mohe\traits\ModelTrait;
use mohe\basic\BaseModel;
use think\Model;

/**
 *  缓存Model
 * Class Cache
 * @package app\model\other
 */
class Cache extends BaseModel
{
    use ModelTrait;

    const EXPIRE = 0;
    /**
     * 模型名称
     * @var string
     */
    protected $name = 'cache';

    /**
     * 缓存KEY搜索器
     * @param Model $query
     * @param $value
     * @param $data
     */
    public function searchKeyAttr($query, $value, $data)
    {
        $query->where('key', $value);
    }
}
