<?php
namespace app\services\merchant;

use app\dao\store\SystemStoreStaffDao;
use app\jobs\user\UserBelongStoreJob;
use app\model\order\StoreDebt;
use app\model\store\SystemStore;
use app\model\yeji\StaffYeji;
use app\services\BaseServices;
use app\services\store\StoreUserServices;
use app\services\store\UserStoreUserServices;
use app\services\user\level\SystemUserLevelServices;
use app\services\user\level\UserLevelServices;
use app\services\user\UserListStatServices;
use app\services\user\UserServices;
use think\exception\ValidateException;
use think\facade\Db;

/**
 * 商家端客户：客群统计、创建客户
 */
class MerchantCustomerServices extends BaseServices
{
    public function segments(int $uid, array $access): array
    {
        /** @var MerchantAccessServices $accessServices */
        $accessServices = app()->make(MerchantAccessServices::class);
        $scopeStoreIds = $accessServices->requireScopeStoreIds($access);

        $list = [
            [
                'key' => 'birthday_today',
                'name' => '今日生日客户',
                'desc' => '生日为当天',
                'count' => 0,
                'action' => '生日关怀',
                'filter' => ['birthday_type' => 1],
            ],
            [
                'key' => 'birthday_month',
                'name' => '本月生日客户',
                'desc' => '生日在当月',
                'count' => 0,
                'action' => '提前邀约',
                'filter' => ['birthday_type' => 3],
            ],
            [
                'key' => 'new_month',
                'name' => '本月新增客户',
                'desc' => '本月第一次产生现金业绩有效下单的客户',
                'count' => 0,
                'action' => '首次回访',
                'filter' => ['segment' => 'new_month'],
                'metric_code' => MerchantCustomerMetricServices::CODE_NEW_CUSTOMER,
            ],
            [
                'key' => 'debt',
                'name' => '欠款客户',
                'desc' => '存在未结清欠款的去重客户（store_debt.status=待还）',
                'count' => 0,
                'action' => '查看名单',
                'filter' => ['segment' => 'debt'],
            ],
            [
                'key' => 'low_balance',
                'name' => '卡余额不足客户',
                'desc' => '门店阈值配置待确认',
                'count' => null,
                'action' => '续卡充值',
                'filter' => ['segment' => 'low_balance'],
                'developing' => true,
            ],
            [
                'key' => 'sleep',
                'name' => '沉睡客户',
                'desc' => '超过90天未消费',
                'count' => null,
                'action' => '唤醒召回',
                'filter' => ['segment' => 'sleep'],
                'developing' => true,
            ],
            [
                'key' => 'invite',
                'name' => '重点邀约客户',
                'desc' => '近30天到店、近7天未到店',
                'count' => null,
                'action' => '邀约预约',
                'filter' => ['segment' => 'invite'],
                'developing' => true,
            ],
            [
                'key' => 'follow',
                'name' => '待回访客户',
                'desc' => '完成服务后尚未回访',
                'count' => null,
                'action' => '回访记录',
                'filter' => ['segment' => 'follow'],
                'developing' => true,
            ],
            [
                'key' => 'card_expire',
                'name' => '卡项即将到期',
                'desc' => '卡项将在设定天数内到期',
                'count' => null,
                'action' => '续期',
                'filter' => ['segment' => 'card_expire'],
                'developing' => true,
            ],
            [
                'key' => 'course',
                'name' => '疗程待跟进',
                'desc' => '已购疗程未按计划服务',
                'count' => null,
                'action' => '预约服务',
                'filter' => ['segment' => 'course'],
                'developing' => true,
            ],
        ];

        if (!$scopeStoreIds) {
            foreach ($list as &$item) {
                if (!($item['developing'] ?? false)) {
                    $item['count'] = null;
                    $item['developing'] = true;
                    $item['desc'] = ($item['desc'] ?? '') . '（无门店范围）';
                }
            }
            unset($item);
            return $list;
        }

        try {
            $today = date('md');
            $month = date('m');
            $list[0]['count'] = (int)Db::name('user')->alias('u')
                ->join('store_user su', 'su.uid = u.uid')
                ->whereIn('su.store_id', $scopeStoreIds)
                ->whereRaw("FROM_UNIXTIME(u.birthday,'%m%d') = ?", [$today])
                ->where('u.birthday', '>', 0)
                ->count('DISTINCT u.uid');
            $list[1]['count'] = (int)Db::name('user')->alias('u')
                ->join('store_user su', 'su.uid = u.uid')
                ->whereIn('su.store_id', $scopeStoreIds)
                ->whereRaw("FROM_UNIXTIME(u.birthday,'%m') = ?", [$month])
                ->where('u.birthday', '>', 0)
                ->count('DISTINCT u.uid');
        } catch (\Throwable $e) {
        }

        // 本月新增：唯一出口 MerchantCustomerMetricServices（系统首次下单）
        $monthStart = strtotime(date('Y-m-01 00:00:00'));
        $monthEnd = time();
        /** @var MerchantCustomerMetricServices $customerMetrics */
        $customerMetrics = app()->make(MerchantCustomerMetricServices::class);
        $newMetric = $customerMetrics->newCustomerMetric($scopeStoreIds, $monthStart, $monthEnd);
        $list[2]['count'] = $newMetric['number'];
        $list[2]['metric_code'] = $newMetric['metric_code'];
        $list[2]['title'] = $newMetric['title'];
        $list[2]['tooltip_api'] = $newMetric['tooltip_api'];
        $list[2]['detail_api'] = $newMetric['detail_api'];
        $list[2]['detail_developing'] = $newMetric['detail_developing'];
        if ($newMetric['developing']) {
            $list[2]['developing'] = true;
            $list[2]['desc'] = $newMetric['note'];
        } else {
            $list[2]['developing'] = false;
            $list[2]['desc'] = $newMetric['note'];
        }

        try {
            $perms = $access['permissions'] ?? [];
            if (!in_array('merchant.debt.view', $perms, true)) {
                // 无欠款权限：不泄露待还人数；勿标 developing
                $list[3]['count'] = null;
                $list[3]['no_permission'] = true;
                $list[3]['action'] = '暂无权限';
                $list[3]['desc'] = '暂无欠款查看权限';
            } else {
                $list[3]['count'] = (int)Db::name('store_debt')
                    ->whereIn('store_id', $scopeStoreIds)
                    ->where('status', StoreDebt::STATUS_PENDING)
                    ->count('DISTINCT uid');
                $list[3]['no_permission'] = false;
            }
        } catch (\Throwable $e) {
            $list[3]['count'] = null;
            $list[3]['no_permission'] = true;
            $list[3]['action'] = '暂无权限';
        }

        return $list;
    }

    public function createCustomer(int $operatorUid, array $access, array $data): array
    {
        $perms = $access['permissions'] ?? [];
        if (!in_array('merchant.customer.create', $perms, true)) {
            throw new ValidateException('暂无新增客户权限');
        }
        $storeId = (int)($access['active_store_id'] ?? 0);
        $scope = $access['scope_store_ids'] ?? [];
        if ($storeId <= 0 || !in_array($storeId, array_map('intval', $scope), true)) {
            throw new ValidateException('请先选择有效门店');
        }
        $phone = trim((string)($data['phone'] ?? ''));
        $nickname = trim((string)($data['nickname'] ?? ''));
        if ($phone === '') {
            throw new ValidateException('请输入手机号');
        }
        if (!preg_match('/^1\d{10}$/', $phone)) {
            throw new ValidateException('手机号格式不正确');
        }
        /** @var UserServices $userServices */
        $userServices = app()->make(UserServices::class);
        $exists = $userServices->get(['phone' => $phone]);
        if ($exists) {
            throw new ValidateException('手机号已存在');
        }
        if ($nickname === '') {
            $nickname = substr_replace($phone, '****', 3, 4);
        }
        $payload = [
            'phone' => $phone,
            'nickname' => $nickname,
            'real_name' => (string)($data['real_name'] ?? $nickname),
            'avatar' => sys_config('h5_avatar'),
            'user_type' => 'merchant',
            'sex' => (int)($data['sex'] ?? 0),
            'mark' => (string)($data['mark'] ?? ''),
        ];
        if (!empty($data['birthday'])) {
            $payload['birthday'] = is_numeric($data['birthday']) ? (int)$data['birthday'] : strtotime((string)$data['birthday']);
        }

        $newUid = 0;
        Db::startTrans();
        try {
            $userInfo = $userServices->save($payload);
            if (!$userInfo) {
                throw new ValidateException('保存用户失败');
            }
            $newUid = (int)$userInfo['uid'];
            if ($newUid <= 0) {
                throw new ValidateException('保存用户失败');
            }
            // 必须复用既有 setStoreUser；该方法会吞异常且恒返回 true，不能只信返回值
            /** @var StoreUserServices $storeUserServices */
            $storeUserServices = app()->make(StoreUserServices::class);
            $storeUserServices->setStoreUser($newUid, $storeId);
            $bound = Db::name('store_user')->where(['uid' => $newUid, 'store_id' => $storeId])->find();
            if (!$bound) {
                throw new ValidateException('绑定门店客户校验失败');
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            if ($e instanceof ValidateException) {
                throw $e;
            }
            throw new ValidateException('创建客户失败：' . $e->getMessage());
        }

        try {
            UserBelongStoreJob::dispatch([$newUid, $storeId, 'merchant', $operatorUid]);
        } catch (\Throwable $e) {
        }
        try {
            event('user.register', [$userServices->get($newUid), true, 0]);
        } catch (\Throwable $e) {
        }

        return [
            'uid' => $newUid,
            'phone' => $phone,
            'nickname' => $nickname,
            'store_id' => $storeId,
        ];
    }

    public function mineSummary(int $uid, array $access): array
    {
        /** @var MerchantAccessServices $accessServices */
        $accessServices = app()->make(MerchantAccessServices::class);
        $scopeStoreIds = $accessServices->requireScopeStoreIds($access);
        $total = 0;
        $newMonth = 0;
        $birthdayToday = 0;
        try {
            if ($scopeStoreIds) {
                $total = (int)Db::name('store_user')->whereIn('store_id', $scopeStoreIds)->count('DISTINCT uid');
                $today = date('md');
                $birthdayToday = (int)Db::name('user')->alias('u')
                    ->join('store_user su', 'su.uid = u.uid')
                    ->whereIn('su.store_id', $scopeStoreIds)
                    ->whereRaw("FROM_UNIXTIME(u.birthday,'%m%d') = ?", [$today])
                    ->where('u.birthday', '>', 0)
                    ->count('DISTINCT u.uid');
            }
        } catch (\Throwable $e) {
        }

        $monthStart = strtotime(date('Y-m-01 00:00:00'));
        /** @var MerchantCustomerMetricServices $customerMetrics */
        $customerMetrics = app()->make(MerchantCustomerMetricServices::class);
        $newMetric = $customerMetrics->newCustomerMetric($scopeStoreIds, $monthStart, time());

        return [
            'total' => $total,
            'new_month' => $newMetric['number'],
            'new_month_developing' => $newMetric['developing'],
            'new_month_metric_code' => $newMetric['metric_code'],
            'new_month_title' => $newMetric['title'],
            'new_month_note' => $newMetric['note'],
            'new_month_tooltip_api' => $newMetric['tooltip_api'],
            'new_month_detail_api' => $newMetric['detail_api'],
            'new_month_detail_developing' => $newMetric['detail_developing'],
            'birthday_today' => $birthdayToday,
            'follow' => null,
            'follow_developing' => true,
            'scope_store_ids' => $scopeStoreIds,
            'resolved_store_ids' => $access['resolved_store_ids'] ?? [],
            'note' => '本月新增走 MerchantCustomerMetricServices（系统首次下单）；待回访未落地；范围=scope_store_ids',
        ];
    }

    /**
     * 商家客户列表：强制 scope_store_ids，禁止复用无范围的 admin/store 旧接口
     */
    public function listCustomers(int $operatorUid, array $access, array $filter): array
    {
        /** @var MerchantAccessServices $accessServices */
        $accessServices = app()->make(MerchantAccessServices::class);
        $scopeStoreIds = $accessServices->requireScopeStoreIds($access);
        if (!$scopeStoreIds) {
            return [
                'list' => [],
                'count' => 0,
                'scope_store_ids' => [],
            ];
        }

        $keyword = trim((string)($filter['keyword'] ?? $filter['nickname'] ?? ''));
        $birthdayType = (int)($filter['birthday_type'] ?? 0);
        $segment = trim((string)($filter['segment'] ?? ''));
        $where = [
            'is_filter_del' => 1,
            'nickname' => $keyword,
            'store_ids' => $scopeStoreIds,
            'field_key' => '',
        ];
        if ($birthdayType > 0) {
            $where['birthday_type'] = $birthdayType;
        }
        // 性别：0其他 1男 2女；空=不限（与 UserStoreUserDao sex 一致）
        if (array_key_exists('sex', $filter) && $filter['sex'] !== '' && $filter['sex'] !== null) {
            $sex = (int)$filter['sex'];
            if (in_array($sex, [0, 1, 2], true)) {
                $where['sex'] = $sex;
            }
        }
        // 余额区间：与门店 PC 同参 now_money_peice（min-max，端可空）
        $moneyPeice = trim((string)($filter['now_money_peice'] ?? ''));
        if ($moneyPeice !== '' && $moneyPeice !== '-') {
            $where['now_money_peice'] = $moneyPeice;
        }
        // 新增客户列表：系统首次下单（与 MerchantCustomerMetricServices 同口径）
        // - new_month：自然月至今
        // - new_customer：须带 start_date/end_date（数仓下钻）
        // 数仓下钻：card_recharge / visit / repurchase（成交不下钻）
        if ($segment === 'new_month' || $segment === 'new_customer') {
            if ($segment === 'new_month') {
                $startTs = strtotime(date('Y-m-01 00:00:00'));
                $endTs = time();
            } else {
                $startDate = trim((string)($filter['start_date'] ?? ''));
                $endDate = trim((string)($filter['end_date'] ?? ''));
                $startTs = $startDate !== '' ? strtotime($startDate . ' 00:00:00') : 0;
                $endTs = $endDate !== '' ? strtotime($endDate . ' 23:59:59') : 0;
                if ($startTs <= 0 || $endTs <= 0 || $endTs < $startTs) {
                    throw new \think\exception\ValidateException('请提供有效的新增客户时间范围');
                }
            }
            /** @var MerchantCustomerMetricServices $metricServices */
            $metricServices = app()->make(MerchantCustomerMetricServices::class);
            $firstUids = $metricServices->listFirstOrderCustomerUids($scopeStoreIds, $startTs, $endTs);
            if (!$firstUids) {
                return [
                    'list' => [],
                    'count' => 0,
                    'scope_store_ids' => $scopeStoreIds,
                    'segment' => $segment,
                    'note' => '当前范围暂无首次下单客户',
                ];
            }
            $where['uids'] = $firstUids;
        } elseif (in_array($segment, ['card_recharge', 'visit', 'repurchase'], true)) {
            $startDate = trim((string)($filter['start_date'] ?? ''));
            $endDate = trim((string)($filter['end_date'] ?? ''));
            $startTs = $startDate !== '' ? strtotime($startDate . ' 00:00:00') : 0;
            $endTs = $endDate !== '' ? strtotime($endDate . ' 23:59:59') : 0;
            if ($startTs <= 0 || $endTs <= 0 || $endTs < $startTs) {
                throw new \think\exception\ValidateException('请提供有效的客户时间范围');
            }
            /** @var MerchantCustomerMetricServices $metricServices */
            $metricServices = app()->make(MerchantCustomerMetricServices::class);
            if ($segment === 'card_recharge') {
                $segUids = $metricServices->listCardRechargeCustomerUids($scopeStoreIds, $startTs, $endTs);
                $emptyNote = '当前范围暂无开卡或充值客户';
            } elseif ($segment === 'visit') {
                $segUids = $metricServices->listVisitCustomerUids($scopeStoreIds, $startTs, $endTs);
                $emptyNote = '当前范围暂无到店客户';
            } else {
                $segUids = $metricServices->listRepurchaseCustomerUids($scopeStoreIds, $startTs, $endTs);
                $emptyNote = '当前范围暂无复购客户';
            }
            if (!$segUids) {
                return [
                    'list' => [],
                    'count' => 0,
                    'scope_store_ids' => $scopeStoreIds,
                    'segment' => $segment,
                    'note' => $emptyNote,
                ];
            }
            $where['uids'] = $segUids;
        } elseif ($segment === 'debt') {
            // 欠款客群：与 segments 计数同口径（scope 内 pending 欠款 DISTINCT uid）
            $accessServices->requirePermissions($access, ['merchant.debt.view'], '暂无欠款查看权限');
            $debtUids = [];
            try {
                $debtUids = Db::name('store_debt')
                    ->whereIn('store_id', $scopeStoreIds)
                    ->where('status', StoreDebt::STATUS_PENDING)
                    ->distinct(true)
                    ->column('uid');
            } catch (\Throwable $e) {
                $debtUids = [];
            }
            $debtUids = array_values(array_unique(array_filter(array_map('intval', $debtUids ?: []))));
            if (!$debtUids) {
                return [
                    'list' => [],
                    'count' => 0,
                    'scope_store_ids' => $scopeStoreIds,
                    'segment' => 'debt',
                    'note' => '当前范围暂无未结清欠款客户',
                ];
            }
            $where['uids'] = $debtUids;
        } elseif ($segment !== '') {
            throw new \think\exception\ValidateException('该客群列表筛选尚未开放');
        }

        if ((string)($filter['field_key'] ?? '') === 'mine') {
            $activeStore = (int)($access['active_store_id'] ?? 0);
            $staffId = 0;
            if ($activeStore > 0 && in_array($activeStore, $scopeStoreIds, true)) {
                try {
                    /** @var SystemStoreStaffDao $staffDao */
                    $staffDao = app()->make(SystemStoreStaffDao::class);
                    $staff = $staffDao->search([
                        'uid' => $operatorUid,
                        'store_id' => $activeStore,
                        'is_del' => 0,
                        'status' => 1,
                    ])->find();
                    $staff = $staff ? (is_array($staff) ? $staff : $staff->toArray()) : null;
                    $staffId = (int)($staff['id'] ?? 0);
                } catch (\Throwable $e) {
                    $staffId = 0;
                }
            }
            if ($staffId <= 0) {
                return [
                    'list' => [],
                    'count' => 0,
                    'scope_store_ids' => $scopeStoreIds,
                    'note' => '当前门店无有效员工任职，无法筛选「我的客户」',
                ];
            }
            $where['salesman_id'] = $staffId;
        }

        $primaryStore = count($scopeStoreIds) === 1
            ? $scopeStoreIds[0]
            : (int)(($access['active_store_id'] ?? 0) ?: $scopeStoreIds[0]);

        /** @var UserStoreUserServices $userStoreUserServices */
        $userStoreUserServices = app()->make(UserStoreUserServices::class);
        [$list, $count] = $userStoreUserServices->getWhereUserList($where, 'u.*');
        if ($list) {
            $uids = array_values(array_unique(array_filter(array_map('intval', array_column($list, 'uid')))));
            /** @var UserServices $userServices */
            $userServices = app()->make(UserServices::class);
            $userlabel = $userServices->getUserLablel($uids, 1, $primaryStore);
            $levelName = app()->make(SystemUserLevelServices::class)->getUsersLevel(array_unique(array_column($list, 'level')));
            $userLevel = app()->make(UserLevelServices::class)->getUsersLevelInfo($uids);
            // 现金/核销统计按完整 scope_store_ids 聚合
            $statMap = UserListStatServices::getStatsByUids($uids, $scopeStoreIds, $where);

            // 批量：最近服务订单（order_type=2）
            $latestOrderByUid = [];
            $maxOrderIds = Db::name('store_order')
                ->whereIn('uid', $uids)
                ->where('refund_status', 0)
                ->where('order_type', 2)
                ->whereIn('store_id', $scopeStoreIds)
                ->group('uid')
                ->column('MAX(id)', 'uid');
            if ($maxOrderIds) {
                $orderRows = Db::name('store_order')
                    ->whereIn('id', array_values($maxOrderIds))
                    ->field('id,uid,add_time,link_id,store_id')
                    ->select()
                    ->toArray();
                foreach ($orderRows as $row) {
                    $latestOrderByUid[(int)$row['uid']] = $row;
                }
            }

            // 批量：手艺人（StaffYeji type=3）
            $linkIds = array_values(array_unique(array_filter(array_map(static function ($o) {
                return (int)($o['link_id'] ?? 0);
            }, $latestOrderByUid))));
            $yejiByLink = [];
            if ($linkIds) {
                $yejiRows = StaffYeji::where('type', 3)->whereIn('link_id', $linkIds)
                    ->field('link_id,staff_name')
                    ->select()
                    ->toArray();
                foreach ($yejiRows as $yr) {
                    $lid = (int)($yr['link_id'] ?? 0);
                    if ($lid <= 0) {
                        continue;
                    }
                    $yejiByLink[$lid][] = (string)($yr['staff_name'] ?? '');
                }
            }

            // 批量：归属门店名称
            $belongIds = array_values(array_unique(array_filter(array_map(static function ($item) {
                return (int)($item['belong_store_id'] ?? 0);
            }, $list))));
            $storeNameMap = $belongIds
                ? SystemStore::whereIn('id', $belongIds)->column('name', 'id')
                : [];

            foreach ($list as &$item) {
                $uid = (int)$item['uid'];
                $order = $latestOrderByUid[$uid] ?? null;
                $item['order_time'] = '';
                $item['shouyi'] = '';
                if ($order) {
                    $item['order_time'] = date('Y-m-d H:i:s', (int)$order['add_time']);
                    $names = $yejiByLink[(int)($order['link_id'] ?? 0)] ?? [];
                    $names = array_values(array_filter($names));
                    if ($names) {
                        $item['shouyi'] = implode(',', $names);
                    }
                }
                $belongId = (int)($item['belong_store_id'] ?? 0);
                $item['belong_store'] = $belongId > 0 ? (string)($storeNameMap[$belongId] ?? '') : '无';
                if ($item['belong_store'] === '') {
                    $item['belong_store'] = '无';
                }
                $item['birthday'] = $item['birthday'] ? date('Y-m-d', (int)$item['birthday']) : '';
                $item['level'] = $levelName[$item['level']] ?? '无';
                $item['labels'] = $userlabel[$item['uid']] ?? '';
                $item['isMember'] = $item['is_money_level'] > 0 ? 1 : 0;
                $stats = $statMap[$uid] ?? [];
                $item['cash_consume_amount'] = $stats['cash_consume_amount'] ?? '0.00';
                $item['cash_consume_count'] = $stats['cash_consume_count'] ?? 0;
                $item['writeoff_count'] = $stats['writeoff_count'] ?? 0;
                $levelinfo = $userLevel[$item['uid']] ?? null;
                $item['vip_name'] = false;
                if ($levelinfo && ($levelinfo['is_forever'] || time() < $levelinfo['valid_time'])) {
                    $item['vip_name'] = $item['level'] != '无' ? $item['level'] : false;
                }
            }
            unset($item);
        }

        return [
            'list' => $list ?: [],
            'count' => (int)$count,
            'scope_store_ids' => $scopeStoreIds,
        ];
    }

    /**
     * 目标客户必须落在 scope_store_ids
     */
    protected function assertCustomerInScope(int $targetUid, array $scopeStoreIds): void
    {
        if ($targetUid <= 0) {
            throw new ValidateException('用户id不能为空');
        }
        if (!$scopeStoreIds) {
            throw new ValidateException('当前身份无有效门店范围');
        }
        $bound = Db::name('store_user')
            ->where('uid', $targetUid)
            ->whereIn('store_id', $scopeStoreIds)
            ->find();
        if (!$bound) {
            throw new ValidateException('客户不在可管理门店范围内');
        }
    }

    /**
     * 商家客户详情：目标 uid 必须落在 scope_store_ids
     */
    public function customerDetail(int $targetUid, array $access): array
    {
        /** @var MerchantAccessServices $accessServices */
        $accessServices = app()->make(MerchantAccessServices::class);
        $scopeStoreIds = $accessServices->requireScopeStoreIds($access);
        $this->assertCustomerInScope($targetUid, $scopeStoreIds);
        /** @var UserServices $userServices */
        $userServices = app()->make(UserServices::class);
        $info = $userServices->manageRead($targetUid);
        $info['scope_store_ids'] = $scopeStoreIds;
        $info['can_edit'] = in_array('merchant.customer.edit', $access['permissions'] ?? [], true);
        return $info;
    }

    /**
     * 商家客户订单/服务记录（范围限定 scope_store_ids）
     */
    public function customerOrders(int $targetUid, array $access, array $filter): array
    {
        /** @var MerchantAccessServices $accessServices */
        $accessServices = app()->make(MerchantAccessServices::class);
        $scopeStoreIds = $accessServices->requireScopeStoreIds($access);
        $this->assertCustomerInScope($targetUid, $scopeStoreIds);

        $showType = (int)($filter['show_type'] ?? 1);
        $where = [
            'uid' => $targetUid,
            'is_system_del' => 0,
            'pid' => -2,
            'not_recharge' => 1,
            'plat_type' => 1,
            'is_refund' => 0,
            'store_id' => $scopeStoreIds,
        ];
        if ($showType === 1) {
            $where['not_auto'] = 1;
        } else {
            $where['link_type'] = 2;
        }
        /** @var \app\services\order\StoreOrderServices $orderServices */
        $orderServices = app()->make(\app\services\order\StoreOrderServices::class);
        $list = $orderServices->getOrderList($where);
        return [
            'list' => $list['data'] ?? [],
            'count' => (int)($list['count'] ?? 0),
            'scope_store_ids' => $scopeStoreIds,
        ];
    }

    /**
     * 商家客户档案保存（需 customer.edit，且客户在 scope 内）
     */
    public function updateCustomer(int $targetUid, array $access, array $data): bool
    {
        /** @var MerchantAccessServices $accessServices */
        $accessServices = app()->make(MerchantAccessServices::class);
        $scopeStoreIds = $accessServices->requireScopeStoreIds($access);
        $this->assertCustomerInScope($targetUid, $scopeStoreIds);

        $payload = [
            'real_name' => (string)($data['real_name'] ?? ''),
            'sex' => (int)($data['sex'] ?? 0),
            'addres' => (string)($data['addres'] ?? ''),
            'mark' => (string)($data['mark'] ?? ''),
        ];
        if (!empty($data['birthday'])) {
            $payload['birthday'] = is_numeric($data['birthday'])
                ? (int)$data['birthday']
                : strtotime((string)$data['birthday']);
        } else {
            $payload['birthday'] = 0;
        }
        $user = Db::name('user')->where('uid', $targetUid)->find();
        if (!$user) {
            throw new ValidateException('用户不存在');
        }
        Db::name('user')->where('uid', $targetUid)->update($payload);
        return true;
    }
}
