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
namespace app\dao\store;

use app\dao\BaseDao;
use app\model\store\SystemStore;

/**
 * 门店dao
 * Class SystemStoreDao
 * @package app\dao\store
 */
class SystemStoreDao extends BaseDao
{
    public function insertGetId(array $data)
    {
        return $this->getModel()->insertGetId($data);
    }
    /**
     * 设置模型
     * @return string
     */
    protected function setModel(): string
    {
        return SystemStore::class;
    }

	/**
	 * @param array $where
	 * @return \mohe\basic\BaseModel|mixed|\think\Model
	 */
	public function search(array $where = [])
	{
		return parent::search($where)->when(isset($where['ids']) && $where['ids'], function ($query) use ($where) {
			$query->whereIn('id', $where['ids']);
		})->when(isset($where['province']) && $where['province'], function ($query) use ($where) {
			$query->where('province', $where['province']);
		})->when(isset($where['salary_status']) && $where['salary_status'] != '', function ($query) use ($where) {
            $query->where('salary_status', $where['salary_status']);
        })->when(isset($where['store_id']) && $where['store_id'], function ($query) use ($where) {
            $query->where('id', $where['store_id']);
        })->when(isset($where['city']) && $where['city'], function ($query) use ($where) {
			$query->where('city', $where['city']);
		})->when(isset($where['area']) && $where['area'], function ($query) use ($where) {
			$query->where('area', $where['area']);
		})->when(isset($where['street']) && $where['street'], function ($query) use ($where) {
			$query->where('street', $where['street']);
//		})->when(isset($where['is_region']) && $where['is_region'] !== '', function ($query) use ($where) {
//			if ($where['is_region']) {//有绑定区域
//				$query->where('region_id', '>', 0);
//			} else {
//				$query->where('region_id', 0);
//			}
		});
	}

	/**
     * 经纬度排序计算
     * @param string $latitude
     * @param string $longitude
     * @return string
     */
    public function distance(string $latitude, string $longitude, bool $type = false)
    {
        if ($type) {
            return "(round(6378137 * 2 * asin(sqrt(pow(sin(((latitude * pi()) / 180 - ({$latitude} * pi()) / 180) / 2), 2) + cos(({$latitude} * pi()) / 180) * cos((latitude * pi()) / 180) * pow(sin(((longitude * pi()) / 180 - ({$longitude} * pi()) / 180) / 2), 2)))))";
        } else {
            return "(round(6378137 * 2 * asin(sqrt(pow(sin(((latitude * pi()) / 180 - ({$latitude} * pi()) / 180) / 2), 2) + cos(({$latitude} * pi()) / 180) * cos((latitude * pi()) / 180) * pow(sin(((longitude * pi()) / 180 - ({$longitude} * pi()) / 180) / 2), 2))))) AS distance";
        }
    }

	/**
	 * 获取列表
	 * @param array $where
	 * @param array $field
	 * @param int $page
	 * @param int $limit
	 * @return array
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function getList(array $where, array $field = ['*'], int $page = 0, int $limit = 0)
	{
		return $this->search($where)->when($page && $limit, function ($query) use ($page, $limit) {
			$query->page($page, $limit);
		})->order('id desc')->field($field)->select()->toArray();
	}

    /**
     * 获取
     * @param array $where
     * @param array $field
     * @param int $page
     * @param int $limit
     * @param string $latitude
     * @param string $longitude
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getStoreList(array $where, array $field = ['*'], int $page = 0, int $limit = 0, array $with = [], string $latitude = '', string $longitude = '', int $order = 0)
    {
        return $this->search($where)->when($with, function ($query) use ($with) {
			$query->with($with);
		})->when($latitude && $longitude, function ($query) use ($longitude, $latitude, $order, $field) {
			$field = array_merge($field, ['latitude', 'longitude', $this->distance($latitude, $longitude)]);
            $query->field($field);
        })->when($page && $limit, function ($query) use ($page, $limit) {
            $query->page($page, $limit);
        })->when(isset($order), function ($query) use ($order) {
            if ($order == 1) {
                $query->order('distance ASC');
            } else {
                $query->order('id desc');
            }
        })->field($field)->select()->toArray();
    }

    /**
     * 获取有效门店
     * @param array $where
     * @return \mohe\basic\BaseModel|mixed|\think\Model
     */
    public function getValidSearch(array $where = [])
    {
        $validWhere = [
            'is_show' => 1,
            'is_del' => 0,
        ];
        return $this->search($where)->where($validWhere);
    }

    /**
     * 获取最近距离距离内的一个门店
     * @param string $latitude
     * @param string $longitude
     * @return array|\think\Model|null
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getDistanceShortStore(string $latitude = '', string $longitude = '')
    {
        return $this->getValidSearch()->when($longitude && $longitude, function ($query) use ($longitude, $latitude) {
            $query->field(['*', $this->distance($latitude, $longitude)])->order('distance ASC');
        })->order('id desc')->find();
    }

    /**
     * 距离排序符合配送范围门店
     * @param string $latitude
     * @param string $longitude
     * @param string $field
     * @param int $limit
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getDistanceShortStoreList(string $latitude = '', string $longitude = '', string $field = '*', int $limit = 0)
    {
        return $this->getValidSearch()->field($field)->when($longitude && $longitude, function ($query) use ($longitude, $latitude, $field) {
            $query->field([$field, $this->distance($latitude, $longitude)])->where('valid_range', 'EXP', '>' . $this->distance($latitude, $longitude, true))->order('distance ASC');
        })->when($limit, function ($query) use ($limit) {
            $query->limit($limit);
        })->order('id desc')->select()->toArray();
    }

	/**
	 * 根据地址区、街道信息获取门店列表
	 * @param string $addressInfo
	 * @param array $where
	 * @param string $field
	 * @param int $page
	 * @param int $limit
	 * @param array $with
	 * @return array
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function getStoreByAddressInfo(string $addressInfo = '', array $where = [], string $field = '*', int $page = 0, int $limit = 0, array $with = [])
    {
        return $this->getValidSearch($where)->field($field)->when($with, function ($query) use ($with) {
				$query->with($with);
		})->when($addressInfo, function ($query) use ($addressInfo) {
            $query->whereLike('address', '%' . $addressInfo . '%');
        })->when($page && $limit, function ($query) use ($page, $limit) {
            $query->page($page, $limit);
        })->when(!$page && $limit, function ($query) use ($limit) {
            $query->limit($limit);
        })->order('id desc')->select()->toArray();
    }

    /**
     * 获取门店不分页
     * @param array $where
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getStore(array $where)
    {
        return $this->search($where)->order('add_time DESC')->field(['id', 'name'])->select()->toArray();
    }

    /**
     * 获取ERP店铺
     * @param array $where
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getErpStore(array $where, array $field = ['id','name','erp_shop_id'])
    {
        return $this->search(['type' => 0])->where($where)->field($field)->select()->toArray();
    }
}
