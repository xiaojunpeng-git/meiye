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

namespace app\model\community;

use app\model\user\User;
use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;
use think\Model;

/**
 * 社区话题Model
 * Class CommunityTopic
 * @package app\model\community
 */
class CommunityTopic extends BaseModel
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
    protected $name = 'community_topic';

    /**
     * @var bool
     */
    protected $autoWriteTimestamp = false;


    public function searchIdAttr($query, $value)
    {
        if (is_array($value)) {
            if ($value) $query->whereIn('id', $value);
        } else {
            if ($value !== '') $query->where('id', $value);
        }
    }

    /**
     * 商户搜索器
     * @param Model $query
     * @param $value
     */
    public function searchTypeAttr($query, $value)
    {
        if (is_array($value)) {
            if ($value) $query->whereIn('type', $value);
        } else {
            if ($value !== '') $query->where('type', $value);
        }
    }

    /**
     * 关联门店ID、供应商ID搜索器
     * @param Model $query
     * @param $value
     */
    public function searchRelationIdAttr($query, $value)
    {
        if (is_array($value)) {
            if ($value) $query->whereIn('relation_id', $value);
        } else {
            if ($value !== '') $query->where('relation_id', $value);
        }
    }

    /**
     * status搜索器
     * @param Model $query
     * @param $value
     */
    public function searchStatusAttr($query, $value)
    {
        if ($value !== '') $query->where('status', $value);
    }

    /**
     * is_recommend搜索器
     * @param Model $query
     * @param $value
     */
    public function searchIsRecommendAttr($query, $value)
    {
        if ($value !== '') $query->where('is_recommend', $value);
    }

    /**
     * is_del搜索器
     * @param Model $query
     * @param $value
     */
    public function searchIsDelAttr($query, $value)
    {
        if ($value !== '') {
            if (is_array($value)) {
                $query->whereIn('is_del', $value);
            } else {
                $query->where('is_del', $value);
            }
        }
    }

    public function searchNameAttr($query, $value)
    {
        if ($value !== '') {
            $query->whereLike('name', "%{$value}%");
        }
    }

}
