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
namespace app\controller\store\system;

use app\controller\store\AuthController;
use app\services\order\DeliveryConfigServices;
use app\services\store\SystemStoreServices;
use think\facade\App;

/**
 * 门店同城配送配置
 * Class DeliveryConfig
 * @package app\controller\store\system
 */
class DeliveryConfig extends AuthController
{
    /**
     * @var DeliveryConfigServices
     */
    protected $services;

    /**
     * DeliveryConfig constructor.
     * @param App $app
     * @param DeliveryConfigServices $services
     */
    public function __construct(App $app, DeliveryConfigServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    /**
     * 修改门店配送设置
     * @return mixed
     */
    public function update(SystemStoreServices $services)
    {
        $data = $this->request->postMore([
            ['city_delivery_type', 0], //同城配送类型：0：商家自配 1：达达2:uu
            ['business', 0],
            ['range_type', 1], //距离设置类型(1:范围 2:行政区 3:电子围栏)
            ['radius', 0],//服务半径(km)
            ['region', []], // 行政区域
            ['fence', []],// 电子围栏配置
            ['min_delivery_amount', 0],
            ['base_shipping_fee', 0],
            ['free_shipping_amount', 0],
            ['is_premium_stack_enabled', 0],
            ['distance_premium_config', []],
            ['weight_premium_config', []],
            ['delivery_time_type', 1],
            ['selectable_days', 7],
            ['delivery_prompt', ''],
        ]);

        $store_data['city_delivery_type'] = $data['city_delivery_type'];
        $store_data['business'] = $data['business'];
        $store_data['range_type'] = $data['range_type'];
        $store_data['radius'] = $data['radius'];
        $store_data['region'] = $data['region'];
        $store_data['fence'] = $data['fence'];
        if($data['min_delivery_amount'] >= $data['free_shipping_amount']) {
            return app('json')->fail('起送价不能大于包邮价！');
        }
        unset($data['city_delivery_type'],$data['business'], $data['range_type'], $data['radius'], $data['region'], $data['fence']);
        $res = $services->update($this->storeId, $store_data);
        if ($res) {
            $config = $this->services->get(['type' => 1, 'relation_id' => $this->storeId]);
            if (!$config) {
                $data['type'] = 1;
                $data['relation_id'] = $this->storeId;
                $data['add_time'] = time();
                $this->services->save($data);
            } else {
                $this->services->update(['type' => 1, 'relation_id' => $this->storeId], $data);
            }
            $storeInfo = $services->get((int)$this->storeId);
            $services->cacheUpdate($storeInfo->toArray());
            return app('json')->success('修改成功');
        } else {
            return app('json')->fail('修改失败');
        }
    }

    /**
     * 获取配送配置
     * @return mixed
     */
    public function detail()
    {
        /** @var SystemStoreServices $StoreServices */
        $StoreServices = app()->make(SystemStoreServices::class);
        $data['storeInfo'] = $StoreServices->get((int)$this->storeId, ['city_delivery_type', 'business', 'range_type', 'radius', 'region', 'fence']);
        $data['config'] = $this->services->get(['type' => 1, 'relation_id' => $this->storeId]);
        if (!$data['config']) {
            $data['config']['city_delivery_type'] = 0;
            $data['config']['range_type'] = 0;
            $data['config']['radius'] = 0;
            $data['config']['region'] = '';
            $data['config']['fence'] = '';
            $data['config']['min_delivery_amount'] = 0;
            $data['config']['base_shipping_fee'] = 0;
            $data['config']['free_shipping_amount'] = 0;
            $data['config']['is_premium_stack_enabled'] = 0;
            $data['config']['distance_premium_config'] = [];
            $data['config']['weight_premium_config'] = [];
            $data['config']['delivery_time_type'] = 1;
            $data['config']['selectable_days'] = 7;
            $data['config']['delivery_prompt'] = '';
        }
        return app('json')->success($data);
    }

}
