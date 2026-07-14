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
namespace app\controller\supplier;

use app\services\order\StoreOrderRefundServices;
use app\services\order\StoreOrderServices;
use app\services\other\CityAreaServices;
use app\services\order\supplier\SupplierOrderServices;
use app\services\supplier\SystemSupplierServices;
use app\services\system\SystemMenusServices;

/**
 * 公共接口基类 主要存放公共接口
 * Class Common
 * @package app\controller\admin
 */
class Common extends AuthController
{
    /**
     * 获取logo
     * @param SystemSupplierServices $supplierServices
     * @return mixed
     */
    public function getLogo(SystemSupplierServices $supplierServices)
    {
        $supplier = $supplierServices->get((int)$this->supplierId, ['id', 'name']);
        return $this->success([
            'logo' => sys_config('site_logo'),
            'logo_square' => sys_config('site_logo'),
            'site_name' => $supplier && isset($supplier['supplier_name']) && $supplier['supplier_name'] ? $supplier['supplier_name'] : sys_config('site_name')
        ]);
    }

    /**
     * @return mixed
     */
    public function getConfig()
    {
        return $this->success([
            'tengxun_map_key' => sys_config('tengxun_map_key'),
            'open_erp' => !!sys_config('erp_open')
        ]);
    }

    /**
     * 省市区信息
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
        $list = $menusServices->getSearchList(4);
        $counts = $menusServices->getColumn([
            ['type', 3],
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
			$data[$key]['menu_path'] = preg_replace('/^\/cashier/', '', $item['menu_path']);
        }
        return $this->success(sort_list_tier($data));
    }

    /**
     * 首页运营头部统计
     * @param SupplierOrderServices $orderServices
     * @return mixed
     */
    public function homeStatics(SupplierOrderServices $orderServices)
    {
        [$time] = $this->request->getMore([
            ['data', '', '', 'time']
        ], true);
        $time = $orderServices->timeHandle($time, true);
        $data = $orderServices->homeStatics((int)$this->supplierId, $time);
        return $this->success($data);
    }

    /**
     * 营业趋势图表
     * @param SupplierOrderServices $orderServices
     * @return mixed
     */
    public function orderChart(SupplierOrderServices $orderServices)
    {
        [$time] = $this->request->getMore([
            ['data', '', '', 'time']
        ], true);
        $time = $orderServices->timeHandle($time, true);
        $data = $orderServices->orderCharts((int)$this->supplierId, $time);
        return $this->success($data);
    }

    /**
     * 订单类型分析
     * @param SupplierOrderServices $orderServices
     * @return mixed
     */
    public function orderType(SupplierOrderServices $orderServices)
    {
        [$time] = $this->request->getMore([
            ['data', '', '', 'time']
        ], true);
        $time = $orderServices->timeHandle($time, true);
        $data = $orderServices->getOrderType((int)$this->supplierId, $time);
        return $this->success($data);
    }

    /**
     * 订单来源分析
     * @param SupplierOrderServices $orderServices
     * @return mixed
     */
    public function orderChannel(SupplierOrderServices $orderServices)
    {
        [$time] = $this->request->getMore([
            ['data', '', '', 'time']
        ], true);
        $time = $orderServices->timeHandle($time, true);
        $data = $orderServices->getOrderChannel((int)$this->supplierId, $time);
        return $this->success($data);
    }

    /**
     * 待办事统计
     * @return mixed
     */
    public function jnotice()
    {
        /** @var StoreOrderServices $orderServices */
        $orderServices = app()->make(StoreOrderServices::class);
		$orderNum = $orderServices->count(['supplier_id' => $this->supplierId, 'pid' => 0, 'status' => 1]);
		/** @var StoreOrderRefundServices $refundServices */
		$refundServices = app()->make(StoreOrderRefundServices::class);
		$orderRefundNum =  $refundServices->count(['is_cancel' => 0, 'refund_type' => [0, 1, 2, 4, 5], 'supplier_id' => $this->supplierId]);

        $value = [];
        if ($orderNum > 0) {
            $value[] = [
                'title' => '您有' . $orderNum . '个待发货的订单',
                'type' => 'bulb',
                'url' => '/' . config('admin.supplier_prefix') . '/order/list?type=7&status=1'
            ];
        }
		if ($orderRefundNum) {
            $value[] = [
                'title' => '您有' . $orderRefundNum . '个售后订单待处理',
                'type' => 'bulb',
                'url' => '/' . config('admin.supplier_prefix') . '/order/refund'
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
		$copyright['extract_min_price'] = sys_config('supplier_extract_min_price');
		$copyright['extract_max_price'] = sys_config('supplier_extract_max_price');
        return $this->success($copyright);
    }
}
