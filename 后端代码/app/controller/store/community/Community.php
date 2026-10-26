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

use app\controller\store\AuthController;
use app\services\community\CommunityCacheServices;
use app\services\community\CommunityServices;
use think\facade\App;

/**
 * 社区
 * Class Community
 * @package app\controller\store\community
 */
class Community extends AuthController
{

    /**
     * @var CommunityServices
     */
    public function __construct(App $app, CommunityServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    /**
     * 显示资源列表头部
     * @return mixed
     */
    public function type_header()
    {
        $where = $this->request->getMore([
            ['data', '', '', 'time'],//时间
            ['topic_id', ''],//话题
            ['star', ''],//推荐指数
            ['content_type', ''],//内容类型1:图文2视频
            ['keyword', ''],//关键字搜索
            ['is_verify', ''],//是否审核
            ['type', ''],//内容来源
        ]);
        $where['is_del'] = 0;
        $where['store_id'] = (int)$this->storeId;
        return $this->success($this->services->getHeader($where));
    }

    /**
     * 社区列表
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function index()
    {
        $where = $this->request->getMore([
            ['data', '', '', 'time'],//时间
            ['topic_id', ''],//话题
            ['star', ''],//推荐指数
            ['content_type', ''],//内容类型1:图文2社区内容
            ['keyword', ''],//关键字搜索
            ['is_verify', ''],//关键字搜索
            ['type', ''],//内容来源
        ]);
        $where['is_del'] = 0;
        $where['store_id'] = (int)$this->storeId;
        return $this->success($this->services->getList($where, ['topic'], 'id desc'));
    }

    /**
     * 获取社区内容信息
     * @param $id
     * @return mixed
     */
    public function info($id)
    {
        if (!$id) return $this->fail('缺少参数');
        return $this->success($this->services->getDetail((int)$id, 0, true));
    }


    /**
     * 保存新增分类
     * @return mixed
     */
    public function save($id)
    {
        $data = $this->request->postMore([
            ['content_type', 1],//内容类型1：图文2视频
            ['title', ''],//标题
            ['content', ''],//内容
            ['image', ''],//封面
            ['video_url', ''],//视频地址
            ['slider_image', []],//图集
            ['topic_id', []],//关联话题
            ['topic_name', []],//关联话题
            ['product_id', []],//关联商品
            ['status', 1],//状态
            ['is_recommend', 1],//推荐
            ['star', 1],//推荐指数
            ['sort', 0],//排序
        ]);
        if (!$data['title']) {
            $this->fail('请输入社区内容标题');
        }
        if (!$data['topic_id']) {
            $this->fail('请至少选择一个话题');
        }
        $data['image'] = !$data['image'] && $data['slider_image'] ? ($data['slider_image'][0] ?? '') : $data['image'];//封面图
        $data['slider_image'] = json_encode($data['slider_image']);

        $data['relation_id'] = (int)$this->storeId;
        //图文视频审核状态
        if ($data['content_type'] == 1) {
            $data['is_verify'] = sys_config('community_verify', 1) ? 0 : 1;
        } else {
            $data['is_verify'] = sys_config('community_video_verify', 1) ? 0 : 1;
        }
        $data['verify_time'] = time();
        $id = $id ?: 0;
        $this->services->saveData($data, $id, 1);
        return $this->success('添加社区内容成功!');
    }

    /**
     * 修改状态
     * @param string $is_show
     * @param string $id
     */
    public function setStatus($id = '', $status = '')
    {
        if ($status == '' || $id == '') return $this->fail('缺少参数');
        $this->services->update($id, ['status' => $status]);
        event('community.operate', [$id, 0, 1]);
        return $this->success($status == 1 ? '显示成功' : '隐藏成功');
    }

    /**
     * 删除社区内容
     * @param $id
     * @return mixed
     */
    public function delete($id)
    {
        if ($id == '') return $this->fail('缺少参数');
        $this->services->communityDelete($id, 0, true);
        return $this->success('删除成功!');
    }
}
