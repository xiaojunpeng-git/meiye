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
namespace app\controller\admin\v1\store;


use app\services\agent\SystemRegionAgentServices;
use think\facade\App;
use app\controller\admin\AuthController;
use app\services\store\finance\StoreExtractServices;


/**
 * 门店提现
 * Class StoreExtract
 * @package app\controller\admin\v1\store
 */
class StoreExtract extends AuthController
{
    /**
     * StoreExtract constructor.
     * @param App $app
     * @param StoreExtractServices $services
     */
    public function __construct(App $app, StoreExtractServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }


	/**
	 * 显示资源列表
	 * @param SystemRegionAgentServices $regionAgentServices
	 * @return \think\Response
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function index(SystemRegionAgentServices $regionAgentServices)
    {
        $where = $this->request->getMore([
            ['status', ''],
            ['pay_status', ''],
            ['extract_type', ''],
            ['nireid', '', '', 'like'],
            ['data', '', '', 'time'],
            ['store_id', '']
        ]);
        if (isset($where['extract_type']) && $where['extract_type'] == 'wx') {
            $where['extract_type'] = 'weixin';
        }
		if (!$where['store_id']) {//无筛选门店
			if ($this->adminType == 3 && $this->agentId) {//区域代理商登录
				$storeIds = $regionAgentServices->getRegionAgentStoreId((int)$this->agentId);
				if ($storeIds) {//区域代理商下门店ID
					$where['store_id'] = $storeIds;
				} else {
					$where['store_id'] = -2;
				}
			}
		}
        $whereData = [
            'store_id' => $where['store_id'],
            'is_del' => 0,
            'trade_type' => 1,
            'no_type' => [1,15]
        ];
        return app('json')->success($this->services->index($where, $whereData));
    }

    /**
     * 增加备注
     * @param $id
     * @return mixed
     */
    public function mark($id)
    {
        [$mark] = $this->request->getMore([
            ['mark', '']
        ], true);
        if (!$id) {
            return app('json')->fail('缺少参数');
        }
        if (!$mark) {
            return app('json')->fail('请填写备注信息');
        }
        $storeExtract = $this->services->get((int)$id);
        if (!$storeExtract) {
            return app('json')->fail('转账记录不存在');
        }
        if (!$this->services->update($id, ['store_mark' => $mark])) {
            return app('json')->fail('备注失败');
        }
        return app('json')->success('备注成功');
    }

    /**
     * 审核
     * @param $id
     * @return mixed
     */
    public function verify($id)
    {
        if (!$id) $this->fail('缺少参数');
        [$type, $message] = $this->request->postMore([
            ['type', 1],
            ['message', '']
        ], true);
        $adminId = $this->adminId;
        if ($type == 1) {
            $res = $this->services->adopt($id, $adminId);
        } else {
            $res = $this->services->refuse((int)$id, $message, $adminId);
        }
        return $this->success($res ? '操作成功' : '操作失败');
    }

    /**
     * 转账表单
     * @param $id
     * @return mixed
     */
    public function transfer($id)
    {
        if (!$id) $this->fail('缺少参数');
        return $this->success($this->services->add_transfer((int)$id));
    }

    /**
     * 转账提交
     * @param $id
     * @return mixed
     */
    public function save_transfer($id)
    {
        $data = $this->request->postMore([
            ['voucher_image', ''],
            ['voucher_title', '']
        ]);
        $info = $this->services->getExtract($id);
        if (!$info) $this->fail('提现记录不存在');
        if ($info['status'] != 1) $this->fail('请先审核提现记录');
        if ($info['pay_status'] == 1) $this->fail('请勿重复提现');
        $data['pay_status'] = 1;
        if (!$this->services->update($id, $data)) {
            return app('json')->fail('转账失败');
        }
        return app('json')->success('转账成功');
    }
}
