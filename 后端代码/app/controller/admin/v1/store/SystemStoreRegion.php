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

use app\services\store\SystemStoreRegionServices;
use think\facade\App;
use app\controller\admin\AuthController;
use app\services\store\SystemStoreServices;

/**
 * 门店区域控制器
 * Class SystemStoreRegion
 * @package app\controller\admin\v1\store
 */
class SystemStoreRegion extends AuthController
{
    /**
     * 构造方法
     * SystemStoreRegion constructor.
     * @param App $app
     * @param SystemStoreRegionServices $services
     */
    public function __construct(App $app, SystemStoreRegionServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

	/**
	 * 获取所有区域已经选择的城市数据
	 * @return mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function getRegionCity()
	{
		[$region_id] = $this->request->getMore([
			['region_id', 0],//区域ID
		], true);
		$region = $this->services->getList(['is_del' => 0, 'not_id' => $region_id], 'id,recommend_region');
		$regionCity = [];
		$recommendRegion = [];
		if ($region) {
			foreach ($region as $item) {
				$recommendRegion = array_merge($recommendRegion, $item['recommend_region']);
				foreach ($item['recommend_region'] as $city) {
					$regionCity[] = end($city);
				}
			}
		}
		$recommendRegion = array_merge(array_unique(array_reduce($recommendRegion, function($carry, $item) {
			return array_merge($carry, $item);
		}, [])));
		return $this->success(['region' => $recommendRegion, 'city' => array_merge(array_unique($regionCity))]);
	}

	/**
	 * 获取区域
	 * @return mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function allRegion()
	{
		$where = $this->request->getMore([
			['is_show', ''],//是否显示
		]);
		$where['is_del'] = 0;
		return $this->success($this->services->getAllRegion($where));
	}

    /**
     * 获取门店区域列表
     * @return mixed
     */
    public function index()
    {
        $where = $this->request->getMore([
            ['name', '', '', 'keyword'],//关键字
            [['is_show', 'd'], ''],//状态
			[['is_alone', 'd'], ''],//是否隔离
        ]);
        $where['is_del'] = 0;
        return $this->success($this->services->getRegionList($where));
    }


	/**
	 * 区域详情
	 * @param SystemStoreServices $storeServices
	 * @param $id
	 * @return mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function info(SystemStoreServices $storeServices, $id)
	{
		$id = (int)$id;
		if (!$id) return $this->fail('数据不存在');
		$info = $this->services->get((int)$id);
		if (!$info) {
			return $this->fail('数据不存在');
		}
		$info = $info->toArray();
		$field = ['id', 'cate_id', 'region_id', 'name', 'phone', 'address', 'detailed_address', 'image', 'is_show', 'day_time', 'day_start', 'day_end'];
		$storeList = $storeServices->getStoreList(['is_del' => 0, 'region_id' => $id], $field, '', '', 0, ['categoryName']);
		$info['stores'] = $storeList['list'] ?? [];
		return $this->success($info);
	}

	/**
	 * 设置区域是否隔离
	 * @param $id
	 * @param $is_alone
	 * @return mixed
	 */
	public function setAlone(SystemStoreServices $storeServices, $id = '', $is_alone = '')
	{
		if($is_alone === '' || $id === '') return $this->fail('缺少参数');
		$res = $this->services->update((int)$id, ['is_alone' => (int)$is_alone]);
		$storeServices->update(['region_id' => $id], ['is_region_alone' => (int)$is_alone]);
		return $this->success('设置成功');
	}


    /**
     * 设置单个门店是否显示
     * @param string $is_show
     * @param string $id
     * @return json
     */
    public function setShow($id = '', $is_show = '')
    {
        if($is_show === '' || $id === '') return $this->fail('缺少参数');
        $res = $this->services->update((int)$id, ['is_show' => (int)$is_show]);
		return $this->success('设置成功');
    }


	/**
	 * @param $id
	 * @return mixed
	 */
    public function save(SystemStoreServices $storeServices, $id = 0)
    {
        $data = $this->request->postMore([
			['name', 1],//门店类型1：自营2加盟
            ['sort', 0],
            ['is_alone', ''],
            ['is_show', 1],
			['recommend_region', ''],//推荐区域
            ['store_id', ''],
        ]);
		if (!$data['name']) {
			return $this->fail('请输入区域名称');
		}
		if (!$data['recommend_region']) {
			return $this->fail('请选择推荐区域');
		}
		$data['store_id'] = is_string($data['store_id']) ? stringToIntArray($data['store_id']) : $data['store_id'];
		$province_id = $city_id = $area_id = [];
		foreach ($data['recommend_region'] as $region) {
			$province_id[] = array_shift($region);
			$count = count($region);
			switch ($count) {
				case 0:
					break;
				case 1:
					$city_id[] = array_shift($region);
					break;
				case 2:
					$city_id[] = $region[0];
					$area_id[] = end($region);
			}
		}
		$data['province_id'] = implode(',', array_unique($province_id));
		$data['city_id'] = implode(',', array_unique($city_id));
		$data['area_id'] = implode(',', array_unique($area_id));

		$id = (int)$id;
		if ($id) {
			$this->services->update($id, $data);
		} else {
			$data['add_time'] = time();
			$res = $this->services->save($data);
			if (!$res) {
				return $this->fail('保存失败');
			}
			$id = (int)$res->id;
		}

		//清除之前的绑定关系
		$storeServices->update(['region_id' => $id], ['region_id' => 0, 'is_region_alone' => 0]);
		//重新绑定
		if ($data['store_id']) $storeServices->update(['id' => $data['store_id']], ['region_id' => $id, 'is_region_alone' => $data['is_alone']]);
        return $this->success('操作成功!');
    }

	/**
	 * 删除门店区域
	 * @param SystemStoreServices $storeServices
	 * @param $id
	 * @return mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function delete(SystemStoreServices $storeServices, $id)
    {
		$id = (int)$id;
        if (!$id) return $this->fail('数据不存在');
        $storeInfo = $this->services->get($id);
        if (!$storeInfo) {
            return $this->fail('数据不存在');
        }
        if ($storeInfo['is_del'] == 0) {
			$this->services->update($id, ['is_del' => 1]);
        }
		//清空门店绑定该区域
		$storeServices->update(['region_id' => $id], ['region_id' => 0, 'is_region_alone' => 0]);
		return $this->success('删除区域成功!');
    }


	/**
	 * 区域删除关联门店
	 * @param SystemStoreServices $storeServices
	 * @param $id
	 * @return mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function delStore(SystemStoreServices $storeServices, $id)
	{
		$id = (int)$id;
		if (!$id) return $this->fail('数据不存在');
		[$store_id] = $this->request->postMore([
			['store_id', ''],//门店类型1：自营2加盟
		], true);
		$store_id = stringToIntArray($store_id);
		if (!$store_id) {
			return $this->fail('请选择门店');
		}
		$info = $this->services->get($id);
		$storeIds = is_string($info['store_id']) ? explode(',', $info['store_id']) : $info['store_id'];
		$newStoreIds = array_diff($storeIds, $store_id);
		//修改区域绑定门店数据
		$this->services->update($id, ['store_id' => $newStoreIds]);
		//修改门店绑定区域数据
		$storeServices->update(['id' => $store_id, 'region_id' => $id], ['region_id' => 0, 'is_region_alone' => 0]);
		return $this->success('操作成功!');
	}

}
