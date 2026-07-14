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

namespace app\controller\supplier\system;


use app\controller\supplier\AuthController;
use app\services\store\StoreConfigServices;
use app\services\system\config\SystemConfigServices;
use think\facade\App;
use app\Request;

/**
 * Class Config
 * @package app\controller\supplier\system
 */
class Config extends AuthController
{

    /**
     * Config constructor.
     * @param App $app
     * @param SystemConfigServices $services
     */
    public function __construct(App $app, SystemConfigServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    /**
	 * 获取门店配置
     * @param $type
     * @param StoreConfigServices $services
     * @return mixed
     */
    public function getConfig($type, StoreConfigServices $services)
    {
        if (!isset(StoreConfigServices::CONFIG_TYPE[$type])) {
            return $this->fail('类型不正确');
        }
        return $this->success($services->getConfigAll(StoreConfigServices::CONFIG_TYPE[$type], 2, (int)$this->supplierId));
    }

    /**
     * 保存数据
     * @param StoreConfigServices $services
     * @return mixed
     */
    public function save(StoreConfigServices $services)
    {
        $data = $this->request->post();
        $services->saveConfig($data,2, (int)$this->supplierId);
		\mohe\services\SystemConfigService::clear();
        return $this->success('修改成功');
    }

	/**
	 * 基础配置
	 * @param Request $request
	 * @param StoreConfigServices $services
	 * @return mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function edit_basics(Request $request, StoreConfigServices $services)
    {
        $name = $this->request->param('name', '');
        if (!$name) {
            return $this->fail('参数错误');
        }
        $supplier_id = (int)$this->supplierId;
        return $this->success($services->getFormBuildRule($name, 2, $supplier_id));
    }


	/**
	 * @param string $type
	 * @return mixed
	 */
	public function getFormBuild(StoreConfigServices $services, string $type)
	{
		$supplier_id = (int)$this->supplierId;
		return $this->success($services->getFormBuildRule($type, 2, $supplier_id));
	}
}
