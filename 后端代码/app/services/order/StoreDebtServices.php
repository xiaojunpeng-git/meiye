<?php

namespace app\services\order;

use app\dao\order\StoreDebtDao;
use app\dao\order\StoreDebtItemDao;
use app\dao\order\StoreDebtRepayDao;
use app\jobs\order\OrderStatusJob;
use app\model\order\StoreDebt;
use app\model\order\StoreOrder;
use app\model\order\StoreOrderTerminalOperation;
use app\model\user\UserCardHolder;
use app\model\yeji\CashType;
use app\model\yeji\StaffYeji;
use app\services\BaseServices;
use app\services\pay\PayServices;
use app\services\store\SystemStoreServices;
use app\services\store\SystemStoreStaffServices;
use app\services\user\UserMoneyServices;
use app\dao\user\UserRechargeDao;
use app\services\user\UserServices;
use app\services\wechat\WechatUserServices;
use app\services\order\ValidCashOrderServices;
use think\exception\ValidateException;
use think\facade\Cache;
use think\facade\Db;

class StoreDebtServices extends BaseServices
{
    /** @var StoreDebtItemDao */
    protected $itemDao;

    /** @var StoreDebtRepayDao */
    protected $repayDao;

    public function __construct(StoreDebtDao $dao, StoreDebtItemDao $itemDao, StoreDebtRepayDao $repayDao)
    {
        $this->dao = $dao;
        $this->itemDao = $itemDao;
        $this->repayDao = $repayDao;
    }

    public function generateDebtNo(): string
    {
        return 'Q' . date('YmdHis') . substr((string)mt_rand(100000, 999999), 0, 6);
    }

    public function generateRepayNo(): string
    {
        return 'HK' . date('YmdHis') . substr((string)mt_rand(100000, 999999), 0, 6);
    }

    public function statusLabel(int $status): string
    {
        $map = [
            StoreDebt::STATUS_PENDING => '待还款',
            StoreDebt::STATUS_SETTLED => '已结清',
            StoreDebt::STATUS_CLOSED => '已关闭',
            StoreDebt::STATUS_VOID => '已作废',
        ];
        return $map[$status] ?? '未知';
    }

    /**
     * 统一解析 Unix 时间戳（兼容 ORM 已格式化的日期字符串）
     */
    protected function normalizeUnixTime($value): int
    {
        if ($value === null || $value === '' || $value === false) {
            return 0;
        }
        if (is_numeric($value)) {
            $num = (int)$value;
            return $num > 1000000000 ? $num : 0;
        }
        if (is_string($value)) {
            $ts = strtotime($value);
            return $ts !== false ? (int)$ts : 0;
        }
        return 0;
    }

    /**
     * 解析订单对应收银员（门店店员ID）
     */
    protected function resolveOrderStaffId(int $orderId, int $fallbackStaffId = 0): int
    {
        if ($fallbackStaffId > 0) {
            return $fallbackStaffId;
        }
        if (!$orderId) {
            return 0;
        }
        /** @var StoreOrderServices $orderServices */
        $orderServices = app()->make(StoreOrderServices::class);
        $order = $orderServices->get($orderId, ['staff_id', 'clerk_id', 'store_id']);
        if (!$order) {
            return 0;
        }
        $order = is_array($order) ? $order : $order->toArray();
        $staffId = (int)($order['staff_id'] ?? 0);
        if ($staffId > 0) {
            return $staffId;
        }
        $clerkId = (int)($order['clerk_id'] ?? 0);
        $storeId = (int)($order['store_id'] ?? 0);
        if ($clerkId > 0 && $storeId > 0) {
            /** @var SystemStoreStaffServices $staffServices */
            $staffServices = app()->make(SystemStoreStaffServices::class);
            $staffId = (int)$staffServices->value([
                'uid' => $clerkId,
                'store_id' => $storeId,
                'is_del' => 0,
            ], 'id');
        }
        return $staffId;
    }

    protected function resolveStaffNameById(int $staffId): string
    {
        if ($staffId <= 0) {
            return '';
        }
        /** @var SystemStoreStaffServices $staffServices */
        $staffServices = app()->make(SystemStoreStaffServices::class);
        $name = $staffServices->value(['id' => $staffId, 'is_del' => 0], 'staff_name');
        return $name ? (string)$name : '';
    }

    protected function resolveStaffDisplayName(int $staffId, int $orderId = 0): string
    {
        $name = $this->resolveStaffNameById($staffId);
        if ($name !== '') {
            return $name;
        }
        if ($orderId > 0) {
            $resolvedId = $this->resolveOrderStaffId($orderId, $staffId);
            return $this->resolveStaffNameById($resolvedId);
        }
        return '';
    }

    /**
     * 用户欠款/还款涉及门店（筛选用）
     */
    public function getFilterStoreList(int $uid): array
    {
        /** @var SystemStoreServices $storeServices */
        $storeServices = app()->make(SystemStoreServices::class);
        $stores = $storeServices->getColumn(['is_show' => 1, 'is_del' => 0], 'name', 'id') ?: [];
        if ($uid > 0) {
            $debtStoreIds = $this->dao->search(['uid' => $uid])->column('store_id') ?: [];
            $repayRows = $this->repayDao->search(['uid' => $uid])->field('debt_store_id,pay_store_id')->select()->toArray();
            $extraIds = array_unique(array_filter(array_merge(
                $debtStoreIds,
                array_column($repayRows, 'debt_store_id'),
                array_column($repayRows, 'pay_store_id')
            )));
            $missingIds = array_values(array_diff($extraIds, array_map('intval', array_keys($stores))));
            if ($missingIds) {
                $extraStores = $storeServices->getColumn([['id', 'in', $missingIds]], 'name', 'id') ?: [];
                foreach ($extraStores as $id => $name) {
                    $stores[$id] = $name;
                }
            }
        }
        if (!$stores) {
            return [];
        }
        $list = [];
        foreach ($stores as $id => $name) {
            $list[] = ['id' => (int)$id, 'name' => (string)$name];
        }
        usort($list, static function ($a, $b) {
            return $a['id'] <=> $b['id'];
        });
        return $list;
    }

    /**
     * 订单支付成功后创建欠款记录
     */
    public function createFromPaidOrder(array $orderInfo): void
    {
        $orderId = (int)($orderInfo['id'] ?? 0);
        $debtAmount = (float)($orderInfo['debt_amount'] ?? 0);
        if (!$orderId || $debtAmount <= 0) {
            return;
        }
        if ($this->dao->be(['order_id' => $orderId])) {
            return;
        }
        $staffId = $this->resolveOrderStaffId($orderId, (int)($orderInfo['staff_id'] ?? 0));
        if (!$staffId) {
            $req = app()->request;
            if ($req->hasMacro('cashierId') && $req->cashierId()) {
                $staffId = (int)$req->cashierId();
            }
        }
        $now = time();
        $debtModel = $this->dao->save([
            'debt_no' => $this->generateDebtNo(),
            'order_id' => $orderId,
            'order_sn' => (string)($orderInfo['order_id'] ?? ''),
            'uid' => (int)($orderInfo['uid'] ?? 0),
            'store_id' => (int)($orderInfo['store_id'] ?? 0),
            'staff_id' => $staffId,
            'total_debt' => $debtAmount,
            'repaid_debt' => 0,
            'status' => StoreDebt::STATUS_PENDING,
            'remark' => '',
            'add_time' => $now,
            'update_time' => $now,
        ]);
        $debtId = (int)$debtModel->id;
        /** @var StoreOrderCartInfoServices $cartServices */
        $cartServices = app()->make(StoreOrderCartInfoServices::class);
        $cartList = $cartServices->getSplitCartList($orderId, '*', 'id');
        $items = [];
        if ($cartList) {
            foreach ($cartList as $row) {
                $lineDebt = (float)($row['debt_amount'] ?? 0);
                if ($lineDebt <= 0) {
                    continue;
                }
                $cartInfo = is_string($row['cart_info'] ?? '') ? json_decode($row['cart_info'], true) : ($row['cart_info'] ?? []);
                $items[] = [
                    'debt_id' => $debtId,
                    'order_id' => $orderId,
                    'cart_info_id' => (int)($row['id'] ?? 0),
                    'product_id' => (int)($row['product_id'] ?? 0),
                    'product_type' => (int)($row['product_type'] ?? 0),
                    'product_name' => (string)($cartInfo['productInfo']['store_name'] ?? ''),
                    'cart_num' => (int)($row['cart_num'] ?? 1),
                    'debt_amount' => $lineDebt,
                    'repaid_debt' => 0,
                    'add_time' => $now,
                    'update_time' => $now,
                ];
            }
        }
        if (!$items) {
            $productName = (int)($orderInfo['order_type'] ?? 0) === 1 ? '储值充值' : '订单欠款';
            $items[] = [
                'debt_id' => $debtId,
                'order_id' => $orderId,
                'cart_info_id' => 0,
                'product_id' => 0,
                'product_type' => 0,
                'product_name' => $productName,
                'cart_num' => 1,
                'debt_amount' => $debtAmount,
                'repaid_debt' => 0,
                'add_time' => $now,
                'update_time' => $now,
            ];
        }
        if ($items) {
            $this->itemDao->saveAll($items);
        }
    }

    public function getUserPendingSummary(int $uid): array
    {
        if (!$uid) {
            return ['total_pending' => 0, 'count' => 0];
        }
        $list = $this->dao->search([
            'uid' => $uid,
            'status' => StoreDebt::STATUS_PENDING,
        ])->field('total_debt,repaid_debt')->select()->toArray();
        $total = '0.00';
        $count = 0;
        foreach ($list as $row) {
            $pending = bcsub((string)($row['total_debt'] ?? 0), (string)($row['repaid_debt'] ?? 0), 2);
            if (bccomp($pending, '0', 2) > 0) {
                $total = bcadd($total, $pending, 2);
                $count++;
            }
        }
        return ['total_pending' => (float)$total, 'count' => $count];
    }

    public function getUserReminderList(int $uid, int $page = 1, int $limit = 5): array
    {
        $where = ['uid' => $uid, 'status' => StoreDebt::STATUS_PENDING];
        $query = $this->dao->search($where)->order('id desc');
        $count = (int)$query->count();
        $list = $query->page($page, $limit)->select()->toArray();
        return ['list' => $this->formatDebtList($list), 'count' => $count];
    }

    /**
     * 收银台欠款提醒：按明细行展示
     */
    public function getUserReminderItemList(int $uid, int $page = 1, int $limit = 5): array
    {
        if (!$uid) {
            return ['list' => [], 'count' => 0, 'total_pending' => 0];
        }
        $debts = $this->dao->search(['uid' => $uid, 'status' => StoreDebt::STATUS_PENDING])->order('id desc')->select()->toArray();
        if (!$debts) {
            return ['list' => [], 'count' => 0, 'total_pending' => 0];
        }
        $debtMap = [];
        $debtIds = [];
        foreach ($debts as $row) {
            $debtMap[$row['id']] = $row;
            $debtIds[] = $row['id'];
        }
        /** @var SystemStoreServices $storeServices */
        $storeServices = app()->make(SystemStoreServices::class);
        $storeIds = array_unique(array_column($debts, 'store_id'));
        $storeMap = $storeIds ? $storeServices->getColumn([['id', 'in', $storeIds]], 'name', 'id') : [];
        /** @var StoreOrderServices $orderServices */
        $orderServices = app()->make(StoreOrderServices::class);
        $orderIds = array_unique(array_filter(array_column($debts, 'order_id')));
        $sourceMap = $orderIds ? $orderServices->getColumn([['id', 'in', $orderIds]], 'source', 'id') : [];
        $items = $this->itemDao->search(['debt_id' => $debtIds])->order('id desc')->select()->toArray();
        $rows = [];
        $totalPending = '0.00';
        foreach ($items as $item) {
            $pending = bcsub((string)($item['debt_amount'] ?? 0), (string)($item['repaid_debt'] ?? 0), 2);
            if (bccomp($pending, '0', 2) <= 0) {
                continue;
            }
            $debt = $debtMap[$item['debt_id']] ?? [];
            $totalPending = bcadd($totalPending, $pending, 2);
            $rows[] = [
                'id' => (int)$item['id'],
                'debt_id' => (int)$item['debt_id'],
                'debt_item_id' => (int)$item['id'],
                'order_id' => (int)($debt['order_id'] ?? 0),
                'order_sn' => $debt['order_sn'] ?? '',
                'store_id' => (int)($debt['store_id'] ?? 0),
                'store_name' => $storeMap[$debt['store_id'] ?? 0] ?? '',
                'product_name' => $item['product_name'] ?? '',
                'product_id' => (int)($item['product_id'] ?? 0),
                'product_type' => (int)($item['product_type'] ?? 0),
                'pending_debt' => (float)$pending,
                'debt_amount' => (float)($item['debt_amount'] ?? 0),
                'repaid_debt' => (float)($item['repaid_debt'] ?? 0),
                'add_time' => $this->normalizeUnixTime($debt['add_time'] ?? 0),
                'add_time_label' => ($t = $this->normalizeUnixTime($debt['add_time'] ?? 0)) ? date('Y-m-d H:i', $t) : '',
                'original_source' => (int)($sourceMap[$debt['order_id'] ?? 0] ?? 0),
            ];
        }
        $count = count($rows);
        $offset = max(0, ($page - 1) * $limit);
        return [
            'list' => array_slice($rows, $offset, $limit),
            'count' => $count,
            'total_pending' => (float)$totalPending,
        ];
    }

    public function getAdminList(array $where, int $page = 1, int $limit = 20): array
    {
        $query = $this->dao->search($where)->order('id desc');
        $count = (int)$query->count();
        $list = $query->page($page, $limit)->select()->toArray();
        return ['list' => $this->formatDebtList($list, true, true), 'count' => $count];
    }

    public function getUserDebtList(int $uid, int $storeId = 0, int $page = 1, int $limit = 20): array
    {
        $where = ['uid' => $uid];
        if ($storeId > 0) {
            $where['store_id'] = $storeId;
        }
        return $this->getAdminList($where, $page, $limit);
    }

    public function getUserRepayList(int $uid, int $storeId = 0, int $page = 1, int $limit = 20): array
    {
        if ($uid <= 0) {
            return ['list' => [], 'count' => 0];
        }
        $where = ['uid' => $uid];
        if ($storeId > 0) {
            $where['store_filter'] = $storeId;
        }
        return $this->getRepayList($where, $page, $limit);
    }

    /**
     * 按订单ID获取欠款详情（含明细）
     */
    public function getDetailByOrderId(int $orderId): ?array
    {
        if (!$orderId) {
            return null;
        }
        $debt = $this->dao->get(['order_id' => $orderId]);
        if (!$debt) {
            return null;
        }
        $row = is_array($debt) ? $debt : $debt->toArray();
        $list = $this->formatDebtList([$row], true, false);
        return $list[0] ?? null;
    }

    public function orderHasPendingDebt(int $orderId): bool
    {
        return $this->resolveOrderActivePendingAmount($orderId) > 0;
    }

    /**
     * 订单有效待还欠款（排除已关闭/已作废/已结清）
     */
    public function resolveOrderActivePendingAmount(int $orderId, array $orderInfo = []): float
    {
        if (!$orderId) {
            return 0.0;
        }
        $debt = $this->dao->get(['order_id' => $orderId]);
        if ($debt) {
            $debtArr = is_array($debt) ? $debt : $debt->toArray();
            return $this->calcOrderActivePendingAmount($debtArr, $orderInfo);
        }
        return $this->calcOrderActivePendingAmount(null, $orderInfo);
    }

    /**
     * 批量计算订单有效待还欠款
     * @return array<int, float> order_id => pending_amount
     */
    public function resolveOrderActivePendingAmountMap(array $orders): array
    {
        if (!$orders) {
            return [];
        }
        $orderInfoMap = [];
        $orderIds = [];
        foreach ($orders as $order) {
            $orderId = (int)($order['id'] ?? 0);
            if ($orderId <= 0) {
                continue;
            }
            $orderIds[] = $orderId;
            $orderInfoMap[$orderId] = $order;
        }
        if (!$orderIds) {
            return [];
        }
        $orderIds = array_unique($orderIds);
        $debtMap = [];
        $debtRows = $this->dao->selectList([['order_id', 'in', $orderIds]], 'order_id,status,total_debt,repaid_debt');
        foreach ($debtRows as $row) {
            $debtMap[(int)($row['order_id'] ?? 0)] = $row;
        }
        $result = [];
        foreach ($orderIds as $orderId) {
            $result[$orderId] = $this->calcOrderActivePendingAmount($debtMap[$orderId] ?? null, $orderInfoMap[$orderId] ?? []);
        }
        return $result;
    }

    /**
     * 根据欠款记录/订单信息计算有效待还欠款
     */
    protected function calcOrderActivePendingAmount(?array $debtArr, array $orderInfo = []): float
    {
        if ($debtArr) {
            if ((int)($debtArr['status'] ?? 0) !== StoreDebt::STATUS_PENDING) {
                return 0.0;
            }
            $pending = bcsub((string)($debtArr['total_debt'] ?? 0), (string)($debtArr['repaid_debt'] ?? 0), 2);
            return bccomp($pending, '0', 2) > 0 ? (float)$pending : 0.0;
        }
        if (!$orderInfo) {
            return 0.0;
        }
        $orderPending = bcsub((string)($orderInfo['debt_amount'] ?? 0), (string)($orderInfo['repaid_debt_amount'] ?? 0), 2);
        return bccomp($orderPending, '0', 2) > 0 ? (float)$orderPending : 0.0;
    }

    /**
     * 订单退款成功后作废关联欠款
     */
    public function voidDebtByOrderId(int $orderId, string $remark = ''): void
    {
        if (!$orderId) {
            return;
        }
        $debt = $this->dao->get(['order_id' => $orderId]);
        if (!$debt) {
            return;
        }
        $debtArr = is_array($debt) ? $debt : $debt->toArray();
        $status = (int)($debtArr['status'] ?? 0);
        if ($status === StoreDebt::STATUS_VOID) {
            return;
        }
        $update = [
            'status' => StoreDebt::STATUS_VOID,
            'update_time' => time(),
        ];
        if ($remark !== '') {
            $update['remark'] = $remark;
        }
        $this->dao->update((int)$debtArr['id'], $update);
    }

    /**
     * 核销补交：获取订单待还欠款明细
     */
    public function getOrderRepayItems(int $orderId): array
    {
        $detail = $this->getDetailByOrderId($orderId);
        if (!$detail || (int)($detail['status'] ?? 0) !== StoreDebt::STATUS_PENDING) {
            return ['debt_id' => 0, 'order_id' => $orderId, 'order_sn' => '', 'uid' => 0, 'items' => []];
        }
        /** @var StoreOrderServices $orderServices */
        $orderServices = app()->make(StoreOrderServices::class);
        $source = (int)$orderServices->value(['id' => $orderId], 'source');
        $items = [];
        foreach ($detail['items'] ?? [] as $item) {
            if ((float)($item['pending_debt'] ?? 0) <= 0) {
                continue;
            }
            $item['debt_item_id'] = (int)($item['id'] ?? 0);
            $item['original_source'] = $source;
            $items[] = $item;
        }
        return [
            'debt_id' => (int)($detail['id'] ?? 0),
            'order_id' => $orderId,
            'order_sn' => (string)($detail['order_sn'] ?? ''),
            'uid' => (int)($detail['uid'] ?? 0),
            'items' => $items,
        ];
    }

    protected function formatDebtList(array $list, bool $withItems = false, bool $withUser = false): array
    {
        if (!$list) {
            return [];
        }
        /** @var SystemStoreServices $storeServices */
        $storeServices = app()->make(SystemStoreServices::class);
        $storeIds = array_unique(array_column($list, 'store_id'));
        $storeMap = $storeIds ? $storeServices->getColumn([['id', 'in', $storeIds]], 'name', 'id') : [];
        $orderIds = array_unique(array_filter(array_column($list, 'order_id')));
        $orderStaffMap = [];
        $sourceMap = [];
        if ($orderIds) {
            /** @var StoreOrderServices $orderServices */
            $orderServices = app()->make(StoreOrderServices::class);
            $orderStaffMap = $orderServices->getColumn([['id', 'in', $orderIds]], 'staff_id', 'id') ?: [];
            $sourceMap = $orderServices->getColumn([['id', 'in', $orderIds]], 'source', 'id') ?: [];
        }
        $resolvedStaffIds = array_unique(array_filter(array_merge(
            array_column($list, 'staff_id'),
            array_values($orderStaffMap)
        )));
        foreach ($list as $row) {
            $staffId = (int)($row['staff_id'] ?? 0);
            if (!$staffId && !empty($row['order_id'])) {
                $staffId = (int)($orderStaffMap[$row['order_id']] ?? 0);
            }
            if (!$staffId && !empty($row['order_id'])) {
                $staffId = $this->resolveOrderStaffId((int)$row['order_id'], 0);
            }
            if ($staffId) {
                $resolvedStaffIds[] = $staffId;
            }
        }
        $staffIds = array_unique(array_filter($resolvedStaffIds));
        $staffMap = [];
        if ($staffIds) {
            /** @var SystemStoreStaffServices $staffServices */
            $staffServices = app()->make(SystemStoreStaffServices::class);
            $staffMap = $staffServices->getColumn([['id', 'in', $staffIds]], 'staff_name', 'id');
        }
        $userMap = [];
        if ($withUser) {
            $uids = array_unique(array_filter(array_column($list, 'uid')));
            if ($uids) {
                /** @var UserServices $userServices */
                $userServices = app()->make(UserServices::class);
                $users = $userServices->getColumn([['uid', 'in', $uids]], 'nickname,phone,avatar,level', 'uid');
                $userMap = $users ?: [];
            }
        }
        $debtIds = array_column($list, 'id');
        $itemMap = [];
        if ($debtIds) {
            $items = $this->itemDao->search(['debt_id' => $debtIds])->select()->toArray();
            foreach ($items as $item) {
                $itemMap[$item['debt_id']][] = $this->formatDebtItemRow($item);
            }
        }
        $result = [];
        $now = time();
        foreach ($list as $row) {
            $pending = (float)bcsub((string)($row['total_debt'] ?? 0), (string)($row['repaid_debt'] ?? 0), 2);
            if ($pending < 0) {
                $pending = 0;
            }
            $items = $itemMap[$row['id']] ?? [];
            $addTime = $this->normalizeUnixTime($row['add_time'] ?? 0);
            $durationDays = $addTime > 0 ? max(1, (int)ceil(($now - $addTime) / 86400)) : 0;
            $staffId = (int)($row['staff_id'] ?? 0);
            if (!$staffId && !empty($row['order_id'])) {
                $staffId = (int)($orderStaffMap[$row['order_id']] ?? 0);
            }
            if (!$staffId && !empty($row['order_id'])) {
                $staffId = $this->resolveOrderStaffId((int)$row['order_id'], 0);
            }
            if (!(int)($row['staff_id'] ?? 0) && $staffId > 0) {
                $this->dao->update((int)$row['id'], ['staff_id' => $staffId, 'update_time' => time()]);
            }
            $staffName = $staffMap[$staffId] ?? '';
            if ($staffName === '') {
                $staffName = $this->resolveStaffDisplayName($staffId, (int)($row['order_id'] ?? 0));
            }
            $pendingItems = array_values(array_filter($items, function ($item) {
                return (float)($item['pending_debt'] ?? 0) > 0;
            }));
            $repayDebtItemId = count($pendingItems) === 1 ? (int)($pendingItems[0]['id'] ?? 0) : 0;
            $user = $userMap[$row['uid']] ?? null;
            $result[] = [
                'id' => (int)$row['id'],
                'debt_id' => (int)$row['id'],
                'debt_item_id' => $repayDebtItemId,
                'debt_no' => $row['debt_no'] ?? '',
                'order_id' => (int)($row['order_id'] ?? 0),
                'order_sn' => $row['order_sn'] ?? '',
                'uid' => (int)($row['uid'] ?? 0),
                'user' => $user ? [
                    'uid' => (int)$row['uid'],
                    'nickname' => $user['nickname'] ?? '',
                    'phone' => $user['phone'] ?? '',
                    'avatar' => $user['avatar'] ?? '',
                    'level' => $user['level'] ?? 0,
                ] : null,
                'store_id' => (int)($row['store_id'] ?? 0),
                'store_name' => $storeMap[$row['store_id']] ?? '',
                'staff_id' => $staffId,
                'staff_name' => $staffName,
                'original_source' => (int)($sourceMap[$row['order_id'] ?? 0] ?? 0),
                'total_debt' => (float)($row['total_debt'] ?? 0),
                'repaid_debt' => (float)($row['repaid_debt'] ?? 0),
                'pending_debt' => $pending,
                'status' => (int)($row['status'] ?? 0),
                'status_label' => $this->statusLabel((int)($row['status'] ?? 0)),
                'add_time' => $addTime,
                'add_time_label' => $addTime ? date('Y-m-d H:i:s', $addTime) : '',
                'debt_duration' => $durationDays ? ($durationDays . '天') : '',
                'remark' => (string)($row['remark'] ?? ''),
                'items' => $items,
                'product_names' => array_column($items, 'product_name'),
            ];
        }
        return $result;
    }

    protected function formatDebtItemRow(array $item): array
    {
        $pending = (float)bcsub((string)($item['debt_amount'] ?? 0), (string)($item['repaid_debt'] ?? 0), 2);
        if ($pending < 0) {
            $pending = 0;
        }
        return [
            'id' => (int)($item['id'] ?? 0),
            'debt_id' => (int)($item['debt_id'] ?? 0),
            'order_id' => (int)($item['order_id'] ?? 0),
            'cart_info_id' => (int)($item['cart_info_id'] ?? 0),
            'product_id' => (int)($item['product_id'] ?? 0),
            'product_type' => (int)($item['product_type'] ?? 0),
            'product_name' => $item['product_name'] ?? '',
            'cart_num' => (int)($item['cart_num'] ?? 1),
            'debt_amount' => (float)($item['debt_amount'] ?? 0),
            'repaid_debt' => (float)($item['repaid_debt'] ?? 0),
            'pending_debt' => $pending,
        ];
    }

    public function closeDebt(int $id): bool
    {
        $debt = $this->dao->get($id);
        if (!$debt) {
            throw new ValidateException('欠款记录不存在');
        }
        if ((int)$debt['status'] !== StoreDebt::STATUS_PENDING) {
            throw new ValidateException('当前状态不可关闭');
        }
        return (bool)$this->dao->update($id, [
            'status' => StoreDebt::STATUS_CLOSED,
            'update_time' => time(),
        ]);
    }

    public function closeDebtForStore(int $id, int $storeId): bool
    {
        $debt = $this->dao->get($id);
        if (!$debt) {
            throw new ValidateException('欠款记录不存在');
        }
        $debtArr = is_array($debt) ? $debt : $debt->toArray();
        if ((int)($debtArr['store_id'] ?? 0) !== $storeId) {
            throw new ValidateException('无权操作该欠款');
        }
        return $this->closeDebt($id);
    }

    public function getDetailByOrderIdForStore(int $orderId, int $storeId): ?array
    {
        $detail = $this->getDetailByOrderId($orderId);
        if (!$detail || (int)($detail['store_id'] ?? 0) !== $storeId) {
            return null;
        }
        return $detail;
    }

    public function getRepayList(array $where, int $page = 1, int $limit = 20): array
    {
        $storeFilter = (int)($where['store_filter'] ?? 0);
        unset($where['store_filter']);
        $query = $this->repayDao->search($where)->order('id desc');
        if ($storeFilter > 0) {
            $query->where(function ($q) use ($storeFilter) {
                $q->where('debt_store_id', $storeFilter)->whereOr('pay_store_id', $storeFilter);
            });
        }
        $count = (int)$query->count();
        $list = $query->page($page, $limit)->select()->toArray();
        /** @var SystemStoreServices $storeServices */
        $storeServices = app()->make(SystemStoreServices::class);
        $storeIds = array_unique(array_merge(
            array_column($list, 'pay_store_id'),
            array_column($list, 'debt_store_id')
        ));
        $storeMap = $storeIds ? $storeServices->getColumn([['id', 'in', $storeIds]], 'name', 'id') : [];
        $staffIds = array_unique(array_filter(array_column($list, 'staff_id')));
        $staffMap = [];
        if ($staffIds) {
            /** @var SystemStoreStaffServices $staffServices */
            $staffServices = app()->make(SystemStoreStaffServices::class);
            $staffMap = $staffServices->getColumn([['id', 'in', $staffIds]], 'staff_name', 'id');
        }
        foreach ($list as &$row) {
            $addTime = $this->normalizeUnixTime($row['add_time'] ?? 0);
            $row['add_time'] = $addTime;
            $row['add_time_label'] = $addTime ? date('Y-m-d H:i', $addTime) : '';
            $row['pay_store_name'] = $storeMap[$row['pay_store_id']] ?? '';
            $row['debt_store_name'] = $storeMap[$row['debt_store_id']] ?? '';
            $staffId = (int)($row['staff_id'] ?? 0);
            $row['staff_name'] = $staffMap[$staffId] ?? '';
            if ($row['staff_name'] === '') {
                $row['staff_name'] = $this->resolveStaffDisplayName($staffId, (int)($row['order_id'] ?? 0));
            }
            $row['pay_type_label'] = $this->resolvePayTypeLabel((string)($row['pay_type'] ?? ''), $row['combination_info'] ?? '');
        }
        unset($row);
        return ['list' => $list, 'count' => $count];
    }

    public function resolvePayTypeLabel(string $payType, $combinationInfo = ''): string
    {
        if ($payType === PayServices::COMBINATION_PAY) {
            $info = is_string($combinationInfo) ? json_decode($combinationInfo, true) : $combinationInfo;
            if (is_array($info) && $info) {
                return implode('、', array_column($info, 'name'));
            }
            return '组合支付';
        }
        $map = PayServices::PAY_TYPE ?? [];
        return $map[$payType] ?? ($payType ?: '未知');
    }

    /**
     * 补交生成普通收银订单（未支付）
     */
    public function createRepayOrder(int $debtId, float $amount, array $params = []): array
    {
        $debt = $this->dao->get($debtId);
        if (!$debt) {
            throw new ValidateException('欠款记录不存在');
        }
        $debt = is_array($debt) ? $debt : $debt->toArray();
        $debtItemId = (int)($params['debt_item_id'] ?? 0);
        $payType = (string)($params['pay_type'] ?? PayServices::CASH_PAY);
        $combinationInfo = $params['combination_info'] ?? [];
        $cashChoose = (int)($params['cash_choose'] ?? 0);
        $source = (int)($params['source'] ?? 0);
        $isBudan = (int)($params['is_budan'] ?? 0);
        $budanTime = (string)($params['budan_time'] ?? '');
        $staffId = (int)($params['staff_id'] ?? 0);
        $payStoreId = (int)($params['pay_store_id'] ?? 0);
        $remarkInfo = $params['remark_info'] ?? [];
        $setYejiAll = $params['set_yeji_all'] ?? $params['setYejiAll'] ?? [];
        if (is_string($setYejiAll)) {
            $setYejiAll = json_decode($setYejiAll, true) ?: [];
        }
        if (!is_array($setYejiAll)) {
            $setYejiAll = [];
        }

        /** @var StoreOrderServices $orderServices */
        $orderServices = app()->make(StoreOrderServices::class);
        $originalOrder = $orderServices->get((int)$debt['order_id']);
        if (!$originalOrder) {
            throw new ValidateException('原订单不存在');
        }
        $originalOrder = is_array($originalOrder) ? $originalOrder : $originalOrder->toArray();
        if (!$source) {
            $source = (int)($originalOrder['source'] ?? 0);
        }

        $repayItems = $this->resolveRepayDebtItems($debtId, $debtItemId, $amount);
        if (!$repayItems) {
            throw new ValidateException('没有可补交的欠款明细');
        }

        /** @var UserServices $userServices */
        $userServices = app()->make(UserServices::class);
        $userInfo = $userServices->getUserInfo((int)$debt['uid']);
        if (!$userInfo) {
            throw new ValidateException('用户不存在');
        }

        $yuePayPrice = '0.00';
        if ($payType === PayServices::YUE_PAY) {
            $yuePayPrice = bcadd((string)$amount, '0', 2);
        } elseif ($payType === PayServices::COMBINATION_PAY && $combinationInfo) {
            foreach ($combinationInfo as $v) {
                if ((int)($v['activePay'] ?? 0) !== 3) {
                    continue;
                }
                $subType = $v['pay_sub_type'] ?? 'balance';
                if ($subType === 'balance') {
                    $yuePayPrice = bcadd($yuePayPrice, (string)($v['price'] ?? 0), 2);
                }
            }
        }
        $cashPayPrice = ValidCashOrderServices::calcOrderCashPayPrice(
            $amount,
            (float)$yuePayPrice,
            $cashChoose,
            is_array($combinationInfo) ? $combinationInfo : [],
            $payType,
            0.00
        );

        /** @var StoreOrderCartInfoServices $cartServices */
        $cartServices = app()->make(StoreOrderCartInfoServices::class);
        $cartInfo = [];
        $totalNum = 0;
        foreach ($repayItems as $repayItem) {
            $lineAmount = (float)$repayItem['repay_amount'];
            $cart = $this->buildRepayCartLine($cartServices, $repayItem, $lineAmount);
            $cartInfo[] = $cart;
            $totalNum += (int)($cart['cart_num'] ?? 1);
        }
        ValidCashOrderServices::scaleCartCashPayAmounts($cartInfo, (float)$cashPayPrice);
        if (bccomp((string)$yuePayPrice, '0', 2) > 0 && $cartInfo) {
            $totalPay = '0.00';
            foreach ($cartInfo as $cart) {
                $totalPay = bcadd($totalPay, (string)($cart['pay_price'] ?? 0), 2);
            }
            $remaining = (string)$yuePayPrice;
            $lastIdx = count($cartInfo) - 1;
            foreach ($cartInfo as $idx => &$cart) {
                if ($idx === $lastIdx) {
                    $cart['yue_pay_amount'] = (float)$remaining;
                } else {
                    $portion = bccomp($totalPay, '0', 2) > 0
                        ? bcmul((string)$yuePayPrice, bcdiv((string)($cart['pay_price'] ?? 0), $totalPay, 4), 2)
                        : '0.00';
                    $cart['yue_pay_amount'] = (float)$portion;
                    $remaining = bcsub($remaining, $portion, 2);
                }
            }
            unset($cart);
        }
        foreach ($cartInfo as &$cart) {
            $pp = (string)($cart['pay_price'] ?? 0);
            $cart['sum_true_price'] = $pp;
            $num = max((int)($cart['cart_num'] ?? 1), 1);
            $cart['truePrice'] = bccomp($pp, '0', 2) > 0 ? bcdiv($pp, (string)$num, 2) : '0.00';
        }
        unset($cart);

        /** @var StoreOrderCreateServices $orderCreateServices */
        $orderCreateServices = app()->make(StoreOrderCreateServices::class);
        $storeId = $payStoreId ?: (int)$originalOrder['store_id'];
        $addTime = time();
        if ($isBudan === 1 && $budanTime !== '') {
            $budanTs = strtotime($budanTime);
            if ($budanTs) {
                $addTime = $budanTs;
            }
        }

        // 渠道补交：order_id 使用 repay_no，便于回调按商户单号从 DB 恢复待入账信息
        $orderSn = !empty($params['repay_no'])
            ? (string)$params['repay_no']
            : $orderCreateServices->getUniqueId();

        $orderInfo = [
            'uid' => (int)$debt['uid'],
            'order_id' => $orderSn,
            'real_name' => $userInfo['nickname'] ?? '',
            'user_phone' => $userInfo['phone'] ?? '',
            'user_address' => '',
            'user_location' => '',
            'cart_id' => [],
            'type' => 0,
            'order_type' => 0,
            'shipping_type' => 4,
            'store_id' => $storeId,
            'staff_id' => $staffId ?: (int)($originalOrder['staff_id'] ?? 0),
            'source' => $source,
            'cash_choose' => $cashChoose,
            'remark_info' => json_encode($remarkInfo, JSON_UNESCAPED_UNICODE),
            'yeji' => json_encode([]),
            'service_yeji' => json_encode([]),
            'send_all' => json_encode([]),
            'total_num' => $totalNum ?: 1,
            'total_price' => $amount,
            'settle_price' => 0,
            'total_postage' => 0,
            'coupon_id' => 0,
            'coupon_price' => 0,
            'promotions_price' => 0,
            'pay_price' => $amount,
            'yue_pay_price' => (float)$yuePayPrice,
            'cash_pay_price' => (float)$cashPayPrice,
            'debt_amount' => 0,
            'repaid_debt_amount' => 0,
            'is_debt_repay' => 1,
            'debt_repay_origin_order_id' => (int)$originalOrder['id'],
            'debt_repay_item_id' => $debtItemId,
            'pay_postage' => 0,
            'deduction_price' => 0,
            'change_price' => 0,
            'paid' => 0,
            'pay_type' => '',
            'use_integral' => 0,
            'gain_integral' => 0,
            'pay_integral' => 0,
            'mark' => '欠款补交',
            'product_type' => (int)($cartInfo[0]['productInfo']['product_type'] ?? 0),
            'activity_id' => 0,
            'pink_id' => 0,
            'cost' => 0,
            'is_channel' => 5,
            'channel_type' => 'cashier',
            'add_time' => $addTime,
            'is_budan' => $isBudan ? 1 : 0,
            'unique' => md5('debt_repay_' . uniqid('', true) . microtime(true)),
            'spread_uid' => 0,
            'spread_two_uid' => 0,
            'custom_form' => json_encode([[]]),
            'promotions_give' => json_encode([]),
            'give_integral' => 0,
            'give_coupon' => '',
            'clerk_id' => 0,
            'gendan_staff_id' => (int)($originalOrder['gendan_staff_id'] ?? 0),
            'is_gendan' => (int)($originalOrder['is_gendan'] ?? 0),
        ];

        if ($payType === PayServices::YUE_PAY && $storeId > 0) {
            $orderInfo['kua_store'] = $orderCreateServices->isKuadian((int)$debt['uid'], $storeId);
        }

        $order = $orderCreateServices->save($orderInfo);
        if (!$order) {
            throw new ValidateException('补交订单生成失败');
        }
        $order = is_array($order) ? $order : $order->toArray();
        $cartServices->setCartInfo((int)$order['id'], $cartInfo, (int)$debt['uid']);

        if ($setYejiAll) {
            $cartRows = $cartServices->getCartColunm(['oid' => (int)$order['id']], '*', 'cart_id');
            $setYejiAll = $this->bindRepayYejiCartIds($setYejiAll, $cartRows, $amount);
            $orderCreateServices->update((int)$order['id'], [
                'yeji' => json_encode($setYejiAll, JSON_UNESCAPED_UNICODE),
            ]);
            $order['yeji'] = json_encode($setYejiAll, JSON_UNESCAPED_UNICODE);
        }

        if ($payType === PayServices::COMBINATION_PAY && $combinationInfo) {
            CashType::validateCombinationInfo($combinationInfo);
            ValidCashOrderServices::validateCombinationTotal($combinationInfo, $amount);
            foreach ($combinationInfo as $v) {
                $lineCashChoose = (int)($v['type'] ?? 0);
                if (($v['pay_sub_type'] ?? '') === 'debt') {
                    $lineCashChoose = CashType::DEBT_ENTRY;
                }
                Db::name('combination_order')->insert([
                    'active_pay' => $v['activePay'],
                    'name' => $v['name'],
                    'price' => $v['price'],
                    'remarkInfo' => json_encode($v['remarkInfo'] ?? []),
                    'cash_choose' => $lineCashChoose,
                    'pay_sub_type' => $v['pay_sub_type'] ?? 'balance',
                    'upgrade_old_oid' => $v['upgrade_old_oid'] ?? 0,
                    'upgrade_old_cart_info_id' => $v['upgrade_old_cart_info_id'] ?? 0,
                    'order_id' => $order['id'],
                    'type' => 1,
                    'add_time' => $orderInfo['add_time'],
                ]);
            }
        }

        return $order;
    }

    /**
     * 还款订单绑定销售业绩 cart_id
     */
    protected function bindRepayYejiCartIds(array $setYejiAll, $cartRows, float $amount): array
    {
        $cartList = [];
        foreach ($cartRows as $cart) {
            $cartList[] = is_array($cart) ? $cart : (is_object($cart) && method_exists($cart, 'toArray') ? $cart->toArray() : (array)$cart);
        }
        foreach ($setYejiAll as &$yeji) {
            if (!is_array($yeji)) {
                continue;
            }
            $goodsId = (int)($yeji['goods_id'] ?? 0);
            $yeji['type'] = (int)($yeji['type'] ?? 2);
            $yeji['price'] = (float)($yeji['price'] ?? $amount);
            $yeji['balance_price'] = (float)($yeji['balance_price'] ?? 0);
            $matched = null;
            foreach ($cartList as $cart) {
                if ($goodsId > 0 && (int)($cart['product_id'] ?? 0) !== $goodsId) {
                    continue;
                }
                $matched = $cart;
                break;
            }
            if (!$matched && $cartList) {
                $matched = $cartList[0];
            }
            if ($matched) {
                $yeji['cart_id'] = $matched['cart_id'] ?? 0;
                if (!$goodsId) {
                    $yeji['goods_id'] = (int)($matched['product_id'] ?? 0);
                }
            }
        }
        unset($yeji);
        return $setYejiAll;
    }

    /**
     * 解析本次补交明细
     */
    protected function resolveRepayDebtItems(int $debtId, int $debtItemId, float $amount): array
    {
        if ($debtItemId > 0) {
            $item = $this->itemDao->get($debtItemId);
            if (!$item || (int)$item['debt_id'] !== $debtId) {
                throw new ValidateException('欠款明细不存在');
            }
            $pending = (float)bcsub((string)$item['debt_amount'], (string)$item['repaid_debt'], 2);
            if (bccomp((string)$amount, (string)$pending, 2) > 0) {
                throw new ValidateException('还款金额超过该明细待还金额');
            }
            return [[
                'item' => is_array($item) ? $item : $item->toArray(),
                'repay_amount' => $amount,
            ]];
        }

        $items = $this->itemDao->search(['debt_id' => $debtId])->select()->toArray();
        $rows = [];
        $totalPending = '0.00';
        foreach ($items as $item) {
            $pending = bcsub((string)($item['debt_amount'] ?? 0), (string)($item['repaid_debt'] ?? 0), 2);
            if (bccomp($pending, '0', 2) <= 0) {
                continue;
            }
            $totalPending = bcadd($totalPending, $pending, 2);
            $rows[] = [
                'item' => $item,
                'repay_amount' => (float)$pending,
            ];
        }
        if (!$rows) {
            return [];
        }
        if (bccomp((string)$amount, $totalPending, 2) !== 0) {
            throw new ValidateException('还款金额须等于待还欠款总额');
        }
        return $rows;
    }

    protected function buildRepayCartLine(StoreOrderCartInfoServices $cartServices, array $repayItem, float $lineAmount): array
    {
        $item = $repayItem['item'];
        $cartRow = null;
        if (!empty($item['cart_info_id'])) {
            $cartRow = $cartServices->get((int)$item['cart_info_id']);
        }
        if ($cartRow) {
            $cart = is_string($cartRow['cart_info'] ?? '') ? json_decode($cartRow['cart_info'], true) : ($cartRow['cart_info'] ?? []);
            if (!is_array($cart)) {
                $cart = [];
            }
            $cart['id'] = $cartRow['cart_id'] ?? ($cart['id'] ?? 0);
            $cart['cart_num'] = (int)($cartRow['cart_num'] ?? 1);
            $cart['product_id'] = $cartRow['product_id'] ?? ($cart['product_id'] ?? 0);
        } else {
            $cart = [
                'id' => 0,
                'cart_num' => (int)($item['cart_num'] ?? 1),
                'product_id' => (int)($item['product_id'] ?? 0),
                'productInfo' => [
                    'store_name' => $item['product_name'] ?? '欠款补交',
                    'type' => 0,
                    'product_type' => (int)($item['product_type'] ?? 0),
                    'id' => (int)($item['product_id'] ?? 0),
                ],
            ];
        }
        $cart['pay_price'] = $lineAmount;
        $cart['total_price'] = $lineAmount;
        $cart['yue_pay_amount'] = 0;
        $cart['card_upgrade_amount'] = 0;
        $cart['debt_pay_amount'] = 0;
        return $cart;
    }

    protected function finalizeRepayPayment(array $repayOrder, string $payType, array $params = []): void
    {
        $uid = (int)$repayOrder['uid'];
        $orderId = (int)$repayOrder['id'];
        if ($payType === PayServices::COMBINATION_PAY) {
            $this->processCombinationRepay($uid, $params['combination_info'] ?? [], (string)($params['user_code'] ?? ''), $orderId);
        } elseif ($payType === PayServices::YUE_PAY) {
            $this->deductUserBalance($uid, (float)($repayOrder['yue_pay_price'] ?? $repayOrder['pay_price']), $orderId);
        }
        /** @var StoreOrderServices $orderServices */
        $orderServices = app()->make(StoreOrderServices::class);
        $freshOrder = $orderServices->get($orderId);
        if ($freshOrder) {
            $repayOrder = is_array($freshOrder) ? $freshOrder : $freshOrder->toArray();
            $repayOrder['pay_integral'] = (int)($repayOrder['pay_integral'] ?? 0);
        }
        /** @var StoreOrderSuccessServices $successService */
        $successService = app()->make(StoreOrderSuccessServices::class);
        $successService->paySuccess($repayOrder, $payType, $params['other'] ?? []);
    }

    protected function buildRepayApplyExtra(int $debtId, float $amount, string $payType, array $params, array $repayOrder): array
    {
        return [
            'debt_item_id' => (int)($params['debt_item_id'] ?? 0),
            'pay_type' => $payType,
            'pay_store_id' => (int)($params['pay_store_id'] ?? 0),
            'staff_id' => (int)($params['staff_id'] ?? 0),
            'combination_info' => $params['combination_info'] ?? [],
            'is_budan' => (int)($params['is_budan'] ?? 0),
            'repay_no' => (string)($params['repay_no'] ?? $this->generateRepayNo()),
            'repay_order_id' => (int)$repayOrder['id'],
        ];
    }

    /**
     * 欠款主表行锁（须在事务内调用）
     */
    protected function lockDebtForUpdate(int $debtId): array
    {
        $debt = Db::name('store_debt')->where('id', $debtId)->lock(true)->find();
        if (!$debt) {
            throw new ValidateException('欠款记录不存在');
        }
        return $debt;
    }

    /**
     * 锁后校验可还金额（可选锁明细行）
     */
    protected function assertDebtRepayableLocked(array $debt, float $amount, int $debtItemId = 0): void
    {
        if ((int)$debt['status'] !== StoreDebt::STATUS_PENDING) {
            throw new ValidateException('当前欠款不可还款');
        }
        $pending = (float)bcsub((string)$debt['total_debt'], (string)$debt['repaid_debt'], 2);
        if ($amount <= 0 || bccomp((string)$amount, (string)$pending, 2) > 0) {
            throw new ValidateException('还款金额不正确');
        }
        if ($debtItemId > 0) {
            $item = Db::name('store_debt_item')->where('id', $debtItemId)->lock(true)->find();
            if (!$item || (int)$item['debt_id'] !== (int)$debt['id']) {
                throw new ValidateException('欠款明细不存在');
            }
            $itemPending = (float)bcsub((string)$item['debt_amount'], (string)$item['repaid_debt'], 2);
            if (bccomp((string)$amount, (string)$itemPending, 2) > 0) {
                throw new ValidateException('还款金额超过该明细待还金额');
            }
        }
    }

    /**
     * 同债务下是否已有未支付补交单（渠道进行中）
     */
    protected function assertNoInFlightDebtRepayOrder(int $originOrderId): void
    {
        if ($originOrderId <= 0) {
            return;
        }
        $exists = (int)Db::name('store_order')
            ->where('is_debt_repay', 1)
            ->where('debt_repay_origin_order_id', $originOrderId)
            ->where('paid', 0)
            ->where('is_del', 0)
            ->count();
        if ($exists > 0) {
            throw new ValidateException('已有进行中的补交支付，请勿重复提交');
        }
    }

    /**
     * 渠道补交待入账信息持久化（DB 为主，Redis 为辅）
     * 必须含：repay_no、debt_id、金额、repay_order_id、支付方式
     */
    protected function rememberDebtRepayPending(array $pending): array
    {
        $repayNo = trim((string)($pending['repay_no'] ?? ''));
        $repayOrderId = (int)($pending['repay_order_id'] ?? 0);
        $debtId = (int)($pending['debt_id'] ?? 0);
        $amount = (float)($pending['amount'] ?? 0);
        if ($repayNo === '' || $repayOrderId <= 0 || $debtId <= 0 || $amount <= 0) {
            throw new ValidateException('补交待入账信息缺失');
        }
        $payload = [
            'debt_id' => $debtId,
            'debt_item_id' => (int)($pending['debt_item_id'] ?? 0),
            'amount' => $amount,
            'pay_type' => (string)($pending['pay_type'] ?? PayServices::WEIXIN_PAY),
            'pay_store_id' => (int)($pending['pay_store_id'] ?? 0),
            'staff_id' => (int)($pending['staff_id'] ?? 0),
            'repay_order_id' => $repayOrderId,
            'repay_no' => $repayNo,
        ];
        $order = Db::name('store_order')->where('id', $repayOrderId)->where('is_debt_repay', 1)->find();
        if (!$order) {
            throw new ValidateException('补交订单不存在');
        }
        $notify = [];
        $raw = (string)($order['notify_data'] ?? '');
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $notify = $decoded;
            }
        }
        $notify['debt_repay_pending'] = $payload;
        $update = [
            'notify_data' => json_encode($notify, JSON_UNESCAPED_UNICODE),
        ];
        // 保证商户单号=repay_no，回调可按 order_id 找回
        if ((string)($order['order_id'] ?? '') !== $repayNo) {
            $update['order_id'] = $repayNo;
        }
        Db::name('store_order')->where('id', $repayOrderId)->update($update);
        Cache::set('debt_repay_pending_' . $repayNo, $payload, 86400);
        return $payload;
    }

    /**
     * 从补交订单 DB 恢复待入账信息（不依赖 Redis）
     * 仅按可索引字段 order_id=repay_no 定位；禁止 notify_data 无索引模糊扫描。
     */
    protected function loadDebtRepayPendingFromDb(string $repayNo): ?array
    {
        $repayNo = trim($repayNo);
        if ($repayNo === '') {
            return null;
        }
        // 渠道建单时已将 order_id 设为 repay_no（见 createRepayOrder / rememberDebtRepayPending）
        $order = Db::name('store_order')
            ->where('is_debt_repay', 1)
            ->where('order_id', $repayNo)
            ->order('id', 'desc')
            ->find();
        if (!$order) {
            return null;
        }
        $notify = json_decode((string)($order['notify_data'] ?? ''), true);
        $pending = is_array($notify['debt_repay_pending'] ?? null) ? $notify['debt_repay_pending'] : null;
        if (!is_array($pending) || (int)($pending['debt_id'] ?? 0) <= 0) {
            // notify_data 损坏时：用补交单+欠款主表最小字段重建
            $originOid = (int)($order['debt_repay_origin_order_id'] ?? 0);
            $debt = $originOid > 0
                ? Db::name('store_debt')->where('order_id', $originOid)->order('id', 'desc')->find()
                : null;
            if (!$debt) {
                return null;
            }
            $pending = [
                'debt_id' => (int)$debt['id'],
                'debt_item_id' => (int)($order['debt_repay_item_id'] ?? 0),
                'amount' => (float)($order['pay_price'] ?? 0),
                'pay_type' => (string)(($order['pay_type'] ?? '') ?: PayServices::WEIXIN_PAY),
                'pay_store_id' => (int)($order['store_id'] ?? 0),
                'staff_id' => (int)($order['staff_id'] ?? 0),
                'repay_order_id' => (int)$order['id'],
                'repay_no' => $repayNo,
            ];
        }
        $pending['repay_order_id'] = (int)($pending['repay_order_id'] ?? $order['id']);
        $pending['repay_no'] = $repayNo;
        if ((int)($pending['debt_id'] ?? 0) <= 0 || (float)($pending['amount'] ?? 0) <= 0) {
            return null;
        }
        return $pending;
    }

    /**
     * 入账成功后清理待入账标记（保留其它 notify_data）
     */
    protected function clearDebtRepayPendingMark(int $repayOrderId, string $repayNo): void
    {
        if ($repayOrderId > 0) {
            $raw = (string)Db::name('store_order')->where('id', $repayOrderId)->value('notify_data');
            if ($raw !== '') {
                $notify = json_decode($raw, true);
                if (is_array($notify) && isset($notify['debt_repay_pending'])) {
                    unset($notify['debt_repay_pending']);
                    Db::name('store_order')->where('id', $repayOrderId)->update([
                        'notify_data' => $notify ? json_encode($notify, JSON_UNESCAPED_UNICODE) : null,
                    ]);
                }
            }
        }
        if ($repayNo !== '') {
            Cache::delete('debt_repay_pending_' . $repayNo);
        }
    }

    /**
     * 现金/余额/组合：欠款 FOR UPDATE + 同事务创建补交单/扣款/paySuccess/applyRepay
     */
    protected function repayPayImmediateLocked(int $debtId, float $amount, string $payType, array $params = []): array
    {
        return $this->transaction(function () use ($debtId, $amount, $payType, $params) {
            $debt = $this->lockDebtForUpdate($debtId);
            $debtItemId = (int)($params['debt_item_id'] ?? 0);
            $this->assertDebtRepayableLocked($debt, $amount, $debtItemId);

            $combinationInfo = $params['combination_info'] ?? [];
            $userCode = (string)($params['user_code'] ?? '');
            $payStoreId = (int)($params['pay_store_id'] ?? 0);
            $staffId = (int)($params['staff_id'] ?? 0);
            $repayNo = !empty($params['repay_no']) ? (string)$params['repay_no'] : $this->generateRepayNo();
            $params['repay_no'] = $repayNo;

            if ($payType === PayServices::COMBINATION_PAY) {
                CashType::validateCombinationInfo($combinationInfo);
                ValidCashOrderServices::validateCombinationTotal($combinationInfo, $amount);
            }

            $repayOrder = $this->createRepayOrder($debtId, $amount, array_merge($params, [
                'pay_type' => $payType,
                'debt_item_id' => $debtItemId,
                'pay_store_id' => $payStoreId,
                'staff_id' => $staffId,
            ]));
            $applyExtra = $this->buildRepayApplyExtra($debtId, $amount, $payType, array_merge($params, [
                'combination_info' => $combinationInfo,
            ]), $repayOrder);

            $finalizeParams = [];
            if ($payType === PayServices::COMBINATION_PAY) {
                $finalizeParams = [
                    'combination_info' => $combinationInfo,
                    'user_code' => $userCode,
                ];
            } elseif (!empty($params['trade_no'])) {
                $finalizeParams = ['other' => ['trade_no' => (string)$params['trade_no']]];
            }

            $this->finalizeRepayPayment($repayOrder, $payType, $finalizeParams);
            $this->applyRepay($debtId, $amount, $applyExtra);

            return [
                'status' => 'SUCCESS',
                'message' => '还款成功',
                'repay_order_id' => (int)$repayOrder['id'],
                'repay_no' => $repayNo,
            ];
        });
    }

    /**
     * 欠款还款支付
     */
    public function repayPay(int $debtId, float $amount, string $payType, array $params = []): array
    {
        $combinationInfo = $params['combination_info'] ?? [];
        // 组合支付若明细全部为余额（不含卡升级），按余额支付落单
        if ($payType === PayServices::COMBINATION_PAY && is_array($combinationInfo) && $combinationInfo !== []) {
            CashType::validateCombinationInfo($combinationInfo);
            if (CashType::isOnlyBalanceCombination($combinationInfo)) {
                $payType = PayServices::YUE_PAY;
                $combinationInfo = [];
                $params['combination_info'] = [];
            }
        }

        if (in_array($payType, [PayServices::YUE_PAY, PayServices::CASH_PAY, PayServices::COMBINATION_PAY], true)) {
            return $this->repayPayImmediateLocked($debtId, $amount, $payType, $params);
        }

        if (in_array($payType, [PayServices::WEIXIN_PAY, PayServices::ALIAPY_PAY], true)) {
            $authCode = (string)($params['auth_code'] ?? '');
            if (!$authCode) {
                throw new ValidateException('缺少支付付款二维码');
            }
            $debtItemId = (int)($params['debt_item_id'] ?? 0);
            $payStoreId = (int)($params['pay_store_id'] ?? 0);
            $staffId = (int)($params['staff_id'] ?? 0);
            $repayNo = !empty($params['repay_no']) ? (string)$params['repay_no'] : $this->generateRepayNo();
            $params['repay_no'] = $repayNo;

            // 创建未付补交单：欠款行锁 + DB 持久化待入账（不依赖 Redis）
            $prepared = $this->transaction(function () use ($debtId, $amount, $payType, $params, $debtItemId, $payStoreId, $staffId, $repayNo) {
                $debt = $this->lockDebtForUpdate($debtId);
                $this->assertDebtRepayableLocked($debt, $amount, $debtItemId);
                $this->assertNoInFlightDebtRepayOrder((int)$debt['order_id']);
                $repayOrder = $this->createRepayOrder($debtId, $amount, array_merge($params, [
                    'pay_type' => $payType,
                    'debt_item_id' => $debtItemId,
                    'pay_store_id' => $payStoreId,
                    'staff_id' => $staffId,
                    'repay_no' => $repayNo,
                ]));
                $this->rememberDebtRepayPending([
                    'debt_id' => $debtId,
                    'debt_item_id' => $debtItemId,
                    'amount' => $amount,
                    'pay_type' => $payType,
                    'pay_store_id' => $payStoreId,
                    'staff_id' => $staffId,
                    'repay_order_id' => (int)$repayOrder['id'],
                    'repay_no' => $repayNo,
                ]);
                return [$debt, $repayOrder];
            });
            /** @var array $debt */
            /** @var array $repayOrder */
            [$debt, $repayOrder] = $prepared;

            $pay = new PayServices();
            $body = '欠款还款-' . ($debt['order_sn'] ?: $repayNo);
            try {
                $response = $pay->setAuthCode($authCode)->pay($payType, '', $repayNo, $amount, 'debt_repay', substrUTf8($body, 30));
            } catch (\Throwable $e) {
                throw new ValidateException('支付失败：' . $e->getMessage());
            }

            if (!empty($response['paid'])) {
                // 渠道已扣款：同事务锁欠款后只入账一次（repay_no 幂等）
                $ok = $this->completeRepayFromNotify($repayNo, (string)($response['trade_no'] ?? ''), $payType);
                if (!$ok) {
                    throw new ValidateException('渠道已扣款但补交入账失败，请联系平台处理');
                }
                $repayOid = (int)Db::name('store_debt_repay')->where('repay_no', $repayNo)->value('repay_order_id');
                return [
                    'status' => 'SUCCESS',
                    'message' => '还款成功',
                    'repay_no' => $repayNo,
                    'repay_order_id' => $repayOid ?: (int)$repayOrder['id'],
                ];
            }

            return [
                'status' => 'PAY_ING',
                'message' => $response['message'] ?? '等待支付',
                'repay_no' => $repayNo,
                'repay_order_id' => (int)$repayOrder['id'],
            ];
        }

        throw new ValidateException('不支持的支付方式');
    }

    /**
     * 手机端欠款收银台信息
     */
    public function getMobileCashierInfo(int $uid, string $orderSn): array
    {
        /** @var StoreOrderServices $orderServices */
        $orderServices = app()->make(StoreOrderServices::class);
        $order = $orderServices->getUserOrderDetail($orderSn, $uid);
        if (!$order) {
            throw new ValidateException('订单不存在');
        }
        $order = is_array($order) ? $order : $order->toArray();
        if (!(int)($order['paid'] ?? 0)) {
            throw new ValidateException('订单未支付');
        }
        $debtAmount = (float)($order['debt_amount'] ?? 0);
        $repaidDebt = (float)($order['repaid_debt_amount'] ?? 0);
        $pending = (float)bcsub((string)$debtAmount, (string)$repaidDebt, 2);
        if ($pending <= 0) {
            throw new ValidateException('该订单无待还欠款');
        }
        $debt = $this->dao->get(['order_id' => (int)$order['id']]);
        if (!$debt || (int)$debt['status'] !== StoreDebt::STATUS_PENDING) {
            throw new ValidateException('欠款记录不存在或不可还款');
        }
        $debt = is_array($debt) ? $debt : $debt->toArray();
        /** @var UserServices $userServices */
        $userServices = app()->make(UserServices::class);
        $userInfo = $userServices->getUserInfo($uid);
        return [
            'debt_id' => (int)$debt['id'],
            'order_id' => (string)$order['order_id'],
            'order_sn' => (string)($debt['order_sn'] ?? $order['order_id']),
            'pay_price' => $pending,
            'repay_amount' => $pending,
            'now_money' => (float)($userInfo['now_money'] ?? 0),
            'yue_pay_status' => (int)sys_config('balance_func_status') && (int)sys_config('yue_pay_status') == 1 ? 1 : 2,
            'pay_weixin_open' => (int)sys_config('pay_weixin_open') ?? 0,
            'ali_pay_status' => (bool)sys_config('ali_pay_status'),
            'offline_pay_status' => 0,
            'invalid_time' => time() + 1800,
        ];
    }

    /**
     * 手机端欠款还款支付
     */
    public function repayPayMobile(int $uid, string $orderSn, float $amount, string $payType, string $from = 'routine', string $quitUrl = ''): array
    {
        /** @var StoreOrderServices $orderServices */
        $orderServices = app()->make(StoreOrderServices::class);
        $order = $orderServices->getUserOrderDetail($orderSn, $uid);
        if (!$order) {
            throw new ValidateException('订单不存在');
        }
        $order = is_array($order) ? $order : $order->toArray();
        $debt = $this->dao->get(['order_id' => (int)$order['id'], 'uid' => $uid]);
        if (!$debt) {
            throw new ValidateException('欠款记录不存在');
        }
        $debt = is_array($debt) ? $debt : $debt->toArray();
        $debtId = (int)$debt['id'];
        $pending = (float)bcsub((string)$debt['total_debt'], (string)$debt['repaid_debt'], 2);
        if ($amount <= 0 || bccomp((string)$amount, (string)$pending, 2) > 0) {
            throw new ValidateException('还款金额不正确');
        }

        if ($payType === PayServices::YUE_PAY || $payType === 'yue') {
            $res = $this->repayPay($debtId, $amount, PayServices::YUE_PAY, [
                'pay_store_id' => (int)($order['store_id'] ?? 0),
            ]);
            $res['order_id'] = $orderSn;
            return $res;
        }

        if (in_array($payType, [PayServices::WEIXIN_PAY, 'weixin', 'routine'])) {
            $payTypeKey = $from === 'routine' ? 'routine' : ($from === 'weixinh5' ? 'weixinh5' : 'weixin');
            /** @var WechatUserServices $wechatUser */
            $wechatUser = app()->make(WechatUserServices::class);
            $userType = in_array($from, ['routine', 'weixin', 'weixinh5']) ? ($from === 'routine' ? 'routine' : 'wechat') : 'routine';
            $openid = $wechatUser->uidToOpenid($uid, $userType);
            if (!$openid) {
                throw new ValidateException('获取用户openid失败,无法支付');
            }
            $repayNo = $this->generateRepayNo();
            $body = '欠款补交-' . ($debt['order_sn'] ?: $repayNo);
            $payStoreId = (int)($order['store_id'] ?? 0);
            // 先锁欠款创建未付补交单并 DB 持久化待入账，再调起渠道
            $repayOrder = $this->transaction(function () use ($debtId, $amount, $payStoreId, $repayNo) {
                $locked = $this->lockDebtForUpdate($debtId);
                $this->assertDebtRepayableLocked($locked, $amount, 0);
                $this->assertNoInFlightDebtRepayOrder((int)$locked['order_id']);
                $created = $this->createRepayOrder($debtId, $amount, [
                    'pay_type' => PayServices::WEIXIN_PAY,
                    'debt_item_id' => 0,
                    'pay_store_id' => $payStoreId,
                    'repay_no' => $repayNo,
                ]);
                $this->rememberDebtRepayPending([
                    'debt_id' => $debtId,
                    'debt_item_id' => 0,
                    'amount' => $amount,
                    'pay_type' => PayServices::WEIXIN_PAY,
                    'pay_store_id' => $payStoreId,
                    'staff_id' => 0,
                    'repay_order_id' => (int)$created['id'],
                    'repay_no' => $repayNo,
                ]);
                return $created;
            });
            $pay = new PayServices();
            try {
                $jsConfig = $pay->pay($payTypeKey, $openid, $repayNo, (string)$amount, 'debt_repay', substrUTf8($body, 30));
            } catch (\Throwable $e) {
                throw new ValidateException('支付失败：' . $e->getMessage());
            }
            if ($from === 'weixinh5') {
                return ['status' => 'wechat_h5_pay', 'jsConfig' => $jsConfig, 'order_id' => $orderSn, 'repay_no' => $repayNo, 'repay_order_id' => (int)$repayOrder['id']];
            }
            return ['status' => 'wechat_pay', 'jsConfig' => $jsConfig, 'order_id' => $orderSn, 'repay_no' => $repayNo, 'repay_order_id' => (int)$repayOrder['id']];
        }

        if (in_array($payType, [PayServices::ALIAPY_PAY, 'alipay'])) {
            if (!$quitUrl && $from !== 'routine') {
                throw new ValidateException('请传入支付宝支付回调URL');
            }
            $repayNo = $this->generateRepayNo();
            $body = '欠款补交-' . ($debt['order_sn'] ?: $repayNo);
            $payStoreId = (int)($order['store_id'] ?? 0);
            $repayOrder = $this->transaction(function () use ($debtId, $amount, $payStoreId, $repayNo) {
                $locked = $this->lockDebtForUpdate($debtId);
                $this->assertDebtRepayableLocked($locked, $amount, 0);
                $this->assertNoInFlightDebtRepayOrder((int)$locked['order_id']);
                $created = $this->createRepayOrder($debtId, $amount, [
                    'pay_type' => PayServices::ALIAPY_PAY,
                    'debt_item_id' => 0,
                    'pay_store_id' => $payStoreId,
                    'repay_no' => $repayNo,
                ]);
                $this->rememberDebtRepayPending([
                    'debt_id' => $debtId,
                    'debt_item_id' => 0,
                    'amount' => $amount,
                    'pay_type' => PayServices::ALIAPY_PAY,
                    'pay_store_id' => $payStoreId,
                    'staff_id' => 0,
                    'repay_order_id' => (int)$created['id'],
                    'repay_no' => $repayNo,
                ]);
                return $created;
            });
            $pay = new PayServices();
            $isCode = $from === 'routine' || $from === 'pc';
            try {
                $jsConfig = $pay->pay('alipay', $quitUrl, $repayNo, (string)$amount, 'debt_repay', substrUTf8($body, 30), $isCode);
            } catch (\Throwable $e) {
                throw new ValidateException('支付失败：' . $e->getMessage());
            }
            return [
                'status' => PayServices::ALIAPY_PAY . '_pay',
                'jsConfig' => $jsConfig,
                'order_id' => $orderSn,
                'repay_no' => $repayNo,
                'repay_order_id' => (int)$repayOrder['id'],
            ];
        }

        throw new ValidateException('不支持的支付方式');
    }

    /**
     * 支付回调完成欠款还款（模拟/真实渠道成功回调入口）
     * - 优先 Redis，缺失则从补交单 DB（notify_data / order_id=repay_no）恢复
     * - DB 也找不到则返回 false（禁止假装成功）
     * - 欠款主表 FOR UPDATE + repay_no/trade_no 幂等，只入账一次
     */
    public function completeRepayFromNotify(string $repayNo, string $tradeNo = '', string $payType = ''): bool
    {
        $repayNo = trim($repayNo);
        if ($repayNo === '') {
            return false;
        }
        $cacheKey = 'debt_repay_pending_' . $repayNo;

        // 已入账：重复回调成功（幂等）
        $existed = Db::name('store_debt_repay')->where('repay_no', $repayNo)->find();
        if ($existed) {
            $this->clearDebtRepayPendingMark((int)($existed['repay_order_id'] ?? 0), $repayNo);
            return true;
        }
        if ($tradeNo !== '') {
            $paidByTrade = (int)Db::name('store_order')
                ->where('is_debt_repay', 1)
                ->where('trade_no', $tradeNo)
                ->where('paid', 1)
                ->value('id');
            if ($paidByTrade > 0) {
                $existByOrder = Db::name('store_debt_repay')->where('repay_order_id', $paidByTrade)->find();
                if ($existByOrder) {
                    $this->clearDebtRepayPendingMark($paidByTrade, $repayNo);
                    return true;
                }
            }
        }

        $pending = Cache::get($cacheKey);
        if (!is_array($pending) || (int)($pending['debt_id'] ?? 0) <= 0) {
            $pending = $this->loadDebtRepayPendingFromDb($repayNo);
        }
        // Redis/DB 都找不到待入账记录：渠道已扣款但本系统无法闭环 → 失败（禁止 return true）
        if (!is_array($pending) || (int)($pending['debt_id'] ?? 0) <= 0 || (float)($pending['amount'] ?? 0) <= 0) {
            return false;
        }

        $debtId = (int)$pending['debt_id'];
        $amount = (float)$pending['amount'];
        $repayOrderId = (int)($pending['repay_order_id'] ?? 0);

        try {
            $this->transaction(function () use ($debtId, $amount, $pending, $repayNo, $tradeNo, $payType, $repayOrderId) {
                $exist = Db::name('store_debt_repay')->where('repay_no', $repayNo)->lock(true)->find();
                if ($exist) {
                    return;
                }
                if ($tradeNo !== '') {
                    $paidByTrade = (int)Db::name('store_order')
                        ->where('is_debt_repay', 1)
                        ->where('trade_no', $tradeNo)
                        ->where('paid', 1)
                        ->lock(true)
                        ->value('id');
                    if ($paidByTrade > 0 && Db::name('store_debt_repay')->where('repay_order_id', $paidByTrade)->value('id')) {
                        return;
                    }
                }

                $debt = $this->lockDebtForUpdate($debtId);
                $resolvedPayType = (string)($pending['pay_type'] ?? $payType ?: PayServices::WEIXIN_PAY);

                if ($repayOrderId > 0) {
                    /** @var StoreOrderServices $orderServices */
                    $orderServices = app()->make(StoreOrderServices::class);
                    $repayOrder = $orderServices->get($repayOrderId);
                    if ($repayOrder && !(int)($repayOrder['paid'] ?? 0)) {
                        $repayOrder = is_array($repayOrder) ? $repayOrder : $repayOrder->toArray();
                        $this->finalizeRepayPayment($repayOrder, $resolvedPayType, [
                            'other' => ['trade_no' => $tradeNo],
                        ]);
                    }
                }

                // 锁后重新读取：已结清则不再 apply（防超额）；是否已有本 repay_no 由事务外二次确认（避免 RR 快照看不到他事务已提交行）
                $debt = $this->lockDebtForUpdate($debtId);
                if ((int)$debt['status'] !== StoreDebt::STATUS_PENDING) {
                    return;
                }

                $this->applyRepay($debtId, $amount, [
                    'debt_item_id' => (int)($pending['debt_item_id'] ?? 0),
                    'pay_type' => $resolvedPayType,
                    'pay_store_id' => (int)($pending['pay_store_id'] ?? 0),
                    'staff_id' => (int)($pending['staff_id'] ?? 0),
                    'repay_no' => $repayNo,
                    'repay_order_id' => $repayOrderId,
                    'trade_no' => $tradeNo,
                ]);
                $this->clearDebtRepayPendingMark($repayOrderId, $repayNo);
            });
        } catch (ValidateException $e) {
            if (Db::name('store_debt_repay')->where('repay_no', $repayNo)->value('id')) {
                $this->clearDebtRepayPendingMark($repayOrderId, $repayNo);
                return true;
            }
            return false;
        } catch (\Throwable $e) {
            return false;
        }

        // 二次确认：必须已写出补交记录，否则视为失败（禁止假成功）
        if (!Db::name('store_debt_repay')->where('repay_no', $repayNo)->value('id')) {
            return false;
        }
        Cache::delete($cacheKey);
        return true;
    }

    protected function processCombinationRepay(int $uid, array $combinationInfo, string $userCode = '', int $linkId = 0): void
    {
        $yuePay = '0.00';
        foreach ($combinationInfo as $row) {
            if ((int)($row['activePay'] ?? 0) !== 3) {
                continue;
            }
            $subType = $row['pay_sub_type'] ?? 'balance';
            if ($subType === 'balance' || $subType === '') {
                $yuePay = bcadd($yuePay, (string)($row['price'] ?? 0), 2);
            }
        }
        if (bccomp($yuePay, '0', 2) > 0) {
            if (!$uid) {
                throw new ValidateException('余额还款需要会员信息');
            }
            if ((int)sys_config('is_cashier_yue_pay_verify') && $userCode) {
                /** @var UserServices $userServices */
                $userServices = app()->make(UserServices::class);
                $userInfo = $userServices->getUserInfo($uid, ['uid', 'bar_code']);
                if (!$userInfo || $userInfo['bar_code'] != $userCode) {
                    throw new ValidateException('身份不一致，请重新支付');
                }
            }
            $this->deductUserBalance($uid, (float)$yuePay, $linkId);
        }
    }

    protected function deductUserBalance(int $uid, float $amount, int $linkId = 0): void
    {
        $doDeduct = function () use ($uid, $amount, $linkId) {
            /** @var \app\services\user\UserBalanceAtomicServices $balanceAtomic */
            $balanceAtomic = app()->make(\app\services\user\UserBalanceAtomicServices::class);
            $deduct = $balanceAtomic->deductPreferBen($uid, (string)$amount, 'debt_repay', $linkId);
            if ($linkId > 0) {
                $balanceAtomic->writeOrderPaidSnapshot(
                    $linkId,
                    (string)$deduct['paid_ben'],
                    (string)$deduct['paid_give']
                );
            }
        };
        // 已在外层补交事务内则并入，避免提前提交
        if ($this->isInDbTransaction()) {
            $doDeduct();
        } else {
            $this->transaction($doDeduct);
        }
    }

    protected function isInDbTransaction(): bool
    {
        try {
            $pdo = Db::getPdo();
            return $pdo && $pdo->inTransaction();
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function checkRepayPayStatus(string $repayNo): array
    {
        $cacheKey = 'debt_repay_pending_' . $repayNo;
        $pending = Cache::get($cacheKey);
        if (!$pending) {
            // 已入账则视为成功
            $exist = Db::name('store_debt_repay')->where('repay_no', $repayNo)->find();
            if ($exist) {
                return ['status' => true, 'message' => '还款成功'];
            }
            return ['status' => false, 'message' => '还款单不存在或已处理'];
        }
        // 简化：轮询时若缓存仍在则继续等待；支付回调场景可扩展
        return ['status' => false, 'message' => '等待支付中'];
    }

    /**
     * 还款（支付成功后调用）：欠款主表 FOR UPDATE + repay_no 幂等
     */
    public function applyRepay(int $debtId, float $amount, array $extra = []): array
    {
        $runner = function () use ($debtId, $amount, $extra) {
            $debt = $this->lockDebtForUpdate($debtId);
            $repayNo = !empty($extra['repay_no']) ? (string)$extra['repay_no'] : $this->generateRepayNo();
            $exist = Db::name('store_debt_repay')->where('repay_no', $repayNo)->lock(true)->find();
            if ($exist) {
                return [
                    'repay_id' => (int)$exist['id'],
                    'status' => (int)$debt['status'],
                    'idempotent' => true,
                ];
            }
            // 交易号幂等：同一渠道交易号已关联补交单则不再入账
            $tradeNo = trim((string)($extra['trade_no'] ?? ''));
            if ($tradeNo !== '') {
                $byTrade = (int)Db::name('store_order')
                    ->where('is_debt_repay', 1)
                    ->where('trade_no', $tradeNo)
                    ->where('paid', 1)
                    ->value('id');
                if ($byTrade > 0) {
                    $existByOrder = Db::name('store_debt_repay')->where('repay_order_id', $byTrade)->find();
                    if ($existByOrder) {
                        return [
                            'repay_id' => (int)$existByOrder['id'],
                            'status' => (int)$debt['status'],
                            'idempotent' => true,
                        ];
                    }
                }
            }

            $this->assertDebtRepayableLocked($debt, $amount, (int)($extra['debt_item_id'] ?? 0));
            $debtItemId = (int)($extra['debt_item_id'] ?? 0);
            $now = time();
            try {
                $repayModel = $this->repayDao->save([
                    'repay_no' => $repayNo,
                    'debt_id' => $debtId,
                    'debt_item_id' => $debtItemId,
                    'order_id' => (int)$debt['order_id'],
                    'order_sn' => (string)$debt['order_sn'],
                    'repay_order_id' => (int)($extra['repay_order_id'] ?? 0),
                    'uid' => (int)$debt['uid'],
                    'repay_amount' => $amount,
                    'pay_type' => (string)($extra['pay_type'] ?? ''),
                    'pay_store_id' => (int)($extra['pay_store_id'] ?? 0),
                    'debt_store_id' => (int)$debt['store_id'],
                    'staff_id' => (int)($extra['staff_id'] ?? 0),
                    'combination_info' => json_encode($extra['combination_info'] ?? [], JSON_UNESCAPED_UNICODE),
                    'add_time' => $now,
                ]);
            } catch (\Throwable $e) {
                // uniq_repay_no：并发下后到者视为幂等成功
                $exist = Db::name('store_debt_repay')->where('repay_no', $repayNo)->find();
                if ($exist) {
                    return [
                        'repay_id' => (int)$exist['id'],
                        'status' => (int)Db::name('store_debt')->where('id', $debtId)->value('status'),
                        'idempotent' => true,
                    ];
                }
                throw $e;
            }
            $repayId = (int)$repayModel->id;
            $newRepaid = (float)bcadd((string)$debt['repaid_debt'], (string)$amount, 2);
            if (bccomp((string)$newRepaid, (string)$debt['total_debt'], 2) > 0) {
                throw new ValidateException('还款金额不正确');
            }
            $status = bccomp((string)$newRepaid, (string)$debt['total_debt'], 2) >= 0
                ? StoreDebt::STATUS_SETTLED
                : StoreDebt::STATUS_PENDING;
            $this->dao->update($debtId, [
                'repaid_debt' => $newRepaid,
                'status' => $status,
                'update_time' => $now,
            ]);
            /** @var StoreOrderServices $orderServices */
            $orderServices = app()->make(StoreOrderServices::class);
            $order = $orderServices->get((int)$debt['order_id']);
            if ($order) {
                $orderRepaid = (float)bcadd((string)($order['repaid_debt_amount'] ?? 0), (string)$amount, 2);
                $orderServices->update((int)$debt['order_id'], ['repaid_debt_amount' => $orderRepaid]);
                if ((int)($order['order_type'] ?? 0) === 1 && (int)($order['link_id'] ?? 0) > 0) {
                    /** @var UserRechargeDao $rechargeDao */
                    $rechargeDao = app()->make(UserRechargeDao::class);
                    $recharge = $rechargeDao->get((int)$order['link_id']);
                    if ($recharge) {
                        $rechargeRepaid = (float)bcadd((string)($recharge['repaid_debt_amount'] ?? 0), (string)$amount, 2);
                        $rechargeDao->update((int)$order['link_id'], ['repaid_debt_amount' => $rechargeRepaid]);
                    }
                }
            }
            $this->allocateRepayToItems($debtId, $debtItemId, $amount);
            $this->allocateRepayToOrderCart($debt, $debtItemId, $amount);
            return ['repay_id' => $repayId, 'status' => $status];
        };

        if ($this->isInDbTransaction()) {
            return $runner();
        }
        return $this->transaction($runner);
    }

    protected function allocateRepayToItems(int $debtId, int $debtItemId, float $amount): void
    {
        if ($debtItemId > 0) {
            $item = $this->itemDao->get($debtItemId);
            if ($item) {
                $repaid = (float)bcadd((string)$item['repaid_debt'], (string)$amount, 2);
                $this->itemDao->update($debtItemId, ['repaid_debt' => $repaid, 'update_time' => time()]);
            }
            return;
        }
        $items = $this->itemDao->search(['debt_id' => $debtId])->select()->toArray();
        $left = (string)$amount;
        foreach ($items as $item) {
            if (bccomp($left, '0', 2) <= 0) {
                break;
            }
            $pending = bcsub((string)$item['debt_amount'], (string)$item['repaid_debt'], 2);
            if (bccomp($pending, '0', 2) <= 0) {
                continue;
            }
            $use = bccomp($left, $pending, 2) > 0 ? $pending : $left;
            $repaid = bcadd((string)$item['repaid_debt'], $use, 2);
            $this->itemDao->update($item['id'], ['repaid_debt' => (float)$repaid, 'update_time' => time()]);
            $left = bcsub($left, $use, 2);
        }
    }

    protected function allocateRepayToOrderCart(array $debt, int $debtItemId, float $amount): void
    {
        /** @var StoreOrderCartInfoServices $cartServices */
        $cartServices = app()->make(StoreOrderCartInfoServices::class);
        if ($debtItemId > 0) {
            $item = $this->itemDao->get($debtItemId);
            if ($item && $item['cart_info_id']) {
                $row = $cartServices->get($item['cart_info_id']);
                if ($row) {
                    $repaid = (float)bcadd((string)($row['repaid_debt_amount'] ?? 0), (string)$amount, 2);
                    $cartServices->update((int)$item['cart_info_id'], ['repaid_debt_amount' => $repaid]);
                }
            }
            return;
        }
        $items = $this->itemDao->search(['debt_id' => (int)$debt['id']])->select()->toArray();
        $left = (string)$amount;
        foreach ($items as $item) {
            if (bccomp($left, '0', 2) <= 0) {
                break;
            }
            $row = $cartServices->get($item['cart_info_id']);
            if (!$row) {
                continue;
            }
            $pending = bcsub((string)$item['debt_amount'], (string)$item['repaid_debt'], 2);
            if (bccomp($pending, '0', 2) <= 0) {
                continue;
            }
            $use = bccomp($left, $pending, 2) > 0 ? $pending : $left;
            $repaid = bcadd((string)($row['repaid_debt_amount'] ?? 0), $use, 2);
            $cartServices->update((int)$item['cart_info_id'], ['repaid_debt_amount' => (float)$repaid]);
            $left = bcsub($left, $use, 2);
        }
    }

    /**
     * 是否补交订单
     */
    public function isDebtRepayOrder(array $order): bool
    {
        return !empty($order['is_debt_repay']);
    }

    /**
     * 补交订单退款金额校验：本次退款 + 订单已累计退款 不得超过补交单 pay_price
     * @param float $refundAmount 本次操作退款金额
     * @param float $alreadyRefunded 订单上已累计退款（不含本次）
     */
    public function validateRepayOrderRefundAmount(array $order, float $refundAmount, float $alreadyRefunded = 0): void
    {
        if (!$this->isDebtRepayOrder($order)) {
            return;
        }
        $payPrice = (float)($order['pay_price'] ?? 0);
        $totalRefund = bcadd((string)$refundAmount, (string)$alreadyRefunded, 2);
        if (bccomp($totalRefund, (string)$payPrice, 2) > 0) {
            throw new ValidateException('退款金额不能超过本次补交金额' . number_format($payPrice, 2, '.', '') . '元');
        }
    }

    /**
     * 主订单退款/作废时联动处理关联补交订单
     *
     * @param bool $forVoid true=作废语义（统一作废编排）；false=退款语义（历史 refund_status）
     * @param string $parentOperationNo 作废时父终态操作号（派生 token / 幂等）
     */
    public function voidRepayOrdersByOriginOrderId(
        int $originOrderId,
        string $reason = '主订单退款',
        bool $forVoid = false,
        string $parentOperationNo = ''
    ): void {
        if ($originOrderId <= 0) {
            return;
        }
        $q = StoreOrder::where('debt_repay_origin_order_id', $originOrderId)
            ->where('is_debt_repay', 1)
            ->where('paid', 1);
        if ($forVoid) {
            $q->where('terminal_action', 0)->where('refund_status', 0);
        } else {
            $q->where('refund_status', 0);
        }
        $orderIds = $q->column('id');
        foreach ($orderIds as $orderId) {
            $this->voidRepayOrder((int)$orderId, $reason, $forVoid, $parentOperationNo);
        }
    }

    /**
     * 处理单个补交订单：作废走统一编排；退款联动仍写退款状态（兼容旧退款事件）
     */
    public function voidRepayOrder(
        int $orderId,
        string $reason = '主订单退款',
        bool $forVoid = false,
        string $parentOperationNo = ''
    ): void {
        if ($orderId <= 0) {
            return;
        }
        $order = StoreOrder::where('id', $orderId)->find();
        if (!$order || (int)$order['paid'] !== 1) {
            return;
        }
        if (empty($order['is_debt_repay'])) {
            return;
        }
        $order = is_array($order) ? $order : $order->toArray();

        if ($forVoid) {
            if ((int)($order['terminal_action'] ?? 0) === StoreOrderTerminalOperation::ACTION_VOID) {
                return;
            }
            if ((int)($order['refund_status'] ?? 0) !== 0) {
                return;
            }
            /** @var StoreOrderVoidServices $voidSvc */
            $voidSvc = app()->make(StoreOrderVoidServices::class);
            $token = $parentOperationNo !== ''
                ? ('void-repay:' . $parentOperationNo . ':' . $orderId)
                : ('void-repay-direct:' . $orderId);
            $result = $voidSvc->voidWholeOrder([
                'store_order_id' => $orderId,
                'store_scope' => 0,
                'request_token' => $token,
                'reason' => $reason,
                'operator_type' => 'system',
                'operator_id' => 0,
                'source_type' => StoreOrderTerminalOperation::SOURCE_ADMIN,
                'parent_operation_no' => $parentOperationNo,
            ]);
            $childOpNo = (string)($result['operation_no'] ?? '');
            if ($childOpNo !== '') {
                /** @var \app\services\order\terminal\RefundSideEffectOutboxServices $outbox */
                $outbox = app()->make(\app\services\order\terminal\RefundSideEffectOutboxServices::class);
                $outbox->flushPending($childOpNo);
            }
            return;
        }

        if ((int)($order['refund_status'] ?? 0) !== 0) {
            return;
        }
        StoreOrder::where('id', $orderId)->update([
            'back_reason' => $reason,
            'refund_status' => 2,
            'refund_type' => 6,
        ]);
        UserCardHolder::where('oid', $orderId)->update(['is_del' => 1]);
        StaffYeji::where('link_id', $orderId)->where('type', 2)->update(['status' => 1]);
        OrderStatusJob::dispatch([$orderId, 'refund_split', [
            'change_message' => $reason,
            'change_manager_type' => 'system',
        ]]);
    }

    /**
     * 补交订单退款后恢复原订单欠款（退款多少，已还减少多少）
     */
    public function reverseRepayOnRefund(int $repayOrderId, float $refundAmount): void
    {
        if ($repayOrderId <= 0 || $refundAmount <= 0) {
            return;
        }
        $repay = $this->repayDao->get(['repay_order_id' => $repayOrderId]);
        if (!$repay) {
            return;
        }
        $repay = is_array($repay) ? $repay : $repay->toArray();
        $debtId = (int)($repay['debt_id'] ?? 0);
        if (!$debtId) {
            return;
        }
        $debt = $this->dao->get($debtId);
        if (!$debt) {
            return;
        }
        $debt = is_array($debt) ? $debt : $debt->toArray();
        if ((int)($debt['status'] ?? 0) === StoreDebt::STATUS_VOID) {
            return;
        }
        $debtItemId = (int)($repay['debt_item_id'] ?? 0);
        $now = time();
        $newRepaid = (float)bcsub((string)($debt['repaid_debt'] ?? 0), (string)$refundAmount, 2);
        if ($newRepaid < 0) {
            $newRepaid = 0.0;
        }
        $status = bccomp((string)$newRepaid, (string)($debt['total_debt'] ?? 0), 2) >= 0
            ? StoreDebt::STATUS_SETTLED
            : StoreDebt::STATUS_PENDING;
        $this->dao->update($debtId, [
            'repaid_debt' => $newRepaid,
            'status' => $status,
            'update_time' => $now,
        ]);
        /** @var StoreOrderServices $orderServices */
        $orderServices = app()->make(StoreOrderServices::class);
        $originOrderId = (int)($debt['order_id'] ?? 0);
        if ($originOrderId > 0) {
            $order = $orderServices->get($originOrderId);
            if ($order) {
                $order = is_array($order) ? $order : $order->toArray();
                $orderRepaid = (float)bcsub((string)($order['repaid_debt_amount'] ?? 0), (string)$refundAmount, 2);
                if ($orderRepaid < 0) {
                    $orderRepaid = 0.0;
                }
                $orderServices->update($originOrderId, ['repaid_debt_amount' => $orderRepaid]);
                if ((int)($order['order_type'] ?? 0) === 1 && (int)($order['link_id'] ?? 0) > 0) {
                    /** @var UserRechargeDao $rechargeDao */
                    $rechargeDao = app()->make(UserRechargeDao::class);
                    $recharge = $rechargeDao->get((int)$order['link_id']);
                    if ($recharge) {
                        $rechargeRepaid = (float)bcsub((string)($recharge['repaid_debt_amount'] ?? 0), (string)$refundAmount, 2);
                        if ($rechargeRepaid < 0) {
                            $rechargeRepaid = 0.0;
                        }
                        $rechargeDao->update((int)$order['link_id'], ['repaid_debt_amount' => $rechargeRepaid]);
                    }
                }
            }
        }
        $this->reverseAllocateRepayToItems($debtId, $debtItemId, $refundAmount);
        $this->reverseAllocateRepayToOrderCart($debt, $debtItemId, $refundAmount);
    }

    protected function reverseAllocateRepayToItems(int $debtId, int $debtItemId, float $amount): void
    {
        if ($debtItemId > 0) {
            $item = $this->itemDao->get($debtItemId);
            if ($item) {
                $repaid = (float)bcsub((string)($item['repaid_debt'] ?? 0), (string)$amount, 2);
                if ($repaid < 0) {
                    $repaid = 0.0;
                }
                $this->itemDao->update($debtItemId, ['repaid_debt' => $repaid, 'update_time' => time()]);
            }
            return;
        }
        $items = array_reverse($this->itemDao->search(['debt_id' => $debtId])->select()->toArray());
        $left = (string)$amount;
        foreach ($items as $item) {
            if (bccomp($left, '0', 2) <= 0) {
                break;
            }
            $repaidOnItem = (string)($item['repaid_debt'] ?? 0);
            if (bccomp($repaidOnItem, '0', 2) <= 0) {
                continue;
            }
            $use = bccomp($left, $repaidOnItem, 2) > 0 ? $repaidOnItem : $left;
            $repaid = bcsub($repaidOnItem, $use, 2);
            $this->itemDao->update($item['id'], ['repaid_debt' => (float)$repaid, 'update_time' => time()]);
            $left = bcsub($left, $use, 2);
        }
    }

    protected function reverseAllocateRepayToOrderCart(array $debt, int $debtItemId, float $amount): void
    {
        /** @var StoreOrderCartInfoServices $cartServices */
        $cartServices = app()->make(StoreOrderCartInfoServices::class);
        if ($debtItemId > 0) {
            $item = $this->itemDao->get($debtItemId);
            if ($item && $item['cart_info_id']) {
                $row = $cartServices->get($item['cart_info_id']);
                if ($row) {
                    $repaid = (float)bcsub((string)($row['repaid_debt_amount'] ?? 0), (string)$amount, 2);
                    if ($repaid < 0) {
                        $repaid = 0.0;
                    }
                    $cartServices->update((int)$item['cart_info_id'], ['repaid_debt_amount' => $repaid]);
                }
            }
            return;
        }
        $items = array_reverse($this->itemDao->search(['debt_id' => (int)$debt['id']])->select()->toArray());
        $left = (string)$amount;
        foreach ($items as $item) {
            if (bccomp($left, '0', 2) <= 0) {
                break;
            }
            $row = $cartServices->get($item['cart_info_id']);
            if (!$row) {
                continue;
            }
            $repaidOnItem = (string)($row['repaid_debt_amount'] ?? 0);
            if (bccomp($repaidOnItem, '0', 2) <= 0) {
                continue;
            }
            $use = bccomp($left, $repaidOnItem, 2) > 0 ? $repaidOnItem : $left;
            $repaid = bcsub($repaidOnItem, $use, 2);
            $cartServices->update((int)$item['cart_info_id'], ['repaid_debt_amount' => (float)$repaid]);
            $left = bcsub($left, $use, 2);
        }
    }
}
