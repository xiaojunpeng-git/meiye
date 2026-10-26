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

use app\Request;
use think\Response;
use \think\facade\Log;

class Stock
{
    /**
     * 库存回调（已停用：禁止 ERP 直写实物库存）
     * @param Request $request
     * @return Response
     */
    public function stockCallback(Request $request)
    {
        [$datas] = $request->postMore([
            ['datas', []]
        ], true);

        Log::info(['data' => json_encode($datas), 'type' => 'stockCallback', 'disabled' => true]);

        // 【库存铁律】不再派发 updatePlatformStock；明确返回失败，避免 ERP 误判已同步
        // if (sys_config('erp_open')) {
        //     ProductSyncErp::dispatchDo('updatePlatformStock', [$datas]);
        // }
        // return Response::create(['code' => "0", "msg" => "执行成功"], "json");

        return Response::create([
            'code' => '1',
            'msg' => '已停用：不可通过 ERP 回调直接覆盖库存，请通过库存管理入库/出库调整',
        ], 'json');
    }
}
