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


use app\jobs\order\CityDeliveryJob;
use app\services\store\SystemStoreStaffServices;
use app\validate\store\system\StoreValidate;
use mohe\services\DeliverySevices;
use think\facade\App;
use app\controller\store\AuthController;
use app\services\store\SystemStoreServices;

/**
 * 门店
 * Class Store
 * @package app\controller\store\system
 */
class Store extends AuthController
{

    public function __construct(App $app, SystemStoreServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    /**
     * 获取门店信息
     * @return mixed
     */
    public function info()
    {
        return app('json')->success($this->services->getStoreInfo((int)$this->storeId));
    }

    /**
     * 修改当前登录门店
     * @return mixed
     */
    public function update(SystemStoreStaffServices $services)
    {
        $data = $this->request->postMore([
            ['image', ''],
            ['background_image', ''],
            ['name', ''],
            ['introduction', ''],
            ['phone', ''],
            ['address', ''],
            ['addressSelect', []],//城市选择
            ['province', ''],
            ['city', ''],
            ['area', ''],
            ['street', ''],
            ['valid_range', 0],
            ['detailed_address', ''],
            ['is_show', ''],
            ['day_time', []],
            ['latitude', ''],
            ['longitude', ''],
//            ['is_store', 0],
            ['delivery_type', []],//配送方式:1、快递发货，2、到店自提，3、门店配送
            ['default_delivery', 1],
            ['customer_type', 1],
            ['home_style', 1],
            ['city_delivery_status', 1],
//            ['city_delivery_type', 1],
            ['delivery_goods_type', 1],
            ['business', 0],
            ['use_system_money', 1],//使用平台余额1:开启0:关闭
            ['is_alone', 0],//是否隔离
        ]);
        validate(StoreValidate::class)->check($data);

        if ($data['addressSelect']) {
            $data['province'] = $data['addressSelect'][0] ?? '';
            $data['city'] = $data['addressSelect'][1] ?? '';
            $data['area'] = $data['addressSelect'][2] ?? '';
            $data['street'] = $data['addressSelect'][3] ?? '';
        }

        [$data['day_start'], $data['day_end']] = $data['day_time'];
        $data['day_time'] = $data['day_time'] ? implode(' - ', $data['day_time']) : '';
        $data['valid_range'] = bcmul($data['valid_range'], '1000', 0);
        if ($data['address'] == '') return $this->fail('请选择门店地址');
        if ($data['detailed_address'] == '') return $this->fail('请输入门店详细地址');
        $data['address'] = str_replace([' ', '/', '\\'], '', $data['address']);
        $data['detailed_address'] = str_replace([' ', '/', '\\'], '', $data['detailed_address']);
        if (!isset($data['delivery_type']) || count($data['delivery_type']) <= 0) {
            return $this->fail('请选择配送方式');
        }
        if (!$data['city_delivery_status'] && in_array(3, $data['delivery_type'])) {
            $data['city_delivery_status'] = 1;
        } else if (!in_array(3, $data['delivery_type'])) {
            $data['city_delivery_status'] = 0;
        }
        $phone = $this->services->value(['id' => $this->storeId], 'phone');
        if ($this->services->update($this->storeId, $data)) {
            if ($phone) {
                $services->update(['phone' => $phone], ['phone' => $data['phone']]);
            }
            $storeInfo = $this->services->get((int)$this->storeId);
            $this->services->cacheUpdate($storeInfo->toArray());
            //修改、保存uu、达达门店
            CityDeliveryJob::dispatchDo('syncCityShop', [$this->storeId,0]);
            return app('json')->success('修改成功');
        } else {
            return app('json')->fail('修改失败');
        }
    }

    /**
     * 获取门店财务信息
     * @return mixed
     */
    public function getFinanceInfo()
    {
        return app('json')->success($this->services->getStoreInfo((int)$this->storeId));
    }

    /**
     * 设置门店财务信息
     * @return mixed
     */
    public function setFinanceInfo()
    {
        $data = $this->request->postMore([
            ['bank_code', ''],
            ['bank_address', ''],
            ['alipay_account', ''],
            ['alipay_qrcode_url', ''],
            ['wechat', ''],
            ['wechat_qrcode_url', '']
        ]);
        $storeInfo = $this->services->get((int)$this->storeId);
        if (!$storeInfo) {
            return app('json')->fail('门店不存在');
        }
        if ($this->services->update($storeInfo['id'], $data)) {
            return app('json')->success('设置成功');
        } else {
            return app('json')->fail('设置失败');
        }
    }

    /**
     * 获取uu、达达配送商品类型
     * @param $type
     * @return mixed
     */
    public function getBusiness($type = 1)
    {
        return app('json')->success(DeliverySevices::init((int)$type)->getBusiness());
    }

    /**获取门店二维码
     * @return mixed
     */
    public function store_qrcode()
    {
        $id = (int)$this->storeId;
        /** @var SystemStoreServices $StoreServices */
        $StoreServices = app()->make(SystemStoreServices::class);
        $data = $StoreServices->getStoreQrcode($id, (int)$this->storeStaffId);
        return $this->success($data);
    }

    /**
     * 门店列表
     * @param SystemStoreServices $services
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function store_list()
    {
        /** @var SystemStoreServices $StoreServices */
        $StoreServices = app()->make(SystemStoreServices::class);
        $where = ['status' => 1];
        return $this->success($StoreServices->getStore($where));
    }

    /**
     * 同城配送设置
     * @return mixed
     */
    public function deliveryStatus()
    {
        /** @var SystemStoreServices $StoreServices */
        $StoreServices = app()->make(SystemStoreServices::class);
        return $this->success($StoreServices->getDeliveryStatus());
    }
}
