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
namespace app\controller\admin\v1\community;

use app\controller\admin\AuthController;
use app\services\agent\SystemRegionAgentServices;
use app\services\community\CommunityCacheServices;
use app\services\community\CommunityServices;
use think\facade\App;

/**
 * 社区
 * Class Community
 * @package app\controller\admin\v1\community
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
	 * @param SystemRegionAgentServices $regionAgentServices
	 * @return mixed
	 * @throws \think\db\exception\DbException
	 */
    public function type_header(SystemRegionAgentServices $regionAgentServices)
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
		if ($this->adminType == 3 && $this->agentId) {//区域代理商登录
			$storeIds = $regionAgentServices->getRegionAgentStoreId((int)$this->agentId);
			if ($storeIds) {//区域代理商下门店ID
				$where['store_id'] = $storeIds;
			} else {
				$where['store_id'] = -2;
			}
		}
        return $this->success($this->services->getHeader($where));
    }

	/**
	 * 社区列表
	 * @param SystemRegionAgentServices $regionAgentServices
	 * @return mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function index(SystemRegionAgentServices $regionAgentServices)
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
		if ($this->adminType == 3 && $this->agentId) {//区域代理商登录
			$storeIds = $regionAgentServices->getRegionAgentStoreId((int)$this->agentId);
			if ($storeIds) {//区域代理商下门店ID
				$where['store_id'] = $storeIds;
			} else {
				$where['store_id'] = -2;
			}
		}
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
            ['data', '', 'time'],
        ]);
        if (!$data['title']) {
            $this->fail('请输入社区内容标题');
        }
        if (!$data['topic_id']) {
            $this->fail('请至少选择一个话题');
        }
        $data['image'] = !$data['image'] && $data['slider_image'] ? ($data['slider_image'][0] ?? '') : $data['image'];//封面图
        $data['slider_image'] = json_encode($data['slider_image']);

        $data['relation_id'] = 0;
        $data['is_verify'] = 1;
        $data['verify_time'] = time();
        $id = $id ?: 0;
        $this->services->saveData($data, $id, 0);
        return $this->success('添加社区内容成功!');
    }

    /**
     * 设置推荐星级表单
     * @param $id
     * @return \think\Response
     */
    public function setStarForm($id)
    {
        if ($id == '') return $this->fail('缺少参数');
        return $this->success($this->services->setStarForm($id));
    }

    /**
     * 设置推荐星级
     * @param $id
     * @return \think\Response
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function setStar($id)
    {
        [$star] = $this->request->postMore([
            ['star', 1],
        ], true);
        if ($id == '') return $this->fail('缺少参数');
        $this->services->getCommunityInfo((int)$id);
        $this->services->update($id, ['star' => $star]);
        return $this->success('设置成功');
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
        event('community.operate', [$id, 0, 0]);
        return $this->success($status == 1 ? '显示成功' : '隐藏成功');
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
     * 强制下架
     * @param $id
     * @param $recommend
     * @return mixed
     */
    public function takeDownForm($id)
    {
        if ($id == '') return $this->fail('缺少参数');
        $info = $this->services->get($id);
        if (!$info) {
            $this->fail('社区内容不存在');
        }
        return $this->success($this->services->verifyForm((int)$id, 2));
    }

	/**
	 * 审核
	 * @param $id
	 * @return mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function setVerify($id = '')
    {
        if (!$id) {
            return $this->fail('缺少参数');
        }
        $data = $this->request->getMore([
            ['is_verify', 1],
            ['refusal', '']
        ]);
        if (in_array($data['is_verify'], [-1, -2]) && !$data['refusal']) {
            return $this->fail('请输入原因');
        }
		$this->services->setVerify((int)$id, $data);
        return $this->success('操作成功');
    }

	/**
	 * 批量审核
	 * @return mixed
	 */
	public function batchVerify()
	{
		$data = $this->request->postMore([
			['where', []],//搜索条件
			['ids', ''],//选择ids
			['all', 0],//是否全选
			['is_verify', 1],
			['refusal', '']
		]);
		if (in_array($data['is_verify'], [-1, -2]) && !$data['refusal']) {
			return $this->fail('请输入原因');
		}
		if ($data['all']) {//全选 根据查询条件
			$ids = $this->services->getIdColumn($data['where'], 'id');
		} else {
			if (empty($data['ids']))
				return $this->fail('请选择需要审核的内容');
			$ids = $data['ids'];
		}
		$ids = is_string($ids) ? stringToIntArray($ids) : $ids;
		$verifyArr = [
			'is_verify' => $data['is_verify'],
			'refusal' => $data['refusal']
		];
		foreach ($ids as $id) {
			try {
				$this->services->setVerify((int)$id, $verifyArr);
			} catch (\Throwable $e) {

			}
		}
		return $this->success('操作成功');
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
