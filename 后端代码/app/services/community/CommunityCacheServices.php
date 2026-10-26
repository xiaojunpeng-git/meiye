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

use app\services\BaseServices;
use mohe\services\CacheService;


class CommunityCacheServices extends BaseServices
{
    //帖子点赞
    const COMMUNITY_LIKE = 'community_like';

    //评论点赞
    const COMMUNITY_COMMENT_LIKE = 'community_comment_like';

    //浏览
    const COMMUNITY_BROWSE = 'community_browse';

    //是否关注
    const COMMUNITY_INTEREST = 'community_interest';

    //最新一条id
    const COMMUNITY_NEWEST = 'community_newest';

    //话题总数
    const COMMUNITY_TOPIC_COUNT = 'community_topic_count';
    /**
     * 点赞操作
     *
     * @param int $id 被点赞对象的ID
     * @param int $uid 执行点赞操作的用户ID
     * @param string $type 点赞类型， 默认为社区点赞
     *
     * @return mixed 返回点赞操作的结果，通常是受影响的元素数量
     */
    public function setLike(int $id, int $uid, int $status = 1, string $type = self::COMMUNITY_LIKE)
    {
        $key = $type . '_' . $id;
        $status == 1 ? CacheService::redisHandler()->sAdd($key, $uid) : CacheService::redisHandler()->sRem($key, $uid);
        return true;
    }

    /**
     * 是否点赞
     * @param int $id
     * @param int $uid
     * @param string $type
     * @return mixed
     */
    public function checkUserLike(int $id, int $uid, string $type = self::COMMUNITY_LIKE)
    {
        $key = $type . '_' . $id;

        return CacheService::redisHandler()->sIsMember($key, $uid) ? 1 : 0;
    }

    /**
     * 删除
     * @param int $id
     * @param int $uid
     * @param string $type
     * @return mixed
     */
    public function deleteKey(int $id, string $type = self::COMMUNITY_LIKE)
    {
        $key = $type . '_' . $id;
        return CacheService::redisHandler()->del($key);
    }

    /**
     * 多个帖子是否点赞
     * @param array $ids
     * @param int $uid
     * @param string $type
     * @return array
     */
    public function checkUserLikeMultiple(array $ids, int $uid, string $type = self::COMMUNITY_LIKE)
    {
        $userLikes = [];
        foreach ($ids as $id) {
            $userLikes[$id] = $this->checkUserLike($id, $uid, $type);
        }
        return $userLikes;
    }

    /**
     * 存用户最新一条帖子
     * @param int $uid
     * @param int $id
     * @return bool
     */
    public function setCommunityNewest(int $uid, int $id)
    {
        /** @var CommunityUserServices $communityUserServices */
        $communityUserServices = app()->make(CommunityUserServices::class);
        $user_id = $communityUserServices->value(['relation_id'=>$uid,'status'=>1,'is_del'=>0],'id');
        $key = self::COMMUNITY_NEWEST . $user_id;
        /** @var CacheService $cacheServices */
        $cacheServices = app()->make(CacheService::class);
        return $cacheServices->set($key, $id,0);
    }

    /**
     * 检查用户是否已读作者最新一条帖子
     * @param int $uid
     * @param int $user_id 关注社区用户 id
     * @return int
     */
    public function checkCommunityNewest(int $uid, int $user_id)
    {
        $key = self::COMMUNITY_NEWEST . $user_id;
        /** @var CacheService $cacheServices */
        $cacheServices = app()->make(CacheService::class);
        $new_id = $cacheServices->get($key);
        if (!$new_id) return 0;
        return $this->checkUserLike((int)$new_id, $uid, self::COMMUNITY_BROWSE) ? 0 : 1;
    }
}
