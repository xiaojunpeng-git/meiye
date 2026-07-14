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
declare (strict_types=1);

namespace app\services\community;

use app\dao\community\CommunityRecordDao;
use app\services\BaseServices;
use mohe\traits\OptionTrait;


/**
 * 社区记录
 * Class CommunityRecordServices
 * @package app\services\community
 * @mixin CommunityRecordDao
 */
class CommunityRecordServices extends BaseServices
{

    use OptionTrait;

    /**
     * CommunityCommentServices constructor.
     * @param CommunityRecordDao $dao
     */
    public function __construct(CommunityRecordDao $dao)
    {
        $this->dao = $dao;
    }

    //点赞
    const SET_TYPE_LIKE = 1;
    //评论
    const SET_TYPE_COMMENT = 2;
    //关注
    const SET_TYPE_FOLLOW = 3;


    public function getList(array $where, string $field = '*', int $page = 0, int $limit = 0)
    {
        if (!$limit) {
            [$page, $limit] = $this->getPageValue();
        }
        $list = $this->dao->getList($where,['*'], $page, $limit);

        $comment_ids = array_unique(array_column($list, 'link_id'));

        $communityServices = app()->make(CommunityServices::class);
        $communityUserServices = app()->make(CommunityUserServices::class);

        $comment_list = $communityServices->getColumn(['id' => $comment_ids], 'image,content_type,is_del,status', 'id', true);
        $data = [];
        foreach ($list as $key=>$item) {
            $item['image'] = $comment_list[$item['link_id']]['image'] ?? '';
            $item['content_type'] = $comment_list[$item['link_id']]['content_type'] ?? '';
            $item['status'] = $comment_list[$item['link_id']]['status'] ?? 0;
            $item['community_is_del'] = $comment_list[$item['link_id']]['is_del'] ?? 0;
            $userFormat = $communityUserServices->getUserFormat($item['relation_id'] ? 2 : 0, $item['relation_id']);
            if(!$userFormat) {
                continue;
            }
            $item['relation_author'] = $userFormat['author'] ?? '';
            $item['relation_author_image'] = $userFormat['author_image'] ?? '';
            $item['time_text'] = timeConverter($item['add_time']);
            $data[] = $item;
        }
        return $data;
    }

    /**
     * 点赞评论关注是否查看
     * @param $uid
     * @return bool[]
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function isViewed($uid)
    {
        $where = [
            'uid' => $uid,
            'is_viewed' => 0
        ];
        $likeViewed = $this->dao->get($where + ['record' => self::SET_TYPE_LIKE]);
        $commentViewed = $this->dao->get($where + ['record' => self::SET_TYPE_COMMENT]);
        $followViewed = $this->dao->get($where + ['record' => self::SET_TYPE_FOLLOW]);
        return [
            'like' => $likeViewed ? 1 : 0,
            'comment' => $commentViewed ? 1 : 0,
            'follow' => $followViewed ? 1 : 0
        ];
    }
}
