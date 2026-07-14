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
namespace app\controller\cashier;

use app\Request;
use app\services\store\SystemStoreStaffServices;
use think\Response;

/**
 * 收银台预约服务人员
 * Class ReservationStaff
 * @package app\controller\cashier
 */
class ReservationStaff extends AuthController
{
    /**
     * 预约服务人员列表（按门店，无距离筛选）
     * @param Request $request
     * @param SystemStoreStaffServices $services
     * @return Response
     */
    public function list(Request $request, SystemStoreStaffServices $services): Response
    {
        [$store_id, $keyword, $service_date, $service_time, $service_duration] = $request->getMore([
            [['store_id', 'd'], 0],
            ['keyword', ''],
            ['service_date', ''],
            ['service_time', ''],
            [['service_duration', 'd'], 0],
        ], true);
        if (!$store_id) {
            $store_id = (int)$this->storeId;
        }
        $list = $services->getReservationStaffList((int)$store_id, [
            'keyword' => $keyword,
            'service_date' => $service_date,
            'service_time' => $service_time,
            'reservation_start' => $request->get('reservation_start', ''),
            'service_duration' => (int)$service_duration,
            'exclude_reservation_id' => (int)$request->get('exclude_reservation_id', 0),
        ]);
        /** @var \app\services\store\StoreStaffScheduleServices $scheduleServices */
        $scheduleServices = app()->make(\app\services\store\StoreStaffScheduleServices::class);
        return app('json')->success([
            'list' => $list,
            'schedule_manage' => $scheduleServices->isScheduleManageEnabled() ? 1 : 0,
        ]);
    }

    /**
     * 员工已被占用时段
     */
    public function availableTime(Request $request): Response
    {
        [$store_id, $staff_id, $staff_ids, $service_date, $service_duration, $exclude_reservation_id] = $request->getMore([
            [['store_id', 'd'], 0],
            [['staff_id', 'd'], 0],
            ['staff_ids', ''],
            ['service_date', ''],
            [['service_duration', 'd'], 0],
            [['exclude_reservation_id', 'd'], 0],
        ], true);
        if (!$store_id) {
            $store_id = (int)$this->storeId;
        }
        if (is_string($staff_ids)) {
            $staff_ids = array_filter(array_map('intval', explode(',', $staff_ids)));
        } elseif (!is_array($staff_ids)) {
            $staff_ids = [];
        }
        /** @var \app\services\store\StoreReservationStaffServices $reservationStaffServices */
        $reservationStaffServices = app()->make(\app\services\store\StoreReservationStaffServices::class);
        $data = $reservationStaffServices->getStaffAvailableTimeData(
            (int)$staff_id,
            (int)$store_id,
            (string)$service_date,
            (int)$service_duration,
            (int)$exclude_reservation_id,
            $staff_ids
        );
        return app('json')->success($data);
    }

    /**
     * 手艺人当前预约时段冲突
     */
    public function conflicts(Request $request): Response
    {
        [$store_id, $staff_ids, $service_date, $reservation_start, $reservation_end, $service_duration, $exclude_reservation_id] = $request->getMore([
            [['store_id', 'd'], 0],
            ['staff_ids', ''],
            ['service_date', ''],
            ['reservation_start', ''],
            ['reservation_end', ''],
            [['service_duration', 'd'], 0],
            [['exclude_reservation_id', 'd'], 0],
        ], true);
        if (!$store_id) {
            $store_id = (int)$this->storeId;
        }
        if (is_string($staff_ids)) {
            $staff_ids = array_filter(array_map('intval', explode(',', $staff_ids)));
        } elseif (!is_array($staff_ids)) {
            $staff_ids = [];
        }
        /** @var \app\services\store\StoreReservationStaffServices $reservationStaffServices */
        $reservationStaffServices = app()->make(\app\services\store\StoreReservationStaffServices::class);
        $list = $reservationStaffServices->getStaffReservationConflictList(
            (int)$store_id,
            $staff_ids,
            (string)$service_date,
            (string)$reservation_start,
            (string)$reservation_end,
            (int)$service_duration,
            (int)$exclude_reservation_id
        );
        return app('json')->success(['list' => $list]);
    }

    /**
     * 指定时段已被占用的手艺人 ID
     */
    public function busyStaff(Request $request): Response
    {
        [$store_id, $service_date, $reservation_start, $reservation_end, $service_duration, $exclude_reservation_id] = $request->getMore([
            [['store_id', 'd'], 0],
            ['service_date', ''],
            ['reservation_start', ''],
            ['reservation_end', ''],
            [['service_duration', 'd'], 0],
            [['exclude_reservation_id', 'd'], 0],
        ], true);
        if (!$store_id) {
            $store_id = (int)$this->storeId;
        }
        /** @var \app\services\store\StoreReservationStaffServices $reservationStaffServices */
        $reservationStaffServices = app()->make(\app\services\store\StoreReservationStaffServices::class);
        $staffIds = $reservationStaffServices->getBusyStaffIdsAtReservationTime(
            (int)$store_id,
            (string)$service_date,
            (string)$reservation_start,
            (string)$reservation_end,
            (int)$service_duration,
            (int)$exclude_reservation_id
        );
        return app('json')->success(['staff_ids' => $staffIds]);
    }
}
