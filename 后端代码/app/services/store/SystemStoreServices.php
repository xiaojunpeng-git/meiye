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
namespace app\services\store;

use app\dao\store\SystemStoreDao;
use app\jobs\user\UserBelongStoreJob;
use app\services\agent\SystemRegionAgentServices;
use app\services\BaseServices;
use app\services\employee\EmployeeStaffWriteServices;
use app\services\order\DeliveryConfigServices;
use app\services\order\store\BranchOrderServices;
use app\services\order\StoreDeliveryOrderServices;
use app\services\order\StoreOrderCartInfoServices;
use app\services\order\StoreOrderServices;
use app\services\other\CityAreaServices;
use app\services\other\QrcodeServices;
use app\services\product\branch\StoreBranchProductServices;
use app\services\product\product\StoreProductServices;
use app\services\product\category\StoreProductCategoryServices;
use app\services\product\sku\StoreProductAttrValueServices;
use app\services\store\finance\StoreFinanceFlowServices;
use app\services\system\admin\SystemAdminServices;
use app\services\system\SystemRoleServices;
use app\services\system\SystemUserApplyServices;
use app\services\user\UserAddressServices;
use app\services\user\UserServices;
use app\services\user\UserVisitStoreServices;
use mohe\exceptions\AdminException;
use mohe\services\CacheService;
use mohe\services\erp\Erp;
use mohe\services\FormBuilder as Form;
use mohe\services\SystemConfigService;
use think\exception\ValidateException;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Log;


/**
 * 门店
 * Class SystemStoreServices
 * @package app\services\system\store
 * @mixin SystemStoreDao
 */
class SystemStoreServices extends BaseServices
{
    /**
     * 进店规则
     * @var string
     */
    public $cacheTag = 'entry_store';

    /**
     * 门店类型
     * @var string[]
     */
    public $typeName = [
        1 => '自营店',
        2 => '加盟店'
    ];

    const RANGE_TYPE_METHOD = [
        1 => 'checkRadius',
        2 => 'checkRegion',
        3 => 'checkFence',
    ];

    /**
     * @param SystemStoreDao $dao
     */
    public function __construct(SystemStoreDao $dao)
    {
        $this->dao = $dao;
    }


    /**
     * 获取门店二维码
     * @param int $id
     * @param int $staff_id
     * @return array
     */
    public function getStoreQrcode(int $id, int $staff_id = 0)
    {
        //生成h5地址
        $weixinPage = "/pages/store/home/index?id=" . $id . '&staff_id=' . $staff_id;
        $weixinFileName = "wechat_store_cate_id_" . $id . ".png";
        /** @var QrcodeServices $QrcodeService */
        $QrcodeService = app()->make(QrcodeServices::class);
        $wechatQrcode = '';
        $routineQrcode = '';
        try {
            $wechatQrcode = $QrcodeService->getWechatQrcodePath($weixinFileName, $weixinPage, false, false) ?: '';
        } catch (\Throwable $e) {
            $wechatQrcode = '';
        }
        try {
            //生成小程序地址（本地无外网/微信配置时返回 false，不得中断门店 info）
            $routineQrcode = $QrcodeService->getRoutineQrcodePath($id, 0, 10, $weixinFileName, true, ['staff_id' => $staff_id]) ?: '';
        } catch (\Throwable $e) {
            $routineQrcode = '';
        }
        return ['wechat' => $wechatQrcode, 'routine' => $routineQrcode, 'url' => $weixinPage];
    }

    /**
     * 获取单个门店信息
     * @param int $id
     * @return array|\think\Model|null
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getStoreInfo(int $id)
    {
        $storeInfo = $this->dao->getOne(['id' => $id, 'is_del' => 0]);
        if (!$storeInfo) {
            throw new ValidateException('获取门店信息失败');
        }
        $storeInfo = $storeInfo->toArray();
        if ($storeInfo['store_rate_type'] == 1) {//默认
            $finance = $this->getStoreFinanceConfig();
            $storeInfo = array_merge($storeInfo, $finance);
        } else {
            $storeInfo['store_svip_order_rate'] = sys_config('store_svip_order_rate');
            $storeInfo['store_recharge_order_rate'] = sys_config('store_recharge_order_rate');
        }
        try {
            $storeInfo['qrcode'] = $this->getStoreQrcode($id);
        } catch (\Throwable $e) {
            // 二维码依赖微信/外网；失败不影响门店基础信息（请货/调拨等页依赖本接口）
            $storeInfo['qrcode'] = ['wechat' => '', 'routine' => '', 'url' => ''];
        }
        $storeInfo['day_time'] = $storeInfo['day_time'] ? explode('-', $storeInfo['day_time']) : [];
        $storeInfo['addressSelect'] = [$storeInfo['province'], $storeInfo['city'], $storeInfo['area'], $storeInfo['street']];
        return $storeInfo;
    }

    /**
     * 根据门店获取财务配置
     * @param int $id
     * @return array|int[]
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getStoreFinanceConfig(int $id = 0)
    {
        $storeInfo = [];
        if ($id) {//查询门店配置
            $storeInfo = $this->dao->get($id);
        }
        if ($storeInfo && isset($storeInfo['store_rate_type']) && $storeInfo['store_rate_type'] == 2) {//自定义
            $data = [
                'store_cashier_order_rate' => $storeInfo['store_cashier_order_rate'] ?? 0,
                'store_self_order_rate' => $storeInfo['store_self_order_rate'] ?? 0,
                'store_writeoff_order_rate' => $storeInfo['store_writeoff_order_rate'] ?? 0,
				'store_recharge_order_rate' => $storeInfo['store_recharge_order_rate'] ?? 0,
				'store_svip_order_rate' => $storeInfo['store_svip_order_rate'] ?? 0,
            ];
        } else {
            $data = SystemConfigService::more([
                'store_cashier_order_rate',
                'store_self_order_rate',
                'store_writeoff_order_rate',
				'store_recharge_order_rate',
				'store_svip_order_rate'
            ]);
        }
        return $data;
    }

    /**
     * 获取默认的进店规格参数
     * @param array $param
     * @return array
     */
    public function getDefaultEntryRulesParam(array $param = [])
    {
        $default = [
            'latitude' => '',
            'longitude' => '',
            'store_id' => 0,
            'select_store_id' => 0,
            'ip' => request()->ip()
        ];
        return $param ? array_merge($default, $param) : $default;
    }

    /**
     * 处理定位经纬度，成城市
     * @param string $latitude
     * @param string $longitude
     * @return array|false|string[]
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function handleLocation(string $latitude, string $longitude)
    {
        $nowCity = [];
        if (!$latitude || !$longitude) {
            return $nowCity;
        }
        //存在经纬度验证定位区域
        $result = geoLbscoder($latitude, $longitude);
        $address = $result['address'] ?? '';
        //解析初地址
        if ($address) {
            if (strpos($address, '/') === false) {
                $address = implode('/', array_values($this->addressHandle($address)));
            }
            /** @var CityAreaServices $cityAreaServices */
            $cityAreaServices = app()->make(CityAreaServices::class);
            $city = $cityAreaServices->searchCity(compact('address'));
            if ($city) {
                $nowCity = array_merge(explode('/', trim($city['path'], '/')), [$city['id']]);
            }
        }
        return $nowCity;
    }

    /**
     * 清空进店规则缓存
     * @return bool
     */
    public function clearEntryStoreCache()
    {
        CacheService::redisHandler($this->cacheTag)->clear();
        return true;
    }

    /**
     * 根据进店规则：获取当前进入的门店ID
     * @param int $uid
     * @param array $param
     * @return array
     */
    public function getStoreIdByEntryRules(int $uid = 0, array $param = [], bool $isCache = false)
    {
        $storeId = 0;
        $default = '';
        $param = $this->getDefaultEntryRulesParam($param);
        $selectStoreId = (int)($param['select_store_id'] ?? 0);
        unset($param['select_store_id']);
        $userEntryRules = SystemConfigService::get('user_entry_rules', []);
        $shopOperationType = sys_config('shop_operation_type', 1);
        if ($uid) {
            $cacheKey = md5('entry_store' . $uid);
        } else {
            $cacheKey = md5('entry_store_' . json_encode($param));
        }
        $user_entry_name = '';
        $data = CacheService::get($cacheKey);
        if (!$data || $isCache) {//移动端请求更新缓存
            //用户移动端切换门店
            if ($selectStoreId) {
                $storeId = $selectStoreId;
            } else {
                if ($userEntryRules) {//设置进店规则
                    $nowCity = [];
                    if ($param['latitude'] && $param['longitude']) {
                        //存在经纬度验证定位区域
                        $nowCity = $this->handleLocation((string)$param['latitude'], (string)$param['longitude']);
                    }
                    foreach ($userEntryRules as $rule) {
                        //禁用状态跳过
                        if (!isset($rule['is_use']) || !$rule['is_use']) continue;
                        switch ($rule['name']) {
                            case 'user_belong_store'://用户归属门店
                                if ($uid) {
                                    /** @var UserServices $userServices */
                                    $userServices = app()->make(UserServices::class);
                                    $userInfo = $userServices->getUserCacheInfo($uid);
                                    $storeId = $userInfo['belong_store_id'] ?? 0;
                                    if ($storeId) {//门店未停业
                                        $count = $this->dao->count(['is_del' => 0, 'id' => $storeId, 'is_show' => 1]);
                                        if (!$count) $storeId = 0;
                                    }
                                }
                                break;
                            case 'user_param_store'://带参门店
                                $storeId = $param['store_id'] ?? 0;
                                break;
                            case 'user_visit_store'://最近访问门店
                                if ($uid) {
                                    /** @var UserVisitStoreServices $userVisitStoreServices */
                                    $userVisitStoreServices = app()->make(UserVisitStoreServices::class);
                                    $storeId = $userVisitStoreServices->getUserNearVisitStore($uid);
                                    if ($rule['is_alone'] && $storeId) {//仅隔离门店进入
                                        $count = $this->dao->count(['is_del' => 0, 'id' => $storeId, 'is_alone' => 1]);
                                        if (!$count) $storeId = 0;
                                    }
                                }
                                break;
                            case 'region_recommend_store'://地区定向推荐门店
                                $storeId = $rule['store_id'] ?? 0;
                                if ($rule['region_id'] && $storeId) {
                                    if ($nowCity) {
                                        $regionCity = array_map(function ($item) {
                                            return end($item);
                                        }, $rule['region_id']);
                                        //不再推荐区域
                                        if (!array_intersect($regionCity, $nowCity)) {
                                            $storeId = 0;
                                        }
                                    } else {
                                        $storeId = 0;
                                    }
                                }
                                break;
                            case 'user_locate_store'://用户定位推荐门店
                                $where = [];
                                if ($rule['is_region_alone'] && $param['latitude'] && $param['longitude']) {//开启区域隔离推荐
                                    //存在经纬度验证定位区域
                                    /** @var SystemRegionAgentServices $regionServices */
                                    $regionServices = app()->make(SystemRegionAgentServices::class);
                                    $region_id = $regionServices->getRegionByCity($nowCity);
                                    if ($region_id) {
                                        $where['region_id'] = $region_id;
                                    }
                                }
                                $nearStore = $this->getNearbyStore($where, $param['latitude'] ?? '', $param['longitude'] ?? '', $param['ip'] ?? '', 1);
                                $storeId = $nearStore['id'] ?? 0;
                                break;
                            case 'default_store'://无门店推荐结果，直接进入指定页面
                                $storeId = $rule['default_type'] == 1 ? ($rule['store_id'] ?? 0) : 0;
                                $default = $rule['default_type'] == 2 ? ($rule['url'] ?? '') : '';
                                break;
                        }
                        $storeId = (int)$storeId;
                        if ($storeId && $this->dao->count(['store_id' => $storeId, 'is_del' => 0, 'is_show' => 1])) {//匹配到进入门店ID 结束
                            $user_entry_name = $rule['name'] ?? '';
                            break;
                        }
                    }
                }
                if (!$storeId) {//进店规则未进入门店，默认取一个
                    $nearStore = $this->getNearbyStore([], $param['latitude'] ?? '', $param['longitude'] ?? '', $param['ip'] ?? '', 1);
                    $storeId = $nearStore['id'] ?? 0;
                }
            }
            $data = ['store_id' => $storeId];
            CacheService::redisHandler($this->cacheTag)->set($cacheKey, $data, 3 * 24 * 3600);
        }
        return array_merge($data, ['default' => $default, 'shop_operation_type' => $shopOperationType, 'user_entry_rules' => $userEntryRules, 'user_entry_name' => $user_entry_name]);
    }

    /**
     * 根据门店，获取当前门店所在区域内所有门店
     * @param int $storeId
     * @return array|void
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getRegionStoreIds(int $storeId)
    {
        $ids = [];
        $storeInfo = $this->dao->get($storeId);
        if (!$storeInfo) {
            return $ids;
        }
        //门店未设置所在区域
        if (!$storeInfo['region_id']) {
            return $ids;
        }
        /** @var SystemRegionAgentServices $regionAgentServices */
        $regionAgentServices = app()->make(SystemRegionAgentServices::class);
        $region = $regionAgentServices->get((int)$storeInfo['region_id']);
        if (!$region) {
            return $ids;
        }
        $region = is_object($region) ? $region->toArray() : $region;
        $regionIds = [(int)$storeInfo['region_id']];
        while (empty($region['is_alone']) && !empty($region['pid'])) {
            $pid = (int)$region['pid'];
            $regionIds[] = $pid;
            $parent = $regionAgentServices->get($pid);
            if (!$parent) {
                break;
            }
            $region = is_object($parent) ? $parent->toArray() : $parent;
        }
        //所在区域是设置为隔离，切换本区域内门店
        if (!empty($region['is_alone'])) {//区域隔离
            $ids = $this->dao->getColumn(['is_show' => 1, 'is_del' => 0, 'region_id' => $regionIds], 'id');
        } else {//不隔离，获取所有区域不隔离门迪娜
            $ids = $this->dao->getColumn(['is_show' => 1, 'is_del' => 0, 'is_region_alone' => 0], 'id');
        }
        return $ids;
    }

    /**
     * 首页获取门店列表
     * @param array $where
     * @param string $latitude
     * @param string $longitude
     * @param string $ip
     * @param int $limit
     * @param int $product_id
     * @param int $store_id
     * @return array|mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getHomeStoreList(int $uid, array $where = [], string $latitude = '', string $longitude = '', string $ip = '', int $limit = 0, int $product_num = 0)
    {
        $where = array_merge($where, ['status' => 1, 'is_del' => 0]);
        if ($limit) {
            $page = 1;
            $field = ['*'];
        } else {
            [$page, $limit] = $this->getPageValue();
            $field = ['id', 'name', 'phone', 'image', 'background_image', 'latitude', 'longitude', 'address', 'detailed_address', 'is_show', 'day_time', 'day_start', 'day_end', 'valid_range', 'delivery_type', 'use_system_money', 'is_alone'];
        }
        //默认附近门店
        $store_type = $where['store_type'] ?? 1;
        $uid = $where['uid'] ?? 0;
        unset($where['store_type'], $where['uid']);
        if ($store_type != 1) {//常用门店
            if ($uid) {
                /** @var StoreUserServices $storeUserServices */
                $storeUserServices = app()->make(StoreUserServices::class);
                $ids = $storeUserServices->getColumn(['uid' => $uid], 'store_id');
                if (!$ids) {
                    return [];
                }
                $where['ids'] = $ids;
            } else {//没登录，无常用门店
                return [];
            }
        }
        $with = [];
        $storeList = [];
        if (isset($where['ids'])) {
            $where['ids'] = array_unique(array_diff($where['ids'], [0]));
        }
        if (isset($where['id']) && $where['id']) {
            $storeList = $this->dao->getStoreList($where, $field, $page, $limit, $with, $latitude, $longitude, $latitude && $longitude ? 1 : 0);
        } elseif ($latitude && $longitude) {
            $storeList = $this->dao->getStoreList($where, $field, $page, $limit, $with, $latitude, $longitude, 1);
        } elseif ((isset($where['province']) && $where['province']) || (isset($where['city']) && $where['city']) || (isset($where['area']) && $where['area'])) {
            $storeList = $this->dao->getStoreList($where, $field, $page, $limit, $with, $latitude, $longitude, $latitude && $longitude ? 1 : 0);
        } elseif ((isset($where['province']) && $where['province']) && (isset($where['city']) && $where['city']) && (isset($where['area']) && $where['area']) && !$latitude && !$longitude) {
            $storeList = $this->dao->getStoreList($where, $field, $page, $limit);
        } elseif ($ip) {
            $strField = $limit == 1 ? '*' : implode(',', $field);
            $addressArr = $this->addressHandle(convertIpToCity($ip));
            $city = $addressArr['city'] ?? '';
            if ($city) {
                $storeList = $this->dao->getStoreByAddressInfo($city, $where, $strField, $page, $limit, $with);
            }
            $province = $addressArr['province'] ?? '';
            if (!$storeList && $province) {
                $storeList = $this->dao->getStoreByAddressInfo($province, $where, $strField, $page, $limit, $with);
            }
        }
        //上面条件都没获取到门店
        if (!$storeList) {
            $storeList = $this->dao->getStoreList($where, $field, $page, $limit, $with);
        }
        if ($storeList) {
            /** @var StoreProductServices $productServices */
            $productServices = app()->make(StoreProductServices::class);
            $productWhere = ['is_del' => 0, 'is_show' => 1, 'is_verify' => 1, 'show_type' => [0, 1]];
            foreach ($storeList as &$item) {
                $item['range'] = 0;
                if (isset($item['distance'])) {
                    $item['range'] = bcdiv($item['distance'], '1000', 1);
                } else {
                    $item['range'] = 0;
                }
                if (isset($item['is_show']) && $item['is_show'] == 1) {
                    $item['status_name'] = '营业中';
                } else {
                    $item['status_name'] = '已停业';
                }
                if (!empty($item['day_start']) && !empty($item['day_end'])) {
                    $start = date('H:i', strtotime($item['day_start']));
                    $end = date('H:i', strtotime($item['day_end']));
                    $item['day_time'] = implode(' - ', [$start, $end]);
                }
                $item['product'] = [];
                if ($product_num) {
                    $item['product'] = $productServices->getSearchList($productWhere + ['type' => 1, 'relation_id' => $item['id']], 0, $product_num, ['id', 'type', 'relation_id', 'store_name', 'image', 'price'], 'sort desc,id desc', []);
                }
            }
        }
        return $storeList;
    }


    /**
     * 附近门店
     * @param array $where
     * @param string $latitude
     * @param string $longitude
     * @param string $ip
     * @param int $limit
     * @param int $product_id
     * @param int $store_id
     * @return array|mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getNearbyStore(array $where, string $latitude, string $longitude, string $ip = '', int $limit = 0, int $product_id = 0, int $store_id = 0)
    {
        $where = array_merge($where, ['status' => 1]);
        if ($limit) {
            $page = 1;
            $field = ['*'];
        } else {
            [$page, $limit] = $this->getPageValue();
            $field = ['id', 'name', 'phone', 'image', 'latitude', 'longitude', 'address', 'detailed_address', 'is_show', 'day_time', 'day_start', 'day_end', 'valid_range', 'delivery_type', 'use_system_money', 'is_alone'];
        }
        //默认附近门店
        $store_type = $where['store_type'] ?? 1;
        $uid = $where['uid'] ?? 0;
        $is_all = $where['is_all'] ?? 0;
        unset($where['store_type'], $where['uid'], $where['is_all']);
        if (!$is_all && $store_type != 1) {//常用门店
            if ($uid) {
                /** @var StoreUserServices $storeUserServices */
                $storeUserServices = app()->make(StoreUserServices::class);
                $ids = $storeUserServices->getColumn(['uid' => $uid], 'store_id');
                if (!$ids) {
                    return [];
                }
                $where['ids'] = $ids;
            } else {//没登录，无常用门店
                return [];
            }
        }
        //移动端选择的门店（预约选店等全量场景不过滤）
        if (!$is_all && $store_id) {
            $where['ids'] = [$store_id];
        }
        //该商品上架的门店
        if ($product_id) {
            /** @var StoreBranchProductServices $productServices */
            $productServices = app()->make(StoreBranchProductServices::class);
            [$ids, $applicableType, $productType] = $productServices->getApplicableStoreIds($product_id);
            if ($ids) {
                if ($productType == 1) {//门店商品
                    $where['ids'] = $ids;
                } else {
                    if ($store_id && !in_array($store_id, $ids)) {
                        return [];
                    }
                    if (isset($where['ids']) && $where['ids']) {
                        $ids = array_intersect($where['ids'], $ids);
                    }
                    if ($ids) {
                        $where['ids'] = $ids;
                    } else {
                        return [];
                    }
                }
            } else {
                if ($applicableType != 1) {//不是所有门店
                    return [];
                }
            }
        }
        $storeList = [];
        if (isset($where['ids'])) {
            $where['ids'] = array_unique(array_diff($where['ids'], [0]));
        }
        if (isset($where['id']) && $where['id']) {
            $storeList = $this->dao->getStoreList($where, $field, $page, $limit, [], $latitude, $longitude, $latitude && $longitude ? 1 : 0);
        } elseif ($latitude && $longitude) {
            $storeList = $this->dao->getStoreList($where, $field, $page, $limit, [], $latitude, $longitude, 1);
        } elseif ((isset($where['province']) && $where['province']) || (isset($where['city']) && $where['city']) || (isset($where['area']) && $where['area'])) {
            $storeList = $this->dao->getStoreList($where, $field, $page, $limit, [], $latitude, $longitude, $latitude && $longitude ? 1 : 0);
        } elseif ((isset($where['province']) && $where['province']) && (isset($where['city']) && $where['city']) && (isset($where['area']) && $where['area']) && !$latitude && !$longitude) {
            $storeList = $this->dao->getStoreList($where, $field, $page, $limit);
        } elseif ($ip) {
            $strField = $limit == 1 ? '*' : implode(',', $field);
            $addressArr = $this->addressHandle(convertIpToCity($ip));
            $city = $addressArr['city'] ?? '';
            if ($city) {
                $storeList = $this->dao->getStoreByAddressInfo($city, $where, $strField, $page, $limit);
            }
            $province = $addressArr['province'] ?? '';
            if (!$storeList && $province) {
                $storeList = $this->dao->getStoreByAddressInfo($province, $where, $strField, $page, $limit);
            }
        }
        //上面条件都没获取到门店
        if (!$storeList) {
            $storeList = $this->dao->getStoreList($where, $field, $page, $limit);
        }
        /** @var DeliveryConfigServices $deliveryConfigService */
        $deliveryConfigService = app()->make(DeliveryConfigServices::class);
        if ($storeList) {
            foreach ($storeList as &$item) {
                $item['range'] = 0;
                if (isset($item['distance'])) {
                    $item['range'] = bcdiv($item['distance'], '1000', 1);
                } else {
                    $item['range'] = 0;
                }
                if (isset($item['is_show']) && $item['is_show'] == 1) {
                    $item['status_name'] = '营业中';
                } else {
                    $item['status_name'] = '已停业';
                }
                if (!empty($item['day_start']) && !empty($item['day_end'])) {
                    $start = date('H:i', strtotime($item['day_start']));
                    $end = date('H:i', strtotime($item['day_end']));
                    $item['day_time'] = implode(' - ', [$start, $end]);
                }
            }
        }
        $storeList = $limit == 1 ? ($storeList[0] ?? []) : $storeList;
        if ($limit == 1 && $storeList) {
            $deliveryConfig = $deliveryConfigService->get(['type' => 1, 'relation_id' => $storeList['id']], ['delivery_time_type', 'selectable_days', 'delivery_prompt']);
            if (!$deliveryConfig) {
                $storeList['delivery_config'] = [];
            } else {
                $deliveryConfig = $deliveryConfig->toArray();
                $storeList['delivery_config'] = $deliveryConfig;
            }
        }
        return $storeList;
    }

    /**
     * 获取门店
     * @param array $where
     * @param array $field
     * @param string $latitude
     * @param string $longitude
     * @param int $product_id
     * @param array $with
     * @param int $type 0:普通1：秒杀
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getStoreList(array $where, array $field = ['*'], string $latitude = '', string $longitude = '', int $product_id = 0, array $with = [], int $type = 0, $store_id = 0)
    {
        $order = 0;
        if (isset($where['order_id']) && $where['order_id']) {
            /** @var StoreOrderServices $storeOrderServices */
            $storeOrderServices = app()->make(StoreOrderServices::class);
            $user_location = $storeOrderServices->value(['id' => $where['order_id']], 'user_location');
            if ($user_location) {
                [$longitude, $latitude] = explode(' ', $user_location);
            }
        }
        if ($longitude && $latitude) {
            $order = 1;
        }
        $list = [];
        $count = 0;
        //移动端定位选择门店
        if ($store_id) {
            $where['ids'] = [$store_id];
        }
        //该商品上架的门店
        if ($product_id) {
            /** @var StoreBranchProductServices $productServices */
            $productServices = app()->make(StoreBranchProductServices::class);
            [$ids, $applicableType, $productType] = $productServices->getApplicableStoreIds($product_id, $type);
            if ($ids) {
                if ($productType == 1) {//门店商品
                    $where['ids'] = $ids;
                } else {
                    if ($store_id && !in_array($store_id, $ids)) {
                        return compact('list', 'count');
                    }
                    if (isset($where['ids']) && $where['ids']) {
                        $ids = array_intersect($where['ids'], $ids);
                    }
                    if ($ids) {
                        $where['ids'] = $ids;
                    } else {
                        return compact('list', 'count');
                    }
                    $where['ids'] = isset($where['ids']) && $where['ids'] ? array_intersect($where['ids'], $ids) : $ids;
                }
            } else {
                if ($applicableType != 1) {//不是所有门店
                    return compact('list', 'count');
                }
            }
        }
        $oid = (int)($where['order_id'] ?? 0);
        unset($where['order_id']);
        if (isset($where['ids'])) {//有选择门店 || 商品适用门店情况
            if (isset($where['id']) && $where['id']) {//合并进店规则进入门店
                $where['ids'] = array_merge($where['ids'], is_array($where['id']) ? $where['id'] : [$where['id']]);
                unset($where['id']);
            }
            $where['ids'] = array_unique(array_diff($where['ids'], [0]));
        }
        if ($oid || $product_id) {//不分页
            $page = $limit = 0;
        } else {
            [$page, $limit] = $this->getPageValue();
        }
        $storeList = $this->dao->getStoreList($where, $field, $page, $limit, $with, $latitude, $longitude, $order);
        if ($storeList) {
            $storeIds = [];
            if ($oid) {
                [$storeIds, $cartInfo] = $this->checkOrderProductShare($oid, $storeList, 2);
            }
            $prefix = config('admin.store_prefix');
            $regionIds = array_values(array_unique(array_filter(array_map('intval', array_column($storeList, 'region_id')))));
            $manageRegionNames = [];
            $managerNames = [];
            $agentNicknameMap = [];
            if ($regionIds) {
                /** @var SystemRegionAgentServices $storeRegionServices */
                $storeRegionServices = app()->make(SystemRegionAgentServices::class);
                /** @var SystemRegionManageServices $manageServices */
                $manageServices = app()->make(SystemRegionManageServices::class);
                /** @var SystemAdminServices $adminServices */
                $adminServices = app()->make(SystemAdminServices::class);
                $agents = $storeRegionServices->dao->getList(
                    ['id' => $regionIds, 'is_del' => 0],
                    'id,name,nickname,manage_region_id'
                ) ?: [];
                foreach ($agents as $agent) {
                    $agentId = (int)($agent['id'] ?? 0);
                    if ($agentId <= 0) {
                        continue;
                    }
                    $agentNicknameMap[$agentId] = trim((string)($agent['nickname'] ?? '')) ?: trim((string)($agent['name'] ?? ''));
                    $manageRegionId = (int)($agent['manage_region_id'] ?? 0);
                    if (!$manageRegionId) {
                        $manageRegionId = $manageServices->findManageRegionIdByAgentId($agentId);
                    }
                    if ($manageRegionId) {
                        $archInfo = $manageServices->getRegionInfo($manageRegionId);
                        $manageRegionNames[$agentId] = $archInfo['name'] ?? '';
                    }
                }
                $adminRows = $adminServices->dao->selectList([
                    ['relation_id', 'in', $regionIds],
                    ['admin_type', '=', 3],
                    ['level', '=', 0],
                    ['is_del', '=', 0],
                ], 'relation_id,account,real_name', 0, 0, '', false) ?: [];
                foreach ($adminRows as $admin) {
                    $relationId = (int)($admin['relation_id'] ?? 0);
                    if ($relationId <= 0) {
                        continue;
                    }
                    $managerNames[$relationId] = trim((string)($admin['real_name'] ?? '')) ?: trim((string)($admin['account'] ?? ''));
                }
            }
            $now = date('Hi');
            foreach ($storeList as &$item) {
                $item['type_name'] = '';
                if (isset($item['type'])) {
                    $item['type_name'] = $this->typeName[$item['type']] ?? '';
                }
                if (isset($item['distance'])) {
                    $item['range'] = bcdiv($item['distance'], '1000', 1);
                } else {
                    $item['range'] = 0;
                }
                $item['business_status'] = 0;
                if ($item['is_show'] == 1) {
                    $item['status_name'] = '营业中';
                    if (!empty($item['day_start']) && !empty($item['day_end'])) {
                        $start = date('Hi', strtotime($item['day_start']));
                        $end = date('Hi', strtotime($item['day_end']));
                        if ($now >= $start && $now <= $end) {
                            $item['business_status'] = 1;
                        }
                    }
                } else {
                    $item['status_name'] = '已停业';
                }

                $item['prefix'] = $prefix;
                $regionId = (int)($item['region_id'] ?? 0);
                $item['manage_region_name'] = $manageRegionNames[$regionId] ?? '';
                $item['region_manager_name'] = $managerNames[$regionId] ?? ($agentNicknameMap[$regionId] ?? '');
                $item['recommend_region_name'] = $item['manage_region_name'];
                if ($oid) {
                    if (in_array($item['id'], $storeIds)) {
                        $list[] = $item;
                    }
                } else {
                    $list[] = $item;
                }
            }
            if (count($list) && $store_id) {
                $arr = [];
                foreach ($list as $key => $value) {
                    if ($value['id'] == $store_id) {
                        $arr = $value;
                        unset($list[$key]);
                    }
                }
                if ($arr) array_unshift($list, $arr);
            }
            if ($oid) {
                $count = count($list);
            } else {
                $count = $this->dao->count($where);
            }
        }

        return compact('list', 'count');
    }

    /**
     * 获取门店头部统计信息
     * @return mixed
     */
    public function getStoreData()
    {
        $data['show'] = [
            'name' => '显示中的门店',
            'num' => $this->dao->count(['type' => 0]),
        ];
        $data['hide'] = [
            'name' => '隐藏中的门店',
            'num' => $this->dao->count(['type' => 1]),
        ];
        $data['recycle'] = [
            'name' => '回收站的门店',
            'num' => $this->dao->count(['type' => 2])
        ];
        return $data;
    }

    /**
     * 门店重置账号密码表单
     * @param int $id
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function storeAdminAccountForm(int $id)
    {
        $storeInfo = $this->getStoreInfo($id);
        /** @var SystemStoreStaffServices $staffServices */
        $staffServices = app()->make(SystemStoreStaffServices::class);
        $staffInfo = $staffServices->getOne(['store_id' => $storeInfo['id'], 'level' => 0, 'is_admin' => 1, 'is_manager' => 1, 'is_del' => 0]);
        $field[] = Form::hidden('staff_id', $staffInfo['id'] ?? 0);
        $field[] = Form::input('phone', '管理员手机号：', $staffInfo['phone'] ?? '')->col(24)->required('请输入手机号')->info('重置手机号：会同步清除之前绑定的商城用户信息，需要在门店后台重新编辑绑定');
        $field[] = Form::input('account', '管理员账号：', $staffInfo['account'] ?? '')->col(24)->required('请输入账号');
        $field[] = Form::input('password', '登录密码：')->type('password')->col(24)->required('请输入密码');
        $field[] = Form::input('true_password', '确认密码：')->type('password')->col(24)->required('请再次确认密码');
        return create_form('门店重置账号密码', $field, $this->url('/store/store/reset_admin/' . $id));
    }

    /**
     * 门店重置账号、密码、管理员手机号
     * @param int $id
     * @param array $data
     * @return bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function resetAdmin(int $id, array $data)
    {
        return Db::transaction(function () use ($id, $data) {
            $storeInfo = Db::name('system_store')->where('id', $id)->lock(true)->find();
            if (!$storeInfo || (int)($storeInfo['is_del'] ?? 0) === 1) {
                throw new ValidateException('门店数据不存在');
            }
            $targetStaff = Db::name('system_store_staff')
                ->where('store_id', $id)
                ->where('level', 0)
                ->where('is_admin', 1)
                ->where('is_manager', 1)
                ->where('is_del', 0)
                ->lock(true)
                ->find();
            if (!$targetStaff) {
                throw new ValidateException('请先在人员管理中设置该门店管理员');
            }

            $submittedStaffId = (int)($data['staff_id'] ?? 0);
            if ($submittedStaffId > 0 && $submittedStaffId !== (int)$targetStaff['id']) {
                throw new ValidateException('管理员任职信息已变化，请刷新后重试');
            }

            $accountOwner = Db::name('system_store_staff')
                ->where('account', (string)$data['account'])
                ->where('is_del', 0)
                ->where('id', '<>', (int)$targetStaff['id'])
                ->lock(true)
                ->find();
            if ($accountOwner) {
                throw new ValidateException('该账号已存在');
            }

            $employeeId = (int)($targetStaff['employee_id'] ?? 0);
            if ($employeeId > 0) {
                $otherAssignment = Db::name('system_store_staff')
                    ->where('employee_id', $employeeId)
                    ->where('id', '<>', (int)$targetStaff['id'])
                    ->where('status', 1)
                    ->where('is_del', 0)
                    ->lock(true)
                    ->find();
                if ($otherAssignment) {
                    throw new ValidateException('当前员工存在其他有效门店任职，请先完成调店处理');
                }
            }

            $phone = trim((string)$data['phone']);
            $phoneOwner = Db::name('system_store_staff')
                ->where('id', '<>', (int)$targetStaff['id'])
                ->where('status', 1)
                ->where('is_del', 0)
                ->where(function ($query) use ($phone) {
                    $query->where('phone', $phone)->whereOr('customer_phone', $phone);
                })
                ->lock(true)
                ->find();
            if ($phoneOwner) {
                throw new ValidateException('该手机号已在其他门店任职，如需变更门店请使用调店功能');
            }

            $update = [
                'account' => (string)$data['account'],
                'phone' => $phone,
                'customer_phone' => $phone,
                'pwd' => $this->passwordHash((string)$data['password']),
            ];
            if ($phone !== (string)($targetStaff['phone'] ?? '')) {
                $update['uid'] = 0;
            }
            Db::name('system_store_staff')->where('id', (int)$targetStaff['id'])->update($update);
            return true;
        });
    }

    /**
     * 获取erp门店列表
     * @return array|mixed
     * @throws \Exception
     */
    public function erpShopList()
    {
        [$page, $limit] = $this->getPageValue();
        if (!sys_config('erp_open')) {
            return [];
        }
        try {
            /** @var Erp $erpService */
            $erpService = app()->make(Erp::class);
            $res = Cache::tag('erp_shop')->remember('list_' . $page . '_' . $limit, function () use ($page, $limit, $erpService) {
                return $erpService->serviceDriver('Comment')->getShopList($page, $limit);
            }, 60);
        } catch (\Throwable $e) {
            Log::error([
                'message' => '读取ERP门店信息失败',
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        }

        return $res['data']['datas'] ?? [];
    }

    /**
     * 保存或修改门店
     * @param int $id
     * @param array $data
     * @param array $staff_data
     * @return mixed
     */
    public function saveStore(int $id, array $data, array $staff_data = [])
    {
        /** @var SystemRegionAgentServices $regionServices */
        $regionServices = app()->make(SystemRegionAgentServices::class);
        /** @var SystemRegionManageServices $manageServices */
        $manageServices = app()->make(SystemRegionManageServices::class);
        $manageRegionId = array_key_exists('manage_region_id', $data)
            ? (int)$data['manage_region_id']
            : -1;
        unset($data['manage_region_id']);
        if ($manageRegionId > 0) {
            try {
                $agentId = $manageServices->getAgentIdByManageRegion($manageRegionId);
            } catch (\Exception $e) {
                throw new AdminException($e->getMessage());
            }
            if (!$agentId) {
                throw new AdminException('所选区域尚未配置区域管理员，请先在区域管理中添加');
            }
            $data['region_id'] = $agentId;
        } elseif ($manageRegionId === 0) {
            $data['region_id'] = 0;
        } elseif (!empty($data['region_id'])) {
            $regionRow = $regionServices->get(['id' => $data['region_id'], 'is_del' => 0]);
            if (!$regionRow && $manageServices->getRegionInfo((int)$data['region_id'])) {
                try {
                    $agentId = $manageServices->getAgentIdByManageRegion((int)$data['region_id']);
                } catch (\Exception $e) {
                    throw new AdminException($e->getMessage());
                }
                if ($agentId) {
                    $data['region_id'] = $agentId;
                    $regionRow = $regionServices->get(['id' => $agentId, 'is_del' => 0]);
                }
            }
            if (!$regionRow) {
                throw new AdminException('选择区域无效或已删除！');
            }
        }
        $region = [];
        if (!empty($data['region_id'])) {
            $region = $regionServices->get(['id' => $data['region_id'], 'is_del' => 0]);
            if (!$region) {
                throw new AdminException('选择区域无效或已删除！');
            }
            $region = $region->toArray();
            $data['is_region_alone'] = $region['is_alone'];
        }
        $storeInfo = [];
        if ($id) {//编辑
            $storeInfo = $this->dao->get($id);
            if (!$storeInfo) {
                throw new AdminException('门店信息不存在');
            }
        }
        $res = $this->transaction(function () use ($id, $data, $staff_data, $storeInfo, $region, $regionServices) {
            $is_synchronous = 0;
            if ($id) {
                $is_new = 0;
                if ($data['product_category_status']) {
                    $categoryServices = app()->make(StoreProductCategoryServices::class);
                    $is_synchronous = $categoryServices->isStoreCate($id) ? 0 : 1;
                }
                if (isset($data['region_id']) && (int)$storeInfo['region_id'] !== (int)$data['region_id']) {
                    // 切换区域前先解除原区域管理人员的管辖关联，避免门店同时出现在多个区域
                    $regionServices->unbindStoreFromManagedAgents($id);
                    if ($storeInfo['region_id']) {
                        $regionOld = $regionServices->get($storeInfo['region_id']);
                        if ($regionOld) {
                            $storeIds = is_string($regionOld['store_id']) ? explode(',', $regionOld['store_id']) : $regionOld['store_id'];
                            $storeIds = array_diff($storeIds, [$id]);
                            $regionServices->update($storeInfo['region_id'], ['store_id' => $storeIds]);
                            $regionServices->setRegionAgentStoreId((int)$storeInfo['region_id']);
                        }
                    }
                }
                $this->dao->update($id, $data);
            } else {
                $is_new = 1;
                $data['add_time'] = time();
                if ($data['product_category_status']) {
                    $is_synchronous = 1;
                }
                $res = $this->dao->save($data);
                if ($staff_data) {
                    $staffServices = app()->make(SystemStoreStaffServices::class);
                    if ($staffServices->count(['account' => $staff_data['account'], 'is_del' => 0])) {
                        throw new AdminException('管理员账号已存在');
                    }
                    $staff_data['level'] = 0;
                    $staff_data['store_id'] = $res->id;
                    $staff_data['is_cashier'] = 1;
                    $staff_data['status'] = 1;
                    $staff_data['pwd'] = $this->passwordHash($staff_data['pwd']);
                    /** @var EmployeeStaffWriteServices $staffWriter */
                    $staffWriter = app()->make(EmployeeStaffWriteServices::class);
                    $assignment = $staffWriter->saveStaffAssignment(0, $staff_data, [
                        'source' => 'admin',
                        'reason' => '新建门店初始化管理员任职',
                    ], ['use_outer_transaction' => true]);
                    $staffId = (int)($assignment['staff_id'] ?? 0);
                    if ($staffId <= 0 || !$staffServices->update($staffId, [
                        'level' => 0,
                        'is_admin' => 1,
                        'is_store' => 1,
                        'verify_status' => 1,
                        'is_manager' => 1,
                        'is_cashier' => 1,
                    ])) {
                        throw new AdminException('创建门店管理员失败！');
                    }
                    $data = [
                        ['role_name' => '店员', 'type' => 1, 'relation_id' => $res->id, 'status' => 1, 'level' => 1, 'rules' => '1048,1049,1097,1098,1099,1100,1050,1051,1101,1102,1103,1273,1274,1275,1276,1081,1104,1105,1106,1052,1054,1086,1129,1132,1133,1134,1135,1136,1137,1138,1139,1140,1141,1142,1143,1144,1145,1146,1147,1148,1149,1150,1151,1152,1153,1154,1155,1156,1157,1158,1159,1160,1161,1162,1163,1130,1166,1167,1168,1169,1131,1170,1171,1172,1173,1174,1175,1176,1242,1088,1122,1123,1124,1125,1126,1127,1164,1165,1053,1107,1108,1109,1110,1111,1112,1113,1114,1115,1116,1117,1118,1119,1120,1280', 'cashier_rules' => '1410,1412,1411,1831,1985,1520,1413,1414,1415,1417'],
                        ['role_name' => '管理员', 'type' => 1, 'relation_id' => $res->id, 'status' => 1, 'level' => 1, 'rules' => '1048,1049,1097,1098,1099,1100,1050,1051,1101,1102,1103,1273,1274,1275,1276,1081,1104,1105,1106,1052,1054,1086,1129,1132,1133,1134,1135,1136,1137,1138,1139,1140,1141,1142,1143,1144,1145,1146,1147,1148,1149,1150,1151,1152,1153,1154,1155,1156,1157,1158,1159,1160,1161,1162,1163,1130,1166,1167,1168,1169,1131,1170,1171,1172,1173,1174,1175,1176,1242,1088,1122,1123,1124,1125,1126,1127,1164,1165,1053,1107,1108,1109,1110,1111,1112,1113,1114,1115,1116,1117,1118,1119,1120,1280,1055,1056,1177,1178,1179,1180,1181,1182,1183,1184,1185,1186,1187,1188,1189,1190,1191,1192,1277,1057,1193,1194,1195,1196,1197,1249,1250,1251,1252,1253,1254,1058,1059,1060,1198,1199,1200,1243,1255,1256,1257,1258,1259,1260,1061,1201,1241,1062,1063,1215,1218,1219,1220,1244,1261,1262,1263,1264,1265,1064,1216,1217,1202,1065,1066,1203,1214,1067,1204,1212,1213,1235,1068,1205,1206,1069,1207,1208,1070,1089,1071,1209,1210,1211,1072,1073,1082,1083,1084,1085,1228,1229,1230,1231,1232,1233,1234,1236,1245,1246,1247,1248,1221,1222,1223,1224,1225,1226,1227,1266,1267,1268,1269,1270,1271,1272,1237,1238,1239,1240', 'cashier_rules' => '1410,1412,1411,1831,1985,1520,1413,1414,1415,1417']
                    ];
                    /** @var SystemRoleServices $systemRoleServices */
                    $systemRoleServices = app()->make(SystemRoleServices::class);
                    $systemRoleServices->saveAll($data);
                }
                $id = (int)$res->id;
            }
            if (isset($data['region_id']) && $data['region_id']) {//关联新的区域
                $storeIds = is_string($region['store_id']) ? explode(',', $region['store_id']) : $region['store_id'];
                $storeIds = array_merge($storeIds, [$id]);
                $regionServices->update($region['id'], ['store_id' => $storeIds]);
                $regionServices->setRegionAgentStoreId((int)$data['region_id']);
            }
            return [$id, $is_new, $is_synchronous];
        });
        //编辑门店 取消门店改价权限 处理平台同步到门店商品
        if ($storeInfo && $storeInfo['product_change_price_status'] && !$data['product_change_price_status']) {
            /** @var StoreBranchProductServices $storeBranchProducesServices */
            $storeBranchProducesServices = app()->make(StoreBranchProductServices::class);
            $storeBranchProducesServices->cancelProductChangePrice($id);
        }
        if ($manageRegionId > 0 && $id) {
            try {
                /** @var \app\services\organization\OrganizationScopeService $scopeService */
                $scopeService = app()->make(\app\services\organization\OrganizationScopeService::class);
                if ($scopeService->isMigrated()) {
                    /** @var \app\dao\organization\OrganizationDao $orgDao */
                    $orgDao = app()->make(\app\dao\organization\OrganizationDao::class);
                    $org = $orgDao->getOne(['legacy_manage_region_id' => $manageRegionId, 'is_del' => 0], 'id');
                    if ($org) {
                        $orgRow = is_object($org) ? $org->toArray() : $org;
                        /** @var \app\services\organization\OrganizationManageServices $orgManage */
                        $orgManage = app()->make(\app\services\organization\OrganizationManageServices::class);
                        $orgManage->bindStoreToOrg((int)$id, (int)$orgRow['id']);
                    }
                }
            } catch (\Throwable $e) {
                // 双读期：组织绑定失败不阻断门店保存
            }
        }
        return $res;
    }

    /**
     * 加盟店入驻审核通过创建门店数据
     * @param int $applyId
     * @param array $info
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function verifyAgreeCreate(int $applyId, array $data = [])
    {
        if (!$applyId) {
            throw new ValidateException('缺少申请ID');
        }
        /** @var SystemUserApplyServices $applyServices */
        $applyServices = app()->make(SystemUserApplyServices::class);
        $info = $applyServices->get($applyId);
        if (!$info) {
            throw new ValidateException('申请数据不存在');
        }
        $info = $info->toArray();
        //加盟店
        $data['type'] = 2;
        $data['delivery_type'] = 1;
        $data['name'] = $info['system_name'];
        $data['phone'] = $info['phone'];
        $data['image'] = sys_config('start_login_logo');//门店登录logo
        $address = [$info['province'] ?? '', $info['city'] ?? '', $info['district'] ?? '', $info['street'] ?? ''];
        $data['address'] = implode('', $address);
        $data['detailed_address'] = $info['detail'];
        $data['valid_range'] = 10000;
        /** @var CityAreaServices $cityAreaServices */
        $cityAreaServices = app()->make(CityAreaServices::class);
        $city = $cityAreaServices->searchCity(['address' => implode('/', $address)]);
        if ($city) {
            $where = [['id', 'in', array_merge([$city['id']], explode('/', trim($city->path, '/')))]];
            $cityList = $cityAreaServices->getCityList($where, 'id as value,id,name as label,parent_id as pid');
            $data['province'] = $cityList[0]['id'] ?? 0;
            $data['city'] = $cityList[1]['id'] ?? 0;
            $data['area'] = $cityList[2]['id'] ?? 0;
            $data['street'] = $cityList[3]['id'] ?? 0;
        }
        $product_ids = [];
        if ($data['applicable_type'] == 2) $product_ids = $data['product_id'];
        $applicable_type = $data['applicable_type'];
        unset($data['product_id'], $data['applicable_type']);

        $staff_data = [
            'uid' => $info['uid'],//门店超级管理员绑定商城uid
            'staff_name' => $info['name'],
            'avatar' => $data['image'],
            'phone' => $info['phone'],
            'account' => $this->getAccount($info['phone']),
            'pwd' => substr($info['phone'], -6)
        ];
        [$id, $is_new, $is_synchronous] = $this->saveStore(0, $data, $staff_data);
        event('store.create', [$data, $id, $is_new, $is_synchronous, $applicable_type, $product_ids]);
        $storeInfo = $this->dao->get((int)$id);
        $this->dao->cacheUpdate($storeInfo->toArray());
        $storeInfo['account'] = $staff_data['account'];
        return $storeInfo;
    }

    /**
     * 获取同意申请 创建账号
     * @param string $phone
     * @return string
     */
    public function getAccount(string $phone)
    {
        $account = '';
        if ($phone) {
            //当前手机号当作账号是否存在
            $adminDCount = $this->dao->count(['account' => $phone, 'is_del' => 0]);
            $account = $phone;
            if ($adminDCount) {
                $account = $account . '_' . $adminDCount;
            }
        }
        return $account;
    }

    /**
     * 获取门店缓存
     * @param int $id
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author 等风来
     * @email 136327134@qq.com
     * @date 2022/11/21
     */
    public function getStoreDisposeCache(int $id, string $felid = '')
    {
        $storeInfo = $this->dao->cacheRemember($id, function () use ($id) {
            $storeInfo = $this->dao->get($id);
            return $storeInfo ? $storeInfo->toArray() : null;
        });

        if ($felid) {
            return $storeInfo[$felid] ?? null;
        }

        if ($storeInfo) {
            $storeInfo['latlng'] = $storeInfo['latitude'] . ',' . $storeInfo['longitude'];
            $storeInfo['dataVal'] = $storeInfo['valid_time'] ? explode(' - ', $storeInfo['valid_time']) : [];
            $storeInfo['timeVal'] = $storeInfo['day_time'] ? explode(' - ', $storeInfo['day_time']) : [];
            $storeInfo['address2'] = $storeInfo['address'] ? explode(',', $storeInfo['address']) : [];
            return $storeInfo;
        }
        return [];
    }

    /**
     * 后台获取门店详情
     * @param int $id
     * @param string $felid
     * @return array|false|mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getStoreDispose(int $id, string $felid = '')
    {
        if ($felid) {
            return $this->dao->value(['id' => $id], $felid);
        } else {
            $storeInfo = $this->dao->get($id);
            if ($storeInfo) {
                $storeInfo = $storeInfo->toArray();
                $storeInfo['latlng'] = $storeInfo['latitude'] . ',' . $storeInfo['longitude'];
                $storeInfo['dataVal'] = $storeInfo['valid_time'] ? explode(' - ', $storeInfo['valid_time']) : [];
                $storeInfo['timeVal'] = $storeInfo['day_time'] ? explode(' - ', $storeInfo['day_time']) : [];
                $storeInfo['address2'] = $storeInfo['address'] ? explode(',', $storeInfo['address']) : [];
                $storeInfo['addressSelect'] = [$storeInfo['province'], $storeInfo['city'], $storeInfo['area'], $storeInfo['street']];
                return $storeInfo;
            }
            return false;
        }
    }


    /**
     * 平台门店运营统计
     * @param array $time
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function storeChart(array $store_id, array $time)
    {
        $storeWhere = ['is_del' => 0, 'is_show' => 1];
        if ($store_id) $storeWhere['id'] = $store_id;
        $list = $this->dao->getStoreList($storeWhere, ['id', 'name', 'image']);
        if ($list) {
            /** @var StoreUserServices $storeUserServices */
            $storeUserServices = app()->make(StoreUserServices::class);
            /** @var BranchOrderServices $orderServices */
            $orderServices = app()->make(BranchOrderServices::class);
            /** @var StoreFinanceFlowServices $storeFinancFlowServices */
            $storeFinancFlowServices = app()->make(StoreFinanceFlowServices::class);
            $where = ['time' => $time];
            $order_where = ['paid' => 1, 'pid' => 0, 'is_del' => 0, 'is_system_del' => 0, 'refund_status' => [0, 3]];
            foreach ($list as &$item) {
                $store_where = ['store_id' => $item['id']];
                $item['store_price'] = (float)bcsub((string)$storeFinancFlowServices->sum($where + $store_where + ['trade_type' => 1, 'no_type' => [1, 15], 'pm' => 1, 'is_del' => 0], 'number', true), (string)$storeFinancFlowServices->sum($where + $store_where + ['trade_type' => 1, 'no_type' => 1, 'pm' => 0, 'is_del' => 0], 'number', true), 2);
                $item['store_product_count'] = $orderServices->sum($where + $store_where + $order_where, 'total_num', true);
                $item['store_order_price'] = $orderServices->sum($where + $store_where + $order_where, 'pay_price', true);
                $item['store_user_count'] = $storeUserServices->count($where + $store_where);
            }
        }
        return $list;
    }

    /**
     * 检测订单商品门店是否支持配送
     * @param int $oid
     * @param array $storeList
     * @param int $getType
     * @return array
     */
    public function checkOrderProductShare(int $oid, array $storeList, int $getType = 1)
    {
        if (!$oid || !$storeList) {
            return [[], []];
        }
        /** @var StoreOrderServices $storeOrderServices */
        $storeOrderServices = app()->make(StoreOrderServices::class);
        $orderInfo = $storeOrderServices->get($oid, ['id', 'order_id', 'uid', 'store_id', 'supplier_id']);
        /** @var StoreOrderCartInfoServices $storeOrderCartInfoServices */
        $storeOrderCartInfoServices = app()->make(StoreOrderCartInfoServices::class);
        $cart_info = $storeOrderCartInfoServices->getSplitCartList($oid, 'cart_info');
        if (!$cart_info) {
            return [[], []];
        }

        /** @var StoreProductAttrValueServices $skuValueServices */
        $skuValueServices = app()->make(StoreProductAttrValueServices::class);
        $platProductIds = [];
        $platStoreProductIds = [];
        $storeProductIds = [];
        foreach ($cart_info as $cart) {
            $productInfo = $cart['productInfo'] ?? [];
            if (isset($productInfo['store_delivery']) && !$productInfo['store_delivery']) {//有商品不支持门店配送
                return [[], $cart_info];
            }
            switch ($productInfo['type'] ?? 0) {
                case 0://平台
                case 2://供应商
                    $platProductIds[] = $cart['product_id'];
                    break;
                case 1://门店
                    if ($productInfo['pid']) {//门店自有商品
                        $storeProductIds[] = $cart['product_id'];
                    } else {
                        $platStoreProductIds[] = $cart['product_id'];
                    }
                    break;

            }
        }
        /** @var StoreBranchProductServices $branchProductServics */
        $branchProductServics = app()->make(StoreBranchProductServices::class);
        //转换成平台商品
        if ($platStoreProductIds) {
            $ids = $branchProductServics->getStoreProductIds($platStoreProductIds);
            $platProductIds = array_merge($platProductIds, $ids);
        }
        $productCount = count($platProductIds);
        $result = [];
        foreach ($storeList as $store) {
            if ($storeProductIds && $store['id'] != $orderInfo['store_id']) {
                continue;
            }
            $is_show = $productCount == $branchProductServics->count(['pid' => $platProductIds, 'is_show' => 1, 'is_del' => 0, 'type' => 1, 'relation_id' => $store['id']]);//商品没下架 && 库存足够
            if (!$is_show) {
                continue;
            }
            $stock = true;
            foreach ($cart_info as $cart) {
;                $productInfo = $cart['productInfo'] ?? [];
                if (!$productInfo) {
                    $stock = false;
                    break;
                }
                $applicable_store_ids = [];
                //验证商品适用门店
                if (isset($productInfo['applicable_store_id'])) $applicable_store_ids = is_array($productInfo['applicable_store_id']) ? $productInfo['applicable_store_id'] : explode(',', $productInfo['applicable_store_id']);
                if (!isset($productInfo['applicable_type']) || $productInfo['applicable_type'] == 0 || ($productInfo['applicable_type'] == 2 && !in_array($store['id'], $applicable_store_ids))) {
                    $stock = false;
                    break;
                }
                $type=$cart['type'] ?? 0;
                switch ($type) {
                    case 0:
                    case 6:
                    case 8:
                    case 9:
                    case 10:
                    case 11:
                    case 12:
                        $suk = $skuValueServices->value(['unique' => $cart['product_attr_unique'], 'product_id' => $cart['product_id'], 'type' => 0], 'suk');
                        break;
                    case 1:
                    case 2:
                    case 3:
                    case 4:
                    case 5:
                    case 7:
                        $suk = $skuValueServices->value(['unique' => $cart['product_attr_unique'], 'product_id' => $cart['activity_id'], 'type' => $cart['type']], 'suk');
                        break;
                }
                $branchProductInfo = $branchProductServics->isValidStoreProduct((int)$cart['product_id'], $store['id']);
                if (!$branchProductInfo) {
                    $stock = false;
                    break;
                }
                $attrValue = $skuValueServices->get(['suk' => $suk, 'product_id' => $branchProductInfo['id'], 'type' => 0]);
                if (!$attrValue) {
                    $stock = false;
                    break;
                }
                if ($cart['cart_num'] > $attrValue['stock']) {
                    $stock = false;
                    break;
                }
            }
            if ($stock) {
                if ($getType == 1) {//只取一条门店数据
                    $result[] = $store['id'];
                    break;
                } else {
                    $result[] = $store['id'];
                }
            }
        }
        return [$result, $cart_info];
    }

    /**
     * 计算距离
     * @param string $user_lat
     * @param string $user_lng
     * @param string $store_lat
     * @param $store_lng
     * @return string
     */
    public function distance(string $user_lat, string $user_lng, string $store_lat, $store_lng)
    {
        try {
            return (round(6378137 * 2 * asin(sqrt(pow(sin((($store_lat * pi()) / 180 - ($user_lat * pi()) / 180) / 2), 2) + cos(($user_lat * pi()) / 180) * cos(($store_lat * pi()) / 180) * pow(sin((($store_lng * pi()) / 180 - ($user_lng * pi()) / 180) / 2), 2)))));
        } catch (\Throwable $e) {
            return '0';
        }
    }

    /**
     * 验证门店配送范围
     * @param int $store_id
     * @param array $addressInfo
     * @param string $latitude
     * @param string $longitude
     * @return bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function checkStoreDeliveryScope(int $store_id, array $addressInfo = [], string $latitude = '', string $longitude = '')
    {
        //没登录 ｜｜ 无添加地址 默认不验证
        if (!$store_id || $store_id == -1 || (!$addressInfo && (!$latitude || !$longitude))) {
            return true;
        }
        //门店是否开启
        if (!sys_config('store_func_status', 1)) {
            return false;
        }
        $store_delivery_scope = (int)sys_config('store_delivery_scope', 0);
        if (!$store_delivery_scope) {//配送范围不验证
            return true;
        }
        $storeInfo = $this->getStoreDisposeCache($store_id);
        if (!$storeInfo) {
            return false;
        }
        if ($addressInfo) {
            $user_lat = $addressInfo['latitude'] ?? '';
            $user_lng = $addressInfo['longitude'] ?? '';
            if (!$user_lat || !$user_lng) {
				$user_address = $addressInfo['province'] . $addressInfo['city'] . $addressInfo['district'] . $addressInfo['street'] . $addressInfo['detail'];
				$addres = lbs_address($user_address, $addressInfo['city']);
				if (!$addres) {
					return false;
				}
				$user_lat = $addres['location']['lat'] ?? '';
				$user_lng = $addres['location']['lng'] ?? '';
            }
        } else {
            $user_lat = $latitude;
            $user_lng = $longitude;
        }

        $distance = $this->distance($user_lat, $user_lng, $storeInfo['latitude'], $storeInfo['longitude']);

        return $distance <= $storeInfo['valid_range'];
    }

    /**
     * 验证是否在配送范围内
     * @param int $uid
     * @param int $store_id
     * @param array $addr
     * @return bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \throwable
     */
    public function checkCityStoreDeliveryScope(int $uid, int $store_id, array $addr = [])
    {
        //没登录 ｜｜ 无添加地址 默认不验证
        if (!$store_id || $store_id == -1) {
            return true;
        }
        //门店是否开启
        if (!sys_config('store_func_status', 1)) {
            return false;
        }
        //同城配送开关
        if (!sys_config('city_delivery_status')) {
            return false;
        }
        $storeInfo = $this->getStoreInfo($store_id);
        if (!$storeInfo) {
            return false;
        }
        $store_city_delivery_status = $storeInfo['city_delivery_status'] && $storeInfo['city_delivery_type'] == 0;
        //商家自配开关
        if (!sys_config('self_delivery_status') && $store_city_delivery_status) {
            return false;
        }
        /** @var DeliveryConfigServices $deliveryConfigServices */
        $deliveryConfigServices = app()->make(DeliveryConfigServices::class);
        /** @var UserAddressServices $addressServices */
        $addressServices = app()->make(UserAddressServices::class);
        if (!$addr) {
            $addr = $addressServices->getUserDefaultAddressCache($uid);
        }
        if (!$addr) {
			return false;
//            throw new ValidateException('请添加收货地址！');
        }
        if ($storeInfo['range_type'] == 2) {
            if ((!isset($addr['province_id']) || !isset($addr['city_id']) || !isset($addr['district_id'])) && $addr['id']) {
                $addr = $addressServices->getAddress($addr['id']);
            }
            if ($addr['province_id'] <= 0 || $addr['city_id'] <= 0 || $addr['district_id'] <= 0) {
                $cityAreaServices = app()->make(CityAreaServices::class);
                if (isset($addr['addressInfo']) && $addr['addressInfo']) {
                    $province = $cityAreaServices->getCityId('', '', '', '', $addr['addressInfo']);
                } else {
                    $province = $cityAreaServices->getCityId($addr['province'], $addr['city'], $addr['district'], $addr['street']);
                    $handle['province_id'] = $province['province'];
                    $handle['city_id'] = $province['city'];
                    $handle['district_id'] = $province['district'];
                    $handle['street_id'] = $province['street'];
                    $addressServices->update($addr['id'], $handle);
                }
                $addr['province_id'] = $province['province'];
                $addr['city_id'] = $province['city'];
                $addr['district_id'] = $province['district'];
                $addr['street_id'] = $province['street'];
            }
        }
        $checkMethod = self::RANGE_TYPE_METHOD[$storeInfo['range_type']];
        if (!$deliveryConfigServices->$checkMethod($addr, $storeInfo)) {
			return false;
//            throw new ValidateException('不在配送范围内！');
        }
        return true;
    }

    /**
     * 进店绑定店员
     * @param $uid
     * @param $param
     * @return bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function setUserBelongStaff($uid, $param)
    {
        $staff_id = $param['staff_id'];
        $spreadUid = $param['spid'];
        if (!$uid || (!$staff_id && !$spreadUid)) return true;
        $staffServices = app()->make(SystemStoreStaffServices::class);
        if ($staff_id) {
            $info = $staffServices->get($staff_id);
            if (!$info) return true;
            //记录用户归属店员
            UserBelongStoreJob::dispatchDo('belongStoreStaff', [$uid, 0, 4, $staff_id, $info['store_id']]);
        } else {
            //记录用户归属店员
            UserBelongStoreJob::dispatchDo('belongStoreStaff', [$uid, $spreadUid, 4]);
        }
        return true;
    }

    /**
     * 同城配送设置
     * @return array
     */
    public function getDeliveryStatus()
    {
        $data['city_delivery_status'] = sys_config('city_delivery_status', 0);
        $data['self_delivery_status'] = sys_config('self_delivery_status', 0);
        $data['dada_delivery_status'] = sys_config('dada_delivery_status', 0);
        $data['uu_delivery_status'] = sys_config('uu_delivery_status', 0);
        return $data;
    }
}
