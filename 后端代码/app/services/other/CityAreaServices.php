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

namespace app\services\other;


use app\dao\other\CityAreaDao;
use app\services\BaseServices;
use mohe\exceptions\AdminException;
use mohe\services\CacheService;
use mohe\services\FormBuilder as Form;

/**
 * 城市数据（街道）
 * Class CityAreaServices
 * @package app\services\other
 * @mixin CityAreaDao
 */
class CityAreaServices extends BaseServices
{

	/**
	 * 城市数据
	 * @var string
	 */
	public $tree_city_key = 'tree_city_list';

    /**
     * 城市类型
     * @var string[]
     */
    public $type = [
        '1' => 'province',
        '2' => 'city',
        '3' => 'area',
        '4' => 'street'
    ];

    /**
     * CityAreaServices constructor.
     * @param CityAreaDao $dao
     */
    public function __construct(CityAreaDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 获取某一个城市id相关上级所有ids
     * @param int $id
     * @return array|int[]
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getRelationCityIds(int $id)
    {
        $cityInfo = $this->dao->get($id);
        $ids = [];
        if ($cityInfo) {
            $ids = explode('/', trim($cityInfo['path'], '/'));
        }
        return array_merge([$id], $ids);
    }

    /**
     * @param int $id
     * @param int $expire
     * @return bool|mixed|null
     */
    public function getRelationCityIdsCache(int $id, int $expire = 1800)
    {
        return CacheService::redisHandler('apiCity')->remember('city_ids_' . $id, function () use ($id) {
            $cityInfo = $this->dao->get($id);
            $ids = [];
            if ($cityInfo) {
                $ids = explode('/', trim($cityInfo['path'], '/'));
            }
            return array_merge([$id], $ids);
        }, $expire);
    }

	/**
	 * 获取城市列表
	 * @return bool|mixed|null
	 */
	public function getAllCityList()
	{
		return CacheService::get($this->tree_city_key, function () {
			return $this->getSonCityList();
		}, 86400);
	}

	/**
	 * @param $pid
	 * @param $parent_name
	 * @return array
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function getSonCityList($pid = 0, $parent_name = '中国')
	{
		$list = $this->dao->getCityList(['parent_id' => $pid], 'id,id as city_id,level,name');
		$arr = [];
		if ($list) {
			foreach ($list as $item) {
				$item['parent_id'] = $parent_name;
				$item['children'] = $this->getSonCityList($item['city_id'], $item['name']);
				$arr [] = $item;
			}
		}
		return $arr;
	}


	/**
	 * 获取城市数据
	 * @param int $pid
	 * @param int $type 1：省市 2：省市区 0、3：省市区街道
	 * @return false|mixed|string|null
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function getCityTreeList(int $pid = 0, int $type = 0)
    {
		$cityList = $this->dao->cacheStrRemember('pid_' . $pid, function () use ($pid) {
			$parent_name = '中国';
			if ($pid) {
				$city = $this->dao->get($pid);
				$parent_name = $city ? $city['name'] : '';
			}
			$cityList = $this->dao->getCityList(['parent_id' => $pid], 'id as value,id,name as label,parent_id as pid,level', ['children']);
			foreach ($cityList as &$item) {
				$item['parent_name'] = $parent_name;
				if (isset($item['children']) && $item['children']) {
					$item['children'] = [];
					$item['loading'] = false;
					$item['_loading'] = false;
				} else {
					unset($item['children']);
				}
			}
			return $cityList;
		});
		if ($cityList) {
			switch ($type) {
				case 0:
				case 3:
					break;
				case 1://控制children 前端不能请求下一级数据
					foreach ($cityList as &$item) {
						if ($item['level'] == 2) {
							unset($item['children'], $item['loading'], $item['_loading']);
						}
					}
					break;
				case 2:
					foreach ($cityList as &$item) {
						if ($item['level'] == 3) {
							unset($item['children'], $item['loading'], $item['_loading']);
						}
					}
					break;
			}
		}

        return $cityList;
    }

	/**
	 * 添加城市数据表单
	 * @param int $parentId
	 * @return mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function createCityForm(int $parentId)
    {
        $info = [];
        if ($parentId) {
            $info = $this->dao->get($parentId);
        }
        $field[] = Form::hidden('level', $info['level'] ?? 0);
        $field[] = Form::hidden('parent_id', $info['id'] ?? 0);
        $field[] = Form::input('parent_name', '父类名称：', $info['name'] ?? '中国')->disabled(true);
        $field[] = Form::input('name', '名称：')->required('请填写城市名称');
        return create_form('添加城市', $field, $this->url('/setting/city/save'));
    }

	/**
	 * 添加城市数据创建
	 * @param int $id
	 * @return mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function updateCityForm(int $id)
    {
        $info = $this->dao->get($id);
        if (!$info) {
            throw new AdminException('需改的数据不存在');
        }
        if ($info['parent_id']) {
            $city = $this->dao->get($info['parent_id']);
            $info['parent_name'] = $city['name'];
        }
        $info = $info->toArray();
        $field[] = Form::hidden('id', $info['id']);
        $field[] = Form::hidden('level', $info['level']);
        $field[] = Form::hidden('parent_id', $info['parent_id']);
        $field[] = Form::input('parent_name', '父类名称：', $info['parent_name'] ?? '中国')->disabled(true);
        $field[] = Form::input('name', '名称：', $info['name'])->required('请填写城市名称');
        return create_form('修改城市', $field, $this->url('/setting/city/save'));
    }


	/**
	 * 根据地址，返回解析后省市区ID
	 * @param string $address
	 * @param string $separator
	 * @return array
	 */
	public function getCityIdByAddress(string $address, string $separator = ' ')
	{
		$province = $city = $area = $street = 0;
		if ($address) {
			$address = str_replace($separator, '/', $address);
			$city = $this->dao->searchCity(compact('address'));
			if ($city) {
				$where = [['id', 'in', array_merge([$city['id']], explode('/', trim($city->path, '/')))]];
				$cityList = $this->dao->getCityList($where, 'id,level');
				if ($cityList) {
					foreach ($cityList as $item) {
						switch ($item['level']) {
							case 1://省
								$province = $item['id'];
								break;
							case 2://市
								$city = $item['id'];
								break;
							case 3://区县
								$area = $item['id'];
								break;
							case 4://街道
								$street = $item['id'];
								break;
						}
					}
				}
			}
		}
		return [$province, $city, $area, $street];
	}

    /**
     * 根据省市区名称获取对应 id
     * @param $province
     * @param $city
     * @param $area
     * @return int[]
     * @throws \ReflectionException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getCityId(string $province = '',string $city = '',string $district = '',string $street = '',string $address = '')
    {
        $result = [
            'province' => 0,
            'city' => 0,
            'district' => 0,
            'street' => 0
        ];
        if(!$address) {
            $address = "{$province}/{$city}/{$district}/{$street}";
        }
        $info = $this->dao->search(['address' => $address])->find();

        if (!$info) {
            return $result;
        }

        $path = explode('/', $info['path']);
        $data = [];
        switch (count($path)) {
            case 0:
                $data['province'] = $info['id'];
                break;
            case 1:
                $data['province'] = $path[1];
                $data['city'] = $info['id'];
                break;
            case 2:
                $data['province'] = $path[1];
                $data['city'] = $path[2];
                $data['district'] = $info['id'];
                break;
            case 3:
                $data['province'] = $path[1];
                $data['city'] = $path[2];
                $data['district'] = $path[3];
                $data['street'] =  $info['id'];
                break;
            default:
                $data = [
                    'province' => $path[1],
                    'city' => $path[2],
                    'district' => $path[3],
                    'street' => $info['id']
                ];
        }
        return $data;
    }
}
