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

namespace app\controller\store\system\form;


use app\controller\store\AuthController;
use app\services\system\form\SystemFormServices;
use think\facade\App;

/**
 *
 * Class SystemForm
 * @package app\controller\store\system\form
 */
class SystemForm extends AuthController
{

    /**
     * Diy constructor.
     * @param App $app
     * @param SystemFormServices $services
     */
    public function __construct(App $app, SystemFormServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }


	/*
	 * 所有系统表单
	 */
	public function allSystemForm()
	{
		$data = $this->services->getFormList([], ['id', 'name']);
		return $this->success($data['list'] ?? []);
	}

	/**
	 * 获取一条数据
	 * @param int $id
	 * @return mixed
	 */
	public function getInfo($id)
	{
		if (!$id) return $this->fail('数据不存在');
		[$type] = $this->request->postMore([
			['type', 0],
		], true);
		$info = $this->services->get((int)$id);
		if ($info) {
			$info = $info->toArray();
		} else {
			return $this->fail('数据不存在');
		}
		$info['value'] = json_decode($info['value'], true);
		if ($type == 1) {//处理表单数据
			$value = $info['value'] ?? [];
			$info = $this->services->handleForm($value);
		}
		return $this->success(compact('info'));
	}




}
