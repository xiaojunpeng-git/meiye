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
namespace app\controller\admin\v1\system;

use think\facade\App;
use app\controller\admin\AuthController;
use app\services\system\timer\SystemTimerServices;

/**
 * 定时任务表控制器
 * Class SystemTimer
 * @package app\controller\admin\v1\system
 */
class SystemTimer extends AuthController
{

    protected $services;

    /**
     * 构造方法
     * SystemTimer constructor.
     * @param App $app
     * @param SystemTimerServices $services
     */
    public function __construct(App $app, SystemTimerServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

	/**
	 * 定时任务名称及标识
	 * @return mixed
	 */
    public function task_name()
    {
		return $this->success($this->services->getTasKName());
    }

    /**
     * 显示列表
     * @return mixed
     */
    public function index()
    {
        $where['is_del'] = 0;
        $data = $this->services->getTimerList($where);
        $cacheCount = $this->services->cacheCount();
        if ($data['count'] != $cacheCount) {
            $this->services->setAllTimerCache();
        }
        return $this->success($data);
    }

    /**
     * 删除指定资源
     * @param string $id
     * @return \think\Response
     */
    public function delete($id)
    {
        $this->services->del($id);
        $this->services->delOneTimerCache($id);
        return $this->success('删除成功');
    }

    /**
     * 修改状态
     * @param string $is_show
     * @param string $id
     */
    public function set_show($id = '', $is_show = '')
    {
        if ($is_show == '' || $id == '') return $this->fail('缺少参数');
        $this->services->setShow($id, $is_show);
        $this->services->updateOneTimerCache($id);
        return $this->success($is_show == 1 ? '显示成功' : '隐藏成功');
    }

    /**获取单条定时器数据
     * @param $id
     * @return void
     */
    public function get_timer_one($id)
    {
        return $this->success($this->services->getOneTimer($id));
    }

    /**
     * 保存定时任务
     * @return mixed
     */
    public function save()
    {
        $data = $this->request->postMore([
            ['name', ''],
            ['mark', ''],
            ['type', 0],
            ['title', ''],
            ['is_open', 0],
            ['cycle', '']
        ]);
        if (!$data['name']) {
            return $this->fail('请输入定时任务名称');
        }
        if (!$data['mark']) {
            return $this->fail('请输入定时任务标识');
        }
        $this->services->createData($data);
        $this->services->setAllTimerCache();
        return $this->success('添加定时器成功!');
    }

    /**
     * 更新定时任务
     * @param $id
     * @return mixed
     */
    public function update($id)
    {
        $data = $this->request->postMore([
            ['name', ''],
            ['mark', ''],
            ['type', 0],
            ['title', ''],
            ['is_open', 0],
            ['cycle', '']
        ]);
        if (!$data['name']) {
            return $this->fail('请输入定时任务名称');
        }
        if (!$data['mark']) {
            return $this->fail('请输入定时任务标识');
        }
        $this->services->editData($id, $data);
        $this->services->updateOneTimerCache($id);
        return $this->success('修改成功!');
    }
}

