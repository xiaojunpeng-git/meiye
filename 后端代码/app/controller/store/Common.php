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
namespace app\controller\store;

use app\Request;
use app\services\order\OtherOrderServices;
use app\services\order\store\BranchOrderServices;
use app\services\order\StoreOrderRefundServices;
use app\services\order\StoreOrderServices;
use app\services\other\CityAreaServices;
use app\services\product\branch\StoreBranchProductServices;
use app\services\product\product\StoreProductReplyServices;
use app\services\store\SystemStoreServices;
use app\services\store\SystemStoreStaffServices;
use app\services\system\SystemMenusServices;
use app\services\user\UserRechargeServices;


/**
 * 公共接口基类 主要存放公共接口
 * Class Common
 * @package app\controller\admin
 */
class Common extends AuthController
{
    /**
     * 获取logo
     * @param SystemStoreServices $storeServices
     * @return mixed
     */
    public function getLogo(SystemStoreServices $storeServices)
    {
        $store = $storeServices->get((int)$this->storeId, ['id', 'image', 'name']);
        return $this->success([
            'logo' => $store && isset($store['image']) && $store['image'] ? $store['image'] : sys_config('site_logo'),
            'logo_square' => $store && isset($store['image']) && $store['image'] ? $store['image'] : sys_config('site_logo'),
            'site_name' => $store && isset($store['name']) && $store['name'] ? $store['name'] : sys_config('site_name')
        ]);
    }

    /**
 	* 获取门店配置
	* @param SystemStoreServices $storeServices
	* @return mixed
	* @throws \think\db\exception\DataNotFoundException
	* @throws \think\db\exception\DbException
	* @throws \think\db\exception\ModelNotFoundException
	*/
    public function getConfig(SystemStoreServices $storeServices)
    {
		$store = $storeServices->get((int)$this->storeId, ['id', 'product_status', 'product_category_status']);
		$brokerage_func_status = sys_config('brokerage_func_status', 1);
		$brokerage_level = sys_config('brokerage_level', 2);
        return $this->success([
            'tengxun_map_key' => sys_config('tengxun_map_key'),//腾讯地图
            'open_erp' => !!sys_config('erp_open'),//erp开关
            'product_status' => $store['product_status'] ?? 1,//添加商品开关
            'product_category_status' => $store['product_category_status'] ?? 1,//同步平台商品分类开关
			'store_brokerage_ratio' => $brokerage_func_status ? sys_config('store_brokerage_ratio', 0) : 0,//一级佣金比率
			'store_brokerage_two' => $brokerage_func_status && $brokerage_level == 2 ? sys_config('store_brokerage_two', 0) : 0,//二级佣金比率
			'store_cashier_order_rate' => sys_config('store_cashier_order_rate ', 0),//收银订单费率
			'store_self_order_rate' => sys_config('store_self_order_rate ', 0),//分配订单费率
			'store_writeoff_order_rate' => sys_config('store_writeoff_order_rate ', 0),//核销订单费率
        ]);
    }

    /**
     * @param CityAreaServices $services
     * @return mixed
     */
    public function city(CityAreaServices $services, $pid = 0)
    {
        return $this->success($services->getCityTreeList((int)$pid));
    }

    /**
     * 格式化菜单
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function menusList()
    {
        /** @var SystemMenusServices $menusServices */
        $menusServices = app()->make(SystemMenusServices::class);
        $list = $menusServices->getSearchList(2);
        $counts = $menusServices->getColumn([
            ['type', 2],
            ['is_show', '=', 1],
            ['auth_type', '=', 1],
            ['is_del', '=', 0],
            ['is_show_path', '=', 0],
        ], 'pid');
        $data = [];
        foreach ($list as $key => $item) {
            $pid = $item->getData('pid');
            $data[$key] = json_decode($item, true);
            $data[$key]['pid'] = $pid;
            if (in_array($item->id, $counts)) {
                $data[$key]['type'] = 1;
            } else {
                $data[$key]['type'] = 0;
            }
			$data[$key]['menu_path'] = preg_replace('/^\/store/', '', $item['menu_path']);
        }
        return app('json')->success(sort_list_tier($data));
    }

    /**
     * 首页运营头部统计
     * @param Request $request
     * @param BranchOrderServices $orderServices
     * @return mixed
     */
    public function homeStatics(Request $request, BranchOrderServices $orderServices)
    {
		$where = $request->getMore([
			['data', '', '', 'time']
		]);
		$where['time'] = $orderServices->timeHandle($where['time']);
		$where['store_id'] = (int)$this->storeId;
        return app('json')->success($orderServices->homeStatics($where));
    }

    /**
     * 首页营业趋势图表
     * @param Request $request
     * @param BranchOrderServices $orderServices
     * @return mixed
     */
    public function operateChart(Request $request, BranchOrderServices $orderServices)
    {
        [$time] = $request->getMore([
            ['data', '', '', 'time']
        ], true);
        $time = $orderServices->timeHandle($time, true);
        return app('json')->success($orderServices->operateChart(['store_id' => (int)$this->storeId], $time));
    }

    /**
     * 首页交易统计
     * @param Request $request
     * @param BranchOrderServices $orderServices
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function orderChart(Request $request, BranchOrderServices $orderServices)
    {
		$where = $request->getMore([
			['data', '', '', 'time']
		]);
		$where['time'] = $orderServices->timeHandle($where['time']);
		$where['store_id'] = (int)$this->storeId;
        return $this->success($orderServices->orderChart($where));
    }

    /**
     * 首页店员统计
     * @param Request $request
     * @param SystemStoreStaffServices $staffServices
     * @return mixed
     */
    public function staffChart(Request $request, SystemStoreStaffServices $staffServices)
    {
        [$time] = $request->getMore([
            ['data', '', '', 'time']
        ], true);
        $time = $staffServices->timeHandle($time);
        return $this->success($staffServices->staffChart((int)$this->storeId, $time));
    }


    /**
     * 待办事统计
     * @return mixed
     */
    public function jnotice()
    {
        /** @var StoreOrderServices $orderServices */
        $orderServices = app()->make(StoreOrderServices::class);
		$orderNum = $orderServices->count(['store_id' => $this->storeId, 'pid' => 0, 'status' => 1]);
        $store_stock = sys_config('store_stock');
        if ($store_stock < 0) $store_stock = 2;
        /** @var StoreBranchProductServices $storeServices */
        $storeServices = app()->make(StoreBranchProductServices::class);
        $inventory = $storeServices->count(['store_id' => $this->storeId, 'is_police' => 1, 'status' => 5, 'store_stock' => $store_stock]);//警戒库存
        /** @var StoreProductReplyServices $replyServices */
        $replyServices = app()->make(StoreProductReplyServices::class);
        $commentNum = $replyServices->replyCount(1, (int)$this->storeId);
		/** @var StoreOrderRefundServices $refundServices */
		$refundServices = app()->make(StoreOrderRefundServices::class);
		$orderRefundNum =  $refundServices->count(['is_cancel' => 0, 'refund_type' => [0, 1, 2, 4, 5], 'store_id' => $this->storeId]);
        $value = [];
        if ($orderNum) {
            $value[] = [
                'title' => '您有' . $orderNum . '个待发货的订单',
                'type' => 'bulb',
                'url' => '/' . config('admin.store_prefix') . '/order/index?type=7&status=1'
            ];
        }
        if ($inventory) {
            $value[] = [
                'title' => '您有' . $inventory . '个商品库存预警',
                'type' => 'information',
                'url' => '/' . config('admin.store_prefix') . '/product/index?type=5',
            ];
        }
        if ($commentNum) {
            $value[] = [
                'title' => '您有' . $commentNum . '条评论待回复',
                'type' => 'bulb',
                'url' => '/' . config('admin.store_prefix') . '/product/product_reply?is_reply=0'
            ];
        }
		if ($orderRefundNum) {
            $value[] = [
                'title' => '您有' . $orderRefundNum . '个售后订单待处理',
                'type' => 'bulb',
                'url' => '/' . config('admin.store_prefix') . '/order/refund'
            ];
        }
        /** @var \app\services\product\inventory\StoreStockRequestServices $stockRequestServices */
        $stockRequestServices = app()->make(\app\services\product\inventory\StoreStockRequestServices::class);
        $stockRequestPending = $stockRequestServices->pendingSupplyCount((int)$this->storeId);
        if ($stockRequestPending) {
            $value[] = [
                'title' => '您有' . $stockRequestPending . '张请货单待调拨/处理',
                'type' => 'bulb',
                'url' => '/' . config('admin.store_prefix') . '/stock/request?status=1'
            ];
        }
        return $this->success($this->noticeData($value));
    }

    /**
     * 消息返回格式
     * @param array $data
     * @return array
     */
    public function noticeData(array $data): array
    {
        // 消息图标
        $iconColor = [
            // 邮件 消息
            'mail' => [
                'icon' => 'md-mail',
                'color' => '#3391e5'
            ],
            // 普通 消息
            'bulb' => [
                'icon' => 'md-bulb',
                'color' => '#87d068'
            ],
            // 警告 消息
            'information' => [
                'icon' => 'md-information',
                'color' => '#fe5c57'
            ],
            // 关注 消息
            'star' => [
                'icon' => 'md-star',
                'color' => '#ff9900'
            ],
            // 申请 消息
            'people' => [
                'icon' => 'md-people',
                'color' => '#f06292'
            ],
        ];
        // 消息类型
        $type = array_keys($iconColor);
        // 默认数据格式
        $default = [
            'icon' => 'md-bulb',
            'iconColor' => '#87d068',
            'title' => '',
            'url' => '',
            'type' => 'bulb',
            'read' => 0,
            'time' => 0
        ];
        $value = [];
        foreach ($data as $item) {
            $val = array_merge($default, $item);
            if (isset($item['type']) && in_array($item['type'], $type)) {
                $val['type'] = $item['type'];
                $val['iconColor'] = $iconColor[$item['type']]['color'] ?? '';
                $val['icon'] = $iconColor[$item['type']]['icon'] ?? '';
            }
            $value[] = $val;
        }
        return $value;
    }

    /**
     * 轮询后台扫码订单状态
     * @param Request $request
     * @param $type
     * @return mixed
     */
    public function checkOrderStatus(Request $request, $type)
    {
        [$order_id, $end_time] = $request->getMore([
            ['order_id', ''],
            ['end_time', 0],
        ], true);
        switch ($type) {
            case 1://recharge
                /** @var UserRechargeServices $userRecharge */
                $userRecharge = app()->make(UserRechargeServices::class);
                $data['status'] = (bool)$userRecharge->count(['order_id' => $order_id, 'paid' => 1]);
                break;
            case 2://svip
                /** @var OtherOrderServices $otherOrderServices */
                $otherOrderServices = app()->make(OtherOrderServices::class);
                $data['status'] = (bool)$otherOrderServices->count(['order_id' => $order_id, 'paid' => 1]);
                break;
            case 3://订单
                $storeOrderServices = app()->make(StoreOrderServices::class);
                $data['status'] = (bool)$storeOrderServices->count(['order_id' => $order_id, 'paid' => 1]);
                break;
            default:
                return app('json')->fail('暂不支持该类型订单查询');
        }
        $time = $end_time - time();
        $data['time'] = $time > 0 ? $time : 0;
        return app('json')->successful($data);
    }


	/**
     * 获取版权
     * @return mixed
     */
    public function getCopyright()
    {
        try {
            $copyright = $this->__z6uxyJQ4xYa5ee1mx5();
        } catch (\Throwable $e) {
            $copyright = ['copyrightContext' => '', 'copyrightImage' => ''];
        }
		$copyright['version'] = get_mohe_version();
		$copyright['prefix'] = config('admin.cashier_prefix');
		$copyright['extract_min_price'] = sys_config('store_extract_min_price');
		$copyright['extract_max_price'] = sys_config('store_extract_max_price');
        return $this->success($copyright);
    }

	/**
	 * 解析（获取导入地图城市地址）
	 * @param CityAreaServices $services
	 * @param Request $request
	 * @return \think\Response
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function resolveCityList(CityAreaServices $services, Request $request)
	{
		$address = $request->param('address', '');
		if (!$address)
			return app('json')->fail('地址不存在');
		if (strpos($address, '/') === false) {
			$address = implode('/', array_values($services->addressHandle($address)));
		}
		$city = $services->searchCity(compact('address'));
		if (!$city) return app('json')->fail('地址暂未录入，请联系管理员');
		$where = [['id', 'in', array_merge([$city['id']], explode('/', trim($city->path, '/')))]];
		return app('json')->success($services->getCityList($where, 'id as value,id,name as label,parent_id as pid', ['children']));
	}
}
