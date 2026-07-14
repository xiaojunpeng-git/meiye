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
 * Class UserVisit
 * @package app\model\user
 */
class UserVisit extends BaseModel
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
    protected $name = 'user_visit';

    /**
     * 访问来源搜索器
     * @param $query
     * @param $value
     */
    public function searchChannelTypeAttr($query, $value)
    {
        if ($value != '') $query->where('channel_type', $value);
    }
}
