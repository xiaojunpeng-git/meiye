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
namespace app\controller\store\community;

use think\db\exception\DbException;
use app\controller\store\AuthController;
use app\services\community\CommunityCommentServices;
use app\services\community\CommunityServices;
use think\facade\App;

/**
 * 社区评论
 * Class CommunityComment
 * @package app\controller\store\community
 */
class CommunityComment extends AuthController
{

    /**
     * @var CommunityCommentServices
     */
    public function __construct(App $app, CommunityCommentServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    /**
     * 显示资源列表
     *
     * @return \think\Response
     * @throws DbException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function index()
    {
        $where = $this->request->getMore([
            ['is_verify', ''],//审核状态
            ['keyword', ''],//关键词
            ['field_key', ''],//关键词key
            ['time', ''],//时间
            ['community_id', ''],//社区内容ID
            ['is_reply', 1],//是否是一级评论
            ['reply_id', ''],//社区评论 id
        ]);
        $where['store_id'] = (int)$this->storeId;
        $list = $this->services->sysPage($where);
        return $this->success($list);
    }

    /**
     * 获取评论回复列表
     * @param $id
     * @return mixed
     * @throws DbException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getCommentReply($id)
    {
        if (!$id) {
            return $this->fail('缺少参数');
        }
        $time = $this->request->get('data', '');
        return $this->success($this->services->getCommentReplyList((int)$id, ['time' => $time]));
    }

    /**
     *  评论回复表单
     * @param $id
     * @return mixed
     */
    public function replyForm($id)
    {
        if (!$id) {
            return $this->fail('缺少参数');
        }
        return $this->success($this->services->replayForm($id));
    }

    /**
     * 管理员回复评论
     * @param $id
     * @return mixed
     */
    public function setReply($id)
    {
        $data = $this->request->postMore([
            ['content', '']
        ]);

        $info = $this->services->get($id);
        if (!$info) {
            return $this->fail('评论不存在');
        }
        if (!$data['content']) {
            return $this->fail('评价内容不能为空');
        }
        $community_id = $info['community_id'];
        $data['ip'] = $this->request->ip();
        $data['is_verify'] = sys_config('community_comment_verify', 0) ? 0 : 1;
        $data['type'] = 1;
        $data['relation_id'] = $this->storeId;
        $this->services->saveComment($community_id, $data, $id);
        return $this->success('回复成功!');
    }

    /**
     *  审核表单
     * @param $id
     * @return mixed
     */
    public function verifyForm($id)
    {
        if (!$id) {
            return $this->fail('缺少参数');
        }
        return $this->success($this->services->verifyForm($id));
    }

    /**
     * 屏蔽
     * @param $id
     * @param $recommend
     * @return mixed
     */
    public function setStatus($id, $status)
    {
        if ($id == '') return $this->fail('缺少参数');
        $info = $this->services->get($id);
        if (!$info) {
            $this->fail('社区内容不存在');
        }
        $info->is_show = $status;
        $info->save();
        event('community.comment.operate', [$id]);
        return $this->success('操作成功');
    }

    /**
     * 创建虚拟评论表单
     * @return mixed
     * @throws \FormBuilder\Exception\FormBuilderException
     */
    public function fictitiousComment($id)
    {
        return $this->success($this->services->createForm((int)$id));
    }

    /**
     * 保存虚拟评论
     * @return mixed
     */
    public function saveFictitiousComment()
    {
        $data = $this->request->postMore([
            ['nickname', ''],
            ['avatar', ''],
            ['content', ''],
            ['community_id', 0],
            ['add_time', 0]
        ]);
        if (!$data['community_id']) {
            return $this->fail('社区内容不能为空');
        }
        if (!$data['content']) {
            return $this->fail('评价内容不能为空');
        }
        $community_id = (int)$data['community_id'];
        unset($data['community_id']);
        $data['ip'] = $this->request->ip();
        $data['type'] = 1;
        $data['is_verify'] = 1;
        $data['is_reply'] = 1;
        $this->services->saveComment($community_id, $data);
        return $this->success('添加成功!');
    }


    /**
     * 删除评论
     * @param $id
     * @return \think\Response
     */
    public function delete($id)
    {
        if (!$id) {
            return $this->fail('缺少参数');
        }
        $id = (int)$id;
        $this->services->commentDelete($id, 0, true);
        return $this->success('删除成功');
    }

}
