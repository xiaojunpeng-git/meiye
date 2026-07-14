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

use app\dao\community\CommunityTopicDao;
use app\services\BaseServices;
use mohe\services\FormBuilder as Form;
use mohe\traits\OptionTrait;
use think\facade\Route as Url;


/**
 * 社区话题
 * Class CommunityTopicServices
 * @package app\services\community
 * @mixin CommunityTopicDao
 */
class CommunityTopicServices extends BaseServices
{
    use OptionTrait;

    /**
     * CommunityTopicServices constructor.
     * @param CommunityTopicDao $dao
     */
    public function __construct(CommunityTopicDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 获取所有的话题列表
     * @param array $where
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getAllTopic(array $where = [], $is_all = false)
    {
        $is_community = $where['is_community'] ?? '';
        unset($where['is_community']);

        [$page, $limit] = $this->getPageValue();
        if ($is_all) {
            $page = $limit = 0;
        }

        $list = $this->dao->getList($where, 'id,name', [], $page, $limit);
        if ($is_community) {
            foreach ($list as &$item) {
                $item['community_count'] = app()->make(CommunityServices::class)->topicCount($item['id']);
            }
        }
        return $list;
    }

    /**
     * 话题
     * @param array $where
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function sysPage(array $where)
    {
        $where['is_del'] = 0;
        [$page, $limit] = $this->getPageValue();
        $list = $this->dao->getList($where, '*', [], $page, $limit);
        if ($list) {
            /** @var CommunityServices $communityServices */
            $communityServices = app()->make(CommunityServices::class);
            foreach ($list as &$item) {
                $item['add_time'] = $item['add_time'] ? date('Y-m-d H:i:s', $item['add_time']) : '';
                $item['community_num'] = $communityServices->count(['topic_id' => $item['id']]);
            }
        }
        $count = $this->dao->count($where);
        return compact('list', 'count');
    }

    /**
     * 创建虚拟评论表单
     * @param int $video_id
     * @param $store_id
     * @return mixed
     */
    public function createForm(int $id)
    {
        $topic = [];
        if ($id) {
            $topic = $this->dao->get($id);
        }
        $field[] = Form::input('name', '话题名称：', $topic['name'] ?? '')->maxlength(10);
        $field[] = Form::number('sort', '排序：', $topic['sort'] ?? 0)->min(0);
        $field[] = Form::switches('is_recommend', '推荐：', (string)($topic['is_recommend'] ?? '1'))->falseValue('0')->trueValue('1')->openStr('打开')->closeStr('关闭')->size('large');
        $field[] = Form::switches('status', '显示：', (string)($topic['status'] ?? '1'))->falseValue('0')->trueValue('1')->openStr('打开')->closeStr('关闭')->size('large');
        return create_form('社区话题', $field, Url::buildUrl('/community/topic/save/' . $id), 'POST');
    }

    /**
     * 修改状态
     * @param $id
     * @return true
     * @throws \ReflectionException
     */
    public function setStatus($id)
    {
        /** @var CommunityServices $communityServices */
        $communityServices = app()->make(CommunityServices::class);
        /** @var CommunityRelevanceServices $relevanceServices */
        $relevanceServices = app()->make(CommunityRelevanceServices::class);
        $info = $communityServices->get($id);
        if ($info['is_verify'] != 1) {
            return true;
        }
        $ids = $relevanceServices->search(['left_id' => $id, 'type' => CommunityRelevanceServices::TYPE_COMMUNITY_TOPIC])->column('right_id');
        if ($ids) {
            $this->dao->update(['id' => $ids, 'is_del' => -1],['is_del' => 0]);
        }
        return true;
    }

}
