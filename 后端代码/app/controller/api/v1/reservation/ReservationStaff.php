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
namespace app\controller\api\v1\reservation;

use app\Request;
use app\services\store\SystemStoreStaffServices;
use think\Response;

/**
 * 预约服务人员
 * Class ReservationStaff
 * @package app\controller\api\v1\reservation
 */
class ReservationStaff
{
    /**
     * @var SystemStoreStaffServices
     */
    protected $services;

    public function __construct(SystemStoreStaffServices $services)
    {
        $this->services = $services;
    }

    /**
     * 预约服务人员列表（按门店，无距离筛选）
     * @param Request $request
     * @return Response
     */
    public function list(Request $request): Response
    {
        [$store_id, $keyword, $service_date, $service_time, $service_duration, $position_ids] = $request->getMore([
            [['store_id', 'd'], 0],
            ['keyword', ''],
            ['service_date', ''],
            ['service_time', ''],
            [['service_duration', 'd'], 0],
            ['position_ids', ''],
        ], true);
        $position_ids = is_array($position_ids) ? $position_ids : explode(',', (string)$position_ids);
        $position_ids = array_values(array_unique(array_filter(array_map('intval', $position_ids), static function (int $id): bool {
            return $id > 0;
        })));
        $list = $this->services->getReservationStaffList((int)$store_id, [
            'keyword' => $keyword,
            'service_date' => $service_date,
            'service_time' => $service_time,
            'service_duration' => (int)$service_duration,
            'position_ids' => $position_ids,
        ]);
        return app('json')->success(['list' => $list]);
    }

    /**
     * 员工已被占用时段（供选时间组件）
     * @param Request $request
     * @return Response
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
     * 门店可预约时段（筛选老师列表用）
     * @param Request $request
     * @return Response
     */
    public function serviceTimeSlots(Request $request): Response
    {
        [$store_id, $service_date, $service_duration, $exclude_reservation_id] = $request->getMore([
            [['store_id', 'd'], 0],
            ['service_date', ''],
            [['service_duration', 'd'], 0],
            [['exclude_reservation_id', 'd'], 0],
        ], true);
        /** @var \app\services\store\StoreReservationStaffServices $reservationStaffServices */
        $reservationStaffServices = app()->make(\app\services\store\StoreReservationStaffServices::class);
        $data = $reservationStaffServices->getStoreServiceTimeSlots(
            (int)$store_id,
            (string)$service_date,
            (int)$service_duration,
            (int)$exclude_reservation_id
        );
        return app('json')->success($data);
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

    /**
     * 手艺人预约冲突列表
     */
    public function staffConflicts(Request $request): Response
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
}
