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

use app\model\product\product\StoreProduct;
use app\model\user\User;
use app\services\community\CommunityRelevanceServices;
use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;
use think\Model;

/**
 * 社区关联Model
 * Class CommunityTopic
 * @package app\model\community
 */
class CommunityRelevance extends BaseModel
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
    protected $name = 'community_relevance';

    public function product()
    {
        $where = ['is_show' => 1, 'is_del' => 0, 'is_verify' => 1];
        return $this->hasOne(StoreProduct::class, 'id', 'right_id')->where($where)->field('id,price,store_name,image,stock');
    }

    public function topic()
    {
        $where = ['is_del' => [0,-1], 'status' => 1];
        return $this->hasOne(CommunityTopic::class, 'id', 'right_id')->where($where)->field('id,name');
    }

    public function community()
    {
        return $this->hasOne(Community::class, 'id', 'right_id');
    }

    public function communityUser()
    {
        return $this->hasOne(CommunityUser::class, 'id', 'right_id');
    }

    public function communityFans()
    {
        return $this->hasOne(CommunityUser::class, 'relation_id', 'left_id');
    }
    /**
     * 搜索器
     * @param Model $query
     * @param $value
     */
    public function searchLeftIdAttr($query, $value)
    {
        if (is_array($value)) {
            if ($value) {
                $query->whereIn('left_id', $value);
            }
        } else {
            if ($value !== '') {
                $query->where('left_id', $value);
            }
        }
    }

    /**
     * 搜索器
     * @param $query
     * @param $value
     * @return void
     */
    public function searchRightIdAttr($query, $value)
    {
        if (is_array($value)) {
            if ($value) {
                $query->whereIn('right_id', $value);
            }
        } else {
            if ($value !== '') {
                $query->where('right_id', $value);
            }
        }
    }

    /**
     * 搜索器
     * @param $query
     * @param $value
     * @return void
     */
    public function searchTypeAttr($query, $value)
    {
        if (is_array($value)) {
            if ($value) {
                $query->whereIn('type', $value);
            }
        } else {
            if ($value !== '') {
                $query->where('type', $value);
            }
        }
    }
}
