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
namespace app\controller\store\system;


use app\controller\store\AuthController;
use app\services\message\SystemPrinterServices;
use think\facade\App;


/**
 * Class SystemPrinter
 * @package app\controller\store\system
 */
class SystemPrinter extends AuthController
{

    /**
     * SystemRole constructor.
     * @param App $app
     * @param SystemPrinterServices $services
     */
    public function __construct(App $app, SystemPrinterServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }


    /**
     * 显示管理员资源列表
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function index()
    {
        $where = $this->request->getMore([
            ['type', 1],
            ['plat_type', 0],
            ['keyword', ''],
        ]);
        $where['relation_id'] = $this->storeId;
        return app('json')->success($this->services->index($where));
    }

	/**
	 * 获取当前登录门店管理员的信息
	 * @return mixed
	 */
	public function info($id)
	{
		return app('json')->success($this->services->getInfo((int)$id));
	}


    /**
     * 保存管理员
     * @return mixed
     */
    public function save($id)
    {
        $data = $this->request->postMore([
            ['name', ''],//名称
            ['plat_type', 1],//打印机类型1易联云 2飞鹅云
            ['print_event', 1],//打印事件1：下单后2：支付后
            ['print_num', 1],//打印联数
            ['status', 1],//状态
            ['yly_user_id', ''],//易联云用户ID
            ['yly_app_id', ''],//易联云应用密钥
            ['yly_app_secret', ''],//易联云应用ID
            ['yly_sn', ''],//易联云终端号

            ['fey_user', ''],//飞鹅云USER
            ['fey_ukey', ''],//飞鹅云UYEK
            ['fey_sn', ''],//飞鹅云SN
		]);
		if (!$data['name']) {
			return app('json')->fail('请输入打印机名称');
		}
		if ((int)$data['print_num'] > 9 || (int)$data['print_num'] < 1) {
			return app('json')->fail('打印联数为1-9数字');
		}
		$one = $this->services->getOne(['name' => $data['name'], 'type' => 1, 'relation_id' => $this->storeId, 'is_del' => 0]);
		if ($one && (($id && $one['id'] != $id) || !$id)) {
			return app('json')->fail('打印机名称重复');
		}
        if ($data['plat_type'] == 1) {//易联云
            if (!$data['yly_user_id'] || !$data['yly_app_id'] || !$data['yly_app_secret'] || !$data['yly_sn']) {
                return app('json')->fail('请完善易联云打印机配置');
            }
        } else {
            if (!$data['fey_user'] || !$data['fey_ukey'] || !$data['fey_sn']) {
                return app('json')->fail('请完善飞蛾云打印机配置');
            }
        }
		$data['type'] = 1;
		$data['relation_id'] = $this->storeId;
		if ($id) {//修改
			$res = $this->services->update($id, $data);
		} else {
			$data['add_time'] = time();
            $data['print_content'] = json_encode($this->services->print_content);
            $data['print_table_content'] = json_encode($this->services->print_table_content);
			$res = $this->services->save($data);
		}
        if ($res) {
            return app('json')->success('保存成功');
        } else {
			return app('json')->fail('保存失败');
        }

    }

    /**
     * 删除打印机
     * @param $id
     * @return mixed
     */
    public function delete($id)
    {
        if (!$id) return $this->fail('删除失败，缺少参数');
        //删除打印机
        $this->services->update((int)$id, ['is_del' => 1]);
        return app('json')->success('删除成功！');
    }

    /**
     * 修改状态
     * @param $id
     * @param $status
     * @return mixed
     */
    public function set_status($id, $status)
    {
        $this->services->update((int)$id, ['status' => $status]);
        return app('json')->success($status == 0 ? '关闭成功' : '开启成功');
    }

    /**
     * 获取小票样式配置
     * @param $id
     * @return mixed
     */
    public function getPrintContent($id)
    {
        $where = $this->request->getMore([
            ['scene', 1],
        ]);
        if (!$id) app('json')->fail('参数有误！');
        return app('json')->success($this->services->getPrintContent($id, $where['scene']));
    }

    /**
     * 设置小票样式配置
     * @param $id
     * @return mixed
     */
    public function savePrintContent($id)
    {
        $data = $this->request->postMore([
            ['scene', 1], // 1  普通  2 桌码
            ['header', []],
            ['delivery', []],
            ['buyer_remarks', 0],
            ['goods', []],
            ['freight', 0],
            ['preferential', []],
            ['pay', []],
            ['custom', 0],
            ['order', []],
            ['code', 0],
            ['code_url', ''],
            ['show_notice', 0],
            ['notice_content', '']
        ]);
        if (!$id) app('json')->fail('参数有误！');
        $scene = $data['scene'] ?? 1;
        if($data['code'] && !$data['code_url']) {
            $data['code_url'] = "/pages/store/home/index?id=" . $this->storeId;
        }
        $this->services->savePrintContent($id, $data, $scene);
        return app('json')->success('保存成功');
    }
}
