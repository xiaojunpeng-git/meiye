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

use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;

/**
 * 社区记录Model
 * Class CommunityRecord
 * @package app\model\community
 */
class CommunityRecord extends BaseModel
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
    protected $name = 'community_record';


    /**
     * 关联用户搜索器
     * @param $query
     * @param $value
     */
    public function searchUidAttr($query, $value)
    {
        if ($value !== '') {
            $query->where('uid', $value);
        }
    }

    /**
     * 关联用户搜索器
     * @param $query
     * @param $value
     */
    public function searchRelationIdAttr($query, $value)
    {
        if ($value !== '') {
            $query->where('relation_id', $value);
        }
    }

    /**
     * 类型搜索器
     * @param $query
     * @param $value
     */
    public function searchTypeAttr($query, $value)
    {
        if ($value !== '') {
            $query->where('type', $value);
        }
    }

    /**
     * 类型搜索器
     * @param $query
     * @param $value
     */
    public function searchRecordAttr($query, $value)
    {
        if ($value !== '') {
            $query->where('record', $value);
        }
    }
    /**
     * 关联帖子搜索器
     * @param $query
     * @param $value
     */
    public function searchPostIdAttr($query, $value)
    {
        if ($value !== '') {
            $query->where('post_id', $value);
        }
    }

    /**
     * 关联评论搜索器
     * @param $query
     * @param $value
     */
    public function searchCommentIdAttr($query, $value)
    {
        if ($value !== '') {
            $query->where('comment_id', $value);
        }
    }

    /**
     * 评论类型搜索器
     * @param $query
     * @param $value
     */
    public function searchCommentTypeAttr($query, $value)
    {
        if ($value !== '') {
            $query->where('comment_type', $value);
        }
    }

    /**
     * 内容搜索器
     * @param $query
     * @param $value
     */
    public function searchContentAttr($query, $value)
    {
        if ($value !== '') {
            $query->whereLike('content', "%{$value}%");
        }
    }

    /**
     * 是否已查看搜索器
     * @param $query
     * @param $value
     */
    public function searchIsViewedAttr($query, $value)
    {
        if ($value !== '') {
            $query->where('is_viewed', $value);
        }
    }

    /**
     * 添加时间搜索器
     * @param $query
     * @param $value
     */
    public function searchAddTimeAttr($query, $value)
    {
        if ($value !== '') {
            $query->where('add_time', $value);
        }
    }
}
