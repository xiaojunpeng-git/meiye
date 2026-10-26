<?php

namespace app\controller\api\v1\order;

use app\Request;
use app\services\cashier\v3\reservation\MemberV3ReservationServices;

/** Member-facing V3 reservation endpoints; legacy reservation tables are not read. */
final class V3ReservationOrder
{
    private $services;

    public function __construct(MemberV3ReservationServices $services)
    {
        $this->services = $services;
    }

    public function listing(Request $request)
    {
        $where = $request->getMore([
            ['status', ''],
            ['search', ''],
            [['oid', 'd'], 0],
            [['page', 'd'], 1],
            [['limit', 'd'], 20],
        ]);
        return app('json')->successful($this->services->listing((int)$request->uid(), $where));
    }

    public function detail(Request $request, $id)
    {
        return app('json')->success($this->services->detail((int)$request->uid(), (int)$id));
    }

    public function create(Request $request, $orderId)
    {
        $data = $request->postMore([
            [['cart_num', 'd'], 1],
            ['reservation_time', ''],
            [['reservation_time_id', 'd'], 0],
            ['reservation_start', ''],
            ['reservation_end', ''],
            ['custom_form', []],
            [['cart_info_id', 'd'], 0],
            [['service_staff_id', 'd'], 0],
            [['service_duration_minutes', 'd'], 0],
            ['addon_items', []],
            ['sync_all', []],
            [['store_id', 'd'], 0],
            ['mark', ''],
            ['reservation_name', ''],
            ['reservation_phone', ''],
            ['reservation_address', ''],
        ]);
        return app('json')->success('预约已提交，请等待门店确认。', $this->services->create((int)$request->uid(), (int)$orderId, $data));
    }

    public function cancel(Request $request, $id)
    {
        return app('json')->success('预约已取消。', $this->services->cancel((int)$request->uid(), (int)$id));
    }

    public function delete(Request $request, $id)
    {
        return app('json')->success('预约记录已删除。', $this->services->delete((int)$request->uid(), (int)$id));
    }
}
