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

namespace app\controller\admin\v1\marketing\seckill;

use app\controller\admin\AuthController;
use app\services\activity\seckill\StoreSeckillTimeServices;
use app\services\activity\StoreActivityServices;
use think\facade\App;

/**
 * 限时秒杀时间  控制器
 * Class StoreSeckillTime
 * @package app\controller\admin\v1\marketing\seckill
 */
class StoreSeckillTime extends AuthController
{
    public function __construct(App $app, StoreSeckillTimeServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    /**
     * 显示资源列表
     *
     * @return \think\Response
     */
    public function index()
    {
        $where = $this->request->getMore([
			['title', ''],
			[['status', 's'], '']
        ]);
        return $this->success($this->services->systemPage($where));
    }

	/**
	 * 可用秒杀时间段
	 * @return mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function time_list()
	{
		return $this->success($this->services->time_list());
	}

	/**
	 * @param $id
	 * @return mixed
	 */
	public function create($id = '')
	{
		return $this->success($this->services->createForm((int)$id));
	}

    /**
     * 详情
     * @param $id
     * @return mixed
     */
    public function read($id)
    {
        $info = $this->services->getInfo((int)$id);
        return $this->success(compact('info'));
    }

    /**
     * 保存秒杀商品
     * @param int $id
     */
    public function save($id)
    {
        $data = $this->request->postMore([
            [['title', 's'], ''],
            [['describe', 's'], ''],
            ['time', []],
			['pic', ''],
            [['status', 'd'], 0],
        ]);

        $this->validate($data, \app\validate\admin\marketing\StoreSeckillTimeValidate::class, 'save');
		$data['start_time'] = str_replace(':', '', $data['time'][0]);
		$data['end_time'] = str_replace(':', '', $data['time'][1]);
		unset($data['time']);
		$id = (int)$id;
		if ($data['end_time'] <= $data['start_time']) {
			return app('json')->fail('时间段结束时间要大于开始时间');
		}
		if (!$this->services->checkTime($data, $id))
			return app('json')->fail('时间段不可重叠');
        if ($id) {
            $seckill = $this->services->get($id);
            if (!$seckill) {
                return $this->fail('数据不存在');
            }
        }
		if ($id) {
			$this->services->update($id, $data);
		} else {
			$this->services->save($data);
		}
        return $this->success('保存成功');
    }

	/**
	 * 修改状态
	 * @param $id
	 * @param $status
	 * @return mixed
	 */
	public function set_status($id, $status)
	{
		$this->services->update($id, ['status' => $status]);
		return $this->success($status == 0 ? '关闭成功' : '开启成功');
	}

    /**
     * 删除指定资源
     *
     * @param int $id
     * @return \think\Response
     */
    public function delete(StoreActivityServices $activityServices, $id)
    {
        if (!$id) return $this->fail('缺少参数');
		$info = $this->services->get((int)$id);
		if (!$info) {
			return $this->fail('数据不存在');
		}
		if ($activityServices->existenceActivity(['start_time' => $info->start_time, 'end_time' => $info->end_time]))
			return app('json')->fail('已有开启活动，不可删除');
        $this->services->delete($id);
        return $this->success('删除成功!');
    }




}
