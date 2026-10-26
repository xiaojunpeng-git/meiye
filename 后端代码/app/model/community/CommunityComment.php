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
 * 社区评价Model
 * Class CommunityComment
 * @package app\model\activity\video
 */
class CommunityComment extends BaseModel
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
    protected $name = 'community_comment';

    /**
     * @var bool
     */
    protected $autoWriteTimestamp = false;


    public function getAddTimeAttr($value)
    {
        return $value ? date('Y-m-d H:i', $value) : '';
    }

    /**
     * 一对一关联
     * 社区内容评论关联社区内容
     * @return \think\model\relation\HasOne
     */
    public function community()
    {
        return $this->hasOne(Community::class, 'id', 'community_id');
    }

    /**
     * 一对一关联
     * 社区内容评论关联社区内容
     * @return \think\model\relation\HasOne
     */
    public function communityTitle()
    {
        return $this->hasOne(Community::class, 'id', 'community_id')->field('id,title')->bind(['title']);
    }

    /**
     * 一对一关联
     * 社区内容评论关联用户
     * @return \think\model\relation\HasOne
     */
    public function user()
    {
        return $this->hasOne(User::class, 'uid', 'uid');
    }

    /**
     * 一对一关联
     * 社区内容评论关联用户
     * @return \think\model\relation\HasOne
     */
    public function userVip()
    {
        return $this->hasOne(User::class, 'uid', 'uid')->field('uid,avatar,nickname,is_money_level')->bind(['is_money_level']);
    }

    /**
     * 管理回复
     * @return \think\model\relation\HasOne
     */
    public function reply()
    {
        return $this->hasOne(self::class, 'pid', 'id')->where('is_del', 0)->where('uid', 0);
    }

    /**
     * 管理回复
     * @return \think\model\relation\HasOne
     */
    public function replyContent()
    {
        return $this->hasOne(self::class, 'pid', 'id')->field('id,pid,content')->where('is_del', 0)->where('uid', 0)->bind(['reply' => 'content']);
    }

    /**
     * @return \think\model\relation\hasMany
     */
    public function children()
    {
        return $this->hasMany(self::class, 'pid', 'id')->where('is_del', 0);
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
     * 搜索器
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
     * 社区内容ID搜索器
     * @param Model $query
     * @param $value
     */
    public function searchCommunityIdAttr($query, $value)
    {
        if (is_array($value)) {
            if ($value) $query->whereIn('community_id', $value);
        } else {
            if ($value) $query->where('community_id', $value);
        }
    }


    /**
     * UID搜索器
     * @param Model $query
     * @param $value
     */
    public function searchUidAttr($query, $value)
    {
        if (is_array($value)) {
            if ($value) $query->whereIn('uid', $value);
        } else {
            if ($value !== '') $query->where('uid', $value);
        }
    }

    /**
     * is_reply搜索器
     * @param Model $query
     * @param $value
     */
    public function searchIsReplyAttr($query, $value)
    {
        if ($value !== '') $query->where('is_reply', $value);
    }

    /**
     * is_del搜索器
     * @param Model $query
     * @param $value
     */
    public function searchIsDelAttr($query, $value)
    {
        if ($value !== '') $query->where('is_del', $value);
    }

    /**
     * 帖子评论
     * @param $query
     * @param $value
     * @return void
     */
    public function searchReplyIdAttr($query, $value)
    {
        if (is_array($value)) {
            $query->whereIn('reply_id', $value);
        } else {
            if ($value !== '') $query->where('reply_id', $value);
        }

    }

    /**
     * 评论回复
     * @param $query
     * @param $value
     * @return void
     */
    public function searchCommentReplyIdAttr($query, $value)
    {
        if ($value !== '') $query->where('comment_reply_id', $value);
    }

//    /**
//     * 是否审核
//     * @param $query
//     * @param $value
//     * @return void
//     * User: liusl
//     * DateTime: 2024/8/19 11:16
//     */
//    public function searchIsVerifyAttr($query, $value)
//    {
//        if ($value !== '') $query->where('is_verify', $value);
//    }
    /**
     * UID搜索器
     * @param Model $query
     * @param $value
     */
    public function searchReplyUidAttr($query, $value)
    {
        if (is_array($value)) {
            if ($value) $query->whereIn('reply_uid', $value);
        } else {
            if ($value !== '') $query->where('reply_uid', $value);
        }
    }

}
