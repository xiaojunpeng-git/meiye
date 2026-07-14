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

namespace app\model\user;

use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;

/**
 * Class UserSearch
 * @package app\model\user
 */
class UserSearch extends BaseModel
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
    protected $name = 'user_search';


    /**
     * 获取搜索结果
     * @param $value
     * @return array|mixed
     */
    public function getResultAttr($value)
    {
        return json_decode($value, true) ?? [];
    }

    public function searchUidAttr($query, $value)
    {
        if ($value !== '') $query->where('uid', $value);
    }

    public function searchKeywordAttr($query, $value)
    {
        if ($value !== '') $query->where('keyword', $value);
    }

    public function searchIsDelAttr($query, $value)
    {
        if ($value !== '') $query->where('is_del', $value);
    }

}
