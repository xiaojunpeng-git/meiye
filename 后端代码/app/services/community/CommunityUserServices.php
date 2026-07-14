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

use app\dao\community\CommunityUserDao;
use app\services\spread\AgentLevelServices;
use app\services\BaseServices;
use app\services\store\SystemStoreServices;
use app\services\user\level\SystemUserLevelServices;
use app\services\user\UserFriendsServices;
use app\services\user\UserServices;
use mohe\services\CacheService;
use mohe\traits\OptionTrait;
use think\db\exception\DataNotFoundException;
use think\db\exception\DbException;
use think\db\exception\ModelNotFoundException;
use think\exception\ValidateException;
use think\Response;


/**
 * 社区用户
 * Class CommunityUserServices
 * @package app\services\community
 * @mixin CommunityUserDao
 */
class CommunityUserServices extends BaseServices
{
    use OptionTrait;

    /**
     * CommunityUserServices constructor.
     * @param CommunityUserDao $dao
     */
    public function __construct(CommunityUserDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 检查用户是否存在
     * 此方法用于确认给定的用户ID是否已在社区中存在，如果不存在，则将其标记为存在
     * @param int $uid 需要检查的用户ID，默认值为0，表示不特定用户
     * @return bool 总是返回true，表示所有用户均被视为存在，特定逻辑处理在方法内部进行
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public function hasUser(int $uid = 0)
    {
        if (!$uid) return true;
        $this->cacheTag()->remember('community_is_user_cache_' . $uid, function () use ($uid) {
            $count = $this->dao->count(['type' => 2, 'relation_id' => $uid, 'status' => 1, 'is_del' => 0]);
            if (!$count) {
                $this->dao->save(['type' => 2, 'relation_id' => $uid, 'add_time' => time()]);
            } else if ($count > 1) {
                $ids = $this->dao->getColumn(['type' => 2, 'relation_id' => $uid, 'status' => 1, 'is_del' => 0], 'id');
                foreach ($ids as $k => $v) {
                    if ($k == 0) continue;
                    $this->dao->delete($v);
                }
            }
            return 1;
        });
        return true;
    }

    /**
     * 主页
     * @param int $authorUid //作者ID
     * @param int $uid
     * @return array
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public function getInfo(int $authorUid, int $uid, bool $is_store = false)
    {
        /** @var UserServices $userServices */
        $userServices = app()->make(UserServices::class);
        /** @var CommunityCacheServices $communityCacheServices */
        $communityCacheServices = app()->make(CommunityCacheServices::class);

        $site_name = sys_config('site_name');
        $site_image = sys_config('wap_login_logo');
        $friend_count = 0;
        $type = 0;
        if ($authorUid == 0) {
            $info = $this->dao->get(['type' => 0, 'is_del' => 0]);
        } else {
            $type = 2;
            if ($is_store) {
                $type = 1;
            }
            $info = $this->dao->get(['type' => $type, 'relation_id' => $authorUid, 'is_del' => 0]);
            if ($type == 2) {
                $userInfo = app()->make(UserServices::class)->getUserCacheInfo($authorUid);
                if (!$userInfo) {
                    throw new ValidateException('用户不存在～');
                }
                /** @var UserFriendsServices $userFriendsServices */
                $userFriendsServices = app()->make(UserFriendsServices::class);
                $friend = $userFriendsServices->getFriendUids($uid);
                $friend = array_diff($friend, [$uid]);
                $friend_count = count($friend);
            }

        }
        if (!$info) {
            throw new ValidateException('用户不存在～');
        }

        $info = $info->toArray();
        $info['friend_count'] = $friend_count;
        $info['level_name'] = '';
        $info['vip_status'] = 0;
        $info['is_self'] = 0;
        $info['is_follow'] = $communityCacheServices->checkUserLike($info['id'], $uid, CommunityCacheServices::COMMUNITY_INTEREST);
        if ($authorUid == $uid) {
            $info['is_self'] = 1;
        }
        $userFormat = $this->getUserFormat($type, $authorUid);
        $info['author'] = $userFormat['author'] ?? '';
        $info['author_image'] = $userFormat['author_image'] ?? '';
        if ($authorUid != 0 && $type == 2) {
            $user = $userServices->get($authorUid);

            $is_open_level = sys_config('member_func_status', 0);
            if ($is_open_level && $user['level']) {
                /** @var SystemUserLevelServices $levelServices */
                $levelServices = app()->make(SystemUserLevelServices::class);
                $levelInfo = $levelServices->getOne(['id' => $user['level']], 'id,grade');
                $info['level_name'] = $levelInfo['grade'] ?? '';
            }
            //看付费会员是否开启
            $is_open_member = sys_config('member_card_status', 0);
            if ($is_open_member) {
                if ($user['is_ever_level']) {
                    $info['vip_status'] = 1;//永久会员
                } else {
                    if ($user['is_money_level'] && $user['overdue_time'] && $user['overdue_time'] > time()) {
                        $info['vip_status'] = 1;//开通了，没有到期
                    }
                }
            }
        }
        return $info;
    }

    /**
     * 关注/取消关注
     * @param int $authorUid
     * @param int $uid
     * @param int $status
     * @return \think\Response|void
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public function setInterest(int $authorUid, int $uid, int $status, bool $is_store = false)
    {
        /** @var CommunityRelevanceServices $communityRelevanceServices */
        $communityRelevanceServices = app()->make(CommunityRelevanceServices::class);
        /** @var CommunityCacheServices $communityCacheServices */
        $communityCacheServices = app()->make(CommunityCacheServices::class);
        $communityRecordServices = app()->make(CommunityRecordServices::class);
        $type = 0;
        if ($authorUid == 0) {
            $info = $this->dao->get(['type' => $type, 'relation_id' => 0]);
        } else {
            $type = 2;
            if ($is_store) {
                $type = 1;
            }
            $info = $this->dao->getUserInfo($authorUid, $type);
        }
        if (!$info)
            return app('json')->fail('社区用户不存在');
        //是否存在点赞
        $check = $communityRelevanceServices->checkHas($uid, $info['id'], CommunityRelevanceServices::TYPE_COMMUNITY_INTEREST);

        if ($status) {
            if ($check) throw new ValidateException('您已经关注过了～');
            $communityRelevanceServices->create($uid, $info['id'], CommunityRelevanceServices::TYPE_COMMUNITY_INTEREST, true);
            //粉丝数量+1
            $this->dao->incUpdate(['id' => $info['id']], 'fans_num');
            //自己关注数量+1
            $this->dao->incUpdate(['relation_id' => $uid], 'follow_num');
            $communityRecordServices->save([
                'uid' => $authorUid,
                'type' => $type,
                'relation_id' => $uid,
                'record' => CommunityRecordServices::SET_TYPE_FOLLOW,
                'add_time' => time()
            ]);
        } else {
            if (!$check) throw new ValidateException('您还未关注哦～');
            $communityRelevanceServices->destory($uid, $info['id'], CommunityRelevanceServices::TYPE_COMMUNITY_INTEREST);
            //粉丝数量-1
            $this->dao->decUpdate(['id' => $info['id']], 'fans_num');
            //自己关注数量-1
            $this->dao->decUpdate(['relation_id' => $uid], 'follow_num');
        }
        $communityCacheServices->setLike($info['id'], $uid, $status, CommunityCacheServices::COMMUNITY_INTEREST);
    }

    /**
     * 查询10条关注用户头像,是否发新作品
     * @param int $uid
     * @return array
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @throws \ReflectionException
     */
    public function follow(int $uid)
    {
        /** @var CommunityRelevanceServices $communityRelevanceServices */
        $communityRelevanceServices = app()->make(CommunityRelevanceServices::class);
        /** @var CommunityCacheServices $communityCacheServices */
        $communityCacheServices = app()->make(CommunityCacheServices::class);
        /** @var CommunityUserServices $communityUserServices */
        $communityUserServices = app()->make(CommunityUserServices::class);

        $where['type'] = CommunityRelevanceServices::TYPE_COMMUNITY_INTEREST;
        $where['left_id'] = $uid;
        $list = $communityRelevanceServices->search($where)->column('right_id');
        if (!$list) return [];
        $netFollow = $followIds = [];
        foreach ($list as $k => $v) {
            if ($communityCacheServices->checkCommunityNewest($uid, $v)) {
                $netFollow[] = $v;
                unset($list[$k]);
            }
        }
        $remainingCount = 10 - count($netFollow);
        if ($remainingCount > 0) {
            $followIds = array_slice($list, 0, $remainingCount);
            $followIds = array_merge($netFollow, $followIds);
        }
        $followIds = $followIds ?: $netFollow;
        $followList = $communityUserServices->search(['id' => $followIds])->orderRaw('FIELD(id,' . implode(',', $followIds) . ')')->select()->toArray();
        $data = [];
        foreach ($followList as &$item) {
            $userFormat = $this->getUserFormat($item['type'], $item['relation_id']);
            if (!$userFormat) continue;
            $user['author'] = $userFormat['author'] ?? '';
            $user['author_image'] = $userFormat['author_image'] ?? '';
            $user['relation_id'] = $item['relation_id'];
            $user['type'] = $item['type'];
            $user['is_new'] = in_array($item['id'], $netFollow) ? 1 : 0;
            $data[] = $user;
        }
        unset($item);
        return $data;
    }

    /**
     * 关注/粉丝列表
     * @param int $uid
     * @param string $type
     * @return array|Response
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @throws \ReflectionException
     */
    public function followList(int $uid, string $type = 'follow')
    {
        if (!$uid) {
            return app('json')->fail('参数错误');
        }
        [$page, $limit] = $this->getPageValue();

        /** @var CommunityRelevanceServices $communityRelevanceServices */
        $communityRelevanceServices = app()->make(CommunityRelevanceServices::class);
        /** @var CommunityCacheServices $communityCacheServices */
        $communityCacheServices = app()->make(CommunityCacheServices::class);
        $where['type'] = CommunityRelevanceServices::TYPE_COMMUNITY_INTEREST;
        $info = $this->dao->getUserInfo($uid, 2);
        $with = ['communityUser'];
        if ($type == 'follow') {
            $where['left_id'] = $uid;
        } else {
            $where['right_id'] = $info['id'];
            $with = ['communityFans'];
        }
        $list = $communityRelevanceServices->search($where)->with($with)->page($page, $limit)->order('id desc')->select()->toArray();
        $data = [];
        foreach ($list as $item) {
            $communityUser = $type == 'follow' ? ($item['communityUser'] ?? []) : ($item['communityFans'] ?? []);
            $user = [];
            if ($communityUser) {
                $userFormat = $this->getUserFormat($communityUser['type'], $communityUser['relation_id']);
                if (!$userFormat) continue;
                $user['author'] = $userFormat['author'] ?? '';
                $user['author_image'] = $userFormat['author_image'] ?? '';
                $user['community_num'] = $communityUser['community_num'];
                $user['fans_num'] = $communityUser['fans_num'];
                $user['relation_id'] = $communityUser['relation_id'];
                if ($type == 'follow') {
                    //我关注对方
                    $user['is_follow'] = 1;
                    $user['is_fans'] = $communityCacheServices->checkUserLike($uid, $communityUser['id'], CommunityCacheServices::COMMUNITY_INTEREST);
                } else {
                    $user['is_fans'] = 1;
                    $user['is_follow'] = $communityCacheServices->checkUserLike($communityUser['id'], $uid, CommunityCacheServices::COMMUNITY_INTEREST);
                }
            }
            if ($user) $data[] = $user;
        }
        $key = $type == 'follow' ? 'follow_num' : 'fans_num';
        $this->dao->update($info['id'], [$key => count($data)]);
        return $data;
    }

    /**
     * 我的好友
     * @param $uid
     * @return array
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public function userFriend($uid)
    {
        /** @var UserFriendsServices $services */
        $services = app()->make(UserFriendsServices::class);
        /** @var CommunityCacheServices $communityCacheServices */
        $communityCacheServices = app()->make(CommunityCacheServices::class);

        [$page, $limit] = $this->getPageValue();
        $uids = $services->getFriendUids($uid);
        $uids = array_diff($uids, [$uid]);
        if (!$uids) {
            return [];
        }
        $data = [];
        $list = $this->dao->getList(['relation_id' => $uids], '*', [], $page, $limit);
        foreach ($list as $item) {
            $userFormat = $this->getUserFormat($item['type'], $item['relation_id']);
            if (!$userFormat) continue;
            $item['author'] = $userFormat['author'] ?? '';
            $item['author_image'] = $userFormat['author_image'] ?? '';
            $item['is_fans'] = $communityCacheServices->checkUserLike($uid, $item['relation_id'], CommunityCacheServices::COMMUNITY_INTEREST);
            $item['is_follow'] = $communityCacheServices->checkUserLike($item['id'], $uid, CommunityCacheServices::COMMUNITY_INTEREST);
            $data[] = $item;
        }
        return $data;
    }

    /**
     * 用户头像昵称
     * @param int $type
     * @param int $uid
     * @return string[]
     */
    public function getUserFormat(int $type, int $uid, array $info = [])
    {
        /** @var UserServices $userServices */
        $userServices = app()->make(UserServices::class);
        /** @var SystemStoreServices $storeServices */
        $storeServices = app()->make(SystemStoreServices::class);
        if ($type == 2) {//用户
            $userInfo = $userServices->getUserCacheInfo(($uid));
            if (!$userInfo) return [];
            $data['author'] = $userInfo['nickname'] ?? '';
            $data['author_image'] = $userInfo['avatar'] ?? sys_config('h5_avatar');
            $data['logout'] = $userInfo ? 0 : 1;
        } else if ($type == 1) {//门店
            $storeInfo = $storeServices->get($uid);
            if (!$storeInfo) return [];
            $data['author'] = $storeInfo['name'] ?? '';
            $data['author_image'] = $storeInfo['image'] ?? '';
            $address = $storeInfo['address'] ?? '';
            $detailed_address = $storeInfo['detailed_address'] ?? '';
            $data['address'] = $address . $detailed_address;
        } else if ($type == 3) {//虚拟
            $data['author'] = $info['nickname'] ?? '';
            $data['author_image'] = $info['avatar'] ?? '';
        } else {//平台
            $data['author'] = sys_config('site_name');
            $data['author_image'] = sys_config('wap_login_logo');
        }
        return $data;
    }

    /**
     * 推荐
     * @param int $uid
     * @return array
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public function recommendList(int $uid)
    {
        [$page, $limit] = $this->getPageValue();
        /** @var CommunityRelevanceServices $communityRelevanceServices */
        $communityRelevanceServices = app()->make(CommunityRelevanceServices::class);
        /** @var CommunityCacheServices $communityCacheServices */
        $communityCacheServices = app()->make(CommunityCacheServices::class);
        $ids = $communityRelevanceServices->getColumn(['left_id' => $uid, 'type' => CommunityRelevanceServices::TYPE_COMMUNITY_INTEREST], 'right_id');
        if ($uid) {
            $info = $this->dao->getUserInfo($uid, 2);
            $ids = array_merge($ids, [$info['id']]);
        }

        $list = $this->dao->getList(['not_id' => $ids, 'is_del' => 0, 'status' => 1, 'is_community_num' => 1], 'id,type,relation_id,community_num,fans_num', ['community'], $page, $limit, 'fans_num desc');
        foreach ($list as &$item) {
            $userFormat = $this->getUserFormat($item['type'], $item['relation_id']);
            $item['author'] = $userFormat['author'] ?? '';
            $item['author_image'] = $userFormat['author_image'] ?? '';
            $item['is_follow'] = $communityCacheServices->checkUserLike($item['id'], $uid, CommunityCacheServices::COMMUNITY_INTEREST);
        }
        unset($item);
        return $list;
    }

    /**
     * 用户发帖数据矫正
     * @param $id
     * @return void
     */
    public function syncUserNum($id, $type)
    {
        /** @var CommunityServices $communityServices */
        $communityServices = app()->make(CommunityServices::class);
        $relation_id = $communityServices->value(['id' => $id], 'relation_id');
        $count = $communityServices->getCount(['relation_id' => $relation_id, 'type' => $type, 'status' => 1, 'is_verify' => 1, 'is_del' => 0]);
        $this->dao->update(['relation_id' => $relation_id, 'type' => $type], ['community_num' => $count]);
    }

    /**
     * 用户注销后续事件
     * @param int $uid
     * @return bool|void
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public function logoutAfter(int $uid = 0)
    {
        $communityUser = $this->dao->get(['type' => 2, 'relation_id' => $uid]);
        if (!$communityUser) return true;
        //删除帖子
        $communityServices = app()->make(CommunityServices::class);
        $communityIds = $communityServices->getColumn(['type' => 2, 'relation_id' => $uid], 'id');
        foreach ($communityIds as $communityId) {
            event('community.delete', [$communityId]);
            //帖子操作事件
            event('community.operate', [$communityId, 0, 2]);
        }
        $communityServices->update(['type' => 2, 'relation_id' => $uid], ['is_del' => 1]);
        //清空关注
        $communityRelevanceServices = app()->make(CommunityRelevanceServices::class);
        $authorUids = $communityRelevanceServices->getColumn(['left_id' => $uid, 'type' => CommunityRelevanceServices::TYPE_COMMUNITY_INTEREST], 'right_id');
        $communityRelevanceServices->delete(['left_id' => $uid, 'type' => CommunityRelevanceServices::TYPE_COMMUNITY_INTEREST]);
        //删除关注我的
        $authorMyUids = $communityRelevanceServices->getColumn(['right_id' => $communityUser['id'], 'type' => CommunityRelevanceServices::TYPE_COMMUNITY_INTEREST], 'left_id');
        $communityRelevanceServices->delete(['right_id' => $communityUser['id'], 'type' => CommunityRelevanceServices::TYPE_COMMUNITY_INTEREST]);
        //减粉丝数量
        if ($authorUids) {
            $this->dao->decUpdate(['id' => $authorUids], 'fans_num');
        }
        if ($authorMyUids) {
            $this->dao->decUpdate(['id' => $authorUids], 'follow_num');
        }

        //删除社区用户
        $this->dao->delete(['type' => 2, 'relation_id' => $uid]);
        //我的好友处理
        /** @var UserFriendsServices $services */
        $friendsServices = app()->make(UserFriendsServices::class);
        $friendsServices->delete(['uid' => $uid]);
        $friendsServices->delete(['friends_uid' => $uid]);
        return true;
    }
}
