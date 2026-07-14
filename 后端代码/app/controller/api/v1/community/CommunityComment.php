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
namespace app\controller\api\v1\community;


use app\services\community\CommunityCacheServices;
use app\services\community\CommunityCommentServices;
use app\services\community\CommunityServices;
use app\Request;
use app\services\community\CommunityTopicServices;
use app\services\product\product\StoreProductLogServices;
use think\facade\App;


/**
 * Class CommunityComment
 * @package app\api\controller\v1\store
 */
class CommunityComment
{

    /**
     * @var CommunityCommentServices
     */
    public function __construct(CommunityCommentServices $services)
    {
        $this->services = $services;
    }


    public function list(Request $request)
    {
        $where = $request->getMore([
            ['community_id', ''],//社区 id
            ['reply_id', ''],//评论id
        ]);
        if (!$where['community_id']) {
            return app('json')->fail('参数错误');
        }
        if (!$where['reply_id']) {
            $where['is_reply'] = 1;
        }
        $where['is_del'] = 0;
        $where['is_verify'] = 1;

        return app('json')->success($this->services->getList($where, '*', [], (int)$request->uid()));
    }

    /**
     * 添加
     * @param Request $request
     * @return \think\Response
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function save(Request $request)
    {
        if (!sys_config('community_comment_status')) return app('json')->fail('评论功能暂未开启');
        if (!sys_config('community_comment_add')) return app('json')->fail('暂不支持新增评论');
        [$community_id, $comment_reply_id, $content] = $request->postMore([
            ['community_id', 0],//社区 id
            ['comment_reply_id', 0],//评论回复id
            ['content', ''],//评论内容
        ], true);
        $uid = (int)$request->uid();
        if (empty($content)) {
            return app('json')->fail('请输入回复内容');
        }
        $data['content'] = $content;
        $data['ip'] = $request->ip();

        $data['is_verify'] = sys_config('community_comment_verify', 0) ? 0 : 1;
        $data['type'] = 2;
        $this->services->saveComment((int)$community_id, $data, (int)$comment_reply_id, $uid);
        $msg = sys_config('community_comment_verify', 1) ? '评论成功，等待审核' : '评论成功';
        return app('json')->success($msg);
    }

    /**
     * 点赞,取消点赞
     * @param $id
     * @param Request $request
     * @return \think\Response
     */
    public function setCommentLike($id, Request $request)
    {
        [$status] = $request->postMore([
            ['status', 1]
        ], true);

        $status = $status == 1 ? 1 : 0;
        $info = $this->services->get($id);
        if (!$info || $info['is_del'] == 1 || $info['is_verify'] != 1)
            return app('json')->fail('评论内容不存在');
        $info = $info->toArray();
        $uid = (int)$request->uid();
        $this->services->setCommentLike($info, $uid, $status);
        if ($status) {
            return app('json')->success('点赞成功');
        } else {
            return app('json')->success('取消点赞');
        }
    }

    /**
     * 删除
     * @param $id
     * @return void
     */
    public function commentDelete($id, Request $request)
    {
        $uid = (int)$request->uid();
        $res = $this->services->commentDelete($id, $uid);
        return app('json')->success('删除成功');
    }
}
