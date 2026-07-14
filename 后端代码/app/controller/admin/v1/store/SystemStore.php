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
use app\services\order\StoreOrderServices;
use app\services\product\branch\StoreBranchProductServices;
use app\services\store\LoginServices;
use app\services\store\SystemStoreStaffServices;
use app\services\system\attachment\SystemAttachmentServices;
use mohe\services\SystemConfigService;
use think\facade\App;
use app\controller\admin\AuthController;
use app\services\store\SystemStoreServices;

/**
 * 门店管理控制器
 * Class SystemStore
 * @package app\controller\admin\v1\store
 */
class SystemStore extends AuthController
{
    /**
     * 构造方法
     * SystemStore constructor.
     * @param App $app
     * @param SystemStoreServices $services
     */
    public function __construct(App $app, SystemStoreServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

	/**
	 * 获取门店财务配置
	 * @param int $id
	 * @return mixed
	 */
	public function storeFinanceConfig($id = 0)
	{
		return $this->success($this->services->getStoreFinanceConfig((int)$id));
	}

	/**
	 * 获取门店列表
	 * @param SystemRegionAgentServices $regionAgentServices
	 * @return mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function index(SystemRegionAgentServices $regionAgentServices)
    {
        $where = $this->request->getMore([
			['name', ''],
            ['keywords', ''],
            [['type', 'd'], 0],//1自营2加盟
			[['status', 'd'], ''],//1营业2休息
            ['id', 0, '', 'order_id'],
			['cate_id', 0],//分类ID
			[['region_id', 'd'], 0],//分类ID
			[['manage_region_id', 'd'], 0],//区域架构ID
			[['is_region', 'd'], ''],//是否绑定区域：1有0没有
			[['is_alone', 'd'], ''],//是否隔离
			['product_status', ''],//是否自建商品
			['product_category_status', ''],//是否自建商品分类
			['product_verify_status', ''],//是否商品免审
			['coupon_verify_status', ''],//是否优惠券免审
			['coupon_self_built_status', ''],//自建优惠券开关
			['use_system_money', ''],//是否适用平台余额
        ]);
		if ($where['name'] && !$where['keywords']) {
			$where['keywords'] = $where['name'];
		}
		unset($where['name']);
        if ($where['type'] == 'all') $where['type'] = '';
        $where['is_del'] = 0;
		if (!empty($where['manage_region_id'])) {
			/** @var \app\services\store\SystemRegionManageServices $manageServices */
			$manageServices = app()->make(\app\services\store\SystemRegionManageServices::class);
			$storeIds = $manageServices->getStoreIdsByManageRegion((int)$where['manage_region_id'], true);
			unset($where['manage_region_id']);
			if (!$storeIds) {
				return $this->success(['list' => [], 'count' => 0]);
			}
			$where['ids'] = $storeIds;
		}
		if ($this->adminType == 3 && $this->agentId) {//区域代理商登录
			$storeIds = $regionAgentServices->getRegionAgentStoreId((int)$this->agentId);
			if ($storeIds) {
				$where['id'] = $storeIds;
			} else {
				return $this->success(['list' => [], 'count' => 0]);
			}
		}
		$field = ['id', 'type', 'cate_id', 'region_id', 'name', 'phone', 'address', 'detailed_address', 'image', 'is_show', 'day_time', 'day_start', 'day_end'];
        return $this->success($this->services->getStoreList($where, $field, '', '', 0, ['categoryName']));
    }

    /**
     * 获取门店头部
     * @return mixed
     */
    public function get_header()
    {
        $count = $this->services->getStoreData();
        return $this->success(compact('count'));
    }

    /**
     * 门店设置
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function get_info($id = 0)
    {
        $id = (int)$id;
        if (!$id) {
            [$id] = $this->request->getMore([
                [['id', 'd'], 0],
            ], true);
        }
        $info = $this->services->getStoreDispose($id);
		if (!$info) {
			return $this->fail('门店信息获取失败');
		}
		if ($info['store_rate_type'] == 1) {//默认
			$finance = $this->services->getStoreFinanceConfig();
			$info = array_merge($info, $finance);
		}
		$info['cate_id'] = $info['cate_com'] && is_string($info['cate_com']) ? stringToIntArray($info['cate_com']) : $info['cate_com'];
		/** @var \app\services\store\SystemRegionManageServices $manageServices */
		$manageServices = app()->make(\app\services\store\SystemRegionManageServices::class);
		$info['manage_region_id'] = 0;
		$info['manage_region_path'] = [];
		if (!empty($info['region_id'])) {
			$info['manage_region_id'] = $manageServices->findManageRegionIdByAgentId((int)$info['region_id']);
			if ($info['manage_region_id']) {
				$info['manage_region_path'] = $manageServices->getManageRegionPath((int)$info['manage_region_id']);
			}
		}
        return $this->success(compact('info'));
    }

    /**
     * 位置选择
     * @return mixed
     */
    public function select_address()
    {
        $key = sys_config('tengxun_map_key');
        if (!$key) return $this->fail('提示：前往设置->系统设置->第三方接口 配置腾讯地图KEY');
        return $this->success(compact('key'));
    }

    /**
     * 设置单个门店是否显示
     * @param string $is_show
     * @param string $id
     * @return json
     */
    public function set_show($is_show = '', $id = '')
    {
        ($is_show == '' || $id == '') && $this->fail('缺少参数');
        $res = $this->services->update((int)$id, ['is_show' => (int)$is_show]);
        if ($res) {
            /** @var SystemStoreStaffServices $storeStaffServices */
            $storeStaffServices = app()->make(SystemStoreStaffServices::class);
            if ($is_show) {
                $storeStaffServices->update(['store_id' => $id, 'is_del' => 0, 'status' => 0], ['status' => 1]);
				$this->services->cacheSaveValue($id, 'is_show', $is_show);
            } else {
                $storeStaffServices->update(['store_id' => $id, 'is_del' => 0, 'status' => 1], ['status' => 0]);
				$this->services->cacheDelById($id);
            }
            event('store.status', [$id, $is_show]);
            return $this->success('设置成功');
        } else {
            return $this->fail('设置失败');
        }
    }

    /**
     * 获取重置账号密码表单
     * @param $id
     * @return mixed
     */
    public function resetAdminForm($id)
    {
        if (!$id) {
            return $this->fail('缺少参数');
        }
        return $this->success($this->services->storeAdminAccountForm($id));
    }

	/**
	 * 重置门店超级管理员账号密码
	 * @param $id
	 * @return mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function resetAdmin($id)
    {
        if (!$id) {
            return $this->fail('缺少参数');
        }
        $data = $this->request->postMore([
            ['staff_id', 0],
			['phone', ''],
            ['account', ''],
            ['password', ''],
            ['true_password', ''],
        ]);
		$this->validate(['store_account' => $data['account'], 'true_password' => $data['true_password'], 'store_password' => $data['password']], \app\validate\admin\merchant\SystemStoreValidate::class, 'reset');
		if (!$data['password']) {
            return $this->fail('请输入密码');
        }
        if (!$data['true_password']) {
            return $this->fail('请输入确认密码');
        }
        if ($data['password'] != $data['true_password']) {
            return $this->fail('两次输入的密码不正确');
        }
		$this->services->resetAdmin((int)$id, $data);
		return $this->success('操作成功!');
    }

    /**
     * 保存修改门店信息
     * param int $id
     * */
    public function save($id = 0)
    {
        $data = $this->request->postMore([
			['type', 1],//门店类型1：自营2加盟
			['cate_id', []],
            ['erp_shop_id', 0],
            ['name', ''],
            ['space_num', ''],
            ['introduction', ''],
            ['is_show', 1],
			['delivery_type', []],//配送类型1:快递发货2同城配送3到店自提
            ['image', ''],
            ['background_image', ''],
            ['phone', ''],
            ['address', ''],
            ['detailed_address', ''],
			['addressSelect', []],//城市选择
            ['province', ''],
            ['city', ''],
            ['area', ''],
            ['street', ''],
            ['longitude', ''],
            ['latitude', ''],
            ['day_time', []],
//            ['valid_range', 0],
            ['store_account', ''],
            ['store_password', ''],
            ['business', 0],
			['product_status', 1],//自主添加商品
            ['product_verify_status', 0],//商品免审
			['default_delivery', 0],//默认配送方式
			['city_delivery_status', 0],//同城配送
            ['city_delivery_type', 0],
			['applicable_type', 1],//1；全部商品 2部分商品 3不同步商品
			['product_id', []],//同步商品Ids
			['product_category_status', 0],//自建商品分类1:开启0关闭
			['product_change_price_status', 0],//门店自主调价1:开启0关闭
			['region_id', 0],//区域代理商ID（兼容旧字段）
			['use_system_money', 1],//使用平台余额1:开启0:关闭
			['is_alone', 0],//是否隔离
            ['coupon_verify_status', 0],//优惠券免审：1:开启0:关闭
            ['coupon_self_built_status', 0],//自建优惠券开关：1:开启0:关闭
			['store_rate_type', 1],//手续费类型1:默认统一配置2:自定义
			['store_cashier_order_rate', 0],//收银订单费率
			['store_self_order_rate', 0],//分配订单费率
			['store_writeoff_order_rate', 0],//核销订单费率
			['store_recharge_order_rate', 0],//储值订单返点
			['store_svip_order_rate', 0],//付费会员返点
        ]);
        if (array_key_exists('manage_region_id', $this->request->post())) {
            $data['manage_region_id'] = (int)$this->request->param('manage_region_id');
        }
        $this->validate($data, \app\validate\admin\merchant\SystemStoreValidate::class, $id ? 'update' : 'save');
        if (!!sys_config('erp_open') && !$data['erp_shop_id']) {
            return $this->fail('开启ERP时,店铺编号必须填写');
        }
		if ($data['addressSelect']) {
			$data['province'] = $data['addressSelect'][0] ?? '';
            $data['city'] = $data['addressSelect'][1] ?? '';
            $data['area'] = $data['addressSelect'][2] ?? '';
            $data['street'] = $data['addressSelect'][3] ?? '';
		}
        if (!isset($data['longitude']) || !isset($data['latitude'])) {
            return $this->fail('请选择门店位置');
        }
        if (!isset($data['delivery_type']) || count($data['delivery_type']) <= 0) {
            return $this->fail('请选择配送方式');
        }
        if (!$id && (!$data['store_account'] || !$data['store_password'])) {
            return $this->fail('请填写门店管理员账号密码');
        }
        if ($data['is_show'] && (!$data['day_time'] || count($data['day_time']) != 2)) {
            return $this->fail('请选择门店营业时间');
        }
//		if (in_array($data['delivery_type'], [1, 2])&& !floatval(trim($data['valid_range']))) {
//			return $this->fail('请输入有效的配送范围');
//		}
		if ($data['applicable_type'] == 2 && !$data['product_id']) {
			return $this->fail('请选择同步商品');
		}
		if ($data['applicable_type'] == 3) {
			$data['product_id'] = [];
		}
        if ($data['day_time'] && count($data['day_time']) == 2) {
            [$data['day_start'], $data['day_end']] = $data['day_time'];
            $data['day_time'] = implode(' - ', $data['day_time']);
        }
        if ($data['image'] && strstr($data['image'], 'http') === false) {
            $site_url = sys_config('site_url');
            $data['image'] = $site_url . $data['image'];
        }
        if ($data['background_image'] && strstr($data['background_image'], 'http') === false) {
            $site_url = sys_config('site_url');
            $data['background_image'] = $site_url . $data['background_image'];
        }
		if ($data['type'] == 1) {//自营门店 默认开启使用余额
			$data['use_system_money'] = 1;
		}
        if($data['address'] == '') return $this->fail('请选择门店地址');
        if($data['detailed_address'] == '') return $this->fail('请输入门店详细地址');
        $data['address'] = str_replace([' ', '/', '\\'], '', $data['address']);
        $data['detailed_address'] = str_replace([' ', '/', '\\'], '', $data['detailed_address']);
        $staff_data = [
            'staff_name' => $data['name'],
            'avatar' => $data['image'],
            'phone' => $data['phone'],
            'account' => $data['store_account'],
            'pwd' => $data['store_password']
        ];
//        $data['valid_range'] = bcmul($data['valid_range'], '1000', 0);
		$data['cate_com'] = $data['cate_id'] ? implode(',', $data['cate_id']) : '';
		$data['cate_id'] = $data['cate_id'] ? end($data['cate_id']) : 0;
		$product_ids = [];
		if ($data['applicable_type'] == 2) $product_ids = $data['product_id'];
		if ($data['type'] == 1) {//自营门店不允许自主上传商品
			$data['product_status'] = 0;
			$data['product_verify_status'] = 0;
		}
		$applicable_type = $data['applicable_type'];
        unset($data['store_account'], $data['store_password'], $data['product_id'], $data['applicable_type']);
        [$id, $is_new, $is_synchronous] = $this->services->saveStore((int)$id, $data, $staff_data);
        event('store.create', [$data, $id, $is_new, $is_synchronous, $applicable_type, $product_ids]);
		$storeInfo = $this->services->get((int)$id);
		$this->services->cacheUpdate($storeInfo->toArray());
        return $this->success('操作成功!');
    }

    /**
     * 删除恢复门店
     * @param $id
     */
    public function delete($id)
    {
		$id = (int)$id;
        if (!$id) return $this->fail('数据不存在');
        $storeInfo = $this->services->get($id);
        if (!$storeInfo) {
            return $this->fail('数据不存在');
        }
        /** @var SystemStoreStaffServices $storeStaffServices */
        $storeStaffServices = app()->make(SystemStoreStaffServices::class);
        if ($storeInfo->is_del == 1) {
            $storeInfo->is_del = 0;
            if (!$storeInfo->save()) {
                return $this->fail('恢复失败,请稍候再试!');
            } else {
                $storeStaffServices->update(['store_id' => $id, 'is_del' => 1], ['is_del' => 0]);

				$this->services->cacheUpdate($storeInfo->toArray());

                return $this->success('恢复门店成功!');
            }
        } else {
            /** @var StoreOrderServices $storeOrderServices */
            $storeOrderServices = app()->make(StoreOrderServices::class);
            $orderCount = $storeOrderServices->count(['store_id' => $id, 'status' => 0]);
            if (!$orderCount) {
                $orderCount = $storeOrderServices->count(['store_id' => $id, 'status' => 1]);
                if (!$orderCount) {
                    $orderCount = $storeOrderServices->count(['store_id' => $id, 'status' => 5]);
                }
            }
            if ($orderCount) {
                return $this->fail('删除失败,该门店还有待处理订单');
            }
            $storeInfo->is_del = 1;
            if (!$storeInfo->save()) {
                return $this->fail('删除失败,请稍候再试!');
            } else {
                $storeStaffServices->update(['store_id' => $id, 'is_del' => 0], ['is_del' => 1]);

				$this->services->cacheDelById($id);

				/** @var StoreBranchProductServices $storeBranchProducesServices */
				$storeBranchProducesServices = app()->make(StoreBranchProductServices::class);
				//删除门店商品
				$storeBranchProducesServices->deleteProducts([], 1, $id);
				/** @var SystemAttachmentServices $attach */
				$attach = app()->make(SystemAttachmentServices::class);
				//删除附件
				$attach->delAttachment([], 2, $id);


                event('store.delete', [$id]);
                return $this->success('删除门店成功!');
            }
        }
    }

    /**
     * 门店登录
     * @param SystemStoreStaffServices $staffServices
     * @param LoginServices $services
     * @param $id
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function storeLogin(SystemStoreStaffServices $staffServices, LoginServices $services, $id)
    {
        $storeInfo = $this->services->get($id);
        if (!$storeInfo) {
            return $this->fail('登录的门店不存在');
        }
        $staffAdmin = $staffServices->getOne(['store_id' => $id, 'level' => 0, 'is_admin' => 1]);
        if (!$staffAdmin) {
            return $this->fail('门店超级管理员异常');
        }
        return $this->success($services->login($staffAdmin['account'], '', 'store', $id));
    }

    /**
     * @return mixed
     * @throws \Exception
     */
    public function getErpShop()
    {
        return $this->success($this->services->erpShopList());
    }

    /**
     * 同城配送设置
     * @return mixed
     */
    public function deliveryStatus()
    {
        return $this->success($this->services->getDeliveryStatus());
    }
}
