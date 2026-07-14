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
use think\Response;
use \think\facade\Log;

class Stock
{
    /**
     * 库存回调
     * @param Request $request
     * @return void
     */
    public function stockCallback(Request $request)
    {
        [$datas] = $request->postMore([
            ['datas', []]
        ], true);

        Log::info(['data' => json_encode($datas), 'type' => 'stockCallback']);
        if (sys_config('erp_open')) {
            ProductSyncErp::dispatchDo('updatePlatformStock', [$datas]);
        }

        return Response::create(['code' => "0", "msg" => "执行成功"], "json");
    }
}
