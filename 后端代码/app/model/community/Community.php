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

use app\services\community\CommunityRelevanceServices;
use app\services\user\UserServices;
use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;
use think\Model;

/**
 * 社区内容Model
 * Class Community
 * @package app\model\activity\seckill
 */
class Community extends BaseModel
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
    protected $name = 'community';

    /**
     * 关联话题ID
     * @param $value
     * @return false|string
     */
    protected function setTopicIdAttr($value)
    {
        if ($value) {
            return is_array($value) ? implode(',', $value) : $value;
        }
        return '';
    }

    public function getAddTimeAttr($value)
    {
        return $value ? date('Y-m-d H:i', $value) : '';
    }
    public function getVerifyTimeAttr($value)
    {
        return $value ? date('Y-m-d H:i', $value) : '';
    }

    /**
     * 关联话题ID
     * @param $value
     * @return mixed
     */
    protected function getTopicIdAttr($value)
    {
        if ($value) {
            return is_string($value) ? explode(',', $value) : $value;
        }
        return [];
    }

    public function product()
    {
        return $this->hasMany(CommunityRelevance::class,'left_id','id')->where('type',CommunityRelevanceServices::TYPE_COMMUNITY_PRODUCT);
    }

    public function topic()
    {
        return $this->hasMany(CommunityRelevance::class,'left_id','id')->where('type',CommunityRelevanceServices::TYPE_COMMUNITY_TOPIC);
    }
    public function relevance()
    {
        return $this->hasMany(CommunityRelevance::class,'left_id','id')->whereIn('type',[CommunityRelevanceServices::TYPE_COMMUNITY_PRODUCT,CommunityRelevanceServices::TYPE_COMMUNITY_TOPIC]);
    }

    /**
     * 轮播图获取器
     * @param $value
     * @return array|mixed
     */
    public function getSliderImageAttr($value)
    {
        return is_string($value) ? json_decode($value, true) : [];
    }

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
     * 内容类型搜索器
     * @param Model $query
     * @param $value
     */
    public function searchContentTypeAttr($query, $value)
    {
        if (is_array($value)) {
            if ($value) $query->whereIn('content_type', $value);
        } else {
            if ($value) $query->where('content_type', $value);
        }
    }

    /**
     * 推荐指数搜索器
     * @param Model $query
     * @param $value
     * @param $data
     */
    public function searchStarAttr($query, $value, $data)
    {
        if ($value !== '') $query->where('star', $value ?? 1);
    }


    /**
     * 状态搜索器
     * @param Model $query
     * @param $value
     * @param $data
     */
    public function searchStatusAttr($query, $value, $data)
    {
        if ($value !== '') $query->where('status', $value ?? 1);
    }

    /**
     * 推荐搜索器
     * @param Model $query
     * @param $value
     * @param $data
     */
    public function searchIsRecommendAttr($query, $value, $data)
    {
        if ($value !== '') $query->where('is_recommend', $value ?? 1);
    }

    /**
     * 是否审核搜索器
     * @param $query
     * @param $value
     */
    public function searchIsVerifyAttr($query, $value)
    {
        if ($value !== ''){
            $query->where('is_verify', $value);
        }
    }

    /**
     * 是否删除搜索器
     * @param Model $query
     * @param $value
     * @param $data
     */
    public function searchIsDelAttr($query, $value, $data)
    {
        if ($value !== '') $query->where('is_del', $value ?? 0);
    }

}
