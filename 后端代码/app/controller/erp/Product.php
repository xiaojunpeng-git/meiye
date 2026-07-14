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
namespace app\controller\erp;

use app\jobs\product\ProductSyncErp;
use app\Request;
use mohe\services\erp\Erp as ErpServices;
use mohe\services\erp\storage\jushuitan\Product as ProductService;
use think\Response;

/**
 * 商品类
 * Class Product
 * @package app\controller\erp
 */
class Product
{

    /*** @var ProductService */
    protected $services;

    public function __construct(ErpServices $services)
    {
        $this->services = $services->serviceDriver('product');
    }

    /**
     * 使用spu同步商品
     * @param Request $request
     * @return mixed
     * @throws \Exception
     */
    public function syncProduct(Request $request)
    {
        [$spuStr] = $request->getMore([
            ['spu_str', ''],
        ], true);

        if (empty($spuStr)) {
            return app('json')->fail('请输入ERP商品SPU');
        }
        $spuArr = explode(',', $spuStr);
        foreach ($spuArr as $item) {
            // 获取商品
            ProductSyncErp::dispatchDo('productFromErp', [$item]);
        }
        return app('json')->success('正在同步中，请稍后查看');
    }

    /**
     * 使用sku同步库存
     * @param Request $request
     * @return mixed
     * @throws \Exception
     */
    public function syncStock(Request $request)
    {
        // 【库存铁律】ERP 同步库存直写已停用
        return app('json')->fail('已停用：不可从 ERP 直接覆盖库存，请通过「库存管理」操作');
        // [$ids] = $request->getMore([['ids', '']], true);
        // ...
    }
}
