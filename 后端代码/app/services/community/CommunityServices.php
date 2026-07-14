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

use app\dao\community\CommunityDao;
use app\services\BaseServices;
use app\services\product\product\StoreProductServices;
use app\services\product\category\StoreProductCategoryServices;
use app\services\store\SystemStoreServices;
use app\services\user\UserBillServices;
use app\services\user\UserServices;
use app\services\wechat\WechatUserServices;
use mohe\services\FormBuilder as Form;
use mohe\services\wechat\MiniProgram;
use mohe\traits\OptionTrait;
use think\db\exception\DataNotFoundException;
use think\db\exception\DbException;
use think\db\exception\ModelNotFoundException;
use think\exception\ValidateException;
use think\facade\Db;
use think\facade\Route as Url;


/**
 * 社区
 * Class CommunityServices
 * @package app\services\community
 * @mixin CommunityDao
 */
class CommunityServices extends BaseServices
{

    use OptionTrait;

    /**
     * 移动端社区内容需要默认值
     * @var array
     */
    protected $videoDefault = [
        'isMore' => false,
        'state' => "pause",
        'playIng' => false,
        'isShowimage' => false,
        'isShowProgressBarTime' => false,
        'isplay' => true
    ];

    /**
     * CommunityServices constructor.
     * @param CommunityDao $dao
     */
    public function __construct(CommunityDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 获取话题信息（获取不到抛出异常）
     * @param int $id
     * @param string $field
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getCommunityInfo(int $id, string $field = '*')
    {
        $videoInfo = $this->dao->getOne(['id' => $id], $field);
        if (!$videoInfo) {
            throw new ValidateException('获取社区内容信息失败');
        }
        return $videoInfo->toArray();
    }

    /**
     * 获取社区内容详情
     * @param int $id
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getInfo(int $id)
    {
        $info = $this->dao->get($id);
        if (!$info) {
            throw new ValidateException('社区内容不存在');
        }
        $info = $info->toArray();
        $site_name = sys_config('site_name');
        $site_image = sys_config('wap_login_logo');
        $info['author'] = $site_name;
        $info['author_image'] = $site_image;
        if ($info['type'] == 2) {//用户
            /** @var UserServices $userServices */
            $userServices = app()->make(UserServices::class);
            $userInfo = $userServices->getUserCacheInfo((int)$info['relation_id']);
            $info['author'] = $userInfo['nickname'] ?? '';
            $info['author_image'] = $userInfo['avatar'] ?? '';
        }
        $topic = [];
        if ($info['topic_id']) {
            $info['topic_id'] = is_string($info['topic_id']) ? explode(',', $info['topic_id']) : $info['topic_id'];
            /** @var CommunityTopicServices $communityTopicServices */
            $communityTopicServices = app()->make(CommunityTopicServices::class);
            $topic = $communityTopicServices->getColumn([['id', 'in', $info['topic_id']]], 'id,name');
        }
        $info['topic'] = $topic;
        $productInfo = [];
        if ($info['product_id']) {
            $info['product_id'] = is_string($info['product_id']) ? explode(',', $info['product_id']) : $info['product_id'];
            /** @var StoreProductServices $productServices */
            $productServices = app()->make(StoreProductServices::class);
            $product_where = ['is_del' => 0, 'is_show' => 1, 'is_verify' => 1];
            $productInfo = $productServices->getSearchList($product_where + ['ids' => $info['product_id']], 0, 0, ['id', 'type', 'product_type', 'price', 'image', 'store_name', 'cate_id', 'sales', 'stock'], '', []);
            if ($productInfo) {
                $cateIds = implode(',', array_column($productInfo, 'cate_id'));
                /** @var StoreProductCategoryServices $categoryService */
                $categoryService = app()->make(StoreProductCategoryServices::class);
                $cateList = $categoryService->getCateParentAndChildName($cateIds);
                foreach ($productInfo as $key => &$item) {
                    $item['cate_name'] = '';
                    if (isset($item['cate_id']) && $item['cate_id']) {
                        $cate_ids = explode(',', $item['cate_id']);
                        $cate_name = $categoryService->getCateName($cate_ids, $cateList);
                        if ($cate_name) {
                            $item['cate_name'] = is_array($cate_name) ? implode(',', $cate_name) : '';
                        }
                    }
                }
            }
        }
        $info['productInfo'] = $productInfo;
        return $info;
    }

    /**
     * 社区顶部header
     * @param array $where
     * @return array[]
     * @throws \think\db\exception\DbException
     */
    public function getHeader(array $where = [])
    {
        if (isset($where['store_id']) && $where['store_id']) {
            $where['type'] = 1;
            $where['relation_id'] = $where['store_id'];
            unset($where['store_id']);
        }
        //已发布
        $onPublish = $this->dao->count(['is_verify' => 1] + $where);
        //待审核
        $unVerify = $this->dao->count(['is_verify' => 0] + $where);
        //审核未通过
        $verifyFail = $this->dao->count(['is_verify' => -1] + $where);
        //强制下架
        $remove = $this->dao->count(['is_verify' => -2] + $where);
        return [
            ['is_verify' => 1, 'name' => '已发布', 'count' => $onPublish],
            ['is_verify' => 0, 'name' => '待审核', 'count' => $unVerify],
            ['is_verify' => -1, 'name' => '审核未通过', 'count' => $verifyFail],
            ['is_verify' => -2, 'name' => '强制下架', 'count' => $remove],
        ];
    }

    /**
     * 社区内容列表
     * @param $where
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getList(array $where, array $with = [], string $order = '', int $uid = 0)
    {
        [$page, $limit] = $this->getPageValue();
        if (isset($where['store_id']) && $where['store_id']) {
            $where['type'] = 1;
            $where['relation_id'] = $where['store_id'];
            unset($where['store_id']);
        }
        $list = $this->dao->getList($where, '*', $page, $limit, $with, $order);
        $count = $this->dao->count($where);
        $site_name = sys_config('site_name');
        $site_image = sys_config('wap_login_logo');
        /** @var UserServices $userServices */
        $userServices = app()->make(UserServices::class);
        $communityTopicServices = app()->make(CommunityTopicServices::class);

        $topicList = array_column($list, 'topic');
        $topIds = [];
        foreach ($topicList as $topic) {
            foreach ($topic as $item) {
                if (!isset($topIds[$item['right_id']])) {
                    $topIds[$item['right_id']] = $item['right_id'];
                }
            }
        }

        $_topicList = $communityTopicServices->getColumn(['id' => $topIds], 'name', 'id', true);
        /** @var CommunityUserServices $communityUserServices */
        $communityUserServices = app()->make(CommunityUserServices::class);
        /** @var CommunityRelevanceServices $relevanceServices */
        $relevanceServices = app()->make(CommunityRelevanceServices::class);
        foreach ($list as &$item) {
            $topicName = array_filter(array_map(function ($v) use ($_topicList) {
                return $_topicList[$v['right_id']] ?? null;
            }, $item['topic']));
            $item['topicName'] = implode(',', $topicName);
            $item['product_sum'] = $relevanceServices->count(['left_id' => $item['id'], 'type' => CommunityRelevanceServices::TYPE_COMMUNITY_PRODUCT]);
            $userFormat = $communityUserServices->getUserFormat($item['type'], $item['relation_id']);
            $item['author'] = $userFormat['author'] ?? '';
            $item['author_image'] = $userFormat['author_image'] ?? '';
        }
        return compact('list', 'count');
    }

    /**
     * 移动端列表
     * @param array $where
     * @param $uid
     * @return array
     * @throws \ReflectionException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getApiList(array $where, int $uid = 0, string $order = 'add_time desc')
    {
        [$page, $limit] = $this->getPageValue();
        /** @var StoreProductServices $productServices */
        $productServices = app()->make(StoreProductServices::class);
        /** @var CommunityRelevanceServices $relevanceServices */
        $relevanceServices = app()->make(CommunityRelevanceServices::class);
//        $appendWhere = $productServices->getWhereByEntryRules($uid);
        $order_by_id = [];
        if (isset($where['start_id']) && $where['start_id']) {
            $start_id = $where['start_id'];
            unset($where['start_id']);
            $order_by_id = array_merge([(int)$start_id], $this->dao->getIdColumn(array_merge(['notId' => $start_id], $where), 'id', $limit));
        }
        $list = $this->dao->search($where)
            ->with(
                [
                    'product' => function ($product) {
                        $product->with(['product']);
                    },
                    'topic' => function ($topic) {
                        $topic->with(['topic']);
                    }
                ]
            )->when(true, function ($query) use ($order_by_id, $order) {
                if ($order_by_id) {
                    $query->whereIn('id', $order_by_id)->orderRaw('FIELD(id,' . implode(',', $order_by_id) . ')');
                } else {
                    $query->order($order);
                }
            })
            ->page($page, $limit)->select()->toArray();

        /** @var CommunityCacheServices $communityCacheServices */
        $communityCacheServices = app()->make(CommunityCacheServices::class);
        /** @var CommunityUserServices $communityUserServices */
        $communityUserServices = app()->make(CommunityUserServices::class);

        foreach ($list as &$item) {
            $item['time_text'] = timeConverter(strtotime($item['add_time']));
            $userFormat = $communityUserServices->getUserFormat($item['type'], $item['relation_id']);
            $item['author'] = $userFormat['author'] ?? '';
            $item['author_image'] = $userFormat['author_image'] ?? '';
            $item['address'] = $userFormat['address'] ?? '';
            if ($item['product']) {
                $productList = [];
                $productIds = [];
                foreach ($item['product'] as $pro) {
                    if (isset($pro['product']) && $pro['product']) {
                        $productIds[] = $pro['product']['id'];
                        $productList[] = $pro['product'];
                    }
                }
                $productCount = count($productList);
                $item['product'] = $productList;
                $item['product_id'] = $productIds;
                $item['productCount'] = $productCount;
            }
            if ($item['topic']) {
                $topicList = [];
                foreach ($item['topic'] as $top) {
                    if (isset($top['topic']) && $top['topic']) {
                        $topicList[] = $top['topic'];
                    }
                }
                $item['topic'] = $topicList;
            }
            if ($uid) {
                //是否是自己
                $item['is_self'] = $uid == $item['relation_id'] ? 1 : 0;
                //是否点赞
                $item['is_like'] = $communityCacheServices->checkUserLike($item['id'], $uid);
                //是否关注
                $item['is_follow'] = $communityCacheServices->checkUserLike($item['community_user_id'], $uid, CommunityCacheServices::COMMUNITY_INTEREST);
            }
        }

        return $list;
    }

    /**
     * 移动端详情
     * @param $id
     * @param int $uid
     * @return array
     * @throws \ReflectionException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getDetail(int $id, int $uid = 0, $is_admin = false)
    {
        /** @var CommunityUserServices $communityUserServices */
        $communityUserServices = app()->make(CommunityUserServices::class);
        /** @var CommunityCommentServices $commentServices */
        $commentServices = app()->make(CommunityCommentServices::class);
        /** @var CommunityCacheServices $communityCacheServices */
        $communityCacheServices = app()->make(CommunityCacheServices::class);
        /** @var StoreProductServices $productServices */
        $productServices = app()->make(StoreProductServices::class);
        /** @var CommunityRelevanceServices $relevanceServices */
        $relevanceServices = app()->make(CommunityRelevanceServices::class);
        $where = [
            'id' => $id,
            'is_del' => 0
        ];
        $info = $this->dao->search($where)
            ->with(
                [
                    'product' => function ($product) {
                        $product->with(['product']);
                    },
                    'topic' => function ($topic) {
                        $topic->with(['topic']);
                    }
                ]
            )->find();
        if (!$info) {
            throw new ValidateException('数据不存在！');
        }
        if (($info['is_verify'] != 1 || $info['status'] != 1) && $uid != $info['relation_id'] && !$is_admin) {
            throw new ValidateException('该种草状态有误！');
        }
        $info = $info->toArray();
        $userFormat = $communityUserServices->getUserFormat($info['type'], $info['relation_id']);
        $info['author'] = $userFormat['author'] ?? '';
        $info['author_image'] = $userFormat['author_image'] ?? '';
        $info['is_self'] = $uid == $info['relation_id'] ? 1 : 0;
		$info['productCount'] = 0;
        if ($info['product']) {
            $productList = [];
            $productIds = [];
            foreach ($info['product'] as $pro) {
                if (isset($pro['product']) && $pro['product']) {
                    $productIds[] = $pro['product']['id'];
                    $productList[] = $pro['product'];
                }
            }
            $info['product'] = $productList;
            $info['product_id'] = $productIds;
            $info['productCount'] = count($productList);
        }
        $pending_approval_reply_sum = $commentServices->count(['community_id'=>$id,'uid'=>$uid,'is_verify' => 0,'is_show' => 0,'is_del' => 0]);
        $info['pending_approval_reply_sum'] = $pending_approval_reply_sum;
        $info['comment_num'] = $info['comment_num'] + $pending_approval_reply_sum;
        if ($is_admin) {
            $communityRelevanceServices = app()->make(CommunityRelevanceServices::class);
            $communityTopicServices = app()->make(CommunityTopicServices::class);
            $topicIds = $communityRelevanceServices->search(['left_id' => $id, 'type' => CommunityRelevanceServices::TYPE_COMMUNITY_TOPIC])->column('right_id');
            $info['topic'] = count($topicIds) ? $communityTopicServices->getList(['id' => $topicIds], 'id,name') : [];
        } else {
            if ($info['topic']) {
                $topicList = [];
                foreach ($info['topic'] as $top) {
                    if (isset($top['topic']) && $top['topic']) {
                        $topicList[] = $top['topic'];
                    }
                }
                $info['topic'] = $topicList;
            }
        }
        $info['is_like'] = $info['is_follow'] = 0;
        if ($uid) {
            //是否点赞
            $info['is_like'] = $communityCacheServices->checkUserLike($info['id'], $uid);
            //是否关注
            $info['is_follow'] = $communityCacheServices->checkUserLike($info['community_user_id'], $uid, CommunityCacheServices::COMMUNITY_INTEREST);
        }
        $this->setBrowse($id, $uid);
        return $info;
    }

    /**
     * 增加浏览量
     *
     * 该方法用于增加特定内容的浏览量，并记录用户的浏览行为。它首先检查是否存在指定的内容，
     * 如果内容存在，则增加其浏览量。同时，通过调用缓存服务检查用户是否已经浏览过该内容，
     * 如果没有，则记录用户的浏览行为，并通过相关服务创建一条浏览相关的记录。
     *
     * @param int $id 内容ID，标识要增加浏览量的内容。
     * @param int $uid 用户ID，标识正在浏览内容的用户。
     *
     * @return bool 总是返回true，表示操作成功。
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public function setBrowse(int $id, int $uid = 0): bool
    {
        // 获取内容信息
        $info = $this->dao->get($id);
        if (!$info) return true;
        $info->play_num = $info->play_num + 1;
        $info->save();
        if (!$uid) return true;

        // 浏览记录
        /** @var CommunityCacheServices $communityCacheServices */
        $communityCacheServices = app()->make(CommunityCacheServices::class);
        /** @var CommunityRelevanceServices $communityRelevanceServices */
        $communityRelevanceServices = app()->make(CommunityRelevanceServices::class);
        // 检查用户是否已经浏览过该内容
        if (!$communityCacheServices->checkUserLike($id, $uid, CommunityCacheServices::COMMUNITY_BROWSE)) {
            // 如果用户未浏览过，记录用户的浏览行为
            $communityCacheServices->setLike($id, $uid, 1, CommunityCacheServices::COMMUNITY_BROWSE);
            // 创建一条浏览相关的记录
            $communityRelevanceServices->create($uid, $id, CommunityRelevanceServices::TYPE_COMMUNITY_BROWSE, true);
        }
        return true;
    }

    /**
     * 设置推荐指数表单
     * @param int $id
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function setStarForm(int $id)
    {
        $info = [];
        if ($id) {
            $info = $this->getCommunityInfo($id);
        }
        $field[] = Form::rate('star', '推荐指数', $info['star'] ?? 1)->allowHalf(false);
        return create_form('推荐指数', $field, Url::buildUrl('/community/community/star/' . $id), 'POST');
    }

    /**
     * 商品审核表单
     * @param int $id
     * @param int $is_verify
     * @return mixed
     */
    public function verifyForm(int $id, int $is_verify = 1)
    {
        $f = [];
        if ($is_verify == 1) {
            $f[] = Form::radio('is_verify', '审核状态：', 1)->options([['value' => 1, 'label' => '通过'], ['value' => -1, 'label' => '拒绝']])->appendControl(-1, [
                Form::textarea('refusal', '拒绝原因：')->required('请输入拒绝原因')]);
        } else {
            $f[] = Form::hidden('is_verify', '-2');
            $f[] = Form::textarea('refusal', '下架原因：')->required('请输入下架原因');
        }
        return create_form($is_verify == 1 ? '内容审核' : '强制下架', $f, Url::buildUrl('/community/community/set_verify/' . $id), 'post');
    }

	/**
	 * 社区内容审核
	 * @param int $id
	 * @param array $data
	 * @return bool
	 * @throws DataNotFoundException
	 * @throws DbException
	 * @throws ModelNotFoundException
	 */
	public function setVerify(int $id, array $data)
	{
		$data['verify_time'] = time();
		$info = $this->dao->get($id);
		if (!$info) {
			throw new ValidateException('社区内容不存在');
		}
		$this->dao->update((int)$id, $data);
		if ($data['is_verify'] == 1) {
			/** @var CommunityCacheServices $communityCacheServices */
			$communityCacheServices = app()->make(CommunityCacheServices::class);
			$communityCacheServices->setCommunityNewest((int)$info['relation_id'], (int)$id);
		}
		event('community.operate', [$id, 1, 0]);
		return true;
	}

    /**
     * 社区内容验证
     * @param $data
     * @return mixed
     */
    public function verify($data)
    {
        if (isset($data['title']) && $data['title']) {
            $response = MiniProgram::msgSecCheck($data['title']);
            if (isset($response['errcode']) && $response['errcode'] != 0 && isset($response['errmsg']) && $response['errmsg'] != 'ok') {
                throw new ValidateException('标题违规');
            }
        }
        if (isset($data['content']) && $data['content']) {
            $response = MiniProgram::msgSecCheck($data['content']);
            if (isset($response['errcode']) && $response['errcode'] != 0 && isset($response['errmsg']) && $response['errmsg'] != 'ok') {
                throw new ValidateException('社区内容违规');
            }
        }
        if (isset($data['image']) && $data['image']) {
            $response = MiniProgram::mediaCheckAsync($data['image'], 2);
            if (isset($response['errcode']) && $response['errcode'] != 0 && isset($response['errmsg']) && $response['errmsg'] != 'ok') {
                throw new ValidateException('社区封面违规');
            }
        }
        if (isset($data['video_url']) && $data['video_url']) {
            $response = MiniProgram::mediaCheckAsync($data['video_url'], 1);
            if (isset($response['errcode']) && $response['errcode'] != 0 && isset($response['errmsg']) && $response['errmsg'] != 'ok') {
                throw new ValidateException('音视频违规');
            }
        }
        if (isset($data['slider_image']) && $data['slider_image']) {
            $errCount = 0;
            foreach ($data['slider_image'] as $k => $img) {
                $response = MiniProgram::mediaCheckAsync($img, 2);
                if (isset($response['errcode']) && $response['errcode'] != 0 && isset($response['errmsg']) && $response['errmsg'] != 'ok') {
                    $errCount += 1;
                }
            }
            if ($errCount > 0) {
                throw new ValidateException('图集有' . $errCount . '张图片违规');
            }
        }
        return $data;
    }

    /**
     * 添加
     * @param array $data
     * @param int $id
     * @param int $type
     * @return bool
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public function saveData(array $data, int $id = 0, int $type = 2)
    {
        $data['type'] = $type;
        $extractedData = [
            'topic_id' => $data['topic_id'],
            'topic_name' => $data['topic_name'] ?? [],
            'product_id' => $data['product_id']
        ];
        unset($data['topic_id'], $data['product_id']);
        //敏感词
//        MiniProgram::msgSecCheck($data['title'] . $data['content']);

        $id = $this->transaction(function () use ($data, $id, $extractedData, $type) {
            $relation_id = $data['relation_id'];
            if ($id) {
                unset($data['type'], $data['relation_id']);
                $data['refusal'] = '';
                $res = $this->dao->update($id, $data);
                $this->extractedDelete($id);
            } else {
                $data['add_time'] = time();
                $res = $this->dao->save($data);
                if ($data['is_verify'] == 1) {
                    /** @var CommunityCacheServices $communityCacheServices */
                    $communityCacheServices = app()->make(CommunityCacheServices::class);
                    $communityCacheServices->setCommunityNewest((int)$data['relation_id'], (int)$res->id);
                }
            }
            /** @var CommunityUserServices $communityUserServices */
            $communityUserServices = app()->make(CommunityUserServices::class);
            $community_user_id = 0;
            if ($type == 0 && !$communityUserServices->get(['type' => 0, 'relation_id' => 0])) {
                $communityUser = $communityUserServices->save(['type' => 0, 'relation_id' => 0, 'add_time' => time()]);
                $community_user_id = $communityUser->id;
            } elseif ($type == 1 && !$communityUserServices->get(['type' => 1, 'relation_id' => $relation_id])) {
                /** @var SystemStoreServices $storeServices */
                $storeServices = app()->make(SystemStoreServices::class);
                $storeInfo = $storeServices->get($relation_id);
                $communityUser = $communityUserServices->save(['type' => 1, 'nickname' => $storeInfo['name'], 'avatar' => $storeInfo['image'], 'relation_id' => $relation_id, 'add_time' => time()]);
                $community_user_id = $communityUser->id;
            } else if (!$id) {
                $info = $communityUserServices->getOne(['type' => $type, 'relation_id' => $relation_id]);
                $community_user_id = $info['id'];
            }
            if (!$id) {
                $this->dao->update($res->id, ['community_user_id' => $community_user_id]);
            }
            $id = $id ?: $res->id;
            $this->extractedFormat($id, $extractedData);
            return $id;
        });
        event('community.operate', [$id, 1, $type]);
        return true;
    }

    /**
     * 添加关联
     * @param $id
     * @param $data
     * @return void
     */
    public function extractedFormat($id, $data)
    {
        /** @var CommunityRelevanceServices $relevanceServices */
        $relevanceServices = app()->make(CommunityRelevanceServices::class);
        $communityTopicServices = app()->make(CommunityTopicServices::class);
        $save = [];


        if (count($data['topic_name']) > 0) {
            foreach ($data['topic_name'] as $v) {
                if(!$v) continue;
                $topicInfo = $communityTopicServices->get(['name' => $v]);

                $topicId = $topicInfo ? $topicInfo['id'] : $communityTopicServices->save([
                    'name' => $v,
                    'type' => 2,
                    'is_del' => -1,
                    'add_time' => time()
                ])->id;

                $save[] = [
                    'left_id' => $id,
                    'right_id' => $topicId,
                    'type' => CommunityRelevanceServices::TYPE_COMMUNITY_TOPIC
                ];
            }
        }

        if (count($data['topic_id']) > 0) {
            foreach ($data['topic_id'] as $v) {
                $save[] = [
                    'left_id' => $id,
                    'right_id' => $v,
                    'type' => CommunityRelevanceServices::TYPE_COMMUNITY_TOPIC
                ];
            }
        }

        if (count($data['product_id']) > 0) {
            foreach ($data['product_id'] as $v) {
                $save[] = [
                    'left_id' => $id,
                    'right_id' => $v,
                    'type' => CommunityRelevanceServices::TYPE_COMMUNITY_PRODUCT
                ];
            }
        }
        if (count($save) > 0) {
            $relevanceServices->saveAll($save);
        }
    }

    /**
     * 删除关联
     * @param $id
     * @return void
     */
    public function extractedDelete($id)
    {
        /** @var CommunityRelevanceServices $relevanceServices */
        $relevanceServices = app()->make(CommunityRelevanceServices::class);
        $rel_types = [CommunityRelevanceServices::TYPE_COMMUNITY_TOPIC, CommunityRelevanceServices::TYPE_COMMUNITY_PRODUCT];
        $relevanceServices->batchDelete($id, $rel_types);
    }

    /**
     * 点赞取消点赞
     * @param array $info
     * @param int $uid
     * @param int $status
     * @return void
     */
    public function setCommunityLike(array $info, int $uid, int $status)
    {
        /** @var CommunityRelevanceServices $communityRelevanceServices */
        $communityRelevanceServices = app()->make(CommunityRelevanceServices::class);
        //是否存在点赞
        $check = $communityRelevanceServices->checkHas($uid, $info['id'], CommunityRelevanceServices::TYPE_COMMUNITY_LIKE);

        if ($status) {
            if ($check) throw new ValidateException('您已经点过赞了～');
            $communityRelevanceServices->create($uid, $info['id'], CommunityRelevanceServices::TYPE_COMMUNITY_LIKE, true);
        } else {
            if (!$check) throw new ValidateException('您还未赞过哦～');
            $communityRelevanceServices->destory($uid, $info['id'], CommunityRelevanceServices::TYPE_COMMUNITY_LIKE);
        }
        event('community.like', [$info, $uid, 1, $status]);
    }

    /**
     * 点赞列表/商品种草秀
     * @param int $uid
     * @return array
     * @throws \ReflectionException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getJoinCommunityList(array $where = [], int $uid = 0, string $condition = 'right_id', int $limit = 0, string $order = 'r.id DESC')
    {
        /** @var CommunityRelevanceServices $communityRelevanceServices */
        $communityRelevanceServices = app()->make(CommunityRelevanceServices::class);
        /** @var CommunityUserServices $communityUserServices */
        $communityUserServices = app()->make(CommunityUserServices::class);
        /** @var CommunityCacheServices $communityCacheServices */
        $communityCacheServices = app()->make(CommunityCacheServices::class);
        if (!$limit) {
            [$page, $limit] = $this->getPageValue();
        }
        $list = $communityRelevanceServices->joinCommunityList($where, $page ?? 0, $limit, $condition, $order);
        $count = $communityRelevanceServices->joinCommunity($where, $condition)->count();
        foreach ($list as &$item) {
            if (!($item['c_id'] ?? '')) continue;
            $userFormat = $communityUserServices->getUserFormat($item['c_type'], $item['relation_id']);
            $item['author'] = $userFormat['author'] ?? '';
            $item['author_image'] = $userFormat['author_image'] ?? '';
            $item['slider_image'] = $item['slider_image'] ? json_decode($item['slider_image'], true) : [];
            $item['id'] = $item['c_id'];
            $item['is_like'] = $communityCacheServices->checkUserLike($item['id'], $uid);
            unset($item['c_id'], $item['left_id'], $item['right_id'], $item['type']);
        }
        unset($item);
        return compact('list', 'count');
    }

    /**
     * 分享
     * @param $id
     * @param $uid
     * @return true
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function communityShare($id)
    {
        $info = $this->dao->get(['id' => $id, 'is_del' => 0]);
        if (!$info) throw new ValidateException('帖子不存在');
        $info->share_num++;
        $info->save();
        return true;
    }

    /**
     * 帖子删除
     * @param $id
     * @param $uid
     * @param bool $is_admin
     * @return void
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function communityDelete(int $id, int $uid = 0, bool $is_admin = false)
    {
        $info = $this->dao->get(['id' => $id, 'is_del' => 0]);
        if (!$info) throw new ValidateException('帖子不存在');
        if (!$is_admin && $info['relation_id'] != $uid) throw new ValidateException('您没有权限删除该帖子');
        $info->is_del = 1;
        $info->save();
        event('community.delete', [$id]);
        //帖子操作事件
        event('community.operate', [$id, 0, $info['type']]);
    }

    /**
     * 删除后续事件
     * @param $id
     * @return void
     * @throws \ReflectionException
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public function deleteFollow($id)
    {
        /** @var CommunityUserServices $communityUserServices */
        $communityUserServices = app()->make(CommunityUserServices::class);
        /** @var CommunityCommentServices $communityCommentServices */
        $communityCommentServices = app()->make(CommunityCommentServices::class);
        /** @var CommunityRelevanceServices $relevanceServices */
        $relevanceServices = app()->make(CommunityRelevanceServices::class);
        /** @var CommunityCacheServices $communityCacheServices */
        $communityCacheServices = app()->make(CommunityCacheServices::class);

        $info = $this->dao->get($id);
        if (!$info) return;

        //帖子评论删除
        $communityCommentServices->update(['community_id' => $id], ['is_del' => 1]);

        //关联表处理

        $rel_types = [
            CommunityRelevanceServices::TYPE_COMMUNITY_TOPIC,
            CommunityRelevanceServices::TYPE_COMMUNITY_PRODUCT,
            CommunityRelevanceServices::TYPE_COMMUNITY_LIKE,
            CommunityRelevanceServices::TYPE_COMMUNITY_BROWSE
        ];
        $relevanceServices->batchDelete($id, $rel_types);

        //缓存删除
        $communityCacheServices->deleteKey($id);
    }

    /**
     * 话题帖子统计缓存
     * @param $id
     * @return mixed
     */
    public function topicCount(int $id)
    {
        return $this->dao->cacheTag()->remember(CommunityCacheServices::COMMUNITY_TOPIC_COUNT . $id, function () use ($id) {
            return $this->getTopicCount($id);
        }, 60 * 60);
    }

    /**
     * 话题帖子统计
     * @param $id
     * @return int
     * @throws DbException
     */
    public function getTopicCount(int $id)
    {
        /** @var CommunityRelevanceServices $communityRelevanceServices */
        $communityRelevanceServices = app()->make(CommunityRelevanceServices::class);
        return $communityRelevanceServices->joinCommunity(['topic_id' => $id], 'left_id')->count();
    }

    /**
     * 话题帖子统计数据矫正
     * @param $id
     * @return true
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @throws \ReflectionException
     */
    public function syncTopicNum(int $id)
    {
        /** @var CommunityRelevanceServices $communityRelevanceServices */
        $communityRelevanceServices = app()->make(CommunityRelevanceServices::class);
        $list = $communityRelevanceServices->search(['left_id' => $id, 'type' => CommunityRelevanceServices::TYPE_COMMUNITY_TOPIC])->select()->toArray();
        foreach ($list as $item) {
            $count = $this->getTopicCount($item['right_id']);
            $this->dao->cacheTag()->set(CommunityCacheServices::COMMUNITY_TOPIC_COUNT . $item['right_id'], $count);
        }
        return true;
    }


    /**
     * 发帖审核后奖励积分经验
     * @param $id //帖子id
     * @return true
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @throws \ReflectionException
     */
    public function giveIncome($id)
    {
        $info = $this->dao->get($id);
        // 经验开关
        $communityExp = sys_config('community_exp');
        // 积分开关
        $communityIntegral = sys_config('community_integral');

        // 检查是否启用了经验或积分系统，以及帖子是否审核
        if (!$communityExp && !$communityIntegral || !$info || $info['is_verify'] != 1 || $info['type'] != 2 || ($info['type'] == 2 && !$info['relation_id'])) {
            return true;
        }

        // 获取经验限制和每次增加的数量配置
        $expRestrict = sys_config('community_exp_restrict');
        $expNum = sys_config('community_exp_num');
        // 获取积分限制和每次增加的数量配置
        $integralNum = sys_config('community_integral_num');
        $integralRestrict = sys_config('community_integral_restrict');

        // 查询用户当天获得的经验和积分记录
        $timestamp = strtotime($info['add_time']);
        $startTime = strtotime('today', $timestamp);
        $endTime = strtotime('tomorrow', $timestamp);
        $userBillServices = app()->make(UserBillServices::class);
        $billList = $userBillServices->getDayOnline($info['relation_id'], $startTime, $endTime);
        $maxExpRestrict = $maxIntegralRestrict = 0;
        foreach ($billList as $item) {
            if ($item['type'] == 'community_add_exp') {
                $maxExpRestrict += $item['number'];
            } else {
                $maxIntegralRestrict += $item['number'];
            }
        }

        $userServices = app()->make(UserServices::class);
        $userInfo = $userServices->get($info['relation_id']);
        $updateData = [];

        // 检查是否需要增加经验,经验开启,且用户今天没有超过限制,有赠送经验,且帖子没有被记录过
        if ($communityExp && ($expRestrict == 0 || ($expRestrict > $maxExpRestrict && $expNum && !$userBillServices->get(['type' => 'community_add_exp', 'link_id' => $id])))) {
            $updateData['exp'] = bcadd((string)$userInfo['exp'], (string)$expNum, 0);
            $userBillServices->income('community_add_exp', $info['relation_id'], (int)$expNum, $updateData['exp'], $id, $timestamp);
        }

        // 检查是否需要增加积分,积分开启,且用户今天没有超过限制,有赠送积分,且帖子没有被记录过
        if ($communityIntegral && ($integralNum != 0 || ($integralRestrict > $maxIntegralRestrict && $integralNum && !$userBillServices->get(['type' => 'community_add_integral', 'link_id' => $id])))) {
            $updateData['integral'] = bcadd((string)$userInfo['integral'], (string)$integralNum, 0);
            $userBillServices->income('community_add_integral', $info['relation_id'], (int)$integralNum, $updateData['integral'], $id, $timestamp);
        }

        // 如果有更新数据，则更新用户信息
        if (!empty($updateData)) {
            $userServices->update($userInfo['uid'], $updateData);
        }

        return true;
    }
}
