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

namespace app\services\order;


use app\dao\order\StoreReservationOrderDao;
use app\dao\order\StoreOrderDao;
use app\jobs\order\OrderStatusJob;
use app\jobs\reservation\ReservationOrderJob;
use app\jobs\store\StoreFinanceJob;
use app\model\order\StoreOrder;
use app\model\order\StoreOrderCartInfo;
use app\model\order\StoreOrderWriteoff;
use app\model\product\product\StoreProduct;
use app\model\yeji\StaffYeji;
use app\model\yeji\YejiCommission;
use app\services\BaseServices;
use app\services\order\store\WriteOffOrderServices;
use app\services\other\CityAreaServices;
use app\services\product\product\StoreProductReservationServices;
use app\services\product\product\StoreProductServices;
use app\services\product\sku\StoreProductAttrValueServices;
use app\services\user\UserServices;
use app\services\product\sku\StoreProductReservationTimeServices;
use app\services\store\SystemStoreServices;
use app\services\store\SystemStoreStaffServices;
use app\services\store\StoreStaffScheduleServices;
use app\services\system\form\SystemFormServices;
use app\services\user\UserCardHolderServices;
use app\services\yeji\SatffYejiServices;
use mohe\traits\OptionTrait;
use think\exception\ValidateException;
use think\facade\Db;

/**
 * 预约单
 * Class StoreReservationOrderServices
 * @package app\services\order
 * @mixin StoreReservationOrderDao
 */
class StoreReservationOrderServices extends BaseServices
{
    use OptionTrait;

    /**
     * 预约单状态
     * @var string[]
     */
    public $statusName = [
        -1 => '已取消',
        0 => '待服务',
        1 => '服务中',
        2 => '已完成',
        3 => '待确认',
        4 => '已退回',
    ];

    /**
     * 预约单状态描述语
     * @var string[]
     */
    public $statusMsg = [
        -1 => '预约已取消，您可以再次预约',
        0 => '您的预约时间已锁定，我们将按时为您服务',
        1 => '服务已开始，我们将全力为您服务',
        2 => '服务已完成，期待再次光临',
        3 => '您的预约已提交，等待门店确认',
        4 => '预约已被门店退回，您可以重新预约',
    ];


    /**
     * 构造方法
     * StoreReservationOrderServices constructor.
     * @param StoreReservationOrderDao $dao
     */
    public function __construct(StoreReservationOrderDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 后台带分页的获取预约单列表
     * @param array $where
     * @param string $field
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getSystemList(array $where, string $field = '*')
    {
        [$page, $limit] = $this->getPageValue();
        if (isset($where['store_id']) && $where['store_id'] <= 0) unset($where['store_id']);
        $list = $this->dao->getList($where, $field, $page, $limit, ['cartInfo']);
        $count = $this->dao->count($where);
        if ($list) {
            /** @var CityAreaServices $cityServices */
            $cityServices = app()->make(CityAreaServices::class);
            /** @var SystemStoreServices $storeServices */
            $storeServices = app()->make(SystemStoreServices::class);
            foreach ($list as &$item) {
                $item['reservation_create_time'] = $item['reservation_create_time'] ? date('Y-m-d H:i:s', (int)$item['reservation_create_time']) : '';
                $item['reservation_time'] = $item['reservation_time'] ? date('Y-m-d', (int)$item['reservation_time']) : '';
                $cartInfo = isset($item['cart_info']) && is_string($item['cart_info']) ? json_decode($item['cart_info'], true) : ($item['cart_info'] ?? []);
                if ($cartInfo) {//预约单 每一个订单cart_num =1
                    $cartInfo['cart_num'] = 1;
                }
                $item['cart_info'] = $cartInfo;
                $item['status_name'] = $this->statusName[$item['status']] ?? '';
                $this->appendReservationStaffMeta($item);
                //上门地址处理
                $item['reservation_address_city_id'] = [];
                if ($item['reservation_address']) {
                    $item['reservation_address_city_id'] = $cityServices->getCityIdByAddress($item['reservation_address']);
                }
                $item['store_name'] = '';
                if ($item['store_id']) {
                    $item['store_name'] = $storeServices->value(['id' => $item['store_id']], 'name');
                }
            }
        }
        return compact('list', 'count');
    }

    /**
     * 看板数据
     * @param array $where
     * @param string $field
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getNoticeBoardData(array $where, string $field = '*')
    {
        /** @var SystemStoreStaffServices $staffServices */
        $staffServices = app()->make(SystemStoreStaffServices::class);
        /** @var StoreStaffScheduleServices $scheduleServices */
        $scheduleServices = app()->make(StoreStaffScheduleServices::class);
        $storeId = (int)$where['store_id'];
        $boardDate = !empty($where['reservation_time'])
            ? date('Y-m-d', is_numeric($where['reservation_time']) ? (int)$where['reservation_time'] : strtotime((string)$where['reservation_time']))
            : date('Y-m-d');
        $filterStaffId = (int)($where['service_staff_id'] ?? 0);

        $listWhere = $where;
        unset($listWhere['service_staff_id']);
        $allReservations = $this->dao->getList($listWhere, $field, 0, 0, ['cartInfo']);
        $reservationStaffIds = [];
        foreach ($allReservations as &$row) {
            $this->formatNoticeBoardReservationRow($row);
            $primaryId = (int)($row['service_staff_id'] ?? 0);
            if ($primaryId > 0) {
                $reservationStaffIds[$primaryId] = $primaryId;
            }
            foreach ($row['staff_choose'] ?? [] as $sc) {
                $sid = (int)($sc['staff_id'] ?? 0);
                if ($sid > 0) {
                    $reservationStaffIds[$sid] = $sid;
                }
            }
        }
        unset($row);

        $scheduledStaff = $scheduleServices->getReservationBoardStaff($storeId, $boardDate, $filterStaffId);
        $staffMap = [];
        foreach ($scheduledStaff as $staff) {
            $staffMap[(int)$staff['id']] = $staff;
        }
        // 有预约的手艺人必须出现在看板（不受当日是否排班影响）
        if (!$filterStaffId) {
            $this->mergeBoardStaffFromReservations($staffMap, $staffServices, $storeId, $reservationStaffIds);
        }

        $data = array_values($staffMap);
        usort($data, function ($a, $b) {
            return strcmp((string)($a['staff_name'] ?? ''), (string)($b['staff_name'] ?? ''));
        });
        if (!$filterStaffId) {
            array_unshift($data, ['id' => 0, 'staff_name' => '', 'phone' => '']);
        }
        foreach ($data as &$item) {
            $staffId = (int)$item['id'];
            $item['list'] = array_values(array_filter($allReservations, function ($row) use ($staffId) {
                return $this->reservationBelongsToBoardStaff($row, $staffId);
            }));
        }
        unset($item);
        if (!$filterStaffId) {
            $this->appendOrphanReservationsToUnassigned($data, $allReservations);
        }

        $staffRows = array_values(array_filter($data, function ($row) {
            return (int)$row['id'] > 0;
        }));
        $restEvents = $scheduleServices->getBoardRestEvents($storeId, $boardDate, $staffRows);
        return [
            'data' => $data,
            'count' => count($allReservations),
            'schedule_manage' => $scheduleServices->isScheduleManageEnabled() ? 1 : 0,
            'rest_events' => $restEvents,
        ];
    }

    /**
     * 看板单行预约数据格式化
     */
    protected function formatNoticeBoardReservationRow(array &$value): void
    {
        $value['reservation_create_time'] = $value['reservation_create_time'] ? date('Y-m-d H:i:s', (int)$value['reservation_create_time']) : '';
        $value['reservation_time'] = $value['reservation_time'] ? date('Y-m-d', (int)$value['reservation_time']) : '';
        $this->appendBoardContactInfo($value);
        $value['cart_info'] = $this->resolveReservationCartInfo($value);
        if ((int)($value['reservation_type'] ?? 0) !== 3) {
            $value['reservation_type'] = 2;
        }
        $value['status_name'] = $this->statusName[$value['status']] ?? '';
        $this->appendReservationStaffMeta($value);
    }

    /**
     * 看板：补全联系人（未购预约或历史数据可能缺姓名/电话）
     */
    protected function appendBoardContactInfo(array &$row): void
    {
        $name = trim((string)($row['reservation_name'] ?? ''));
        $phone = trim((string)($row['reservation_phone'] ?? ''));
        if ($name && $phone) {
            return;
        }
        $uid = (int)($row['uid'] ?? 0);
        if (!$uid) {
            return;
        }
        /** @var UserServices $userServices */
        $userServices = app()->make(UserServices::class);
        $userInfo = $userServices->getUserInfo($uid);
        if (!$userInfo) {
            return;
        }
        $userInfo = is_object($userInfo) ? $userInfo->toArray() : (array)$userInfo;
        if (!$name) {
            $row['reservation_name'] = trim((string)($userInfo['real_name'] ?? $userInfo['nickname'] ?? ''));
        }
        if (!$phone) {
            $row['reservation_phone'] = trim((string)($userInfo['phone'] ?? ''));
        }
    }

    /**
     * 是否已购项目预约（关联原订单明细）
     */
    protected function isPurchasedReservation(array $row): bool
    {
        return (int)($row['oid'] ?? 0) > 0 && (int)($row['cart_info_id'] ?? 0) > 0;
    }

    /**
     * 未购项目：按商品规格组装 cart_info（列表/看板/详情展示）
     */
    protected function buildGuestReservationCartInfo(int $productId, string $unique, int $storeId = 0): array
    {
        if (!$productId) {
            return [];
        }
        /** @var StoreProductServices $productServices */
        $productServices = app()->make(StoreProductServices::class);
        $productInfo = $productServices->getOne(['id' => $productId], '*');
        if (!$productInfo) {
            return [];
        }
        $productInfo = is_object($productInfo) ? $productInfo->toArray() : (array)$productInfo;
        /** @var StoreProductReservationServices $productReservationServices */
        $productReservationServices = app()->make(StoreProductReservationServices::class);
        $productInfo = $productReservationServices->fillServiceDuration($productInfo);
        /** @var StoreProductAttrValueServices $attrValueServices */
        $attrValueServices = app()->make(StoreProductAttrValueServices::class);
        if (!$unique) {
            $unique = (string)$attrValueServices->value(['product_id' => $productId, 'type' => 0], 'unique');
        }
        $attrInfo = $unique ? $attrValueServices->getOne(['unique' => $unique, 'type' => 0, 'product_id' => $productId]) : null;
        if ($attrInfo) {
            $attrInfo = is_object($attrInfo) ? $attrInfo->toArray() : (array)$attrInfo;
        } else {
            $attrInfo = [
                'unique' => $unique,
                'suk' => '',
                'image' => $productInfo['image'] ?? '',
                'price' => $productInfo['price'] ?? 0,
            ];
        }
        $productInfo['attrInfo'] = $attrInfo;
        $price = (string)($attrInfo['price'] ?? $productInfo['price'] ?? 0);
        return [
            'product_id' => $productId,
            'cart_num' => 1,
            'productInfo' => $productInfo,
            'attrInfo' => $attrInfo,
            'truePrice' => $price,
            'pay_price' => $price,
        ];
    }

    /**
     * 解析预约单 cart_info（已购走订单明细，未购按商品组装）
     */
    protected function resolveReservationCartInfo(array $row): array
    {
        $cartInfo = isset($row['cart_info']) && is_string($row['cart_info']) ? json_decode($row['cart_info'], true) : ($row['cart_info'] ?? []);
        if (is_array($cartInfo) && !empty($cartInfo['productInfo'])) {
            $cartInfo['cart_num'] = 1;
            return $cartInfo;
        }
        if (!$this->isPurchasedReservation($row)) {
            $snapshot = $row['reservation_info'] ?? [];
            if (is_string($snapshot)) {
                $snapshot = json_decode($snapshot, true) ?: [];
            }
            if (is_array($snapshot) && !empty($snapshot['productInfo'])) {
                $snapshot['cart_num'] = 1;
                return $snapshot;
            }
        }
        if ($this->isPurchasedReservation($row)) {
            return is_array($cartInfo) ? $cartInfo : [];
        }
        $cartInfo = $this->buildGuestReservationCartInfo(
            (int)($row['product_id'] ?? 0),
            (string)($row['sku_unique'] ?? ''),
            (int)($row['store_id'] ?? 0)
        );
        if ($cartInfo) {
            $cartInfo['cart_num'] = 1;
        }
        return $cartInfo;
    }

    /**
     * 看板：补全「有预约但当日未排班」的手艺人行
     */
    protected function mergeBoardStaffFromReservations(
        array &$staffMap,
        SystemStoreStaffServices $staffServices,
        int $storeId,
        array $reservationStaffIds
    ): void {
        if (!$reservationStaffIds) {
            return;
        }
        $missingIds = array_values(array_diff(array_keys($reservationStaffIds), array_keys($staffMap)));
        if (!$missingIds) {
            return;
        }
        $missingIdSet = array_flip(array_map('intval', $missingIds));
        foreach ($staffServices->geAllList(['store_id' => $storeId, 'is_del' => 0]) as $staff) {
            $staffId = (int)($staff['id'] ?? 0);
            if (!$staffId || !isset($missingIdSet[$staffId])) {
                continue;
            }
            $staffMap[$staffId] = [
                'id' => $staffId,
                'staff_name' => (string)($staff['staff_name'] ?? ''),
                'phone' => (string)($staff['phone'] ?? ''),
            ];
            unset($missingIdSet[$staffId]);
        }
        if (!$missingIdSet) {
            return;
        }
        foreach ($staffServices->geAllList(['is_del' => 0]) as $staff) {
            $staffId = (int)($staff['id'] ?? 0);
            if (!$staffId || !isset($missingIdSet[$staffId])) {
                continue;
            }
            $staffMap[$staffId] = [
                'id' => $staffId,
                'staff_name' => (string)($staff['staff_name'] ?? ''),
                'phone' => (string)($staff['phone'] ?? ''),
            ];
            unset($missingIdSet[$staffId]);
            if (!$missingIdSet) {
                break;
            }
        }
    }

    /**
     * 看板：未匹配到任何手艺人行的预约归入「未分配」
     */
    protected function appendOrphanReservationsToUnassigned(array &$data, array $allReservations): void
    {
        $assignedReservationIds = [];
        foreach ($data as $item) {
            foreach ($item['list'] ?? [] as $row) {
                $assignedReservationIds[(int)($row['id'] ?? 0)] = true;
            }
        }
        $orphans = [];
        foreach ($allReservations as $row) {
            $reservationId = (int)($row['id'] ?? 0);
            if ($reservationId && !isset($assignedReservationIds[$reservationId])) {
                $orphans[] = $row;
            }
        }
        if (!$orphans) {
            return;
        }
        foreach ($data as &$item) {
            if ((int)($item['id'] ?? -1) !== 0) {
                continue;
            }
            $item['list'] = array_values(array_merge($item['list'] ?? [], $orphans));
            break;
        }
        unset($item);
    }

    /**
     * 预约是否归属看板某一行（未分配 / 指定手艺人）
     */
    protected function reservationBelongsToBoardStaff(array $row, int $staffId): bool
    {
        $primaryId = (int)($row['service_staff_id'] ?? 0);
        if ($staffId === 0) {
            return $primaryId === 0;
        }
        if ($primaryId === $staffId) {
            return true;
        }
        foreach ($row['staff_choose'] ?? [] as $sc) {
            if ((int)($sc['staff_id'] ?? 0) === $staffId) {
                return true;
            }
        }
        return false;
    }

    /**
     * 获取预约单列表
     * @param array $where
     * @param string $field
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getReservationOrderList(array $where, string $field = '*')
    {
        [$page, $limit] = $this->getPageValue();
        /** @var SystemStoreServices $storeServices */
        $storeServices = app()->make(SystemStoreServices::class);
        $list = $this->dao->getList($where, $field, $page, $limit, ['cartInfo']);
        if ($list) {
            foreach ($list as &$item) {
                $item['reservation_create_time'] = $item['reservation_create_time'] ? date('Y-m-d H:i:s', (int)$item['reservation_create_time']) : '';
                $item['reservation_time'] = $item['reservation_time'] ? date('Y-m-d', (int)$item['reservation_time']) : '';
                $this->appendBoardContactInfo($item);
                $item['cart_info'] = $this->resolveReservationCartInfo($item);
                $item['is_guest_reservation'] = !$this->isPurchasedReservation($item);
                $item['name'] = $storeServices->value(['id'=>$item['store_id']],'name');
                $item['status_name'] = $this->statusName[$item['status']] ?? '';
                $this->appendReservationStaffMeta($item);
                $this->appendReservationListDisplayMeta($item);
            }
        }
        return $list;
    }

    /**
     * 手机端预约列表：展示字段（对齐旧系统「我的项目」）
     */
    protected function appendReservationListDisplayMeta(array &$item): void
    {
        $start = trim((string)($item['reservation_start'] ?? ''));
        $item['appointment_time'] = trim(($item['reservation_time'] ?? '') . ($start ? ' ' . $start : ''));
        if ($item['appointment_time'] && $start && strlen($start) <= 5) {
            $item['appointment_time'] .= ':00';
        }
        $item['master_phone'] = $this->resolveStoreMasterPhone((int)($item['store_id'] ?? 0));
        $item['project_list'] = $this->buildReservationProjectRows($item);
        $item['mark'] = trim((string)($item['mark'] ?? $item['remark'] ?? ''));
        $item['primary_staff_id'] = $this->resolvePrimaryStaffId(
            $item['staff_choose'] ?? [],
            (int)($item['service_staff_id'] ?? 0)
        );
        $item['is_cancel_reservation'] = $this->resolveListCancelReservation($item);
        $item['customer_name'] = trim((string)($item['reservation_name'] ?? ''));
        $item['customer_phone'] = trim((string)($item['reservation_phone'] ?? ''));
        $item['customer_sex'] = '';
        $uid = (int)($item['uid'] ?? 0);
        if ($uid) {
            /** @var UserServices $userServices */
            $userServices = app()->make(UserServices::class);
            $userInfo = $userServices->getUserInfo($uid);
            if ($userInfo) {
                $userInfo = is_object($userInfo) ? $userInfo->toArray() : (array)$userInfo;
                if (!$item['customer_name']) {
                    $item['customer_name'] = trim((string)($userInfo['real_name'] ?? $userInfo['nickname'] ?? ''));
                }
                if (!$item['customer_phone']) {
                    $item['customer_phone'] = trim((string)($userInfo['phone'] ?? ''));
                }
                $sex = (int)($userInfo['sex'] ?? 0);
                $item['customer_sex'] = $sex === 1 ? '男' : ($sex === 2 ? '女' : '');
            }
        }
        $item['staff_phone'] = $this->resolveReservationStaffPhone($item);
        $item['booker_name'] = trim((string)($item['staff_name'] ?? ''));
        $item['item_status_tag'] = $this->resolveReservationItemStatusTag((int)($item['status'] ?? 0));
        $item['service_room'] = trim((string)($item['table_name'] ?? ''));
        $durationMinutes = (int)($item['service_duration_minutes'] ?? 0);
        if (!$durationMinutes) {
            $durationMinutes = $this->calcReservationTotalDurationMinutes($item);
        }
        $item['service_duration_minutes'] = $durationMinutes;
        $item['service_duration_text'] = $durationMinutes ? ($durationMinutes . '分钟') : '';
        $item['work_status'] = $this->resolveTeacherWorkStatus((int)($item['status'] ?? 0), $item);
        $item['service_end_date'] = $this->resolveServiceEndDate($item);
        $serviceTags = trim((string)($item['service_tags'] ?? ''));
        $item['tags'] = $serviceTags;
        $item['tags_attr'] = $serviceTags !== '' ? array_values(array_filter(explode(',', $serviceTags))) : [];
        $item['other_tag'] = trim((string)($item['service_other_tag'] ?? ''));
        $storeInfo = $this->resolveReservationStoreInfo((int)($item['store_id'] ?? 0));
        $item['storeName'] = $storeInfo['name'] ?? ($item['name'] ?? '');
        $item['store_info'] = $storeInfo;
    }

    /**
     * 管家中心：各状态数量
     */
    public function getButlerCenterStatistics(array $where): array
    {
        $baseWhere = array_merge(['is_del' => 0], $where);
        unset($baseWhere['status']);
        return [
            'pending_confirm' => (int)$this->dao->count(array_merge($baseWhere, ['status' => 3])),
            'pending_service' => (int)$this->dao->count(array_merge($baseWhere, ['status' => 0])),
            'pending_evaluate' => 0,
            'completed' => (int)$this->dao->count(array_merge($baseWhere, ['status' => 2])),
            'returned' => (int)$this->dao->count(array_merge($baseWhere, ['status' => 4])),
        ];
    }

    /**
     * 商家首页：今日待确认(3)+待服务(0)预约
     * 口径（产品确认 2026-07-16）：今日、不含取消/退回/完成/服务中；店长全店、员工仅本人；最多 $limit 条
     *
     * @return array{count:int,list:array<int,array>}
     */
    public function getMerchantHomeTodayPending(int $storeId, int $serviceStaffId = 0, int $limit = 5): array
    {
        if ($storeId <= 0) {
            return ['count' => 0, 'list' => []];
        }
        $limit = max(1, min(20, $limit));
        $dayStart = strtotime(date('Y-m-d') . ' 00:00:00');
        $dayEnd = strtotime(date('Y-m-d') . ' 23:59:59');
        $where = [
            'store_id' => $storeId,
            'is_del' => 0,
            'status' => [0, 3],
            'reservation_time' => [$dayStart, $dayEnd],
        ];
        if ($serviceStaffId > 0) {
            $where['service_staff_id'] = $serviceStaffId;
        }
        $count = (int)$this->dao->count($where);
        $rows = $this->dao->search($where)
            ->field('id,uid,store_id,status,reservation_time,reservation_start,reservation_name,reservation_phone,service_staff_id,cart_info_id,product_id')
            ->with(['cartInfo', 'user'])
            ->order('reservation_start asc,id asc')
            ->limit($limit)
            ->select()
            ->toArray();
        $list = [];
        foreach ($rows as $row) {
            $list[] = $this->formatMerchantHomeReservationRow(is_array($row) ? $row : (array)$row);
        }
        return ['count' => $count, 'list' => $list];
    }

    /**
     * 商家首页预约卡片展示字段
     */
    protected function formatMerchantHomeReservationRow(array $item): array
    {
        $start = trim((string)($item['reservation_start'] ?? ''));
        if ($start === '') {
            $timeText = '--:--';
        } else {
            $timeText = strlen($start) >= 5 ? substr($start, 0, 5) : $start;
        }
        $userName = trim((string)($item['reservation_name'] ?? ''));
        if ($userName === '') {
            $userName = trim((string)($item['nickname'] ?? ''));
        }
        if ($userName === '') {
            $userName = '客户';
        }
        $serviceName = '服务项目';
        $cartInfo = $item['cart_info'] ?? null;
        if (is_string($cartInfo)) {
            $cartInfo = json_decode($cartInfo, true);
        }
        if (is_array($cartInfo)) {
            $productInfo = $cartInfo['productInfo'] ?? [];
            if (!is_array($productInfo)) {
                $productInfo = [];
            }
            $name = trim((string)($productInfo['store_name'] ?? $productInfo['name'] ?? $cartInfo['store_name'] ?? ''));
            if ($name !== '') {
                $serviceName = $name;
            }
        }
        $status = (int)($item['status'] ?? 0);
        return [
            'id' => (int)($item['id'] ?? 0),
            'timeText' => $timeText,
            'userName' => $userName,
            'serviceName' => $serviceName,
            'statusText' => $this->statusName[$status] ?? '',
            'status' => $status,
        ];
    }

    /**
     * 老师中心：订单统计（待服务/已完成）
     */
    public function getTeacherOrderStatistics(array $where): array
    {
        $baseWhere = array_merge(['is_del' => 0], $where);
        unset($baseWhere['status'], $baseWhere['teacher_tab']);
        if (isset($baseWhere['teacher_staff_id'])) {
            unset($baseWhere['service_staff_id']);
        }
        return [
            'pending_service' => (int)$this->dao->count(array_merge($baseWhere, ['status' => [0, 1]])),
            'pending_evaluate' => (int)$this->dao->count(array_merge($baseWhere, ['teacher_tab' => 1])),
            'completed' => (int)$this->dao->count(array_merge($baseWhere, ['teacher_tab' => 2])),
        ];
    }

    /**
     * 老师中心列表 Tab 筛选（0待服务含服务中 1待评价 2已完成）
     */
    public function applyTeacherTabFilter(array $where, $status): array
    {
        unset($where['status']);
        $where['teacher_tab'] = (int)$status;
        return $where;
    }

    /**
     * 服务标签选项（对齐旧系统 UserLabel type=1）
     */
    public function getTeacherServiceTagOptions(): array
    {
        return \app\model\user\label\UserLabel::where('type', 1)
            ->field('id,label_name')
            ->order('id asc')
            ->select()
            ->toArray();
    }

    /**
     * 设置预约单服务标签
     */
    public function setTeacherServiceTags(int $id, int $staffId, array $data): void
    {
        $order = $this->dao->get($id);
        if (!$order) {
            throw new ValidateException('预约单不存在');
        }
        $row = is_object($order) ? $order->toArray() : (array)$order;
        if ((int)($row['status'] ?? 0) !== 1) {
            throw new ValidateException('服务开始后才可设置标签');
        }
        if (!$this->isTeacherAssignedToReservation($row, $staffId)) {
            throw new ValidateException('无权操作该预约单');
        }
        $tagList = $data['tagList'] ?? [];
        if (!is_array($tagList)) {
            $tagList = [];
        }
        $tagList = array_values(array_filter(array_map('trim', $tagList)));
        $this->dao->update($id, [
            'service_tags' => implode(',', $tagList),
            'service_other_tag' => trim((string)($data['other_tag'] ?? '')),
        ]);
    }

    protected function isTeacherAssignedToReservation(array $row, int $staffId): bool
    {
        if (!$staffId) {
            return false;
        }
        if ((int)($row['service_staff_id'] ?? 0) === $staffId) {
            return true;
        }
        $staffChoose = $this->decodeReservationStaffChoose($row['staff_choose'] ?? []);
        foreach ($staffChoose as $item) {
            if ((int)($item['staff_id'] ?? 0) === $staffId) {
                return true;
            }
        }
        return false;
    }

    /**
     * 门店可用房间列表
     */
    public function getStoreTableList(int $storeId): array
    {
        if (!$storeId) {
            return [];
        }
        return \app\model\activity\table\TableQrcode::where([
            'store_id' => $storeId,
            'is_del' => 0,
            'is_using' => 1,
        ])->field('id,remarks,table_number,seat_num')->order('id asc')->select()->toArray();
    }

    /**
     * 门店房间当前占用情况（管家接单后待服务/服务中）
     */
    public function getRoomOccupancyMap(int $storeId): array
    {
        if (!$storeId) {
            return [];
        }
        $rows = \app\model\order\StoreReservationOrder::where([
            ['store_id', '=', $storeId],
            ['is_del', '=', 0],
            ['is_system_del', '=', 0],
            ['table_id', '>', 0],
        ])->whereIn('status', [0, 1])
            ->field('table_id, COUNT(*) as people_count')
            ->group('table_id')
            ->select()
            ->toArray();
        $map = [];
        foreach ($rows as $row) {
            $tableId = (int)($row['table_id'] ?? 0);
            if (!$tableId) {
                continue;
            }
            $map[$tableId] = [
                'room_occupied' => 1,
                'room_people_count' => (int)($row['people_count'] ?? 0),
            ];
        }
        return $map;
    }

    /**
     * 写入预约单房间信息（可选）
     */
    protected function applyReservationTableFields(array $data, array $reservationInfo): array
    {
        $tableId = (int)($reservationInfo['table_id'] ?? 0);
        $tableName = trim((string)($reservationInfo['table_name'] ?? ''));
        if ($tableId && $tableName === '') {
            $tableName = trim((string)\app\model\activity\table\TableQrcode::where('id', $tableId)->value('remarks'));
            if ($tableName === '') {
                $tableName = trim((string)\app\model\activity\table\TableQrcode::where('id', $tableId)->value('table_number'));
            }
        }
        $data['table_id'] = $tableId;
        $data['table_name'] = $tableName;
        return $data;
    }

    protected function resolveReservationStoreInfo(int $storeId): array
    {
        if (!$storeId) {
            return [];
        }
        /** @var SystemStoreServices $storeServices */
        $storeServices = app()->make(SystemStoreServices::class);
        $store = $storeServices->getOne(['id' => $storeId], 'id,name,address,latitude,longitude,phone');
        if (!$store) {
            return [];
        }
        return is_object($store) ? $store->toArray() : (array)$store;
    }

    protected function calcReservationTotalDurationMinutes(array $item): int
    {
        $total = 0;
        foreach ($this->buildReservationProjectRows($item) as $row) {
            $desc = (string)($row['desc'] ?? '');
            if (preg_match('/(\d+)分钟/', $desc, $m)) {
                $total += (int)$m[1] * max(1, (int)($row['cart_num'] ?? 1));
            }
        }
        if (!$total) {
            $start = strtotime((string)($item['reservation_start'] ?? ''));
            $end = strtotime((string)($item['reservation_end'] ?? ''));
            if ($start && $end && $end > $start) {
                $total = (int)ceil(($end - $start) / 60);
            }
        }
        return $total;
    }

    protected function resolveTeacherWorkStatus(int $status, array $item = []): int
    {
        if ($status === 2) {
            return 2;
        }
        if ($status === 1 || !empty($item['service_time'])) {
            return 1;
        }
        return 0;
    }

    protected function resolveServiceEndDate(array $item): string
    {
        $status = (int)($item['status'] ?? 0);
        if ($status !== 1) {
            return '';
        }
        $serviceTime = (int)($item['service_time'] ?? 0);
        if (!$serviceTime) {
            return '';
        }
        $minutes = (int)($item['service_duration_minutes'] ?? 0);
        if (!$minutes) {
            $minutes = $this->calcReservationTotalDurationMinutes($item);
        }
        if ($minutes <= 0) {
            $start = strtotime((string)($item['reservation_start'] ?? ''));
            $end = strtotime((string)($item['reservation_end'] ?? ''));
            if ($start && $end && $end > $start) {
                return date('Y-m-d H:i:s', $serviceTime + ($end - $start));
            }
            return '';
        }
        return date('Y-m-d H:i:s', $serviceTime + $minutes * 60);
    }

    protected function resolveStoreButlerUid(int $storeId): int
    {
        if (!$storeId) {
            return 0;
        }
        /** @var SystemStoreStaffServices $staffServices */
        $staffServices = app()->make(SystemStoreStaffServices::class);
        // 兼容期：优先店长，其次历史管家；迁移后仅留店长
        foreach ([['is_manager' => 1], ['is_butler' => 1]] as $whereExtra) {
            $staff = $staffServices->getOne(array_merge(['store_id' => $storeId, 'is_del' => 0, 'status' => 1], $whereExtra), 'uid');
            if ($staff) {
                $staff = is_object($staff) ? $staff->toArray() : (array)$staff;
                $uid = (int)($staff['uid'] ?? 0);
                if ($uid) {
                    return $uid;
                }
            }
        }
        return 0;
    }

    protected function resolvePrimaryTeacherUid(array $reservationRow): int
    {
        $staffChoose = $this->decodeReservationStaffChoose($reservationRow['staff_choose'] ?? []);
        $staffId = $this->resolvePrimaryStaffId($staffChoose, (int)($reservationRow['service_staff_id'] ?? 0));
        if (!$staffId) {
            return 0;
        }
        /** @var SystemStoreStaffServices $staffServices */
        $staffServices = app()->make(SystemStoreStaffServices::class);
        $staff = $staffServices->getOne(['id' => $staffId], 'uid');
        if (!$staff) {
            return 0;
        }
        $staff = is_object($staff) ? $staff->toArray() : (array)$staff;
        return (int)($staff['uid'] ?? 0);
    }

    protected function buildYuyueNoticePayload(array $reservationRow): array
    {
        $cartInfo = $this->resolveReservationCartInfo($reservationRow);
        $productInfo = $cartInfo['productInfo'] ?? [];
        $workName = (string)($productInfo['store_name'] ?? '');
        foreach ($this->resolveReservationAddonList($reservationRow) as $addon) {
            if (!empty($addon['product_name'])) {
                $workName = $workName ? ($workName . '、' . $addon['product_name']) : $addon['product_name'];
            }
        }
        $storeInfo = $this->resolveReservationStoreInfo((int)($reservationRow['store_id'] ?? 0));
        $appointment = trim((string)($reservationRow['reservation_time'] ?? ''));
        $start = trim((string)($reservationRow['reservation_start'] ?? ''));
        if (is_numeric($appointment)) {
            $appointment = date('Y-m-d', (int)$appointment);
        }
        $timeStr = trim($appointment . ($start ? ' ' . $start : ''));
        if ($timeStr && $start && strlen($start) <= 5) {
            $timeStr .= ':00';
        }
        return [
            'store_name' => (string)($storeInfo['name'] ?? ''),
            'store_phone' => $this->resolveStoreMasterPhone((int)($reservationRow['store_id'] ?? 0)),
            'time' => $timeStr,
            'work_name' => $workName ?: '预约服务',
            'technician_uid' => $this->resolvePrimaryTeacherUid($reservationRow),
            'refuse_reason' => (string)($reservationRow['refuse_reason'] ?? ''),
            'customer_name' => trim((string)($reservationRow['reservation_name'] ?? '')),
            'remark' => trim((string)($reservationRow['mark'] ?? $reservationRow['remark'] ?? '')),
            'real_name' => trim((string)($reservationRow['reservation_name'] ?? '')),
            'remark_room' => trim((string)($reservationRow['table_name'] ?? '')),
        ];
    }

    protected function dispatchYuyueCustomerNotice(array $reservationRow): void
    {
        $masterUid = $this->resolveStoreButlerUid((int)($reservationRow['store_id'] ?? 0));
        if (!$masterUid) {
            return;
        }
        event('notice.notice', [[
            'master_uid' => $masterUid,
            'notice' => $this->buildYuyueNoticePayload($reservationRow),
        ], 'yuyue_customer']);
    }

    protected function dispatchYuyueSuccessNotice(array $reservationRow): void
    {
        $uid = (int)($reservationRow['uid'] ?? 0);
        if (!$uid) {
            return;
        }
        event('notice.notice', [[
            'uid' => $uid,
            'notice' => $this->buildYuyueNoticePayload($reservationRow),
        ], 'yuyue_success']);
    }

    protected function dispatchYuyueRefuseNotice(array $reservationRow, bool $notifyTeacher = false): void
    {
        $uid = (int)($reservationRow['uid'] ?? 0);
        if (!$uid) {
            return;
        }
        $payload = [
            'uid' => $uid,
            'notice' => $this->buildYuyueNoticePayload($reservationRow),
        ];
        if ($notifyTeacher) {
            $payload['technician_uid'] = $this->resolvePrimaryTeacherUid($reservationRow);
        }
        event('notice.notice', [$payload, 'yuyue_refuse']);
    }

    /**
     * 计算服务结束时间戳
     */
    protected function resolveServiceEndTimestamp(array $reservationRow): int
    {
        $serviceTime = (int)($reservationRow['service_time'] ?? 0);
        if (!$serviceTime) {
            return 0;
        }
        $minutes = (int)($reservationRow['service_duration_minutes'] ?? 0);
        if (!$minutes) {
            $minutes = $this->calcReservationTotalDurationMinutes($reservationRow);
        }
        if ($minutes > 0) {
            return $serviceTime + $minutes * 60;
        }
        $start = strtotime((string)($reservationRow['reservation_start'] ?? ''));
        $end = strtotime((string)($reservationRow['reservation_end'] ?? ''));
        if ($start && $end && $end > $start) {
            return $serviceTime + ($end - $start);
        }
        return 0;
    }

    /**
     * 开始服务后：延迟队列预约 YUYUE_JINDU 提醒
     */
    protected function scheduleYuyueJinduReminders(int $id, array $reservationRow): void
    {
        $serviceTime = (int)($reservationRow['service_time'] ?? 0);
        if (!$serviceTime) {
            return;
        }
        $endTimestamp = $this->resolveServiceEndTimestamp($reservationRow);
        if (!$endTimestamp || $endTimestamp <= $serviceTime) {
            return;
        }
        $durationSeconds = $endTimestamp - $serviceTime;
        $fiveMinDelay = max(0, $durationSeconds - 300);
        ReservationOrderJob::dispatchSece($fiveMinDelay, 'sendYuyueJinduFiveMinute', [$id]);
        ReservationOrderJob::dispatchSece($durationSeconds, 'sendYuyueJinduEnd', [$id]);
    }

    /**
     * 发送服务进度订阅消息（five=距结束5分钟，end=服务到时）
     */
    public function tryDispatchYuyueJinduNotice(int $id, string $type): bool
    {
        if (!in_array($type, ['five', 'end'], true)) {
            return false;
        }
        $reservation = $this->dao->get($id);
        if (!$reservation) {
            return false;
        }
        $row = is_object($reservation) ? $reservation->toArray() : (array)$reservation;
        if ((int)($row['status'] ?? 0) !== 1) {
            return false;
        }
        $flagField = $type === 'five' ? 'jindu_five_notice' : 'jindu_end_notice';
        if ((int)($row[$flagField] ?? 0) === 1) {
            return false;
        }
        $endTimestamp = $this->resolveServiceEndTimestamp($row);
        $serviceTime = (int)($row['service_time'] ?? 0);
        if (!$endTimestamp || !$serviceTime) {
            return false;
        }
        $now = time();
        if ($type === 'five') {
            $remaining = $endTimestamp - $now;
            if ($remaining > 300 || $remaining <= 0) {
                return false;
            }
        } elseif ($now < $endTimestamp) {
            return false;
        }
        $masterUid = $this->resolveStoreButlerUid((int)($row['store_id'] ?? 0));
        if (!$masterUid) {
            return false;
        }
        $this->dao->update($id, [$flagField => 1]);
        $notice = $this->buildYuyueNoticePayload($row);
        $notice['jindu'] = $type === 'end' ? '服务已结束' : '服务还有五分钟结束';
        $notice['remark'] = trim((string)($row['table_name'] ?? ''));
        event('notice.notice', [[
            'master_uid' => $masterUid,
            'uid' => (int)($row['uid'] ?? 0),
            'notice' => $notice,
            'jindu_type' => $type,
        ], 'yuyue_jindu']);
        return true;
    }

    /**
     * 定时扫描服务中的预约单，补发 YUYUE_JINDU（队列丢失时的兜底）
     */
    public function processReservationJinduNotices(): int
    {
        $list = $this->dao->getList([
            'status' => 1,
            'is_del' => 0,
            'is_system_del' => 0,
        ], 'id,status,service_time,service_duration_minutes,reservation_start,reservation_end,store_id,uid,table_name,jindu_five_notice,jindu_end_notice', 0, 0);
        $count = 0;
        foreach ($list as $item) {
            $row = is_object($item) ? $item->toArray() : (array)$item;
            if (!(int)($row['service_time'] ?? 0)) {
                continue;
            }
            $endTimestamp = $this->resolveServiceEndTimestamp($row);
            if (!$endTimestamp) {
                continue;
            }
            $now = time();
            $remaining = $endTimestamp - $now;
            if ($remaining <= 300 && $remaining > 0) {
                if ($this->tryDispatchYuyueJinduNotice((int)$row['id'], 'five')) {
                    $count++;
                }
            }
            if ($now >= $endTimestamp) {
                if ($this->tryDispatchYuyueJinduNotice((int)$row['id'], 'end')) {
                    $count++;
                }
            }
        }
        return $count;
    }

    protected function resolveReservationStaffPhone(array $item): string
    {
        $staffId = (int)($item['service_staff_id'] ?? 0);
        if (!$staffId && !empty($item['staff_choose'][0]['staff_id'])) {
            $staffId = (int)$item['staff_choose'][0]['staff_id'];
        }
        if (!$staffId) {
            return '';
        }
        /** @var SystemStoreStaffServices $staffServices */
        $staffServices = app()->make(SystemStoreStaffServices::class);
        $staff = $staffServices->getOne(['id' => $staffId, 'is_del' => 0], 'phone');
        if (!$staff) {
            return '';
        }
        $staff = is_object($staff) ? $staff->toArray() : (array)$staff;
        return trim((string)($staff['phone'] ?? ''));
    }

    protected function resolveReservationItemStatusTag(int $status): string
    {
        $map = [
            3 => '待确认',
            0 => '待核销',
            1 => '服务中',
            2 => '已完成',
            4 => '已退回',
            -1 => '已取消',
        ];
        return $map[$status] ?? '';
    }

    /**
     * 门店店长电话：优先店长，兼容期其次历史管家，否则门店电话
     */
    protected function resolveStoreMasterPhone(int $storeId): string
    {
        if (!$storeId) {
            return '';
        }
        /** @var SystemStoreServices $storeServices */
        $storeServices = app()->make(SystemStoreServices::class);
        /** @var SystemStoreStaffServices $staffServices */
        $staffServices = app()->make(SystemStoreStaffServices::class);
        foreach ([['is_manager' => 1], ['is_butler' => 1]] as $whereExtra) {
            $staff = $staffServices->getOne(array_merge(['store_id' => $storeId, 'is_del' => 0], $whereExtra), 'phone');
            if ($staff) {
                $staff = is_object($staff) ? $staff->toArray() : (array)$staff;
                $phone = trim((string)($staff['phone'] ?? ''));
                if ($phone) {
                    return $phone;
                }
            }
        }
        return trim((string)$storeServices->value(['id' => $storeId], 'phone'));
    }

    /**
     * 预约项目行（主项目 + 增项）
     */
    protected function buildReservationProjectRows(array $item): array
    {
        $rows = [];
        $cartInfo = $item['cart_info'] ?? [];
        if (is_array($cartInfo) && !empty($cartInfo['productInfo'])) {
            $productInfo = $cartInfo['productInfo'];
            if (!is_array($productInfo)) {
                $productInfo = [];
            }
            $duration = (int)($productInfo['project_service_duration'] ?? 0);
            $desc = $duration ? $duration . '分钟' : '';
            $storeInfo = trim((string)($productInfo['store_info'] ?? ''));
            if ($storeInfo) {
                $desc = $desc ? ($desc . ' · ' . $storeInfo) : $storeInfo;
            } elseif (!empty($productInfo['attrInfo']['suk'])) {
                $desc = (string)$productInfo['attrInfo']['suk'];
            }
            $rows[] = [
                'product_name' => (string)($productInfo['store_name'] ?? ''),
                'image' => (string)($productInfo['image'] ?? ''),
                'desc' => $desc,
                'cart_num' => (int)($cartInfo['cart_num'] ?? 1),
                'is_addon' => 0,
            ];
        }
        foreach ($this->resolveReservationAddonList($item) as $addonRow) {
            $rows[] = $addonRow;
        }
        return $rows;
    }

    /**
     * 解析增项展示数据
     */
    protected function resolveReservationAddonList(array $row): array
    {
        $addonItems = $row['addon_items'] ?? '';
        $addonItems = $this->decodeJsonField($addonItems, []);
        if (!is_array($addonItems) || !$addonItems) {
            return [];
        }
        /** @var StoreProductServices $productServices */
        $productServices = app()->make(StoreProductServices::class);
        $list = [];
        foreach ($addonItems as $addon) {
            if (!is_array($addon)) {
                continue;
            }
            $productId = (int)($addon['product_id'] ?? 0);
            $productInfo = [];
            if ($productId) {
                $pinfo = $productServices->getOne(['id' => $productId], 'id,image,store_name,store_info,addon_service_duration');
                if ($pinfo) {
                    $productInfo = is_object($pinfo) ? $pinfo->toArray() : (array)$pinfo;
                }
            }
            $duration = (int)($addon['addon_service_duration'] ?? $productInfo['addon_service_duration'] ?? 0);
            $desc = $duration ? $duration . '分钟' : '';
            $storeInfo = trim((string)($productInfo['store_info'] ?? ''));
            if ($storeInfo) {
                $desc = $desc ? ($desc . ' · ' . $storeInfo) : $storeInfo;
            }
            $list[] = [
                'product_name' => (string)($addon['product_name'] ?? $productInfo['store_name'] ?? ''),
                'image' => (string)($productInfo['image'] ?? ''),
                'desc' => $desc,
                'cart_num' => 1,
                'is_addon' => 1,
            ];
        }
        return $list;
    }

    /**
     * 列表是否可取消预约（仅待确认可取消，不校验商品配置）
     */
    protected function resolveListCancelReservation(array $item): int
    {
        return (int)($item['status'] ?? 0) === 3 ? 1 : 0;
    }

    /**
     * 预约单详情
     * @param int $uid
     * @param int $id
     * @return array
     */
    public function getReservationOrderInfo(int $uid, int $id, int $store_id = 0)
    {
        $reservationOrderInfo = [];
        if ($id) {
            $where = ['id' => $id];
            if ($uid) $where['uid'] = $uid;
            if ($store_id) $where['store_id'] = $store_id;
            $reservationOrderInfo = $this->dao->get($where, ['*'], ['cartInfo']);
        }
        if (!$reservationOrderInfo) {
            throw new ValidateException('预约单已取消或删除!');
        }
        $reservationOrderInfo = $reservationOrderInfo->toArray();

        $cartInfo = $this->resolveReservationCartInfo($reservationOrderInfo);
        if ($cartInfo) {
            $productId = (int)($cartInfo['product_id'] ?? $reservationOrderInfo['product_id'] ?? 0);
            if ($productId) {
                $cartInfo['yeji'] = $this->resolveProductLaborYeji($productId);
            }
        }
        $reservationOrderInfo['cart_info'] = $cartInfo;
        $reservationOrderInfo['is_guest_reservation'] = !$this->isPurchasedReservation($reservationOrderInfo);

        /** @var StoreOrderServices $storeOrderServices */
        $storeOrderServices = app()->make(StoreOrderServices::class);
        $orderInfo = [];
        if ((int)$reservationOrderInfo['oid'] > 0) {
            $orderInfo = $storeOrderServices->get((int)$reservationOrderInfo['oid'], ['id', 'order_id', 'total_num']);
            $orderInfo = $orderInfo ? (is_object($orderInfo) ? $orderInfo->toArray() : (array)$orderInfo) : [];
        }
        $reservationOrderInfo['store_order_id'] = $orderInfo['order_id'] ?? '';
        $reservationOrderInfo['reservation_create_time'] = $reservationOrderInfo['reservation_create_time'] ? date('Y-m-d H:i:s', (int)$reservationOrderInfo['reservation_create_time']) : '';
        $reservationOrderInfo['reservation_time'] = $reservationOrderInfo['reservation_time'] ? date('Y-m-d', (int)$reservationOrderInfo['reservation_time']) : '';
        $reservationOrderInfo['reservation_show_time'] = $reservationOrderInfo['reservation_start'] . '-' . $reservationOrderInfo['reservation_end'];
        //服务时长
        $reservationOrderInfo['service_span'] = strtotime($reservationOrderInfo['reservation_end']) - strtotime($reservationOrderInfo['reservation_start']);
        $reservationOrderInfo['service_stop_time'] = 0;
        //服务结束时间
        if ($reservationOrderInfo['status'] == 1 && $reservationOrderInfo['service_time']) {
            $reservationOrderInfo['service_stop_time'] = $reservationOrderInfo['service_time'] + $reservationOrderInfo['service_span'];
        }
        $reservationOrderInfo['service_time'] = $reservationOrderInfo['service_time'] ? date('Y-m-d H:i:s', (int)$reservationOrderInfo['service_time']) : '';
        $reservationOrderInfo['service_end_time'] = $reservationOrderInfo['service_end_time'] ? date('Y-m-d H:i:s', (int)$reservationOrderInfo['service_end_time']) : '';
        $storeInfo = [];
        if ($reservationOrderInfo['store_id']) {
            /** @var SystemStoreServices $storeServices */
            $storeServices = app()->make(SystemStoreServices::class);
            $storeInfo = $storeServices->get($reservationOrderInfo['store_id'], ['id', 'name', 'phone']);
        }
        $reservationOrderInfo['storeInfo'] = $storeInfo;
        $rawReservationInfo = $reservationOrderInfo['reservation_info'] ?? [];
        if (is_string($rawReservationInfo)) {
            $rawReservationInfo = json_decode($rawReservationInfo, true) ?: [];
        }
        if ($reservationOrderInfo['is_guest_reservation']) {
            $reservationOrderInfo['reservation_info'] = [];
        } else {
            $reservationOrderInfo['reservation_info'] = is_array($rawReservationInfo) ? $rawReservationInfo : [];
        }
        $serviceImages = $reservationOrderInfo['service_images'] ?? '';
        if (is_string($serviceImages) && $serviceImages !== '') {
            $decodedImages = json_decode($serviceImages, true);
            $reservationOrderInfo['service_images'] = is_array($decodedImages) ? $decodedImages : [];
        } else {
            $reservationOrderInfo['service_images'] = is_array($serviceImages) ? $serviceImages : [];
        }
        $reservationOrderInfo['service_describe'] = (string)($reservationOrderInfo['service_describe'] ?? '');
        $serviceTags = trim((string)($reservationOrderInfo['service_tags'] ?? ''));
        $reservationOrderInfo['tags'] = $serviceTags;
        $reservationOrderInfo['tags_attr'] = $serviceTags !== '' ? array_values(array_filter(explode(',', $serviceTags))) : [];
        $reservationOrderInfo['other_tag'] = trim((string)($reservationOrderInfo['service_other_tag'] ?? ''));
        if ((int)$reservationOrderInfo['oid'] > 0 && (int)$reservationOrderInfo['cart_info_id'] > 0) {
            $reservationOrderCount = $this->dao->count(['oid' => $reservationOrderInfo['oid'], 'cart_info_id' => $reservationOrderInfo['cart_info_id'], 'status' => [0, 1, 2], 'is_del' => 0, 'is_system_del' => 0]);
            $reservationOrderInfo['is_reservation'] = ($orderInfo['total_num'] ?? 0) > $reservationOrderCount;
        } else {
            $reservationOrderInfo['is_reservation'] = 0;
        }

        $is_cancel_reservation = (int)$reservationOrderInfo['status'] === 3 ? 1 : 0;
        $reservationOrderInfo['is_cancel_reservation'] = $is_cancel_reservation;
        $reservationOrderInfo['status_name'] = $this->statusName[$reservationOrderInfo['status']] ?? '待服务';
        $reservationOrderInfo['status_msg'] = $this->statusMsg[$reservationOrderInfo['status']] ?? '';
        $reservationOrderInfo['status_pic'] = '';
        try {
            $order_details_images = sys_data('order_details_images') ?: [];
            $order_details_images = array_combine(array_column($order_details_images, 'order_status'), $order_details_images);
            $status_type = in_array($reservationOrderInfo['status'], [0, 1, 3]) ? 0 : ($reservationOrderInfo['status'] == -1 ? -1 : 4);
            $reservationOrderInfo['status_pic'] = $order_details_images[$status_type]['pic'] ?? $order_details_images[0]['pic'] ?? '';
        } catch (\Throwable $e) {
        }
        //上门地址处理
        $reservationOrderInfo['reservation_address_city_id'] = [];
        if ($reservationOrderInfo['reservation_address']) {
            /** @var CityAreaServices $cityServices */
            $cityServices = app()->make(CityAreaServices::class);
            $reservationOrderInfo['reservation_address_city_id'] = $cityServices->getCityIdByAddress($reservationOrderInfo['reservation_address']);
        }
        $staffMeta = $this->buildReservationStaffChoose((int)$reservationOrderInfo['id'], $reservationOrderInfo);
        $reservationOrderInfo['staff_choose'] = $staffMeta['staff_choose'];
        $reservationOrderInfo['writeoff_id'] = $staffMeta['writeoff_id'];
        $reservationOrderInfo['staff_name'] = $this->formatReservationStaffDisplay($staffMeta['staff_choose']);
        $reservationOrderInfo['addon_items'] = $this->decodeJsonField($reservationOrderInfo['addon_items'] ?? [], []);
        $reservationOrderInfo['project_list'] = $this->buildReservationProjectRows($reservationOrderInfo);
        $reservationOrderInfo['service_room'] = trim((string)($reservationOrderInfo['table_name'] ?? ''));
        $this->appendBoardContactInfo($reservationOrderInfo);
        return $reservationOrderInfo;
    }

    /**
     * 预约详情：读取已选手艺人（预约单 staff_choose；开始服务后读核销业绩）
     */
    protected function buildReservationStaffChoose(int $reservationId, array $reservationOrderInfo = []): array
    {
        $writeoffId = 0;
        $staffChoose = $this->decodeReservationStaffChoose($reservationOrderInfo['staff_choose'] ?? []);
        $status = (int)($reservationOrderInfo['status'] ?? 0);
        if (!$staffChoose && in_array($status, [1, 2], true)) {
            $writeoff = StoreOrderWriteoff::where('reservation_oid', $reservationId)->order('id desc')->find();
            if ($writeoff) {
                $writeoffId = (int)$writeoff['id'];
                $rows = StaffYeji::where('link_id', $writeoffId)->where('type', 3)
                    ->where(function ($query) {
                        $query->whereNull('status')->whereOr('status', 0);
                    })
                    ->order('id asc')
                    ->select();
                foreach ($rows as $row) {
                    $row = is_object($row) ? $row->toArray() : (array)$row;
                    $staffChoose[] = $this->formatStaffChooseItem($row);
                }
            }
        }
        if (!$staffChoose && (int)($reservationOrderInfo['service_staff_id'] ?? 0)) {
            /** @var SystemStoreStaffServices $staffServices */
            $staffServices = app()->make(SystemStoreStaffServices::class);
            $staff = $staffServices->get((int)$reservationOrderInfo['service_staff_id'], ['id', 'staff_name', 'position', 'position_level']);
            if ($staff) {
                $staff = is_object($staff) ? $staff->toArray() : (array)$staff;
                $staffChoose[] = [
                    'staff_id' => (int)$staff['id'],
                    'staff_name' => (string)($staff['staff_name'] ?? ''),
                    'position' => (int)($staff['position'] ?? 0),
                    'position_label' => '',
                    'position_level' => (int)($staff['position_level'] ?? 0),
                    'position_level_label' => '',
                    'yeji' => 0,
                    'is_dian' => 0,
                ];
            }
        }
        return ['staff_choose' => $staffChoose, 'writeoff_id' => $writeoffId];
    }

    /**
     * 列表/看板：补充手艺人展示字段
     */
    protected function appendReservationStaffMeta(array &$item): void
    {
        $staffMeta = $this->buildReservationStaffChoose((int)$item['id'], $item);
        $item['staff_choose'] = $staffMeta['staff_choose'];
        $item['staff_name'] = $this->formatReservationStaffDisplay($staffMeta['staff_choose']);
    }

    /**
     * 解析预约手艺人 JSON
     */
    protected function decodeReservationStaffChoose($value): array
    {
        if (is_array($value)) {
            return $this->normalizeStaffChooseList($value);
        }
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $this->normalizeStaffChooseList($decoded) : [];
    }

    /**
     * 从 sync_all 提取手艺人列表
     */
    protected function extractStaffChooseFromSyncAll(array $syncAll): array
    {
        $staffChoose = [];
        foreach ($syncAll as $row) {
            if (empty($row['staffChoose']) || !is_array($row['staffChoose'])) {
                continue;
            }
            foreach ($row['staffChoose'] as $staff) {
                $staffChoose[] = $this->formatStaffChooseItem($staff);
            }
        }
        return $this->normalizeStaffChooseList($staffChoose);
    }

    protected function normalizeStaffChooseList(array $staffChoose): array
    {
        $list = [];
        $seen = [];
        foreach ($staffChoose as $staff) {
            if (!is_array($staff)) {
                continue;
            }
            $item = $this->formatStaffChooseItem($staff);
            $staffId = (int)($item['staff_id'] ?? 0);
            if (!$staffId || isset($seen[$staffId])) {
                continue;
            }
            $seen[$staffId] = true;
            $list[] = $item;
        }
        return $list;
    }

    protected function formatStaffChooseItem(array $staff): array
    {
        return [
            'staff_id' => (int)($staff['staff_id'] ?? 0),
            'staff_name' => (string)($staff['staff_name'] ?? ''),
            'position' => (int)($staff['position'] ?? 0),
            'position_label' => (string)($staff['position_label'] ?? ''),
            'position_level' => (int)($staff['position_level'] ?? 0),
            'position_level_label' => (string)($staff['position_level_label'] ?? ''),
            'yeji' => $staff['yeji'] ?? 0,
            'is_dian' => (int)($staff['is_dian'] ?? 0),
        ];
    }

    protected function resolvePrimaryStaffId(array $staffChoose, int $fallbackId = 0): int
    {
        if (!$staffChoose) {
            return $fallbackId;
        }
        foreach ($staffChoose as $item) {
            if ((int)($item['is_dian'] ?? 0) === 1) {
                return (int)($item['staff_id'] ?? 0);
            }
        }
        return (int)($staffChoose[0]['staff_id'] ?? $fallbackId);
    }

    protected function encodeReservationStaffChoose(array $staffChoose): string
    {
        $staffChoose = $this->normalizeStaffChooseList($staffChoose);
        return $staffChoose ? json_encode($staffChoose, JSON_UNESCAPED_UNICODE) : '';
    }

    /**
     * 商品劳动业绩（与收银台消耗列表 YejiCommission 一致）
     */
    protected function resolveProductLaborYeji(int $productId): string
    {
        if ($productId <= 0) {
            return '0';
        }
        $pid = (int)StoreProduct::where('id', $productId)->value('pid');
        if ($pid <= 0) {
            $pid = $productId;
        }
        $yeji = YejiCommission::where('product_id', $pid)->value('yeji');
        return bcadd((string)($yeji ?? 0), '0', 2);
    }

    /**
     * 劳动业绩在手艺人之间平分
     * @param array<int, array<string, mixed>> $staffChoose
     */
    protected function splitLaborYejiAmongStaff(array $staffChoose, string $laborYeji): array
    {
        $len = count($staffChoose);
        if ($len <= 0) {
            return $staffChoose;
        }
        if (bccomp($laborYeji, '0', 2) <= 0) {
            foreach ($staffChoose as &$row) {
                $row['yeji'] = 0;
            }
            unset($row);
            return $staffChoose;
        }
        $onceYeji = bcdiv($laborYeji, (string)$len, 2);
        $yu = bcsub($laborYeji, bcmul($onceYeji, (string)$len, 2), 2);
        $lastNk = $len - 1;
        foreach ($staffChoose as $k => &$row) {
            $row['yeji'] = $onceYeji;
            if (bccomp($yu, '0', 2) > 0 && $lastNk === $k) {
                $row['yeji'] = bcadd($onceYeji, $yu, 2);
            }
        }
        unset($row);
        return $staffChoose;
    }

    /**
     * 开始服务：由预约单暂存手艺人构建 sync_all
     */
    protected function buildReservationSyncAllFromStoredStaff(array $reservationOrder): array
    {
        $staffChoose = $this->decodeReservationStaffChoose($reservationOrder['staff_choose'] ?? []);
        if (!$staffChoose) {
            return [];
        }
        $productId = (int)($reservationOrder['product_id'] ?? 0);
        $row = [
            'type' => 3,
            'staffChoose' => $staffChoose,
            'goods_id' => $productId,
            'value' => 1,
        ];
        $oid = (int)($reservationOrder['oid'] ?? 0);
        $cartInfoId = (int)($reservationOrder['cart_info_id'] ?? 0);
        if ($oid && $cartInfoId) {
            /** @var StoreOrderCartInfoServices $cartServices */
            $cartServices = app()->make(StoreOrderCartInfoServices::class);
            $cartInfo = $cartServices->getOne(['oid' => $oid, 'id' => $cartInfoId]);
            if ($cartInfo) {
                $cartInfo = is_object($cartInfo) ? $cartInfo->toArray() : (array)$cartInfo;
                $row['cart_id'] = $cartInfo['cart_id'] ?? 0;
                $row['order_id'] = $oid;
                if (!$productId) {
                    $productId = (int)($cartInfo['product_id'] ?? 0);
                    $row['goods_id'] = $productId;
                }
            }
        }
        $laborYeji = $this->resolveProductLaborYeji($productId);
        $row['price'] = $laborYeji;
        $row['once_price'] = $laborYeji;
        $row['true_price'] = $laborYeji;
        $row['staffChoose'] = $this->splitLaborYejiAmongStaff($row['staffChoose'], $laborYeji);
        return [$row];
    }

    /**
     * 预约创建/核销：补全 sync_all 字段，确保手艺人写入 staff_yeji
     */
    protected function normalizeReservationSyncAll(array $syncAll, array $cartInfo, int $oid, string $unitPrice): array
    {
        if (!$syncAll) {
            return [];
        }
        $cartId = $cartInfo['cart_id'] ?? '';
        $productId = (int)($cartInfo['product_id'] ?? 0);
        $normalized = [];
        foreach ($syncAll as $row) {
            if (!is_array($row) || empty($row['staffChoose']) || !is_array($row['staffChoose'])) {
                continue;
            }
            $syncCartId = (string)($row['cart_id'] ?? '');
            if ($syncCartId === '' || $syncCartId === '0') {
                $row['cart_id'] = $cartId;
            }
            if (empty($row['goods_id'])) {
                $row['goods_id'] = $productId;
            }
            if (empty($row['order_id'])) {
                $row['order_id'] = $oid;
            }
            if (empty($row['type'])) {
                $row['type'] = 3;
            }
            $laborYeji = $this->resolveProductLaborYeji((int)($row['goods_id'] ?? $productId));
            if (bccomp($laborYeji, '0', 2) <= 0 && $unitPrice !== '') {
                $laborYeji = bcadd($unitPrice, '0', 2);
            }
            $row['price'] = $laborYeji;
            $row['once_price'] = $laborYeji;
            $row['true_price'] = $laborYeji;
            $row['staffChoose'] = $this->splitLaborYejiAmongStaff($row['staffChoose'], $laborYeji);
            $row['value'] = $row['value'] ?? 1;
            $normalized[] = $row;
        }
        return $normalized;
    }

    /**
     * 手艺人展示文案
     */
    protected function formatReservationStaffDisplay(array $staffChoose, string $fallbackName = ''): string
    {
        if (!$staffChoose) {
            return $fallbackName;
        }
        $parts = [];
        foreach ($staffChoose as $item) {
            $name = trim((string)($item['staff_name'] ?? ''));
            if (!$name) {
                continue;
            }
            $suffix = (int)($item['is_dian'] ?? 0) === 1 ? '(点)' : '(轮)';
            $parts[] = $name . $suffix;
        }
        return $parts ? implode(',', $parts) : $fallbackName;
    }

    /**
     * 按服务总时长重算预约结束时间
     * @param array $reservationTimeInfo
     * @param int $durationMinutes
     * @param string $reservationDate
     * @return array
     */
    protected function applyServiceDurationToReservationEnd(array $reservationTimeInfo, int $durationMinutes, string $reservationDate): array
    {
        if ($durationMinutes <= 0 || empty($reservationTimeInfo['start'])) {
            return $reservationTimeInfo;
        }
        $date = date('Y-m-d', strtotime($reservationDate) ?: time());
        $startTs = strtotime($date . ' ' . $reservationTimeInfo['start']);
        if (!$startTs) {
            return $reservationTimeInfo;
        }
        $reservationTimeInfo['end'] = date('H:i', $startTs + $durationMinutes * 60);
        return $reservationTimeInfo;
    }

    /**
     * 解析预约时段：兼容旧 reservation_time_id，或直接使用 reservation_start/reservation_end（chooseTime）
     */
    public function resolveReservationTimeInfo(
        StoreProductReservationServices $productReservationServices,
        int $productId,
        string $unique,
        int $cartNum,
        string $reservationDate,
        array $reservationInfo,
        bool $isCheckReservationTime = true
    ): array {
        $timeId = (int)($reservationInfo['reservation_time_id'] ?? 0);
        $start = trim((string)($reservationInfo['reservation_start'] ?? ''));
        $end = trim((string)($reservationInfo['reservation_end'] ?? ''));

        if ($timeId > 0) {
            return $productReservationServices->checkReservationProductTimeStock(
                $productId,
                $unique,
                $cartNum,
                $reservationDate,
                $timeId,
                [],
                $isCheckReservationTime
            );
        }

        if (!$start) {
            throw new ValidateException('请选择预约时段!');
        }

        // 仅校验可预约日期等规则，不再依赖商品时段划分表
        $productReservationServices->checkReservationProductTimeStock(
            $productId,
            $unique,
            $cartNum,
            $reservationDate,
            0,
            [],
            $isCheckReservationTime
        );

        $start = $this->normalizeReservationClock($start);
        $end = $end ? $this->normalizeReservationClock($end) : '';

        return [
            'id' => 0,
            'start' => $start,
            'end' => $end,
        ];
    }

    /**
     * 统一为 H:i 格式
     */
    protected function normalizeReservationClock(string $value): string
    {
        $value = trim($value);
        if (!$value) {
            return '';
        }
        if (strpos($value, ' ') !== false) {
            $ts = strtotime($value);
            return $ts ? date('H:i', $ts) : $value;
        }
        return strlen($value) <= 5 ? $value : date('H:i', strtotime($value));
    }

    /**
     * 校验手艺人是否存在且可为当前门店预约（与业绩选人 can_choose 规则一致）
     */
    protected function resolveReservationStaff(int $staffId, int $storeId): array
    {
        if (!$staffId) {
            throw new ValidateException('请选择手艺人');
        }
        /** @var SystemStoreStaffServices $staffServices */
        $staffServices = app()->make(SystemStoreStaffServices::class);
        $staff = $staffServices->getOne(['id' => $staffId, 'status' => 1, 'is_del' => 0]);
        if (!$staff) {
            throw new ValidateException('手艺人不存在或已离职');
        }
        $staff = is_object($staff) ? $staff->toArray() : (array)$staff;
        $staffStoreId = (int)($staff['store_id'] ?? 0);
        if ($staffStoreId !== $storeId && (int)($staff['can_choose'] ?? 0) !== 1) {
            throw new ValidateException(($staff['staff_name'] ?? '手艺人') . '不属于当前门店，无法预约');
        }
        return $staff;
    }

    /**
     * 校验手艺人该时段是否可预约
     */
    protected function validateServiceStaffAvailability(int $staffId, int $storeId, string $reservationDate, array $reservationTimeInfo, int $durationMinutes = 0, int $excludeReservationId = 0): void
    {
        $staff = $this->resolveReservationStaff($staffId, $storeId);
        /** @var \app\services\store\StoreStaffScheduleServices $scheduleServices */
        $scheduleServices = app()->make(\app\services\store\StoreStaffScheduleServices::class);
        $scheduleEnabled = $scheduleServices->isScheduleManageEnabled();
        if ((int)($staff['is_reservable'] ?? 1) !== 1) {
            throw new ValidateException(($staff['staff_name'] ?? '手艺人') . '暂不可被预约');
        }
        $date = date('Y-m-d', strtotime($reservationDate) ?: time());
        $startStr = trim((string)($reservationTimeInfo['start'] ?? ''));
        if (!$startStr) {
            throw new ValidateException('请选择预约时间');
        }
        $appointmentTs = strtotime($date . ' ' . (strlen($startStr) <= 5 ? $startStr . ':00' : $startStr));
        if (!$appointmentTs) {
            throw new ValidateException('预约时间无效');
        }
        /** @var \app\services\store\StoreReservationStaffServices $reservationStaffServices */
        $reservationStaffServices = app()->make(\app\services\store\StoreReservationStaffServices::class);
        if ($durationMinutes <= 0) {
            $durationMinutes = 120;
        }
        $rangeEnd = $appointmentTs + $durationMinutes * 60;
        $offWork = trim((string)($staff['off_work_time'] ?? ''));
        if ($offWork && $reservationStaffServices->isStaffBlockedByOffWorkTime($staffId, $appointmentTs, $durationMinutes)) {
            throw new ValidateException(($staff['staff_name'] ?? '手艺人') . '已于' . $offWork . '下班，请重新选择');
        }
        if ($scheduleEnabled) {
            $boardStaff = $scheduleServices->getReservationBoardStaff($storeId, $date, $staffId);
            if (!$boardStaff) {
                throw new ValidateException(($staff['staff_name'] ?? '手艺人') . '在该日期未排班，请重新选择');
            }
            if (!$scheduleServices->isStaffScheduledForSlot($staffId, $storeId, $date, $appointmentTs, $rangeEnd)) {
                throw new ValidateException(($staff['staff_name'] ?? '手艺人') . '在该时间段未排班，请重新选择');
            }
        }
        if (!$reservationStaffServices->isStaffAvailable($staffId, $storeId, $appointmentTs, $durationMinutes, $excludeReservationId)) {
            if (!$reservationStaffServices->isStaffOnDuty($staffId)) {
                throw new ValidateException(($staff['staff_name'] ?? '手艺人') . '暂不可被预约');
            }
            if ($offWork && $reservationStaffServices->isStaffBlockedByOffWorkTime($staffId, $appointmentTs, $durationMinutes)) {
                throw new ValidateException(($staff['staff_name'] ?? '手艺人') . '已于' . $offWork . '下班，请重新选择');
            }
            throw new ValidateException(($staff['staff_name'] ?? '手艺人') . '在该时间段不可预约（休息或已被预约），请重新选择');
        }
    }

    /**
     * 创建预约单
     * @param int $uid
     * @param int $oid
     * @param array $reservationInfo
     * @param $orderInfo
     * @param $is_check_reservation_time
     * @param $is_check_custom_form
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function createReservationOrder(int $uid, int $oid, array $reservationInfo, $orderInfo = [], $is_check_reservation_time = true, $is_check_custom_form = true)
    {
        if (!$oid || !$reservationInfo) {
            throw new ValidateException('缺少预约必要信息!');
        }
        extract($reservationInfo);
        $cross_store_verification = (int)sys_config('cross_store_verification', 1);//跨店核销
        if (!isset($cart_num) || !$cart_num) throw new ValidateException('请选择预约数量!');
        if (!isset($reservation_time) || !$reservation_time) throw new ValidateException('请选择预约日期!');
        $reservationTimeId = (int)($reservation_time_id ?? 0);
        $reservationStart = trim((string)($reservationInfo['reservation_start'] ?? ''));
        if (!$reservationTimeId && !$reservationStart) {
            throw new ValidateException('请选择预约时段!');
        }
        /** @var StoreOrderServices $orderServices */
        $orderServices = app()->make(StoreOrderServices::class);
        if (!$orderInfo) {
            $orderInfo = $orderServices->get($oid);
        }
        if (!$orderInfo) {
            throw new ValidateException('获取订单信息失败!');
        }
        $orderInfo = is_object($orderInfo) ? $orderInfo->toArray() : (array)$orderInfo;
        // 收银台/部分历史订单 store_id 可能为空，优先原订单门店，否则用请求传入的当前门店
        $store_id = (int)($orderInfo['store_id'] ?? 0);
        if (!$store_id) {
            $store_id = (int)($reservationInfo['store_id'] ?? 0);
        }
        if (!$store_id) {
            throw new ValidateException('缺少门店信息');
        }
        //先买后约需要验证原订单 下单同步创建不需要验证
        $isCheckOrder = $this->getItem('is_check_order', 0);
        if ($isCheckOrder) {
            if ($orderInfo['paid'] != 1) {
                throw new ValidateException('原订单未支付');
            }
            if (!empty($orderInfo['is_del'])) {
                throw new ValidateException('原订单已删除!');
            }
        }

        //预约商品信息 验证商品
        $reservationOrderInfo = $this->getOrderInfo($uid, $oid, (int)($cart_info_id ?? 0));
        if ($is_check_custom_form && $reservationOrderInfo['custom_form'] && (!isset($custom_form) || !$custom_form)) {//商品需要补充信息
            throw new ValidateException('请补充预约信息!');
        }
        if ($reservationOrderInfo['cart_num'] < $cart_num) {//超出订单剩余最大预约数量
            throw new ValidateException('已全部预约，无可预约数量!');
        }
        $productId = (int)$reservationOrderInfo['product_id'];
        $unique = (string)$reservationOrderInfo['unique'];
        if (isset($reservationInfo['store_id']) && (int)$orderInfo['store_id'] && (int)$orderInfo['store_id'] != (int)$reservationInfo['store_id'] && !$cross_store_verification) {
            throw new ValidateException('未开启跨店核销，不能切换门店!');
        }
        if (isset($reservationInfo['store_id']) && (int)$orderInfo['store_id'] && (int)$orderInfo['store_id'] != (int)$reservationInfo['store_id'] && $cross_store_verification) {
            /** @var StoreProductServices $productServices */
            $productServices = app()->make(StoreProductServices::class);
            $productInfo = $productServices->getOne(['id' => $productId], '*');
            $platformProductId = (int)($productInfo['pid'] ?? 0);
            if ($platformProductId <= 0) {
                $platformProductId = (int)($productInfo['id'] ?? 0);
            }
            $newProductInfo = $productServices->getOne(['pid' => $platformProductId, 'type' => 1, 'relation_id' => $reservationInfo['store_id']], '*');
            if (!$newProductInfo) {
                throw new ValidateException('这个商品在该门店下不存在或已下架，请联系管理员');
            }
            $productId = $newProductInfo['id'];
            /** @var StoreProductAttrValueServices $attrValueServices */
            $attrValueServices = app()->make(StoreProductAttrValueServices::class);
            $attrInfo_y = $attrValueServices->getOne(['unique' => $unique, 'type' => 0]);
            if (!$unique || !$attrInfo_y) {
                throw new ValidateException('商品sku不存在或已下架，请联系管理员');
            }
            $attrInfo_x = $attrValueServices->getOne(['product_id' => $newProductInfo['id'], 'type' => 0, 'suk' => $attrInfo_y['suk']]);
            if (!$attrInfo_x) {
                throw new ValidateException('商品sku不存在或已下架，请联系管理员');
            }
            $unique = $attrInfo_x['unique'];
            $store_id = (int)($reservationInfo['store_id'] ?? $store_id);
        }
        /** @var StoreProductReservationServices $productReservationServices */
        $productReservationServices = app()->make(StoreProductReservationServices::class);
        $reservationTimeInfo = $this->resolveReservationTimeInfo(
            $productReservationServices,
            $productId,
            $unique,
            (int)$cart_num,
            (string)$reservation_time,
            $reservationInfo,
            $is_check_reservation_time
        );
        $serviceDurationMinutes = (int)($reservationInfo['service_duration_minutes'] ?? 0);
        $addonItems = $this->decodeJsonField($reservationInfo['addon_items'] ?? [], []);
        if ($serviceDurationMinutes > 0) {
            $reservationTimeInfo = $this->applyServiceDurationToReservationEnd($reservationTimeInfo, $serviceDurationMinutes, (string)$reservation_time);
        }
        $staffId = (int)($service_staff_id ?? 0);
        $syncAll = $this->decodeJsonField($reservationInfo['sync_all'] ?? [], []);
        $staffIdsToValidate = [];
        if ($syncAll) {
            foreach ($syncAll as $row) {
                if (empty($row['staffChoose']) || !is_array($row['staffChoose'])) {
                    continue;
                }
                foreach ($row['staffChoose'] as $staffRow) {
                    $sid = (int)($staffRow['staff_id'] ?? 0);
                    if ($sid) {
                        $staffIdsToValidate[$sid] = $sid;
                    }
                }
            }
        } elseif ($staffId) {
            $staffIdsToValidate[$staffId] = $staffId;
        }
        foreach ($staffIdsToValidate as $validateStaffId) {
            if ((int)$this->getItem('skip_reservation_availability_check', 0) !== 1) {
                $this->validateServiceStaffAvailability($validateStaffId, (int)$store_id, (string)$reservation_time, $reservationTimeInfo, $serviceDurationMinutes);
            }
        }
        if (!$staffId && $staffIdsToValidate) {
            $staffId = (int)reset($staffIdsToValidate);
            $service_staff_id = $staffId;
        }
        $staffChoose = $this->extractStaffChooseFromSyncAll($syncAll);
        $primaryStaffId = $this->resolvePrimaryStaffId($staffChoose, (int)$service_staff_id);
        $addonDeductionList = $this->parsePurchasedAddonDeductionList(
            $addonItems,
            $oid,
            (int)$reservationOrderInfo['cart_info_id']
        );
        $this->validatePurchasedAddonQuota(
            $uid,
            $addonDeductionList,
            $oid,
            (int)$reservationOrderInfo['cart_info_id'],
            (int)$cart_num
        );
        $custom_form = $this->decodeJsonField($custom_form ?? null, [[]]);
        /** @var StoreOrderCreateServices $createOrderServices */
        $createOrderServices = app()->make(StoreOrderCreateServices::class);
        $ids = $this->transaction(function () use (
            $uid, $oid, $cart_num, $reservationOrderInfo, $productId, $unique, $store_id, $orderInfo,
            $reservationTimeInfo, $serviceDurationMinutes, $addonItems, $reservationInfo, $custom_form,
            $primaryStaffId, $staffChoose, $reservation_time, $createOrderServices, $orderServices, $addonDeductionList
        ) {
            $ids = [];
            $data = [
                'uid' => $uid,
                'oid' => $oid,
                'cart_info_id' => $reservationOrderInfo['cart_info_id'],
                'product_id' => $productId,
                'sku_unique' => $unique,
                'sku' => $reservationOrderInfo['sku'],
                'store_id' => (int)$store_id,
                'reservation_type' => $orderInfo['reservation_type'],
                'reservation_time' => strtotime($reservation_time),
                'reservation_time_id' => (int)($reservationTimeInfo['id'] ?? 0),
                'reservation_start' => $reservationTimeInfo['start'],
                'reservation_end' => $reservationTimeInfo['end'],
                'service_duration_minutes' => $serviceDurationMinutes,
                'addon_items' => $addonItems ? json_encode($addonItems, JSON_UNESCAPED_UNICODE) : '',
                'reservation_name' => $reservationInfo['reservation_name'] ?? ($orderInfo['real_name'] ?? ''),
                'reservation_phone' => $reservationInfo['reservation_phone'] ?? ($orderInfo['user_phone'] ?? ''),
                'reservation_address' => $reservationInfo['reservation_address'] ?? ($reservationOrderInfo['user_address'] ?? ''),
                'service_staff_id' => $primaryStaffId,
                'staff_choose' => $this->encodeReservationStaffChoose($staffChoose),
                'mark' => $reservationInfo['mark'] ?? ($reservationOrderInfo['mark'] ?? ''),
                'status' => (int)$this->getItem('reservation_initial_status', 0),
                'reservation_create_time' => time(),
                'custom_form_title' => $reservationOrderInfo['custom_form_title'] ?? '',
                'add_time' => time()
            ];
            $data = $this->applyReservationTableFields($data, $reservationInfo);
            for ($i = 0; $i < $cart_num; $i++) {
                $data['order_id'] = $this->getUniqueId('yy');
                $data['verify_code'] = $createOrderServices->getStoreCode();
                $data['reservation_info'] = json_encode($custom_form[$i] ?? []);
                $res = $this->dao->save($data);
                if (!$res) {
                    throw new ValidateException('预约失败!');
                }
                $ids[] = $res->id;
            }
            /** @var StoreOrderCartInfoServices $cartServices */
            $cartServices = app()->make(StoreOrderCartInfoServices::class);
            $cartInfo = $cartServices->getOne(['oid' => $oid, 'id' => $reservationOrderInfo['cart_info_id']], 'id,cart_id,oid,product_id,sku_unique,write_times,write_surplus_times');
            $writeSurplusTimes = max((int)bcsub((string)$cartInfo['write_surplus_times'], (string)$cart_num, 0), 0);
            $cartServices->update($cartInfo['id'], ['write_surplus_times' => $writeSurplusTimes]);
            $this->deductPurchasedAddonQuota($addonDeductionList);
            $reservation_status = $this->getOrderReservationStatus($oid, $orderInfo);
            $orderServices->update($oid, ['reservation_status' => $reservation_status]);
            return $ids;
        });
        if ($ids) {
            foreach ($ids as $reservationId) {
                $saved = $this->dao->get((int)$reservationId);
                if ($saved && (int)$saved['status'] === 3) {
                    $this->dispatchYuyueCustomerNotice($saved->toArray());
                }
            }
        }
        return $ids;
    }

    /**
     * 兼容 JSON 字符串 / 已解码数组
     */
    protected function decodeJsonField($value, $default = [])
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : $default;
        }
        return $default;
    }

    /**
     * 支付成功订单：读取预约商品购物车行
     */
    protected function getReservationProductCartRow(int $oid): ?array
    {
        /** @var StoreOrderCartInfoServices $cartServices */
        $cartServices = app()->make(StoreOrderCartInfoServices::class);
        $cartRow = $cartServices->getOne(
            ['oid' => $oid, 'product_type' => 6, 'cart_type' => 0],
            'id,cart_info,cart_num,product_type'
        );
        if ($cartRow) {
            return is_object($cartRow) ? $cartRow->toArray() : (array)$cartRow;
        }
        $cartRow = $cartServices->getOne(['oid' => $oid, 'cart_type' => 0], 'id,cart_info,cart_num,product_type');
        if (!$cartRow) {
            return null;
        }
        $cartRow = is_object($cartRow) ? $cartRow->toArray() : (array)$cartRow;
        $cartData = $this->decodeJsonField($cartRow['cart_info'] ?? null);
        if (!$cartData) {
            return null;
        }
        $productType = (int)($cartData['productInfo']['product_type'] ?? $cartData['product_type'] ?? 0);
        return $productType === 6 ? $cartRow : null;
    }

    /**
     * 支付成功订单：是否应创建预约单（含时段信息）
     */
    protected function shouldCreateReservationFromPaidOrder(array $orderInfo, array $cartData): bool
    {
        if (!empty($orderInfo['refund_status'])) {
            return false;
        }
        $isReservationProduct = (int)($orderInfo['product_type'] ?? 0) === 6
            || (int)($orderInfo['type'] ?? 0) === 12
            || (int)($cartData['productInfo']['product_type'] ?? $cartData['product_type'] ?? 0) === 6;
        if (!$isReservationProduct) {
            return false;
        }
        $meta = $this->resolvePaidOrderReservationPayload($orderInfo, $cartData);
        return (bool)($meta['has_reservation_slot'] ?? false);
    }

    /**
     * 支付成功订单：合并订单与购物车中的预约信息
     */
    protected function resolvePaidOrderReservationPayload(array $orderInfo, array $cartData): array
    {
        $reservationTimeId = (int)($orderInfo['reservation_time_id'] ?? $cartData['reservation_time_id'] ?? 0);
        $reservationStart = trim((string)($cartData['reservation_start'] ?? ''));
        $reservationEnd = trim((string)($cartData['reservation_end'] ?? ''));
        $reservationTimeTs = (int)($orderInfo['reservation_time'] ?? 0);
        $reservationDate = $reservationTimeTs ? date('Y-m-d', $reservationTimeTs) : trim((string)($cartData['reservation_time'] ?? ''));
        if (!$reservationStart) {
            $showTime = trim((string)($cartData['reservation_show_time'] ?? ''));
            if (!$showTime && !empty($orderInfo['reservation_show_time'])) {
                $showTime = trim((string)$orderInfo['reservation_show_time']);
            }
            if ($showTime && strpos($showTime, '-') !== false) {
                [$startPart, $endPart] = array_map('trim', explode('-', $showTime, 2));
                $reservationStart = $startPart;
                if (!$reservationEnd) {
                    $reservationEnd = $endPart;
                }
            } elseif ($showTime) {
                $reservationStart = $showTime;
            }
        }
        if (!$reservationDate && $reservationTimeTs) {
            $reservationDate = date('Y-m-d', $reservationTimeTs);
        }
        $addonItems = $this->decodeJsonField($cartData['addon_items'] ?? [], []);
        $syncAll = $this->decodeJsonField($cartData['sync_all'] ?? [], []);
        $hasReservationSlot = $reservationTimeId > 0 || $reservationStart !== '' || $reservationTimeTs > 0;
        return [
            'has_reservation_slot' => $hasReservationSlot,
            'reservation_time_id' => $reservationTimeId,
            'reservation_start' => $reservationStart,
            'reservation_end' => $reservationEnd,
            'reservation_time' => $reservationDate,
            'service_duration_minutes' => (int)($cartData['service_duration_minutes'] ?? 0),
            'service_staff_id' => (int)($orderInfo['service_staff_id'] ?? $cartData['service_staff_id'] ?? 0),
            'addon_items' => is_array($addonItems) ? $addonItems : [],
            'sync_all' => is_array($syncAll) ? $syncAll : [],
        ];
    }

    /**
     * 预约商品下单支付成功后：创建预约单并扣减次数（与已购订单预约一致）
     */
    public function createReservationOrderFromPaidStoreOrder(array $orderInfo): void
    {
        $oid = (int)($orderInfo['id'] ?? 0);
        if (!$oid) {
            return;
        }
        /** @var StoreOrderServices $orderServices */
        $orderServices = app()->make(StoreOrderServices::class);
        $freshOrder = $orderServices->get($oid);
        if (!$freshOrder) {
            return;
        }
        $orderInfo = is_object($freshOrder) ? $freshOrder->toArray() : (array)$freshOrder;
        $uid = (int)($orderInfo['uid'] ?? 0);
        if (!$uid) {
            return;
        }
        if ($this->dao->count(['oid' => $oid, 'is_del' => 0, 'is_system_del' => 0])) {
            return;
        }
        $cartRow = $this->getReservationProductCartRow($oid);
        if (!$cartRow) {
            return;
        }
        $cartData = $this->decodeJsonField($cartRow['cart_info'] ?? null);
        if (!$this->shouldCreateReservationFromPaidOrder($orderInfo, $cartData)) {
            return;
        }
        $payload = $this->resolvePaidOrderReservationPayload($orderInfo, $cartData);
        $customForm = $this->decodeJsonField($orderInfo['custom_form'] ?? null, [[]]);
        if (!$customForm) {
            $customForm = [[]];
        }
        $reservationInfo = [
            'cart_num' => (int)($cartRow['cart_num'] ?? 1),
            'reservation_time' => (string)($payload['reservation_time'] ?? ''),
            'reservation_time_id' => (int)($payload['reservation_time_id'] ?? 0),
            'reservation_start' => (string)($payload['reservation_start'] ?? ''),
            'reservation_end' => (string)($payload['reservation_end'] ?? ''),
            'service_duration_minutes' => (int)($payload['service_duration_minutes'] ?? 0),
            'service_staff_id' => (int)($payload['service_staff_id'] ?? 0),
            'addon_items' => $payload['addon_items'] ?? [],
            'sync_all' => $payload['sync_all'] ?? [],
            'custom_form' => $customForm,
            'store_id' => (int)($orderInfo['store_id'] ?? 0),
        ];
        $this->setItem('reservation_initial_status', 3);
        $this->setItem('skip_reservation_availability_check', 1);
        $this->createReservationOrder($uid, $oid, $reservationInfo, $orderInfo, false, false);
        $this->reset();
    }

    /**
     * 收银台：未购项目仅生成预约记录（不扣次、不生成核销）
     */
    public function createGuestReservationRecord(int $uid, int $storeId, array $reservationInfo)
    {
        if (!$uid) {
            throw new ValidateException('请选择会员');
        }
        if (!$storeId) {
            throw new ValidateException('缺少门店信息');
        }
        extract($reservationInfo);
        if (!isset($product_id) || !$product_id) {
            throw new ValidateException('请选择预约服务');
        }
        if (!isset($reservation_time) || !$reservation_time) {
            throw new ValidateException('请选择预约日期');
        }
        $reservationTimeId = (int)($reservation_time_id ?? 0);
        $reservationStart = trim((string)($reservationInfo['reservation_start'] ?? ''));
        if (!$reservationTimeId && !$reservationStart) {
            throw new ValidateException('请选择预约时段');
        }
        $unique = (string)($unique ?? '');
        /** @var StoreProductReservationServices $productReservationServices */
        $productReservationServices = app()->make(StoreProductReservationServices::class);
        [$productId, $productInfo] = $productReservationServices->getProductInfo((int)$product_id, $storeId);
        if ((int)($productInfo['product_type'] ?? 0) !== 6) {
            throw new ValidateException('请选择预约商品');
        }
        /** @var StoreProductAttrValueServices $attrValueServices */
        $attrValueServices = app()->make(StoreProductAttrValueServices::class);
        if (!$unique) {
            $unique = (string)$attrValueServices->value(['product_id' => $productId, 'type' => 0], 'unique');
        }
        $attrInfo = $attrValueServices->getOne(['unique' => $unique, 'type' => 0, 'product_id' => $productId]);
        if (!$unique || !$attrInfo) {
            throw new ValidateException('商品规格不存在或已下架');
        }
        $reservationTimeInfo = $this->resolveReservationTimeInfo(
            $productReservationServices,
            $productId,
            $unique,
            1,
            (string)$reservation_time,
            $reservationInfo
        );
        $serviceDurationMinutes = (int)($reservationInfo['service_duration_minutes'] ?? 0);
        $addonItems = $this->decodeJsonField($reservationInfo['addon_items'] ?? [], []);
        if ($serviceDurationMinutes > 0) {
            $reservationTimeInfo = $this->applyServiceDurationToReservationEnd($reservationTimeInfo, $serviceDurationMinutes, (string)$reservation_time);
        }
        $staffId = (int)($service_staff_id ?? 0);
        $syncAll = $this->decodeJsonField($reservationInfo['sync_all'] ?? [], []);
        $staffIdsToValidate = [];
        if ($syncAll) {
            foreach ($syncAll as $row) {
                if (empty($row['staffChoose']) || !is_array($row['staffChoose'])) {
                    continue;
                }
                foreach ($row['staffChoose'] as $staffRow) {
                    $sid = (int)($staffRow['staff_id'] ?? 0);
                    if ($sid) {
                        $staffIdsToValidate[$sid] = $sid;
                    }
                }
            }
        } elseif ($staffId) {
            $staffIdsToValidate[$staffId] = $staffId;
        }
        foreach ($staffIdsToValidate as $validateStaffId) {
            $this->validateServiceStaffAvailability($validateStaffId, $storeId, (string)$reservation_time, $reservationTimeInfo, $serviceDurationMinutes);
        }
        if (!$staffId && $staffIdsToValidate) {
            $staffId = (int)reset($staffIdsToValidate);
        }
        $staffChoose = $this->extractStaffChooseFromSyncAll($syncAll);
        $primaryStaffId = $this->resolvePrimaryStaffId($staffChoose, $staffId);
        $reservationName = trim((string)($reservationInfo['reservation_name'] ?? ''));
        $reservationPhone = trim((string)($reservationInfo['reservation_phone'] ?? ''));
        if (!$reservationName || !$reservationPhone) {
            /** @var UserServices $userServices */
            $userServices = app()->make(UserServices::class);
            $userInfo = $userServices->getUserInfo($uid);
            if ($userInfo) {
                $userInfo = is_object($userInfo) ? $userInfo->toArray() : (array)$userInfo;
                if (!$reservationName) {
                    $reservationName = trim((string)($userInfo['real_name'] ?? $userInfo['nickname'] ?? ''));
                }
                if (!$reservationPhone) {
                    $reservationPhone = trim((string)($userInfo['phone'] ?? ''));
                }
            }
        }
        $guestCartInfo = $this->buildGuestReservationCartInfo($productId, $unique, $storeId);
        /** @var StoreOrderCreateServices $createOrderServices */
        $createOrderServices = app()->make(StoreOrderCreateServices::class);
        $data = [
            'uid' => $uid,
            'oid' => 0,
            'cart_info_id' => 0,
            'product_id' => $productId,
            'sku_unique' => $unique,
            'sku' => $attrInfo['suk'] ?? '',
            'store_id' => $storeId,
            'reservation_type' => 2,
            'reservation_time' => strtotime($reservation_time),
            'reservation_time_id' => (int)($reservationTimeInfo['id'] ?? 0),
            'reservation_start' => $reservationTimeInfo['start'],
            'reservation_end' => $reservationTimeInfo['end'],
            'service_duration_minutes' => $serviceDurationMinutes,
            'addon_items' => $addonItems ? json_encode($addonItems, JSON_UNESCAPED_UNICODE) : '',
            'reservation_name' => $reservationName,
            'reservation_phone' => $reservationPhone,
            'reservation_address' => $reservationInfo['reservation_address'] ?? '',
            'service_staff_id' => $primaryStaffId,
            'staff_choose' => $this->encodeReservationStaffChoose($staffChoose),
            'mark' => $reservationInfo['mark'] ?? '',
            'status' => (int)$this->getItem('reservation_initial_status', 0),
            'reservation_create_time' => time(),
            'custom_form_title' => '',
            'add_time' => time(),
            'order_id' => $this->getUniqueId('yy'),
            'verify_code' => $createOrderServices->getStoreCode(),
            'reservation_info' => $guestCartInfo ? json_encode($guestCartInfo, JSON_UNESCAPED_UNICODE) : json_encode([]),
        ];
        $data = $this->applyReservationTableFields($data, $reservationInfo);
        $res = $this->dao->save($data);
        if (!$res) {
            throw new ValidateException('预约失败');
        }
        return [(int)$res->id];
    }

    /**
     * 已购项目预约成功：生成待核销记录（已废弃，核销改在开始服务时生成）
     * @deprecated
     */
    public function createReservationPendingWriteoff(int $reservationId, array $syncAll = []): void
    {
        $reservationOrder = $this->dao->get($reservationId);
        if (!$reservationOrder || !(int)$reservationOrder['oid'] || !(int)$reservationOrder['cart_info_id']) {
            return;
        }
        $reservationOrder = $reservationOrder->toArray();
        /** @var StoreOrderWriteOffServices $storeOrderWriteoffServices */
        $storeOrderWriteoffServices = app()->make(StoreOrderWriteOffServices::class);
        if ($storeOrderWriteoffServices->get(['oid' => $reservationOrder['oid'], 'reservation_oid' => $reservationId, 'status' => 0])) {
            return;
        }
        /** @var StoreOrderCartInfoServices $cartServices */
        $cartServices = app()->make(StoreOrderCartInfoServices::class);
        $cartInfo = $cartServices->getOne(['oid' => $reservationOrder['oid'], 'id' => $reservationOrder['cart_info_id']]);
        if (!$cartInfo) {
            return;
        }
        $cartInfo = is_object($cartInfo) ? $cartInfo->toArray() : (array)$cartInfo;
        $price = bcdiv((string)$cartInfo['pay_price'], (string)max((int)$cartInfo['write_times'], 1), 2);
        if ($syncAll) {
            $syncAll = $this->normalizeReservationSyncAll($syncAll, $cartInfo, (int)$reservationOrder['oid'], $price);
        }
        $writeoffData = [
            'staff_id' => (int)($reservationOrder['service_staff_id'] ?? 0),
            'price' => $price,
            'store_id' => (int)($reservationOrder['store_id'] ?? 0),
            'is_auto' => 1,
        ];
        if ($syncAll) {
            $writeoffData['sync_all'] = $syncAll;
        }
        $storeOrderWriteoffServices->saveWriteOff(
            (int)$reservationOrder['oid'],
            $reservationId,
            [['cart_id' => $cartInfo['cart_id'], 'cart_num' => 1]],
            $writeoffData
        );
    }

    /**
     * 取消预约：作废关联的待核销记录
     */
    public function voidReservationPendingWriteoff(int $reservationId): void
    {
        $writeoff = StoreOrderWriteoff::where('reservation_oid', $reservationId)->where('status', 0)->find();
        if (!$writeoff) {
            return;
        }
        StoreOrderWriteoff::where('id', $writeoff['id'])->update(['status' => 1]);
        StaffYeji::where('link_id', $writeoff['id'])->where('type', 3)->update(['status' => 1]);
    }

    /**
     * 开始服务：生成/更新核销单并同步手艺人业绩
     */
    public function createReservationWriteoffOnStart(int $reservationId, int $staffId, array $syncAll, array $appendData = []): void
    {
        $writeoff = StoreOrderWriteoff::where('reservation_oid', $reservationId)->where('status', 0)->find();
        if ($writeoff) {
            $this->syncReservationWriteoffStaff($reservationId, $staffId, $syncAll);
            return;
        }
        $reservationOrder = $this->dao->get($reservationId);
        if (!$reservationOrder || !(int)$reservationOrder['oid'] || !(int)$reservationOrder['cart_info_id']) {
            return;
        }
        $reservationOrder = $reservationOrder->toArray();
        /** @var StoreOrderCartInfoServices $cartServices */
        $cartServices = app()->make(StoreOrderCartInfoServices::class);
        $cartInfo = $cartServices->getOne(['oid' => $reservationOrder['oid'], 'id' => $reservationOrder['cart_info_id']]);
        if (!$cartInfo) {
            return;
        }
        $cartInfo = is_object($cartInfo) ? $cartInfo->toArray() : (array)$cartInfo;
        $price = bcdiv((string)$cartInfo['pay_price'], (string)max((int)$cartInfo['write_times'], 1), 2);
        if ($syncAll) {
            $syncAll = $this->normalizeReservationSyncAll($syncAll, $cartInfo, (int)$reservationOrder['oid'], $price);
        }
        $writeoffData = [
            'staff_id' => $staffId,
            'price' => $price,
            'store_id' => (int)($appendData['store_id'] ?? $reservationOrder['store_id'] ?? 0),
            'is_auto' => 1,
        ];
        if ($syncAll) {
            $writeoffData['sync_all'] = $syncAll;
        }
        /** @var StoreOrderWriteOffServices $storeOrderWriteoffServices */
        $storeOrderWriteoffServices = app()->make(StoreOrderWriteOffServices::class);
        $storeOrderWriteoffServices->saveWriteOff(
            (int)$reservationOrder['oid'],
            $reservationId,
            [['cart_id' => $cartInfo['cart_id'], 'cart_num' => 1]],
            $writeoffData
        );
    }

    /**
     * 开始服务：同步更新预约关联核销记录的手艺人业绩
     */
    public function syncReservationWriteoffStaff(int $reservationId, int $staffId, array $syncAll = []): void
    {
        $writeoff = StoreOrderWriteoff::where('reservation_oid', $reservationId)->where('status', 0)->find();
        if (!$writeoff) {
            return;
        }
        $writeoffId = (int)$writeoff['id'];
        if ($staffId > 0) {
            StoreOrderWriteoff::where('id', $writeoffId)->update(['staff_id' => $staffId]);
        }
        StaffYeji::where('link_id', $writeoffId)->where('type', 3)->update(['status' => 1]);
        if (!$syncAll) {
            return;
        }
        /** @var SatffYejiServices $staffYejiService */
        $staffYejiService = app()->make(SatffYejiServices::class);
        foreach ($syncAll as $row) {
            if (empty($row['staffChoose']) || !is_array($row['staffChoose'])) {
                continue;
            }
            $row['link_id'] = $writeoffId;
            $row['order_id'] = (int)$writeoff['oid'];
            $row['type'] = 3;
            $row['goods_id'] = (int)($row['goods_id'] ?? $writeoff['product_id']);
            $row['is_auto'] = 1;
            $staffYejiService->saveYeji($row, false);
        }
    }

    /**
     * 修改预约单
     * @param int $id
     * @param array $reservationInfo
     * @return bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function updateReservationOrder(int $id, array $reservationInfo)
    {
        $reservationOrder = $this->dao->get($id);
        if (!$reservationOrder) {
            throw new ValidateException('预约单不存在!');
        }
        $reservationOrder = is_object($reservationOrder) ? $reservationOrder->toArray() : $reservationOrder;
        extract($reservationInfo);
        if (empty($reservation_time)) {
            $reservation_time = date('Y-m-d', (int)$reservationOrder['reservation_time']);
        }
        if (empty($reservation_start)) {
            $reservation_start = (string)($reservationOrder['reservation_start'] ?? '');
        }
        if (empty($reservation_end)) {
            $reservation_end = (string)($reservationOrder['reservation_end'] ?? '');
        }
        if (!$reservation_time) {
            throw new ValidateException('请选择预约日期!');
        }
        if ($reservationOrder['reservation_type'] == 3) {//上门服务
            if (isset($reservation_address) && !$reservation_address) {
                throw new ValidateException('请选择上门地址!');
            }
        }
        $serviceDurationMinutes = (int)($reservationInfo['service_duration_minutes'] ?? 0);
        if ($serviceDurationMinutes <= 0) {
            $serviceDurationMinutes = (int)($reservationOrder['service_duration_minutes'] ?? 0);
        }
        $hasSyncAll = array_key_exists('sync_all', $reservationInfo);
        $syncAll = $this->decodeJsonField($reservationInfo['sync_all'] ?? [], []);
        $staffChoose = [];
        if ($hasSyncAll) {
            $staffChoose = $this->extractStaffChooseFromSyncAll($syncAll);
        } elseif (isset($reservationInfo['staff_choose'])) {
            $staffChoose = $this->decodeReservationStaffChoose($reservationInfo['staff_choose']);
        }
        /** @var StoreProductReservationServices $productReservationServices */
        $productReservationServices = app()->make(StoreProductReservationServices::class);
        $reservationTimeInfo = $this->resolveReservationTimeInfo(
            $productReservationServices,
            (int)$reservationOrder['product_id'],
            (string)$reservationOrder['sku_unique'],
            1,
            (string)$reservation_time,
            array_merge($reservationInfo, [
                'reservation_start' => $reservation_start,
                'reservation_end' => $reservation_end,
            ]),
            false
        );
        if ($serviceDurationMinutes > 0) {
            $reservationTimeInfo = $this->applyServiceDurationToReservationEnd($reservationTimeInfo, $serviceDurationMinutes, (string)$reservation_time);
        }
        if ($staffChoose) {
            $storeId = (int)($reservationOrder['store_id'] ?? 0);
            foreach ($staffChoose as $item) {
                $validateStaffId = (int)($item['staff_id'] ?? 0);
                if ($validateStaffId) {
                    $this->validateServiceStaffAvailability(
                        $validateStaffId,
                        $storeId,
                        (string)$reservation_time,
                        $reservationTimeInfo,
                        $serviceDurationMinutes,
                        $id
                    );
                }
            }
        }
        $data = [
            'reservation_time' => strtotime($reservation_time),
            'reservation_time_id' => (int)($reservationTimeInfo['id'] ?? 0),
            'reservation_start' => $reservationTimeInfo['start'],
            'reservation_end' => $reservationTimeInfo['end'],
            'reservation_name' => $reservation_name ?? '',
            'reservation_phone' => $reservation_phone ?? '',
        ];
        if ($hasSyncAll) {
            $data['staff_choose'] = $this->encodeReservationStaffChoose($staffChoose);
            $data['service_staff_id'] = $this->resolvePrimaryStaffId(
                $staffChoose,
                (int)($service_staff_id ?? 0)
            );
        } elseif ($staffChoose) {
            $data['staff_choose'] = $this->encodeReservationStaffChoose($staffChoose);
            $data['service_staff_id'] = $this->resolvePrimaryStaffId(
                $staffChoose,
                (int)($service_staff_id ?? $reservationOrder['service_staff_id'] ?? 0)
            );
        } elseif (isset($service_staff_id)) {
            $data['service_staff_id'] = (int)$service_staff_id;
        }
        if ($serviceDurationMinutes > 0) {
            $data['service_duration_minutes'] = $serviceDurationMinutes;
        }
        if (array_key_exists('addon_items', $reservationInfo)) {
            $newAddonItems = $this->decodeJsonField($reservationInfo['addon_items'], []);
            $oldAddonItems = $this->decodeJsonField($reservationOrder['addon_items'] ?? '', []);
            $oldJson = json_encode($oldAddonItems, JSON_UNESCAPED_UNICODE);
            $newJson = json_encode($newAddonItems, JSON_UNESCAPED_UNICODE);
            if ($oldJson !== $newJson) {
                $oid = (int)($reservationOrder['oid'] ?? 0);
                $cartInfoId = (int)($reservationOrder['cart_info_id'] ?? 0);
                $uid = (int)($reservationOrder['uid'] ?? 0);
                if ($oid && $cartInfoId) {
                    $this->restorePurchasedAddonQuota($this->parsePurchasedAddonDeductionList($oldAddonItems, $oid, $cartInfoId));
                    $newDeductionList = $this->parsePurchasedAddonDeductionList($newAddonItems, $oid, $cartInfoId);
                    $this->validatePurchasedAddonQuota($uid, $newDeductionList, $oid, $cartInfoId, 1);
                    $this->deductPurchasedAddonQuota($newDeductionList);
                }
                $data['addon_items'] = $newAddonItems ? json_encode($newAddonItems, JSON_UNESCAPED_UNICODE) : '';
            }
        }
        if (array_key_exists('custom_form', $reservationInfo)) {
            $customForm = $this->decodeJsonField($reservationInfo['custom_form'], null);
            if ($customForm !== null) {
                $data['reservation_info'] = json_encode($customForm, JSON_UNESCAPED_UNICODE);
            }
        }
        if (isset($reservation_address)) {
            $data['reservation_address'] = $reservation_address;
        }
        if (array_key_exists('mark', $reservationInfo)) {
            $data['mark'] = trim((string)$reservationInfo['mark']);
        }
        if (array_key_exists('table_id', $reservationInfo) || array_key_exists('table_name', $reservationInfo)) {
            $data = $this->applyReservationTableFields($data, $reservationInfo);
        }
        $this->dao->update($id, $data);
        return true;
    }

    /**
     * 确认预约（待确认 -> 待服务）
     * @param int $id
     * @param int $storeId
     * @param int $confirmStaffId
     * @return bool
     */
    public function confirmReservationOrder(int $id, int $storeId = 0, int $confirmStaffId = 0, int $tableId = 0, string $tableName = '', bool $requireRoom = false)
    {
        $where = ['id' => $id, 'is_del' => 0, 'is_system_del' => 0];
        if ($storeId) $where['store_id'] = $storeId;
        $reservationOrder = $this->dao->get($where);
        if (!$reservationOrder) {
            throw new ValidateException('预约单不存在!');
        }
        if ($reservationOrder['status'] != 3) {
            throw new ValidateException('当前预约单无需确认!');
        }
        if ($requireRoom && !$tableId && $tableName === '') {
            throw new ValidateException('请选择服务房间!');
        }
        $update = [
            'status' => 0,
            'confirm_time' => time(),
            'table_id' => $tableId,
            'table_name' => trim($tableName),
        ];
        if ($confirmStaffId) {
            $update['confirm_staff_id'] = $confirmStaffId;
        }
        $this->dao->update($id, $update);
        $row = $reservationOrder->toArray();
        $row = array_merge($row, $update);
        $this->dispatchYuyueSuccessNotice($row);
        return true;
    }

    /**
     * 管家拒绝预约（待确认 -> 已退回）
     * @param int $id
     * @param int $storeId
     * @param string $refuseReason
     * @param int $staffId
     * @return bool
     */
    public function refuseReservationOrder(int $id, int $storeId = 0, string $refuseReason = '', int $staffId = 0)
    {
        if (!trim($refuseReason)) {
            throw new ValidateException('请填写拒绝原因');
        }
        $where = ['id' => $id, 'is_del' => 0, 'is_system_del' => 0];
        if ($storeId) $where['store_id'] = $storeId;
        $reservationOrderInfo = $this->dao->get($where);
        if (!$reservationOrderInfo) {
            throw new ValidateException('预约单不存在!');
        }
        if ($reservationOrderInfo['status'] != 3) {
            throw new ValidateException('当前预约单无法拒绝!');
        }
        $update = [
            'status' => 4,
            'refuse_reason' => trim($refuseReason),
        ];
        if ($staffId) {
            $update['confirm_staff_id'] = $staffId;
        }
        $this->dao->update($id, $update);
        $this->restorePurchasedReservationQuota($reservationOrderInfo->toArray());
        $row = $reservationOrderInfo->toArray();
        $row = array_merge($row, $update);
        $this->dispatchYuyueRefuseNotice($row, true);
        return true;
    }

    /**
     * 解析需扣次的已购加项（含 cart_info_id 或同单商品定位）
     */
    protected function parsePurchasedAddonDeductionList(array $addonItems, int $mainOid, int $mainCartInfoId): array
    {
        $list = [];
        $seen = [];
        foreach ($addonItems as $item) {
            if (!is_array($item)) {
                continue;
            }
            $cartInfoId = (int)($item['cart_info_id'] ?? 0);
            $addonOid = (int)($item['oid'] ?? $mainOid);
            if (!$cartInfoId && $addonOid && !empty($item['product_id'])) {
                $cartInfoId = $this->resolveAddonCartInfoId($addonOid, (int)$item['product_id'], (string)($item['unique'] ?? ''));
            }
            if (!$cartInfoId) {
                continue;
            }
            $key = $addonOid . '_' . $cartInfoId;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $list[] = ['oid' => $addonOid, 'cart_info_id' => $cartInfoId];
        }
        return $list;
    }

    /**
     * 同订单下按商品定位加项 cart_info_id（兼容未传 cart_info_id 的请求）
     */
    protected function resolveAddonCartInfoId(int $oid, int $productId, string $unique): int
    {
        /** @var StoreOrderCartInfoServices $cartServices */
        $cartServices = app()->make(StoreOrderCartInfoServices::class);
        $where = ['oid' => $oid, 'product_id' => $productId];
        if ($unique !== '') {
            $where['sku_unique'] = $unique;
        }
        $cartInfo = $cartServices->getOne($where, 'id');
        return $cartInfo ? (int)$cartInfo['id'] : 0;
    }

    /**
     * 校验已购加项剩余次数（含主项与加项合计扣次）
     */
    protected function validatePurchasedAddonQuota(
        int $uid,
        array $deductionList,
        int $mainOid = 0,
        int $mainCartInfoId = 0,
        int $mainCartNum = 1
    ): void {
        $needs = [];
        if ($mainOid && $mainCartInfoId && $mainCartNum > 0) {
            $key = $mainOid . '_' . $mainCartInfoId;
            $needs[$key] = ['oid' => $mainOid, 'cart_info_id' => $mainCartInfoId, 'num' => $mainCartNum];
        }
        foreach ($deductionList as $row) {
            $key = (int)$row['oid'] . '_' . (int)$row['cart_info_id'];
            if (!isset($needs[$key])) {
                $needs[$key] = ['oid' => (int)$row['oid'], 'cart_info_id' => (int)$row['cart_info_id'], 'num' => 0];
            }
            $needs[$key]['num']++;
        }
        if (!$needs) {
            return;
        }
        /** @var StoreOrderCartInfoServices $cartServices */
        $cartServices = app()->make(StoreOrderCartInfoServices::class);
        /** @var StoreOrderServices $orderServices */
        $orderServices = app()->make(StoreOrderServices::class);
        foreach ($needs as $row) {
            $order = $orderServices->get((int)$row['oid']);
            if (!$order || (int)$order['uid'] !== $uid) {
                throw new ValidateException('加项订单无效');
            }
            $cartInfo = $cartServices->getOne(
                ['oid' => $row['oid'], 'id' => $row['cart_info_id']],
                'id,write_surplus_times'
            );
            if (!$cartInfo || (int)$cartInfo['write_surplus_times'] < (int)$row['num']) {
                throw new ValidateException('服务剩余次数不足');
            }
        }
    }

    /**
     * 扣减已购加项剩余次数
     */
    protected function deductPurchasedAddonQuota(array $deductionList): void
    {
        if (!$deductionList) {
            return;
        }
        /** @var StoreOrderCartInfoServices $cartServices */
        $cartServices = app()->make(StoreOrderCartInfoServices::class);
        /** @var StoreOrderServices $orderServices */
        $orderServices = app()->make(StoreOrderServices::class);
        $updatedOids = [];
        foreach ($deductionList as $row) {
            $cartInfo = $cartServices->getOne(
                ['oid' => $row['oid'], 'id' => $row['cart_info_id']],
                'id,write_surplus_times'
            );
            if (!$cartInfo) {
                throw new ValidateException('加项服务信息不存在');
            }
            if ((int)$cartInfo['write_surplus_times'] <= 0) {
                throw new ValidateException('加项服务剩余次数不足');
            }
            $writeSurplusTimes = max((int)bcsub((string)$cartInfo['write_surplus_times'], '1', 0), 0);
            $cartServices->update($cartInfo['id'], ['write_surplus_times' => $writeSurplusTimes]);
            $updatedOids[(int)$row['oid']] = true;
        }
        foreach (array_keys($updatedOids) as $addonOid) {
            $orderServices->update($addonOid, ['reservation_status' => $this->getOrderReservationStatus($addonOid)]);
        }
    }

    /**
     * 回退已购加项剩余次数
     */
    protected function restorePurchasedAddonQuota(array $deductionList): void
    {
        if (!$deductionList) {
            return;
        }
        /** @var StoreOrderCartInfoServices $cartServices */
        $cartServices = app()->make(StoreOrderCartInfoServices::class);
        /** @var StoreOrderServices $orderServices */
        $orderServices = app()->make(StoreOrderServices::class);
        $updatedOids = [];
        foreach ($deductionList as $row) {
            $cartInfo = $cartServices->getOne(
                ['oid' => $row['oid'], 'id' => $row['cart_info_id']],
                'id,write_surplus_times'
            );
            if (!$cartInfo) {
                continue;
            }
            $writeSurplusTimes = (int)bcadd((string)$cartInfo['write_surplus_times'], '1', 0);
            $cartServices->update($cartInfo['id'], ['write_surplus_times' => $writeSurplusTimes]);
            $updatedOids[(int)$row['oid']] = true;
        }
        foreach (array_keys($updatedOids) as $addonOid) {
            $orderServices->update($addonOid, ['reservation_status' => $this->getOrderReservationStatus($addonOid)]);
        }
    }

    /**
     * 已购预约退回次数（取消/拒绝）
     */
    protected function restorePurchasedReservationQuota(array $reservationOrderInfo): void
    {
        $oid = (int)($reservationOrderInfo['oid'] ?? 0);
        if (!$oid || !(int)($reservationOrderInfo['cart_info_id'] ?? 0)) {
            return;
        }
        $this->voidReservationPendingWriteoff((int)$reservationOrderInfo['id']);
        /** @var StoreOrderServices $orderServices */
        $orderServices = app()->make(StoreOrderServices::class);
        $orderServices->update($oid, ['reservation_status' => $this->getOrderReservationStatus($oid)]);
        /** @var StoreOrderCartInfoServices $cartServices */
        $cartServices = app()->make(StoreOrderCartInfoServices::class);
        $cartInfo = $cartServices->getOne(['oid' => $oid, 'id' => $reservationOrderInfo['cart_info_id']], 'id,write_surplus_times');
        if ($cartInfo) {
            $writeSurplusTimes = (int)bcadd((string)$cartInfo['write_surplus_times'], '1', 0);
            $cartServices->update($cartInfo['id'], ['write_surplus_times' => $writeSurplusTimes]);
        }
        $addonItems = $reservationOrderInfo['addon_items'] ?? '';
        if (is_string($addonItems)) {
            $addonItems = json_decode($addonItems, true) ?: [];
        }
        if (!is_array($addonItems)) {
            $addonItems = [];
        }
        $this->restorePurchasedAddonQuota($this->parsePurchasedAddonDeductionList(
            $addonItems,
            $oid,
            (int)$reservationOrderInfo['cart_info_id']
        ));
    }

    /**
     * 校验店长操作权限（兼容期历史管家等同店长）
     */
    public function assertReservationManagePermission(array $staffInfo): void
    {
        if (!$staffInfo) {
            throw new ValidateException('无操作权限');
        }
        if (\app\services\store\SystemStoreStaffServices::staffIsManager($staffInfo)) {
            return;
        }
        throw new ValidateException('仅店长可执行此操作');
    }

    /**
     * 解析预约操作用户：本人或管家代客
     */
    public function resolveBookingUid(int $loginUid, int $bookUid = 0): int
    {
        if (!$bookUid || $bookUid === $loginUid) {
            return $loginUid;
        }
        /** @var SystemStoreStaffServices $staffServices */
        $staffServices = app()->make(SystemStoreStaffServices::class);
        try {
            $staffInfo = $staffServices->getStaffInfoByUid($loginUid)->toArray();
        } catch (\Throwable $e) {
            $staffInfo = [];
        }
        $this->assertReservationManagePermission($staffInfo);
        return $bookUid;
    }

    /**
     * 预约列表查询用户：本人 / 管家代客 / 按订单归属
     */
    public function resolveReservationListUid(int $loginUid, int $oid = 0, int $bookUid = 0): int
    {
        if ($bookUid > 0) {
            return $this->resolveBookingUid($loginUid, $bookUid);
        }
        if ($oid > 0) {
            /** @var StoreOrderServices $orderServices */
            $orderServices = app()->make(StoreOrderServices::class);
            $order = $orderServices->get($oid);
            if (!$order) {
                return $loginUid;
            }
            $order = is_object($order) ? $order->toArray() : (array)$order;
            $orderUid = (int)($order['uid'] ?? 0);
            if (!$orderUid || $orderUid === $loginUid) {
                return $loginUid;
            }
            return $this->resolveBookingUid($loginUid, $orderUid);
        }
        return $loginUid;
    }

    /**
     * 订单信息（售后预约）
     * @param int $uid
     * @param int $id
     * @return array|\think\Response
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getOrderInfo(int $uid, int $id, int $cart_info_id = 0)
    {
        /** @var SystemStoreServices $storeServices */
        $storeServices = app()->make(SystemStoreServices::class);
        /** @var StoreOrderServices $orderServices */
        $orderServices = app()->make(StoreOrderServices::class);
        $order = $orderServices->getUserOrderDetail((string)$id, $uid);
        if (!$order) throw new ValidateException('订单不存在');
        /** @var StoreOrderCartInfoServices $cartServices */
        $cartServices = app()->make(StoreOrderCartInfoServices::class);
        $where = ['oid' => $order['id']];
        if ((int)$order['type'] === 11) {
            $where['cart_type'] = 2;
        } else {
            $where['product_type'] = 6;
        }
        if ($cart_info_id) $where['id'] = $cart_info_id;
        $cartInfo = $cartServices->getOne($where, 'id,cart_num,cart_id,oid,product_id,sku_unique,write_surplus_times,cart_type,product_type');
        if (!$cartInfo) {
            throw new ValidateException('获取订单商品信息失败');
        }
        $productId = $cartInfo['product_id'] ?? 0;
        $unique = $cartInfo['sku_unique'] ?? '';
        /** @var StoreProductServices $productServices */
        $productServices = app()->make(StoreProductServices::class);
        $productInfo = $productServices->getOne(['id' => $productId], '*');
        if (!$productInfo) {
            throw new ValidateException('商品不存在或已下架，请联系管理员');
        }
        /** @var StoreProductReservationServices $productReservationServices */
        $productReservationServices = app()->make(StoreProductReservationServices::class);
        $productInfo = $productReservationServices->fillServiceDuration($productInfo->toArray());
        /** @var StoreProductAttrValueServices $attrValueServices */
        $attrValueServices = app()->make(StoreProductAttrValueServices::class);
        $attrInfo = $attrValueServices->getOne(['unique' => $unique, 'type' => 0]);
        if (!$unique || !$attrInfo || $attrInfo['product_id'] != $productId) {
            throw new ValidateException('商品sku不存在或已下架，请联系管理员');
        }
        $customForm = [];
        $customFormTitle = '';
        if ($productInfo['system_form_id']) {
            /** @var SystemFormServices $systemFormServices */
            $systemFormServices = app()->make(SystemFormServices::class);
            $formInfo = $systemFormServices->get(['id' => $productInfo['system_form_id']], ['id', 'name', 'value']);
            if ($formInfo) {
                $customForm = is_string($formInfo['value']) ? json_decode($formInfo['value'], true) : $formInfo['value'];
                $customFormTitle = $formInfo['name'];
            }
        }
        $store_name = $storeServices->value(['id' => $order['store_id']], 'name');
        //获取预约订单剩余预约数量
        $maxCartNum = $this->getReservationOrderSurplusNum((int)$id, (int)$cartInfo['id'], $order);
        $card_product_id = 0;
        if($order['type'] == 11) $card_product_id = $order['activity_id'];
        $reservationType = (int)($order['reservation_type'] ?? 0);
        if (!$reservationType) {
            $reservationType = (int)($productInfo['reservation_type'] ?? 2);
        }
        return [
            'id' => $order['id'],
            'cart_num' => $maxCartNum,
            'reservation_type' => $reservationType,
            'product_id' => $productId,
            'product_name' => $productInfo['store_name'] ?? '',
            'sku_name' => $attrInfo['suk'] ?? '',
            'project_service_duration' => (int)($productInfo['project_service_duration'] ?? 0),
            'addon_service_duration' => (int)($productInfo['addon_service_duration'] ?? 0),
            'card_product_id' => $card_product_id,
            'pid' => $productInfo['pid'],
            'unique' => $unique,
            'sku' => $attrInfo['suk'],
            'cart_info_id' => $cartInfo['id'],//订单商品ID
            'cart_id' => $cartInfo['cart_id'],//购物车ID
            'is_show_stock' => $productInfo['is_show_stock'],
            'store_id' => $order['store_id'],
            'store_name' => $store_name,
            'custom_form' => $customForm,
            'custom_form_title' => $customFormTitle,
			'system_from_type' => $productInfo['system_form_type'] ?? 1,
            'mark' => $order['mark'] ?? '',
            'user_address' => $order['user_address'] ?? '',
        ];
    }

    /**
     * 切换门店获取该商品信息
     * @param int $store_id
     * @param int $pid
     * @param string $unique
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getSwitchGoodsInfo(int $store_id, int $pid, string $unique)
    {
        /** @var StoreProductServices $productServices */
        $productServices = app()->make(StoreProductServices::class);
        $productInfo = $productServices->getOne(['pid' => $pid, 'relation_id' => $store_id, 'type' => 1], '*');
        if (!$productInfo) {
            throw new ValidateException('商品不存在或已下架，请联系管理员');
        }
        /** @var StoreProductAttrValueServices $attrValueServices */
        $attrValueServices = app()->make(StoreProductAttrValueServices::class);
        $attrInfo_y = $attrValueServices->getOne(['unique' => $unique, 'type' => 0]);
        if (!$unique || !$attrInfo_y) {
            throw new ValidateException('商品sku不存在或已下架，请联系管理员');
        }
        $attrInfo_x = $attrValueServices->getOne(['product_id' => $productInfo['id'], 'type' => 0, 'suk' => $attrInfo_y['suk']]);
        if (!$attrInfo_x) {
            throw new ValidateException('商品sku不存在或已下架，请联系管理员');
        }

        return [
            'product_id' => $productInfo['id'],
            'unique' => $attrInfo_x['unique'],
            'sku' => $attrInfo_x['suk'],
            'is_show_stock' => $productInfo['is_show_stock'],
        ];
    }

    /**
     * 获取预约订单剩余预约数量
     * @param int $oid
     * @param int $cart_info_id
     * @param $orderInfo
     * @return mixed
     */
    public function getReservationOrderSurplusNum(int $oid, int $cart_info_id = 0, $orderInfo = [])
    {
        if (!$orderInfo) {
            /** @var StoreOrderServices $orderServices */
            $orderServices = app()->make(StoreOrderServices::class);
            $orderInfo = $orderServices->get($oid);
        }
        if (!$orderInfo) {
            throw new ValidateException('获取订单信息失败!');
        }
        //未核销预约单
        $reservationOrderCount = $this->dao->count(['oid' => $oid, 'cart_info_id' => $cart_info_id, 'status' => [0, 1, 3], 'is_del' => 0, 'is_system_del' => 0]);
        //核销
        /** @var StoreOrderWriteOffServices $storeOrderWriteoffServices */
        $storeOrderWriteoffServices = app()->make(StoreOrderWriteOffServices::class);
        $writeoffCount = $storeOrderWriteoffServices->sum(['oid' => $oid, 'order_cart_id' => $cart_info_id], 'writeoff_num');
        /** @var StoreOrderCartInfoServices $cartServices */
        $cartServices = app()->make(StoreOrderCartInfoServices::class);
        $cartInfo = $cartServices->getOne(['oid' => $oid, 'id' => $cart_info_id], 'id,cart_id,oid,product_id,sku_unique,cart_num,write_surplus_times,cart_type,product_type');
        if (!$cartInfo) {
            return 0;
        }
        // 卡项明细：剩余次数以 write_surplus_times 为准（预约/核销都会同步扣减）
        if ((int)($orderInfo['type'] ?? 0) === 11 || (int)$cartInfo['cart_type'] === 2) {
            return max((int)$cartInfo['write_surplus_times'], 0);
        }
        if ((int)$cartInfo['product_type'] !== 6) {
            return 0;
        }
        return max(((int)$cartInfo['cart_num'] - (int)$reservationOrderCount - (int)$writeoffCount), 0);
    }

    /**
     * 获取预约订单：预约状态
     * @param int $oid
     * @param $orderInfo
     * @return int
     */
    public function getOrderReservationStatus(int $oid, $orderInfo = [])
    {
        /** @var StoreOrderServices $orderServices */
        $orderServices = app()->make(StoreOrderServices::class);
        if (!$orderInfo) {
            $orderInfo = $orderServices->get($oid);
        }
        if (!$orderInfo) {
            throw new ValidateException('获取订单信息失败!');
        }
        //一种可预约数量
        /** @var StoreOrderCartInfoServices $cartServices */
        $cartServices = app()->make(StoreOrderCartInfoServices::class);
        $reservationNum = $cartServices->sum(['oid' => $oid, 'product_type' => 6, 'cart_type' => 0], 'cart_num');
        //已经预约数量
        $unServiceCount = (int)$this->dao->count(['oid' => $oid, 'is_del' => 0, 'is_system_del' => 0, 'status' => [0, 3]]);
        $inServiceCount = (int)$this->dao->count(['oid' => $oid, 'is_del' => 0, 'is_system_del' => 0, 'status' => 1]);
        $completeCount = (int)$this->dao->count(['oid' => $oid, 'is_del' => 0, 'is_system_del' => 0, 'status' => 2]);
        if (($unServiceCount + $inServiceCount + $completeCount) < $reservationNum) {//存在还未预约
            $reservationStatus = -1;
        } else {
            if ($unServiceCount) {//还有待服务
                $reservationStatus = 0;
            } elseif ($inServiceCount) {//还有服务中
                $reservationStatus = 1;
            } else {
                $reservationStatus = 2;
            }
        }
        return $reservationStatus;
    }

    /**
     * 取消预约
     * @param int $uid
     * @param int $id
     * @param bool $is_check_cancel 收银台操作，不验证取消时间
     * @return bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function cancelReservationOrder(int $uid, int $id, bool $is_check_cancel = true)
    {
        $where = ['id' => $id, 'is_del' => 0, 'is_system_del' => 0];
        if ($uid) $where['uid'] = $uid;
        $reservationOrderInfo = $this->dao->get($where, ['*'], ['cartInfo']);
        if (!$reservationOrderInfo) {
            throw new ValidateException('预约单已取消或删除!');
        }
        // 仅待确认状态可取消（不校验商品「允许取消预约」配置）
        if ((int)$reservationOrderInfo['status'] !== 3) {
            throw new ValidateException($reservationOrderInfo['status'] == -1 ? '预约单已取消!' : '预约单已开始（完成）不可取消');
        }
        $this->dao->update($id, ['status' => -1]);
        $oid = (int)$reservationOrderInfo['oid'];
        $isPurchased = $oid > 0 && (int)$reservationOrderInfo['cart_info_id'] > 0;
        if ($isPurchased) {
            $this->restorePurchasedReservationQuota($reservationOrderInfo->toArray());
        }
        return true;
    }

    /**
     * 删除已取消预约单
     * @param int $uid
     * @param int $id
     * @return bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function delReservationOrder(int $uid, int $id)
    {
        $where = ['id' => $id];
        if ($uid) $where['uid'] = $uid;
        $reservationOrderInfo = $this->dao->get($where, ['*'], ['cartInfo']);
        if (!$reservationOrderInfo) {
            throw new ValidateException('获取预约单失败!');
        }
        //待服务状态才可以取消
        if ($reservationOrderInfo['status'] != -1) {
            throw new ValidateException('请先取消预约单');
        }
        $this->dao->update($id, ['is_del' => 1]);
        return true;
    }

    /**
     * 设置预约单服务状态
     * @param int $uid
     * @param int $id
     * @param int $status
     * @param int $staff_id
     * @param array $appendData
     * @return bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function setServiceStatus(int $uid, int $id, int $status = 1, int $staff_id = 0, array $appendData = [])
    {
        if (!in_array($status, [1, 2])) {
            throw new ValidateException('预约状态错误');
        }
        $where = ['id' => $id];
        if ($uid) $where['uid'] = $uid;
        $reservationOrderInfo = $this->dao->get($where, ['*'], ['cartInfo']);
        if (!$reservationOrderInfo) {
            throw new ValidateException('获取预约单失败!');
        }
        if (in_array($reservationOrderInfo['status'], [-1, 2, 4])) {
            throw new ValidateException($reservationOrderInfo['status'] == -1 ? '预约单已取消!' : ($reservationOrderInfo['status'] == 4 ? '预约已退回!' : '预约单已完成'));
        }
        if ($status == 1 && $reservationOrderInfo['status'] == 3) {
            throw new ValidateException('预约待确认，请先确认预约');
        }
        if ($status == 1 && $reservationOrderInfo['status'] != 0) {
            throw new ValidateException('预约单状态异常，无法开始服务');
        }
        $reservationRow = $reservationOrderInfo->toArray();
        $oid = (int)$reservationRow['oid'];
        $isPurchased = $this->isPurchasedReservation($reservationRow);
        if ($status == 2 && !$isPurchased) {
            $currentStatus = (int)$reservationRow['status'];
            if ($currentStatus === 2) {
                throw new ValidateException('预约单已完成');
            }
            if ($currentStatus !== 1) {
                throw new ValidateException($currentStatus === 0 ? '请先开始服务' : '预约单状态异常，无法完成');
            }
            $update = ['status' => 2, 'service_end_time' => time()];
            if ($staff_id) {
                $update['service_staff_id'] = $staff_id;
            }
            $syncAll = $appendData['sync_all'] ?? [];
            if (is_array($syncAll) && $syncAll) {
                $staffChoose = $this->extractStaffChooseFromSyncAll($syncAll);
                if ($staffChoose) {
                    $update['staff_choose'] = $this->encodeReservationStaffChoose($staffChoose);
                }
            }
            $this->dao->update($id, $update);
            return true;
        }
        if ($status == 2 && (int)$reservationOrderInfo['status'] !== 1) {
            throw new ValidateException((int)$reservationOrderInfo['status'] === 2 ? '预约单已完成' : '请先开始服务');
        }
        if ($isPurchased) {
            /** @var StoreOrderServices $storeOrderServices */
            $storeOrderServices = app()->make(StoreOrderServices::class);
            $orderInfo = $storeOrderServices->dao->get($oid, ['id', 'paid', 'is_del']);
            if (!$orderInfo) {
                throw new ValidateException('原订单未能查到!');
            }
            if ($orderInfo['paid'] != 1) {
                throw new ValidateException('原订单未支付');
            }
            if ($orderInfo->is_del) {
                throw new ValidateException('原订单已删除!');
            }
        }
        $cross_store_verification = (int)sys_config('cross_store_verification', 1);//跨店核销

        $update = ['status' => $status];
        if ($cross_store_verification && isset($appendData['store_id'])) {
            $update['store_id'] = $appendData['store_id'];
        }
        if (isset($appendData['store_id'])) {
            unset($appendData['store_id']);
        }
        if ($staff_id) $update['service_staff_id'] = $staff_id;
        if ($status == 1) {//开始服务
            $syncAll = $appendData['sync_all'] ?? [];
            if (is_array($syncAll) && $syncAll) {
                $staffChoose = $this->extractStaffChooseFromSyncAll($syncAll);
                if ($staffChoose) {
                    $update['staff_choose'] = $this->encodeReservationStaffChoose($staffChoose);
                }
            }
            $update['service_time'] = time();
            $this->dao->update($id, $update);
            if ($isPurchased) {
                //服务时长
                $service_span = strtotime($reservationOrderInfo['reservation_end']) - strtotime($reservationOrderInfo['reservation_start']);
                //开始服务 加入延迟队列结束服务（到时自动消耗）
                ReservationOrderJob::dispatchSece($service_span + 600, 'saveReservationOrderWriteoff', [$id]);
            }

			//分配服务人员
			if ($update['service_staff_id']) {
                $boardCartInfo = $this->resolveReservationCartInfo($reservationRow);
				/** @var SystemStoreStaffServices $staffServices */
				$staffServices = app()->make(SystemStoreStaffServices::class);
				$staffInfo = $staffServices->getOne(['id'=> $update['service_staff_id']], 'id,uid,staff_name,phone');
				event('notice.notice', [
						[
							'id' => $reservationOrderInfo['id'],
							'store_name' => $boardCartInfo['productInfo']['store_name'] ?? '',
							'reservation_time' => date('Y-m-d', $reservationOrderInfo['reservation_time']),
							'reservation_start' => $reservationOrderInfo['reservation_start'],
							'reservation_end' => $reservationOrderInfo['reservation_end'],
							'service_time' => $reservationOrderInfo['reservation_start'] . '-' . $reservationOrderInfo['reservation_end'],
							'reservation_name' => $reservationOrderInfo['reservation_name'],
							'reservation_phone' => $reservationOrderInfo['reservation_phone'],
							'reservation_address' => $reservationOrderInfo['reservation_address'],
							'phone' => $staffInfo['phone'] ?? '',
							'uid' => $staffInfo['uid'] ?? 0,
						],
					'reservation_service_reminder']);
			}
        } else {//结束服务 / 立即消耗
            $update['service_end_time'] = time();
            if ($appendData) {//服务附录数据
                if (isset($appendData['service_images'])) $appendData['service_images'] = json_encode($appendData['service_images']);
                $update = array_merge($update, $appendData);
            }
            $this->consumeReservationOrder((int)$id, $reservationOrderInfo->toArray(), $update, $appendData);
        }
        if ($isPurchased) {
            //订单预约状态
            $reservation_status = $this->getOrderReservationStatus($oid);
            /** @var StoreOrderServices $orderServices */
            $orderServices = app()->make(StoreOrderServices::class);
            $orderServices->update($oid, ['reservation_status' => $reservation_status]);
        }
        if ($status == 1) {
            $mergedRow = array_merge($reservationRow, $update);
            $this->scheduleYuyueJinduReminders($id, $mergedRow);
            return ['endDate' => $this->resolveServiceEndDate($mergedRow)];
        }
        return [];
    }

    /**
     * 预约单消耗（立即消耗 / 服务结束自动消耗）
     * @param int $id
     * @param array $reservationOrderInfo
     * @param array $update
     * @param array $appendData
     * @return void
     */
    protected function consumeReservationOrder(int $id, array $reservationOrderInfo, array $update = [], array $appendData = []): void
    {
        // 外层共同事务 + 锁预约单：核销记录、院装耗材扣料、预约完成、原订单/权益状态整体提交或回滚；
        // 任一步失败（含院装耗材不足/缺副本抛错）全部回滚，且并发/重试消耗被行锁串行化。
        $this->transaction(function () use ($id, $reservationOrderInfo, $update, $appendData) {
            $locked = Db::name('store_reservation_order')->where('id', $id)->lock(true)->find();
            if (!$locked) {
                throw new ValidateException('预约单不存在');
            }
            // 以锁定后的最新状态为准，避免并发读到旧状态重复消耗
            $reservationOrderInfo = array_merge($reservationOrderInfo, $locked);
            $this->doConsumeReservationOrder($id, $reservationOrderInfo, $update, $appendData);
        });
    }

    /**
     * 预约单消耗实体逻辑（须在 consumeReservationOrder 的外层事务 + 行锁内执行）
     */
    protected function doConsumeReservationOrder(int $id, array $reservationOrderInfo, array $update = [], array $appendData = []): void
    {
        if ((int)($reservationOrderInfo['status'] ?? 0) === 2) {
            return;
        }
        if ((int)($reservationOrderInfo['status'] ?? 0) !== 1) {
            throw new ValidateException((int)($reservationOrderInfo['status'] ?? 0) === -1 ? '预约单已取消!' : '请先开始服务!');
        }
        $oid = (int)($reservationOrderInfo['oid'] ?? 0);
        $cartInfoId = (int)($reservationOrderInfo['cart_info_id'] ?? 0);
        $isPurchased = $oid > 0 && $cartInfoId > 0;
        if (!$isPurchased) {
            if (empty($update['service_end_time'])) {
                $update['service_end_time'] = time();
            }
            $update['status'] = 2;
            unset($update['sync_all']);
            $this->dao->update($id, $update);
            return;
        }
        /** @var StoreOrderWriteOffServices $storeOrderWriteoffServices */
        $storeOrderWriteoffServices = app()->make(StoreOrderWriteOffServices::class);
        /** @var StoreOrderCartInfoServices $cartServices */
        $cartServices = app()->make(StoreOrderCartInfoServices::class);
        $cartInfo = $cartServices->getOne(['oid' => $oid, 'id' => $cartInfoId]);
        if (!$cartInfo) {
            throw new ValidateException('预约关联商品不存在');
        }
        $cartInfo = is_object($cartInfo) ? $cartInfo->toArray() : (array)$cartInfo;
        $price = bcdiv((string)$cartInfo['pay_price'], (string)max((int)$cartInfo['write_times'], 1), 2);
        if ($storeOrderWriteoffServices->get(['oid' => $oid, 'reservation_oid' => $id])) {
            $writeoff = StoreOrderWriteoff::where('oid', $oid)->where('reservation_oid', $id)->order('id desc')->find();
            if ($writeoff) {
                $writeoffRow = is_array($writeoff) ? $writeoff : $writeoff->toArray();
                $storeOrderWriteoffServices->ensureWriteoffSubOrder(
                    (int)$writeoffRow['id'],
                    $oid,
                    $writeoffRow,
                    [
                        'store_id' => (int)($reservationOrderInfo['store_id'] ?? 0),
                        'price' => $price,
                    ],
                    (int)($appendData['is_auto'] ?? 0)
                );
            }
            if (empty($update['service_end_time'])) {
                $update['service_end_time'] = time();
            }
            $update['status'] = 2;
            unset($update['sync_all']);
            $this->dao->update($id, $update);
            return;
        }
        $syncAll = $appendData['sync_all'] ?? [];
        if (!is_array($syncAll)) {
            $syncAll = [];
        }
        if (!$syncAll) {
            $syncAll = $this->buildReservationSyncAllFromStoredStaff($reservationOrderInfo);
        }
        if ($syncAll) {
            $syncAll = $this->normalizeReservationSyncAll($syncAll, $cartInfo, $oid, $price);
            foreach ($syncAll as &$syncRow) {
                if (!is_array($syncRow)) {
                    continue;
                }
                $syncRow['cart_id'] = $cartInfo['cart_id'];
                $syncRow['order_id'] = $oid;
                if (empty($syncRow['type'])) {
                    $syncRow['type'] = 3;
                }
            }
            unset($syncRow);
        }
        $staffId = (int)($update['service_staff_id'] ?? $reservationOrderInfo['service_staff_id'] ?? 0);
        $storeId = (int)($reservationOrderInfo['store_id'] ?? 0);
        if (!$storeId) {
            $storeId = (int)($update['store_id'] ?? 0);
        }
        $isAuto = (int)($appendData['is_auto'] ?? 0);
        $cartIds = [['cart_id' => $cartInfo['cart_id'], 'cart_num' => 1]];
        $writeoffData = [
            'staff_id' => $staffId,
            'price' => $price,
            'store_id' => $storeId,
            'sync_all' => $syncAll,
            'is_auto' => $isAuto,
        ];
        // 预约消耗：预约时已扣次，此处仅生成核销记录/业绩，不再扣减 write_surplus_times
        $storeOrderWriteoffServices->saveWriteOff($oid, $id, $cartIds, $writeoffData);
        $writeoff = StoreOrderWriteoff::where('oid', $oid)->where('reservation_oid', $id)->order('id desc')->find();
        if ($writeoff) {
            $writeoffRow = is_array($writeoff) ? $writeoff : $writeoff->toArray();
            $storeOrderWriteoffServices->ensureWriteoffSubOrder(
                (int)$writeoffRow['id'],
                $oid,
                $writeoffRow,
                $writeoffData,
                $isAuto
            );
        }
        $this->afterReservationConsumeOrder($oid, $reservationOrderInfo, $cartInfo);
        OrderStatusJob::dispatch([$oid, 'writeoff', ['change_message' => '预约单消耗完成']]);
        if (empty($update['service_end_time'])) {
            $update['service_end_time'] = time();
        }
        $update['status'] = 2;
        unset($update['sync_all']);
        $this->dao->update($id, $update);
    }

    /**
     * 预约消耗后：更新原订单/卡项状态（不重复扣减剩余次数）
     */
    protected function afterReservationConsumeOrder(int $oid, array $reservationOrderInfo, array $cartInfo): void
    {
        /** @var StoreOrderCartInfoServices $cartServices */
        $cartServices = app()->make(StoreOrderCartInfoServices::class);
        /** @var StoreOrderWriteOffServices $storeOrderWriteoffServices */
        $storeOrderWriteoffServices = app()->make(StoreOrderWriteOffServices::class);
        /** @var StoreOrderServices $storeOrderServices */
        $storeOrderServices = app()->make(StoreOrderServices::class);
        $orderInfo = $storeOrderServices->get($oid);
        if (!$orderInfo) {
            return;
        }
        $orderInfo = is_object($orderInfo) ? $orderInfo->toArray() : (array)$orderInfo;
        if ((int)$orderInfo['type'] === 11) {
            $writeoffSum = $cartServices->sum(['oid' => $oid, 'cart_type' => 2], 'write_surplus_times');
            if ($writeoffSum <= 0) {
                $cartServices->update(['oid' => $oid, 'cart_type' => 0], ['is_writeoff' => 1, 'write_surplus_times' => 0]);
            }
            /** @var UserCardHolderServices $holderServices */
            $holderServices = app()->make(UserCardHolderServices::class);
            $holderServices->update(['oid' => $oid], ['write_surplus_times' => $writeoffSum]);
        }
        $writeoffCount = $storeOrderWriteoffServices->sum(
            ['oid' => $oid, 'order_cart_id' => (int)$reservationOrderInfo['cart_info_id']],
            'writeoff_num'
        );
        if ($writeoffCount >= (int)$cartInfo['write_times']) {
            $cartServices->update((int)$reservationOrderInfo['cart_info_id'], ['is_writeoff' => 1, 'write_surplus_times' => 0]);
        }
        $orderUpdate = [];
        if (!$cartServices->count(['oid' => $oid, 'is_writeoff' => 0])) {
            $orderUpdate['status'] = 2;
            $orderUpdate['delivery_time'] = time();
            /** @var StoreOrderTakeServices $storeOrderTakeServices */
            $storeOrderTakeServices = app()->make(StoreOrderTakeServices::class);
            $storeOrderTakeServices->storeProductOrderUserTakeDelivery($orderInfo, false);
        } else {
            $orderUpdate['status'] = 5;
        }
        $orderUpdate['reservation_status'] = $this->getOrderReservationStatus($oid, $orderInfo);
        $storeOrderServices->update($oid, $orderUpdate);
    }

    /**
     * 记录预约单核销，修改原订单状态
     * @param int $id
     * @param array $update
     * @return bool
     */
    public function saveReservationOrderWriteoff(int $id, array $update = [])
    {
        $reservationOrderInfo = $this->dao->get($id, ['*']);
        if (!$reservationOrderInfo) {
            throw new ValidateException('获取预约单失败!');
        }
        $reservationOrderInfo = $reservationOrderInfo->toArray();
        if ((int)($reservationOrderInfo['status'] ?? 0) === 2) {
            return true;
        }
        try {
            $this->consumeReservationOrder($id, $reservationOrderInfo, $update, ['is_auto' => 1]);
        } catch (ValidateException $e) {
            if ((int)($reservationOrderInfo['status'] ?? 0) === 2) {
                return true;
            }
            throw $e;
        }
        $oid = (int)($reservationOrderInfo['oid'] ?? 0);
        if ($oid > 0) {
            $reservation_status = $this->getOrderReservationStatus($oid);
            /** @var StoreOrderServices $orderServices */
            $orderServices = app()->make(StoreOrderServices::class);
            $orderServices->update($oid, ['reservation_status' => $reservation_status]);
        }
        return true;
    }

    /**
     * 收银台预约：获取用户可预约的已购项目（与消耗页「有效卡」列表及顺序一致）
     * @param int $uid
     * @param int $storeId
     * @return array
     */
    public function getUserPurchasedRemainItems(int $uid, int $storeId = 0): array
    {
        if (!$uid) {
            return ['list' => []];
        }
        $crossStoreVerification = (int)sys_config('cross_store_verification', 1);
        // 与 Order::getVerifyList + search_type=2（有效卡）完全相同的订单筛选
        $where = [
            'uid' => $uid,
            'paid' => 1,
            'is_del' => 0,
            'is_system_del' => 0,
            'is_user_del' => 0,
            'type' => 105,
            'pid' => 0,
            'search_type' => 2,
        ];
        if (!$crossStoreVerification && $storeId > 0) {
            $where['store_id'] = $storeId;
        }
        /** @var StoreOrderDao $orderDao */
        $orderDao = app()->make(StoreOrderDao::class);
        $orders = $orderDao->getOrderList(
            $where,
            ['id', 'order_id', 'type', 'product_type', 'store_id', 'status', 'refund_status', 'pay_price', 'card_upgrade_use_oid', 'add_time'],
            0,
            0,
            [],
            'add_time DESC,id DESC'
        );
        if (!$orders) {
            return ['list' => []];
        }
        /** @var WriteOffOrderServices $writeOffOrderServices */
        $writeOffOrderServices = app()->make(WriteOffOrderServices::class);
        $list = [];
        foreach ($orders as $order) {
            if ((int)($order['card_upgrade_use_oid'] ?? 0) > 0) {
                continue;
            }
            $writeOffOrderServices->syncOrderWriteoffConsistency($order);
            $orderInfo = $writeOffOrderServices->getOrderCartInfo(0, (int)$order['id']);
            $cartRows = $orderInfo['cart_info'] ?? [];
            if (!$cartRows) {
                continue;
            }
            $isCardOrder = (int)($order['type'] ?? 0) === 11 || (int)($order['product_type'] ?? 0) === 5;
            $cardName = '';
            if ($isCardOrder) {
                $mainCart = StoreOrderCartInfo::where('oid', (int)$order['id'])
                    ->where('cart_type', 0)
                    ->field('cart_info')
                    ->find();
                $cardName = $this->parseCartProductName($mainCart['cart_info'] ?? '');
            }
            $validItems = [];
            foreach ($cartRows as $item) {
                if (!$this->isVerifyListItem($item)) {
                    continue;
                }
                $pinfo = $item['cart_info']['productInfo'] ?? [];
                $liveProductId = (int)($item['product_id'] ?? 0);
                if ($liveProductId && (int)($item['product_type'] ?? 0) === 6) {
                    /** @var StoreProductServices $productServices */
                    $productServices = app()->make(StoreProductServices::class);
                    /** @var StoreProductReservationServices $productReservationServices */
                    $productReservationServices = app()->make(StoreProductReservationServices::class);
                    $liveProduct = $productServices->getOne(['id' => $liveProductId], 'pid,project_service_duration,addon_service_duration');
                    if ($liveProduct) {
                        $liveProduct = $productReservationServices->fillServiceDuration($liveProduct->toArray());
                        $pinfo['project_service_duration'] = (int)($liveProduct['project_service_duration'] ?? 0);
                        $pinfo['addon_service_duration'] = (int)($liveProduct['addon_service_duration'] ?? 0);
                        $item['project_service_duration'] = $pinfo['project_service_duration'];
                        $item['addon_service_duration'] = $pinfo['addon_service_duration'];
                        if (isset($item['cart_info']['productInfo'])) {
                            $item['cart_info']['productInfo'] = $pinfo;
                        }
                    }
                }
                $projectName = $pinfo['store_name'] ?? '';
                if ((int)($item['is_gift'] ?? 0) === 1) {
                    $projectName = '赠送' . $projectName;
                }
                $item['display_name'] = ($isCardOrder && $cardName)
                    ? ($projectName . '（' . $cardName . '）')
                    : $projectName;
                $validItems[] = $item;
            }
            if ($validItems) {
                $list[] = [
                    'order' => $order,
                    'cart_info' => $validItems,
                ];
            }
        }
        return ['list' => $list];
    }

    /**
     * 消耗页有效卡明细展示口径（与 verify userOrder 表格行一致，含已消耗项）
     * @param array $item
     * @return bool
     */
    protected function isVerifyListItem(array $item): bool
    {
        if ((int)($item['product_type'] ?? 0) === 0) {
            return false;
        }
        return true;
    }

    /**
     * 解析订单商品名称
     * @param mixed $cartInfo
     * @return string
     */
    protected function parseCartProductName($cartInfo): string
    {
        $_info = is_string($cartInfo) ? json_decode($cartInfo, true) : ($cartInfo ?? []);
        return $_info['productInfo']['store_name'] ?? '';
    }

}
