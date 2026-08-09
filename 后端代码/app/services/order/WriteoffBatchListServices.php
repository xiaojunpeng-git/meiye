<?php

namespace app\services\order;

use app\dao\order\StoreOrderDao;
use app\model\order\StoreOrder;
use app\model\order\StoreOrderCartInfo;
use app\model\order\StoreOrderWriteoff;
use app\model\product\product\StoreProduct;
use app\model\user\UserCardHolder;
use app\model\yeji\StaffYeji;
use app\services\BaseServices;
use think\exception\ValidateException;
use think\facade\Db;

/**
 * 核销业务主单聚合：admin/store 订单列表按业务行键（batch:{id} / order:{id}）查询、
 * 详情/整笔撤销的可见性与撤销权判定。cashier 不使用本服务，行为保持不变。
 *
 * 权威方案：任务管理/进行中/核销业务主单聚合与订单列表展示方案.md
 */
class WriteoffBatchListServices extends BaseServices
{
    /**
     * 业务行键分页聚合列表：count/排序/分页统一按 list_row_key（batch:{id} / order:{id}）口径，
     * 禁止先按子单 page 再折叠。
     *
     * @param array $where 与 StoreOrderServices::getOrderList 相同语义的过滤条件（已含 employee_data_scope 等）
     * @param array $field
     * @param array $with
     * @param bool $abridge
     * @param string $order 仅用于 order 行 hydration 时 with 的展示，排序统一走本方法内的业务行键排序
     * @param bool $storeBackendListProductFormat
     * @param int $page
     * @param int $limit
     * @return array{data:array,count:int,stat:array,batch_url:string}
     */
    public function getAggregatedList(
        array $where,
        array $field,
        array $with,
        bool $abridge,
        string $order,
        bool $storeBackendListProductFormat,
        int $page,
        int $limit
    ): array {
        $where2 = $where;
        unset($where2['aggregate_writeoff_batch']);
        $scope = $this->normalizeScope($where2['employee_data_scope'] ?? null);

        /** @var StoreOrderDao $orderDao */
        $orderDao = app()->make(StoreOrderDao::class);

        // 查询层业务行键：对 UNION 结果集做 COUNT / ORDER BY / LIMIT（禁止先拉全量再 PHP 切片）
        [$count, $pageRows] = $this->queryListRowKeysPage($where2, $scope, $page, $limit);

        $pageOrderIds = [];
        $pageBatchIds = [];
        foreach ($pageRows as $r) {
            if (($r['row_type'] ?? '') === 'order') {
                $pageOrderIds[] = (int)$r['row_id'];
            } else {
                $pageBatchIds[] = (int)$r['row_id'];
            }
        }

        // hydration：仅当前页；order 行走既有 tidyOrderList；batch 行走本服务格式化
        $orderRowsById = [];
        if ($pageOrderIds) {
            $rawOrderRows = $orderDao->getOrderList(['id' => $pageOrderIds], $field, 0, 0, $with, $order);
            /** @var StoreOrderServices $orderServices */
            $orderServices = app()->make(StoreOrderServices::class);
            $hydrated = $orderServices->hydrateOrderListRows($rawOrderRows, $abridge, $storeBackendListProductFormat);
            foreach ($hydrated as $row) {
                $row['list_row_key'] = 'order:' . (int)$row['id'];
                $row['is_writeoff_batch'] = 0;
                $orderRowsById[(int)$row['id']] = $row;
            }
        }

        $batchRowsById = [];
        if ($pageBatchIds) {
            $batchRows = Db::name('store_writeoff_batch')->whereIn('id', $pageBatchIds)->select();
            foreach ($batchRows as $batchRow) {
                $batchArr = (array)$batchRow;
                // 防御：行键层漏网时仍不得对 visibility=none 做全量 hydration
                if (!$this->isBatchVisible($batchArr, $scope)) {
                    continue;
                }
                try {
                    $batchRowsById[(int)$batchArr['id']] = $this->formatBatchForAdmin($batchArr, true, $scope);
                } catch (ValidateException $e) {
                    continue;
                }
            }
        }

        $list = [];
        foreach ($pageRows as $r) {
            $rid = (int)($r['row_id'] ?? 0);
            if (($r['row_type'] ?? '') === 'order') {
                if (isset($orderRowsById[$rid])) {
                    $list[] = $orderRowsById[$rid];
                }
            } elseif (isset($batchRowsById[$rid])) {
                $list[] = $batchRowsById[$rid];
            }
        }

        return [
            'data' => $list,
            'count' => $count,
            'stat' => [],
            'batch_url' => 'file/upload/1',
        ];
    }

    /**
     * 构造业务行键：先物化匹配订单 ID，再物化 list_row_key 集合，最后对该集合 COUNT + ORDER BY + LIMIT。
     * MySQL 禁止同一语句两次打开同一临时表，故拆成两张临时表分步写入。
     *
     * @return array{0:int,1:array<int,array{list_row_key:string,sort_ts:int,row_type:string,row_id:int}>}
     */
    protected function queryListRowKeysPage(array $where2, array $scope, int $page, int $limit): array
    {
        /** @var StoreOrderDao $orderDao */
        $orderDao = app()->make(StoreOrderDao::class);
        $orderIdSql = $orderDao->search($where2)->field('id')->buildSql(true);
        $prefix = (string)config('database.connections.mysql.prefix');
        $suffix = substr(md5(uniqid((string)mt_rand(), true)), 0, 10);
        $tmpOrd = '_wb_ord_' . $suffix;
        $tmpKey = '_wb_key_' . $suffix;

        Db::execute("DROP TEMPORARY TABLE IF EXISTS `{$tmpKey}`");
        Db::execute("DROP TEMPORARY TABLE IF EXISTS `{$tmpOrd}`");
        // MEMORY 临时表受 max_heap_table_size 限制；总部全量订单列表可达数万行，默认 16MB 会报 Table is full
        Db::execute('SET SESSION max_heap_table_size = 268435456');
        Db::execute('SET SESSION tmp_table_size = 268435456');
        Db::execute("CREATE TEMPORARY TABLE `{$tmpOrd}` (`id` INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=Memory");
        Db::execute("INSERT IGNORE INTO `{$tmpOrd}` (`id`) SELECT `id` FROM {$orderIdSql} AS _wb_src");

        Db::execute("CREATE TEMPORARY TABLE `{$tmpKey}` (
            `list_row_key` VARCHAR(64) NOT NULL,
            `sort_ts` INT NOT NULL DEFAULT 0,
            `row_type` VARCHAR(16) NOT NULL,
            `row_id` INT UNSIGNED NOT NULL,
            PRIMARY KEY (`list_row_key`),
            KEY `idx_sort` (`sort_ts`, `row_id`)
        ) ENGINE=Memory");

        $directSql = $this->buildBatchDirectWhereSql($where2, 'b');

        // 从匹配子单反查 batch_id（避免扫全量 batch 表）
        Db::execute("INSERT IGNORE INTO `{$tmpKey}` (`list_row_key`, `sort_ts`, `row_type`, `row_id`)
            SELECT CONCAT('batch:', b.id),
                CAST(COALESCE(NULLIF(b.operate_time, 0), b.business_time, 0) AS SIGNED),
                'batch',
                b.id
            FROM `{$prefix}store_order_writeoff` w
            INNER JOIN `{$prefix}store_order` o ON o.link_id = w.id AND o.order_type = 2
            INNER JOIN `{$tmpOrd}` t ON t.id = o.id
            INNER JOIN `{$prefix}store_writeoff_batch` b ON b.id = w.batch_id
            WHERE IFNULL(w.batch_id, 0) > 0");

        // personal：操作人 / 手艺人参与者（即使子单未落在 order.staff 字段上）仍可见主单行
        if (($scope['mode'] ?? '') === 'personal' && !empty($scope['staff_ids'])) {
            $staffIn = implode(',', array_map('intval', $scope['staff_ids']));
            if ($staffIn !== '') {
                Db::execute("INSERT IGNORE INTO `{$tmpKey}` (`list_row_key`, `sort_ts`, `row_type`, `row_id`)
                    SELECT CONCAT('batch:', b.id),
                        CAST(COALESCE(NULLIF(b.operate_time, 0), b.business_time, 0) AS SIGNED),
                        'batch',
                        b.id
                    FROM `{$prefix}store_writeoff_batch` b
                    WHERE b.staff_id IN ({$staffIn}){$directSql}");
                Db::execute("INSERT IGNORE INTO `{$tmpKey}` (`list_row_key`, `sort_ts`, `row_type`, `row_id`)
                    SELECT CONCAT('batch:', b.id),
                        CAST(COALESCE(NULLIF(b.operate_time, 0), b.business_time, 0) AS SIGNED),
                        'batch',
                        b.id
                    FROM `{$prefix}store_order_writeoff` w
                    INNER JOIN `{$prefix}staff_yeji` y ON y.link_id = w.id AND y.type = 3
                    INNER JOIN `{$prefix}store_writeoff_batch` b ON b.id = w.batch_id
                    WHERE IFNULL(w.batch_id, 0) > 0 AND y.staff_id IN ({$staffIn}){$directSql}");
            }
        }

        // 完整 batch_no 精确搜索（^WB[0-9]+$）：门店/时间 + 与列表同一可见谓词（禁止绕过 personal）
        $batchNoSearch = $this->resolveBatchNoSearch($where2);
        if ($batchNoSearch !== '') {
            $quoted = "'" . addslashes($batchNoSearch) . "'";
            $wbVisibilitySql = $this->buildBatchVisibilitySql($scope, $prefix, 'b');
            if ($wbVisibilitySql !== null) {
                Db::execute("INSERT IGNORE INTO `{$tmpKey}` (`list_row_key`, `sort_ts`, `row_type`, `row_id`)
                    SELECT CONCAT('batch:', b.id),
                        CAST(COALESCE(NULLIF(b.operate_time, 0), b.business_time, 0) AS SIGNED),
                        'batch',
                        b.id
                    FROM `{$prefix}store_writeoff_batch` b
                    WHERE b.batch_no = {$quoted}{$directSql}{$wbVisibilitySql}");
            }
        }

        Db::execute("INSERT IGNORE INTO `{$tmpKey}` (`list_row_key`, `sort_ts`, `row_type`, `row_id`)
            SELECT CONCAT('order:', o.id),
                CAST(o.add_time AS SIGNED),
                'order',
                o.id
            FROM `{$prefix}store_order` o
            INNER JOIN `{$tmpOrd}` t ON t.id = o.id
            WHERE NOT (
                o.order_type = 2
                AND EXISTS (
                    SELECT 1 FROM `{$prefix}store_order_writeoff` w
                    WHERE w.id = o.link_id AND IFNULL(w.batch_id, 0) > 0
                )
            )");

        $countRow = Db::query("SELECT COUNT(*) AS c FROM `{$tmpKey}`");
        $count = (int)($countRow[0]['c'] ?? 0);

        $pageSql = "SELECT list_row_key, sort_ts, row_type, row_id FROM `{$tmpKey}`
            ORDER BY sort_ts DESC, row_id DESC";
        if ($page > 0 && $limit > 0) {
            $offset = max(0, ($page - 1) * $limit);
            $pageSql .= ' LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset;
        }
        $pageRows = Db::query($pageSql) ?: [];
        try {
            Db::execute("DROP TEMPORARY TABLE IF EXISTS `{$tmpKey}`");
            Db::execute("DROP TEMPORARY TABLE IF EXISTS `{$tmpOrd}`");
        } catch (\Throwable $e) {
            // ignore
        }
        return [$count, $pageRows];
    }

    /**
     * WB 精确搜索 / 直查 batch 时的可见性谓词，与列表 personal 口径一致。
     * @return string|null SQL 片段（含前导 AND）；null 表示当前 scope 无权靠单号直查任何 batch（如 mode=none）
     */
    protected function buildBatchVisibilitySql(array $scope, string $prefix, string $alias = 'b'): ?string
    {
        $scope = $this->normalizeScope($scope);
        if ($this->isFullScope($scope)) {
            return '';
        }
        if (($scope['mode'] ?? '') !== 'personal') {
            return null;
        }
        $staffIn = implode(',', array_map('intval', $scope['staff_ids'] ?? []));
        if ($staffIn === '') {
            return null;
        }
        return " AND (
            {$alias}.staff_id IN ({$staffIn})
            OR EXISTS (
                SELECT 1 FROM `{$prefix}store_order_writeoff` w
                INNER JOIN `{$prefix}staff_yeji` y ON y.link_id = w.id AND y.type = 3
                WHERE w.batch_id = {$alias}.id AND y.staff_id IN ({$staffIn})
            )
        )";
    }

    /**
     * batch 表直查附加条件（门店 + 业务时间），与 applyBatchDirectFilters 同一口径，供原生 SQL 拼接。
     */
    protected function buildBatchDirectWhereSql(array $where2, string $alias = 'b'): string
    {
        $parts = [];
        if (isset($where2['store_id']) && $where2['store_id'] !== '' && $where2['store_id'] !== -1) {
            if (is_array($where2['store_id'])) {
                $ids = implode(',', array_map('intval', $where2['store_id']));
                if ($ids !== '') {
                    $parts[] = "{$alias}.store_id IN ({$ids})";
                }
            } else {
                $parts[] = "{$alias}.store_id = " . (int)$where2['store_id'];
            }
        }
        if (!empty($where2['date_range'])) {
            $range = explode('-', (string)$where2['date_range']);
            $start = trim($range[0] ?? '') !== '' ? strtotime(trim($range[0])) : false;
            $end = trim($range[1] ?? '') !== '' ? strtotime(trim($range[1]) . ' 23:59:59') : false;
            if ($start !== false && $end !== false) {
                $parts[] = "{$alias}.business_time BETWEEN " . (int)$start . ' AND ' . (int)$end;
            }
        } elseif (!empty($where2['date_range_time']) && is_array($where2['date_range_time'])) {
            $start = strtotime((string)($where2['date_range_time'][0] ?? ''));
            $end = strtotime((string)($where2['date_range_time'][1] ?? ''));
            if ($start !== false && $end !== false) {
                $parts[] = "{$alias}.business_time BETWEEN " . (int)$start . ' AND ' . (int)$end;
            }
        }
        return $parts ? (' AND ' . implode(' AND ', $parts)) : '';
    }

    /**
     * 批量核销主单详情：全量明细（含已撤销）+ original/active 汇总 + visibility
     *
     * @param int $batchId
     * @param array $scope employee_data_scope（与列表同一口径，由调用端 applyOrderListScope 得到）
     * @param int $storeId 门店端传本店 store_id 做归属校验；平台端传 0
     * @param string $end 'admin' | 'store'
     * @return array
     */
    public function getBatchDetail(int $batchId, array $scope, int $storeId = 0, string $end = 'admin'): array
    {
        if ($batchId <= 0) {
            throw new ValidateException('缺少批量核销单');
        }
        $batch = Db::name('store_writeoff_batch')->where('id', $batchId)->find();
        if (!$batch) {
            throw new ValidateException('批量核销单不存在');
        }
        if ($end === 'store' && $storeId > 0 && (int)$batch['store_id'] !== $storeId) {
            throw new ValidateException('无权查看该批量核销单');
        }
        $scope = $this->normalizeScope($scope);
        if (!$this->isBatchVisible($batch, $scope)) {
            throw new ValidateException('无权查看该批量核销单');
        }
        return $this->formatBatchForAdmin($batch, true, $scope);
    }

    /**
     * 整笔撤销权限校验（与可见性分离）：仅操作人或具备门店级/平台级撤销权限
     */
    public function canCancelBatch(array $batch, array $scope): bool
    {
        $scope = $this->normalizeScope($scope);
        if ($this->isFullScope($scope)) {
            return true;
        }
        if (($scope['mode'] ?? '') === 'personal') {
            $staffIds = array_map('intval', $scope['staff_ids'] ?? []);
            return (int)($batch['staff_id'] ?? 0) > 0 && in_array((int)$batch['staff_id'], $staffIds, true);
        }
        return false;
    }

    /**
     * 对外暴露 scope 归一化（供改手艺人等服务复用同一口径）
     */
    public function normalizeScopePublic($scope): array
    {
        return $this->normalizeScope($scope);
    }

    /**
     * 单项明细是否可修改手艺人（与列表/详情可见性同口径；不可因能见 partial 主单改隐藏项）
     * - 主单或明细已撤销 → 不可
     * - 门店级/平台级 full scope → 可
     * - personal：批次操作人或该明细对手艺人可见 → 可
     */
    public function canEditCraftStaff(array $batch, array $writeoff, array $scope, array $craftStaffRows = []): bool
    {
        $scope = $this->normalizeScope($scope);
        if ((int)($batch['status'] ?? 0) !== 0 || (int)($writeoff['status'] ?? 0) !== 0) {
            return false;
        }
        if ($this->isFullScope($scope)) {
            return true;
        }
        if (($scope['mode'] ?? '') !== 'personal') {
            return false;
        }
        $staffIds = array_map('intval', $scope['staff_ids'] ?? []);
        if ((int)($batch['staff_id'] ?? 0) > 0 && in_array((int)$batch['staff_id'], $staffIds, true)) {
            return true;
        }
        return $this->itemVisibleToScope($writeoff, $scope, $craftStaffRows);
    }

    /**
     * 供 Controller 撤销前取 batch 行（含 store_id 归属校验）
     */
    public function getBatchForCancelCheck(int $batchId, int $storeId, string $end): array
    {
        $batch = Db::name('store_writeoff_batch')->where('id', $batchId)->find();
        if (!$batch) {
            throw new ValidateException('批量核销单不存在');
        }
        if ($end === 'store' && $storeId > 0 && (int)$batch['store_id'] !== $storeId) {
            throw new ValidateException('无权撤销其它门店的批量核销');
        }
        return (array)$batch;
    }

    /**
     * 批量核销主单：admin/store 全量明细格式化（含已撤销、original/active、visibility 裁剪）
     *
     * @param array $batch store_writeoff_batch 行
     * @param bool $includeRevoked 恒为 true：列表/详情均须含已撤销明细供追溯
     * @param array $scope employee_data_scope
     */
    public function formatBatchForAdmin(array $batch, bool $includeRevoked, array $scope): array
    {
        $scope = $this->normalizeScope($scope);
        $batchId = (int)$batch['id'];

        $writeoffs = StoreOrderWriteoff::where('batch_id', $batchId)->order('id', 'asc')->select()->toArray();
        if (!$includeRevoked) {
            $writeoffs = array_values(array_filter($writeoffs, static function ($w) {
                return (int)($w['status'] ?? 0) === 0;
            }));
        }

        $writeoffIds = array_map(static function ($w) {
            return (int)$w['id'];
        }, $writeoffs);

        // 手艺人（劳动业绩 type=3）：按 link_id=writeoff.id 聚合
        $craftMap = [];
        if ($writeoffIds) {
            $craftRows = StaffYeji::whereIn('link_id', $writeoffIds)->where('type', 3)
                ->field('link_id,staff_id,staff_name,is_dian')->select()->toArray();
            foreach ($craftRows as $c) {
                $craftMap[(int)$c['link_id']][] = [
                    'staff_id' => (int)$c['staff_id'],
                    'staff_name' => (string)$c['staff_name'],
                    'is_dian' => (int)$c['is_dian'],
                ];
            }
        }

        // 子单（order_type=2，link_id=writeoff.id）
        $subOrderMap = [];
        if ($writeoffIds) {
            $subRows = StoreOrder::whereIn('link_id', $writeoffIds)->where('order_type', 2)
                ->field('id,link_id,order_id')->select()->toArray();
            foreach ($subRows as $s) {
                $subOrderMap[(int)$s['link_id']] = $s;
            }
        }

        // 原购订单号
        $oids = array_values(array_unique(array_map(static function ($w) {
            return (int)$w['oid'];
        }, $writeoffs)));
        $purchaseOrderMap = $oids ? StoreOrder::whereIn('id', $oids)->column('order_id', 'id') : [];

        $visibility = $this->resolveVisibility($batch, $scope, $writeoffs, $craftMap);
        if ($visibility === 'none') {
            throw new ValidateException('无权查看该批量核销单');
        }

        $items = [];
        $originalTotals = ['cards' => [], 'projects' => 0, 'times' => 0, 'amount' => 0];
        $activeTotals = ['cards' => [], 'projects' => 0, 'times' => 0, 'amount' => 0];

        foreach ($writeoffs as $wo) {
            $wid = (int)$wo['id'];
            if ($visibility === 'partial' && !$this->itemVisibleToScope($wo, $scope, $craftMap[$wid] ?? [])) {
                continue;
            }
            $cart = StoreOrderCartInfo::where('id', (int)$wo['order_cart_id'])->find();
            $cart = $cart ? $cart->toArray() : [];
            $decoded = is_string($cart['cart_info'] ?? null)
                ? json_decode((string)$cart['cart_info'], true)
                : ($cart['cart_info'] ?? []);
            if (!is_array($decoded)) {
                $decoded = [];
            }
            $productName = (string)($decoded['productInfo']['store_name'] ?? '');
            if ($productName === '') {
                $productName = (string)(StoreProduct::where('id', (int)$wo['product_id'])->value('store_name') ?: '项目');
            }
            $holder = UserCardHolder::where('oid', (int)$wo['oid'])->where('uid', (int)$wo['uid'])->find();
            $status = (int)($wo['status'] ?? 0);
            $amount = WriteoffIntegerAmount::truncate($wo['writeoff_price'] ?? 0);
            $times = (int)($wo['writeoff_num'] ?? 0);

            $originalTotals['cards'][(int)$wo['oid']] = true;
            $originalTotals['projects']++;
            $originalTotals['times'] += $times;
            $originalTotals['amount'] += $amount;
            if ($status === 0) {
                $activeTotals['cards'][(int)$wo['oid']] = true;
                $activeTotals['projects']++;
                $activeTotals['times'] += $times;
                $activeTotals['amount'] += $amount;
            }

            $craftStaff = $craftMap[$wid] ?? [];
            $items[] = [
                'writeoff_id' => $wid,
                'sub_order_id' => (int)($subOrderMap[$wid]['id'] ?? 0),
                'sub_order_no' => (string)($subOrderMap[$wid]['order_id'] ?? ''),
                'holder_id' => $holder ? (int)$holder['id'] : 0,
                'card_no' => $holder ? (string)($holder['card_no'] ?? '') : '',
                'card_name' => $holder ? (string)$holder['card_name'] : '',
                'product_id' => (int)$wo['product_id'],
                'product_name' => $productName,
                'writeoff_num' => $times,
                'writeoff_amount' => $amount,
                'craft_staff' => $craftStaff,
                'service_target' => (string)($wo['service_object'] ?? '本人'),
                'purchase_oid' => (int)$wo['oid'],
                'purchase_order_id' => (string)($purchaseOrderMap[(int)$wo['oid']] ?? ''),
                'status' => $status,
                'status_name' => $status === 1 ? '已撤销' : '正常',
                'can_edit_craft_staff' => $this->canEditCraftStaff($batch, $wo, $scope, $craftStaff),
            ];
        }

        $batchStatus = (int)($batch['status'] ?? 0);
        // 列表主行金额：未撤销用 active；已撤销用 original（可追溯，避免变 0）
        $displayAmount = $batchStatus === 1 ? $originalTotals['amount'] : $activeTotals['amount'];
        $statusLabel = $batchStatus === 1 ? '已撤销' : '正常';

        $uid = (int)$batch['uid'];
        $user = $uid > 0 ? Db::name('user')->where('uid', $uid)->field('uid,nickname,real_name,phone')->find() : null;
        $storeId = (int)$batch['store_id'];
        $storeName = $storeId > 0
            ? (string)(Db::name('system_store')->where('id', $storeId)->value('name') ?: '')
            : '';
        $staffId = (int)$batch['staff_id'];
        $staffName = $staffId > 0
            ? (string)(Db::name('system_store_staff')->where('id', $staffId)->value('staff_name') ?: '')
            : '';

        return [
            // 列表兼容字段：id 用负 batch_id，避免与真实订单 id 冲突；业务主键仍是 batch_id
            'id' => -1 * $batchId,
            'order_id' => (string)$batch['batch_no'],
            'order_type' => 2,
            'order_type_label' => '项目核销',
            'paid' => 1,
            'pay_price' => $displayAmount,
            'total_price' => $displayAmount,
            'pay_type_name' => '卡项核销',
            'refund_status' => $batchStatus === 1 ? 1 : 0,
            'refund' => [],
            'is_all_refund' => 0,
            'terminal_action' => 0,
            'delete_time' => null,
            'nickname' => (string)($user['nickname'] ?? ''),
            'real_name' => (string)($user['real_name'] ?? ''),
            'user_phone' => (string)($user['phone'] ?? ''),
            'user_nickname' => (string)($user['nickname'] ?? ''),
            'store_name' => $storeName,
            'kua_store_name' => $storeName,
            'staff_name' => $staffName,
            'gendan_staff_name' => '',
            'yeji_craft_staff' => '',
            'yeji_sales_staff' => '',
            'is_writeoff_batch' => 1,
            'list_row_key' => 'batch:' . $batchId,
            'batch_id' => $batchId,
            'batch_no' => (string)$batch['batch_no'],
            'uid' => $uid,
            'store_id' => $storeId,
            'staff_id' => $staffId,
            'business_time' => (int)$batch['business_time'],
            'operate_time' => (int)$batch['operate_time'],
            '_add_time' => (int)$batch['add_time'],
            'add_time' => date('Y-m-d H:i:s', (int)($batch['operate_time'] ?: $batch['business_time'])),
            'status' => $batchStatus,
            'status_name' => ['status_name' => $statusLabel],
            'batch_status_name' => $statusLabel,
            'visibility' => $visibility,
            'original_total_cards' => count($originalTotals['cards']),
            'original_total_projects' => $originalTotals['projects'],
            'original_total_times' => $originalTotals['times'],
            'original_total_amount' => $originalTotals['amount'],
            'active_total_cards' => count($activeTotals['cards']),
            'active_total_projects' => $activeTotals['projects'],
            'active_total_times' => $activeTotals['times'],
            'active_total_amount' => $activeTotals['amount'],
            'can_cancel_batch' => $batchStatus === 0 && $this->canCancelBatch($batch, $scope),
            'remark' => (string)($batch['remark'] ?? ''),
            'batch_items' => $items,
        ];
    }

    /**
     * @return array{mode:string,staff_ids:int[]}
     */
    protected function normalizeScope($scope): array
    {
        if (!is_array($scope)) {
            return ['mode' => 'none', 'staff_ids' => []];
        }
        $mode = (string)($scope['mode'] ?? 'none');
        $staffIds = array_values(array_unique(array_map('intval', (array)($scope['staff_ids'] ?? []))));
        return ['mode' => $mode, 'staff_ids' => $staffIds];
    }

    protected function isFullScope(array $scope): bool
    {
        // 门店端当前门店全量 + 历史任职补齐：当前店并非个人可见性，
        // WB 精确搜索应与普通订单列表一样放行当前门店的业务主单。
        return in_array($scope['mode'] ?? '', ['all', 'stores', 'store_all', 'store_with_history'], true);
    }

    /**
     * 列表/直查场景：是否可见（不裁剪明细，仅判断整批可见/不可见）
     * @param array<int,bool> $participatedBatchIds 已知的可见 batch id 集合（可选性能优化，避免重复子查询）
     */
    protected function isBatchVisible(array $batch, array $scope, array $participatedBatchIds = []): bool
    {
        $scope = $this->normalizeScope($scope);
        if ($this->isFullScope($scope)) {
            return true;
        }
        if (($scope['mode'] ?? '') !== 'personal') {
            return false;
        }
        $batchId = (int)($batch['id'] ?? 0);
        if (isset($participatedBatchIds[$batchId])) {
            return true;
        }
        $staffIds = $scope['staff_ids'] ?? [];
        if ((int)($batch['staff_id'] ?? 0) > 0 && in_array((int)$batch['staff_id'], $staffIds, true)) {
            return true;
        }
        // 兜底：直接查该批次是否有本人参与的手艺人明细
        if (!$staffIds) {
            return false;
        }
        $writeoffIds = StoreOrderWriteoff::where('batch_id', $batchId)->column('id');
        if (!$writeoffIds) {
            return false;
        }
        $hit = StaffYeji::whereIn('link_id', $writeoffIds)->where('type', 3)
            ->whereIn('staff_id', $staffIds)->count();
        return $hit > 0;
    }

    /**
     * 详情/列表 hydration 时的可见性档位：full=全量明细可见；partial=仅本人参与明细
     */
    protected function resolveVisibility(array $batch, array $scope, array $writeoffs, array $craftMap): string
    {
        if ($this->isFullScope($scope)) {
            return 'full';
        }
        if (($scope['mode'] ?? '') !== 'personal') {
            return 'none';
        }
        $staffIds = $scope['staff_ids'] ?? [];
        if ((int)($batch['staff_id'] ?? 0) > 0 && in_array((int)$batch['staff_id'], $staffIds, true)) {
            return 'full';
        }
        foreach ($writeoffs as $wo) {
            if ($this->itemVisibleToScope($wo, $scope, $craftMap[(int)$wo['id']] ?? [])) {
                return 'partial';
            }
        }
        return 'none';
    }

    /**
     * 单条明细是否对当前 personal 员工可见（本人为该行手艺人）
     */
    protected function itemVisibleToScope(array $writeoff, array $scope, array $craftStaffRows): bool
    {
        if ($this->isFullScope($scope)) {
            return true;
        }
        if (($scope['mode'] ?? '') !== 'personal') {
            return false;
        }
        $staffIds = $scope['staff_ids'] ?? [];
        if (!$staffIds) {
            return false;
        }
        foreach ($craftStaffRows as $c) {
            if (in_array((int)($c['staff_id'] ?? 0), $staffIds, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 完整 batch_no（WB 开头纯数字）搜索关键字解析；不支持尾号/后几位冒充
     */
    protected function resolveBatchNoSearch(array $where): string
    {
        $kw = trim((string)($where['search_order_id'] ?? ''));
        return preg_match('/^WB[0-9]+$/', $kw) === 1 ? $kw : '';
    }

    /**
     * batch 表直查场景通用过滤：门店范围 + 时间范围，与订单列表同口径字段对齐（store_id / date_range）
     */
    protected function applyBatchDirectFilters($query, array $where2): void
    {
        if (isset($where2['store_id']) && $where2['store_id'] !== '' && $where2['store_id'] !== -1) {
            if (is_array($where2['store_id'])) {
                $query->whereIn('store_id', array_map('intval', $where2['store_id']));
            } else {
                $query->where('store_id', (int)$where2['store_id']);
            }
        }
        if (!empty($where2['date_range'])) {
            $range = explode('-', (string)$where2['date_range']);
            $start = trim($range[0] ?? '') !== '' ? strtotime(trim($range[0])) : false;
            $end = trim($range[1] ?? '') !== '' ? strtotime(trim($range[1]) . ' 23:59:59') : false;
            if ($start !== false && $end !== false) {
                $query->whereBetween('business_time', [$start, $end]);
            }
        } elseif (!empty($where2['date_range_time']) && is_array($where2['date_range_time'])) {
            $start = strtotime((string)($where2['date_range_time'][0] ?? ''));
            $end = strtotime((string)($where2['date_range_time'][1] ?? ''));
            if ($start !== false && $end !== false) {
                $query->whereBetween('business_time', [$start, $end]);
            }
        }
    }
}
