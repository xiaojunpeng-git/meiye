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

namespace app\model\message\service;


use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;
use think\Model;

/**
 * 客服留言反馈
 * Class StoreServiceFeedback
 * @package app\model\message\service
 */
class StoreServiceFeedback extends BaseModel
{

    use ModelTrait;

    /**
     * @var string
     */
    protected $name = 'store_service_feedback';

    /**
     * @var string
     */
    protected $pk = 'id';

    /**
     * @param $value
     * @return false|string
     */
    public function getAddTimeAttr($value)
    {
        return date('Y-m-d H:i:s', $value);
    }

    /**
     * 标题搜索
     * @param Model $query
     * @param $value
     */
    public function searchTitleAttr($query, $value)
    {
        $value && $query->whereLike('rela_name|phone|content|uid', "%" . $value . "%");
    }

}
