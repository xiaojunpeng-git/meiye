<?php
declare(strict_types=1);

namespace app\services\activity\combination;

use app\services\user\UserServices;
use think\exception\ValidateException;
use think\facade\Db;

/**
 * 本次分享归属与永久分销上下级独立。只冻结会员与会员所属门店，绝不借用同号员工。
 * 大屏、个人推广、下钻及导出共用同一订单粒度查询；金额取支付及成功退款事实。
 */
class CombinationPromotionServices
{
    /** 由订单创建事务调用；资格在服务端验证，重复回调不能改变归属快照。 */
    public function registerOrderInTx(int $orderId, int $shareUid): void
    {
        $order = Db::name('store_order')->where('id', $orderId)->lock(true)->find();
        if (!$order || (int)$order['activity_id'] <= 0) throw new ValidateException('拼团订单来源无效');
        if (Db::name('combination_promotion_order')->where('order_id', $orderId)->find()) return;
        $activity = Db::name('store_combination')->where('id', $order['activity_id'])->find();
        $member = $shareUid > 0 ? Db::name('user')->where('uid', $shareUid)->lock(true)->find() : null;
        $eligible = $member && (int)$member['status'] === 1 && !(int)$member['is_del']
            && app()->make(UserServices::class)->checkUserPromoter($shareUid, $member);
        $store = $eligible ? Db::name('system_store')->where('id', (int)$member['belong_store_id'])
            ->where('is_del', 0)->find() : null;
        $reason = !$shareUid ? 'no_share' : (!$eligible ? 'not_eligible' : (!$store ? 'missing_store' : 'qualified_share'));
        Db::name('combination_promotion_order')->insert([
            'order_id' => $orderId, 'activity_id' => (int)$order['activity_id'],
            'share_uid' => $store ? $shareUid : 0, 'store_id' => $store ? (int)$store['id'] : 0,
            'promoter_name' => $store ? (string)($member['real_name'] ?: $member['nickname']) : '',
            'store_name' => $store ? (string)$store['name'] : '',
            'activity_name' => (string)($activity['title'] ?? ''),
            'attribution_reason' => $reason, 'created_at' => time(),
        ]);
    }

    /** 新报表须显式授权，不能依赖旧中间件对未知路由的默认放行。 */
    public function assertDashboard(array $admin): void
    {
        if (empty($admin['id'])) throw new ValidateException('请重新登录');
        if (app()->make(\app\services\organization\EmployeeDataScopeServices::class)->isSuperAdmin($admin)) return;
        $grants = app()->make(\app\services\system\SystemRoleServices::class)->getRolesByAuth((array)($admin['roles'] ?? []), 1);
        foreach ((array)$grants as $grant) if (($grant['unique_auth'] ?? '') === 'marketing-store_combination-dashboard') return;
        throw new ValidateException('无拼团大屏权限');
    }

    /** 严格 ID 契约；空数组表示不再额外筛选，但不会绕过服务端数据范围。 */
    private function ids($value): array
    {
        if (is_string($value)) $value = $value === '' ? [] : explode(',', $value);
        if (!is_array($value) || count($value) > 500) throw new ValidateException('活动选择无效');
        foreach ($value as $id) if (!ctype_digit((string)$id) || (int)$id <= 0) throw new ValidateException('筛选编号无效');
        return array_values(array_unique(array_map('intval', $value)));
    }

    /** 独立保存当前管理员的展示选择，不更改活动发布状态、订单或角色权限。 */
    public function settings(array $admin, ?array $input = null): array
    {
        $this->assertDashboard($admin);
        if ($input !== null) {
            $ids = $this->ids($input['activity_ids'] ?? []);
            $visible = array_column($this->activities($admin)['list'], 'id');
            if (array_diff($ids, array_map('intval', $visible))) throw new ValidateException('所选活动不在当前可见范围');
            Db::name('combination_dashboard_setting')->replace()->insert(['admin_id' => (int)$admin['id'],
                'activity_ids' => json_encode($ids), 'updated_at' => time()]);
        }
        $row = Db::name('combination_dashboard_setting')->where('admin_id', (int)$admin['id'])->find();
        return ['activity_ids' => $row ? (json_decode((string)$row['activity_ids'], true) ?: []) : []];
    }

    /** 活动目录仅提供名称，不带未授权门店的订单/金额；记录范围仍在每次查询中重新校验。 */
    public function activities(array $admin): array
    {
        $this->assertDashboard($admin);
        $allowed = (new CombinationGroupListServices())->allowedStores($admin);
        $query = Db::name('store_combination')->where('is_del', 0)->field('id,title');
        if ($allowed !== null) {
            $activityIds = $this->query([], $allowed, null)->distinct(true)->column('activity_id');
            $query->whereIn('id', $activityIds ?: [0]);
        }
        return ['list' => $query->order('id desc')->select()->toArray()];
    }

    /**
     * 一行一笔真实已支付拼团订单。虚拟名额不产生销售；未支付排除。
     * 原单 is_del=1 是待成团隐藏标记，不能误当删除。没有合格推广人时，个人榜不计业绩，
     * 门店榜仍按原购买门店归集；原单也没有购买门店时才显示未归属。
     * 成团状态改变只移动金额桶；成功退款才扣减，失败待退净额仍归未成团。
     */
    private function query(array $filters, ?array $allowedStores, ?int $ownUid)
    {
        // 先由订单日期/活动/授权归属缩小候选，再按订单索引查名额与退款；不能每30秒全表聚合历史名额。
        $group = Db::name('store_pink')->where('is_virtual', 0)->where('uid', '>', 0)
            ->whereRaw('order_id_key=o.id')
            // 旧成团流程把原拼团订单 type 改为 0；仅凭活动 ID 不足以辨认原单，
            // 必须同时核对参团记录的活动、业务单号和会员，避免普通订单混入业绩。
            ->whereRaw('(o.type=3 OR (o.type=0 AND cid=o.activity_id AND order_id=o.order_id AND uid=o.uid))')
            ->field('MAX(IF(k_id>0,k_id,id))')->buildSql();
        $refunds = Db::name('store_order_terminal_operation')->where('business_type', 1)
            ->where('action_type', 1)->where('state', 4)->whereRaw('store_order_id=o.id')
            ->field('SUM(refund_amount)')->buildSql();
        // Only successful terminal facts (or successful legacy refunds) reduce performance.
        // GREATEST avoids counting the same successful refund from receipt and original row twice.
        $refund = 'LEAST(o.pay_price,GREATEST(COALESCE(' . $refunds . ',0),IF(o.refund_status IN (2,3),o.refund_price,0)))';
        $base = Db::name('store_order')->alias('o')
            ->join('store_pink g', 'g.id=' . $group)->leftJoin('combination_promotion_order a', 'a.order_id=o.id')
            ->leftJoin('store_combination c', 'c.id=o.activity_id')->leftJoin('user u', 'u.uid=o.uid')
            ->leftJoin('system_store purchase_store', 'purchase_store.id=o.store_id')
            ->where('o.paid', 1)->whereIn('o.type', [0, 3])
            ->field('o.id,o.order_id AS order_no,o.uid AS member_id,o.activity_id,g.id AS group_id,o.pay_time AS paid_at,'
                . 'COALESCE(a.share_uid,0) AS promoter_id,IF(COALESCE(a.share_uid,0)>0 AND COALESCE(a.store_id,0)>0,a.store_id,COALESCE(o.store_id,0)) AS store_id,'
                // 有推广人时沿用创建订单时冻结的归属；无推广人时以原单购买门店归集门店业绩。
                // 购买门店从原单读取，不从会员当前门店倒推，避免调店改写历史排行。
                . "COALESCE(NULLIF(a.promoter_name,''),'未归属') AS promoter_name,"
                . "CASE WHEN COALESCE(a.share_uid,0)>0 AND COALESCE(a.store_id,0)>0 THEN COALESCE(NULLIF(a.store_name,''),'未归属') ELSE COALESCE(NULLIF(purchase_store.name,''),'未归属') END AS store_name,"
                . "COALESCE(NULLIF(a.activity_name,''),c.title,'历史活动') AS activity_name,COALESCE(NULLIF(u.real_name,''),NULLIF(u.nickname,''),'会员') AS member_name,"
                . "CASE WHEN g.status=2 THEN 'formed' WHEN g.status=3 THEN 'failed' ELSE 'pending' END AS group_status,"
                . 'ROUND(o.pay_price*100) AS paid_cents,ROUND(' . $refund . '*100) AS refund_cents,'
                . 'GREATEST(0,ROUND((o.pay_price-(' . $refund . '))*100)) AS net_cents');
        // 空授权必须拒绝全部，不能用IN(0)误放行历史未归属且购买门店也为0的订单。
        if ($allowedStores === []) $base->whereRaw('1=0');
        elseif ($allowedStores !== null) $base->whereRaw('IF(COALESCE(a.share_uid,0)>0 AND COALESCE(a.store_id,0)>0,a.store_id,o.store_id) IN ('
            . implode(',', array_map('intval', $allowedStores)) . ')');
        if ($ownUid !== null) {
            if ($ownUid <= 0) throw new ValidateException('请先登录');
            $base->where('a.share_uid', $ownUid);
        }
        $stores = $this->ids($filters['store_ids'] ?? []);
        if ($stores) $base->whereRaw('IF(COALESCE(a.share_uid,0)>0 AND COALESCE(a.store_id,0)>0,a.store_id,COALESCE(o.store_id,0)) IN ('
            . implode(',', $stores) . ')');
        $ids = $this->ids($filters['activity_ids'] ?? (($filters['activity_id'] ?? '') !== '' ? [$filters['activity_id']] : []));
        if ($ids) $base->whereIn('o.activity_id', $ids);
        foreach (['start_date' => '>=', 'end_date' => '<='] as $field => $op) {
            $date = trim((string)($filters[$field] ?? ''));
            if ($date === '') continue;
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (!$parsed || $parsed->format('Y-m-d') !== $date) throw new ValidateException('日期格式无效');
            $base->where('o.pay_time', $op, $parsed->getTimestamp() + ($op === '<=' ? 86399 : 0));
        }
        if (!empty($filters['start_date']) && !empty($filters['end_date']) && $filters['start_date'] > $filters['end_date']) throw new ValidateException('开始日期不能晚于结束日期');
        $query = Db::table([$base->buildSql() => 'facts']);
        $status = (string)($filters['status'] ?? '');
        if ($status === 'unformed') $query->whereIn('group_status', ['pending', 'failed']);
        elseif ($status !== '') {
            if (!in_array($status, ['formed', 'pending', 'failed'], true)) throw new ValidateException('拼团状态无效');
            $query->where('group_status', $status);
        }
        $dimension = (string)($filters['dimension'] ?? '');
        if ($dimension !== '') {
            $field = ['member' => 'promoter_id', 'store' => 'store_id', 'activity' => 'activity_id'][$dimension] ?? '';
            if (!$field || !ctype_digit((string)($filters['dimension_id'] ?? ''))) throw new ValidateException('明细范围无效');
            $query->where($field, (int)$filters['dimension_id']);
        }
        return $query;
    }

    /** Money is computed by SQL in integer cents; presentation/exports use exact decimal yuan strings. */
    private function aggregateSql(): string
    {
        return "COALESCE(SUM(IF(group_status='formed',net_cents,0)),0) AS formed_amount,"
            . "COALESCE(SUM(IF(group_status<>'formed',net_cents,0)),0) AS pending_amount,"
            . "COALESCE(SUM(net_cents),0) AS total_amount,COALESCE(SUM(IF(group_status='failed',net_cents,0)),0) AS refund_pending_amount,"
            . 'COUNT(*) AS order_count,COUNT(DISTINCT group_id) AS group_count,'
            . "COUNT(DISTINCT IF(group_status='formed',group_id,NULL)) AS formed_count,"
            . "COUNT(DISTINCT IF(group_status='pending',group_id,NULL)) AS pending_count,"
            . "COUNT(DISTINCT IF(group_status='failed',group_id,NULL)) AS failed_count";
    }

    private function metricRow(array $row): array
    {
        foreach (['formed_amount', 'pending_amount', 'total_amount', 'refund_pending_amount'] as $field)
            $row[$field] = bcdiv((string)($row[$field] ?? 0), '100', 2);
        foreach (['order_count', 'group_count', 'formed_count', 'pending_count', 'failed_count'] as $field) $row[$field] = (int)($row[$field] ?? 0);
        return $row;
    }

    /** Settings filter is a convenience, never an authorization boundary. */
    public function dashboard(array $filters, array $admin): array
    {
        return $this->readSnapshot(function () use ($filters, $admin) { return $this->dashboardSnapshot($filters, $admin); });
    }

    /** 三榜与合计读取同一快照，成团/退款并发不能让同一响应的金额互相矛盾。 */
    private function dashboardSnapshot(array $filters, array $admin): array
    {
        $settings = $this->settings($admin);
        if (!array_key_exists('activity_ids', $filters)) $filters['activity_ids'] = $settings['activity_ids'];
        $query = $this->query($filters, (new CombinationGroupListServices())->allowedStores($admin), null);
        $ranks = [];
        foreach (['members' => ['promoter_id', 'promoter_name'], 'stores' => ['store_id', 'store_name'], 'activities' => ['activity_id', 'activity_name']] as $key => $spec) {
            // 没有推广人时仍计入总业绩、购买门店和活动，但绝不虚构个人推广业绩。
            $rankQuery = clone $query;
            if ($key === 'members') $rankQuery->where('promoter_id', '>', 0);
            $rows = $rankQuery->field($spec[0] . ' AS id,MAX(' . $spec[1] . ') AS name,'
                . ($key === 'members' ? "IF(COUNT(DISTINCT store_id)=1,MAX(store_name),'多个归属门店') AS store_name," : '')
                . ($key === 'stores' ? "IF(COUNT(DISTINCT member_id)=1,MAX(member_name),'') AS buyer_name," : '')
                . $this->aggregateSql())->group($spec[0])->order('total_amount desc,id asc')->limit(50)->select()->toArray();
            $ranks[$key] = array_map([$this, 'metricRow'], $rows);
        }
        // 此处为无外层条件的派生表聚合；find() 会被 ORM 的无条件保护短路，必须显式执行聚合查询。
        $totals = (clone $query)->field($this->aggregateSql())->select()->toArray();
        return ['settings' => $settings, 'metrics' => $this->metricRow($totals[0] ?? []),
            'rankings' => $ranks, 'updated_at' => date('Y-m-d H:i:s'), 'source_notes' => $this->sourceNotes()];
    }

    /** Member identity is supplied by auth middleware only, never request UID or permanent referral ancestry. */
    public function memberSummary(array $filters, int $uid): array
    {
        return $this->readSnapshot(function () use ($filters, $uid) { return $this->memberSummarySnapshot($filters, $uid); });
    }

    /** 会员活动分组与总览使用相同读取时点。 */
    private function memberSummarySnapshot(array $filters, int $uid): array
    {
        $query = $this->query($filters, null, $uid);
        $activityFilters = $filters;
        unset($activityFilters['activity_id'], $activityFilters['activity_ids'], $activityFilters['status']);
        $rows = $this->query($activityFilters, null, $uid)->field('activity_id AS id,MAX(activity_name) AS name,' . $this->aggregateSql())
            ->group('activity_id')->order('total_amount desc,id asc')->select()->toArray();
        // 与大屏一致使用执行式聚合，不能让 ORM 将合法的空外层条件解释为不查询。
        $totals = (clone $query)->field($this->aggregateSql())->select()->toArray();
        return ['activities' => array_map([$this, 'metricRow'], $rows),
            'metrics' => $this->metricRow($totals[0] ?? []), 'source_notes' => $this->sourceNotes()];
    }

    /** Page/count/summary/export share the exact query; stable order ID provides precise attribution drilldown. */
    public function details(array $filters, ?array $admin = null, ?int $uid = null, bool $export = false): array
    {
        // 导出自行维持跨批快照；普通分页的总数、合计和当前页同样必须一致。
        if ($export) return $this->detailsSnapshot($filters, $admin, $uid, true);
        return $this->readSnapshot(function () use ($filters, $admin, $uid) { return $this->detailsSnapshot($filters, $admin, $uid, false); });
    }

    private function detailsSnapshot(array $filters, ?array $admin, ?int $uid, bool $export): array
    {
        if ($admin !== null) {
            $settings = $this->settings($admin);
            if (!array_key_exists('activity_ids', $filters)) $filters['activity_ids'] = $settings['activity_ids'];
        } elseif ($uid === null) throw new ValidateException('请先登录');
        $query = $this->query($filters, $admin !== null ? (new CombinationGroupListServices())->allowedStores($admin) : null, $uid);
        if ($export) return $this->exportDetails($query, $filters);
        $count = (clone $query)->count();
        $page = min(max(1, (int)($filters['page'] ?? 1)), max(1, (int)ceil($count / 20)));
        $summary = $this->detailSummary($query);
        $rows = $this->formatDetailRows($query->order('paid_at desc,id desc')->page($page, 20)->select()->toArray());
        return ['list' => $rows, 'count' => $count, 'page' => $page, 'limit' => 20, 'summary' => $summary,
            'columns' => $this->columns(), 'table_layout' => ['fixed' => true], 'source_notes' => $this->sourceNotes()];
    }

    /**
     * 报表独立请求开启只读可重复读；若调用方已有事务则沿用其快照，不提交/回滚调用方的业务写入。
     * 禁止修改会话级隔离设置，避免长驻进程下一笔业务继承只读状态。
     */
    private function readSnapshot(callable $read): array
    {
        $connection = Db::connect();
        $pdo = $connection->getPdo();
        if ($pdo && $pdo->inTransaction()) return $read();
        $connection->execute('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        $connection->startTrans();
        try {
            $result = $read();
            $connection->commit();
            return $result;
        } catch (\Throwable $exception) {
            $connection->rollback();
            throw $exception;
        }
    }

    /** 外层派生表可能没有 where/order；ThinkORM find 会直接返回空，聚合须使用 select 的唯一行。 */
    private function detailSummary($query): array
    {
        $rows = (clone $query)->field('COALESCE(SUM(paid_cents),0) AS paid_amount,COALESCE(SUM(refund_cents),0) AS refund_amount,COALESCE(SUM(net_cents),0) AS net_amount')->select()->toArray();
        $summary = $rows[0] ?? ['paid_amount' => 0, 'refund_amount' => 0, 'net_amount' => 0];
        foreach ($summary as $key => $value) $summary[$key] = bcdiv((string)$value, '100', 2);
        return $summary;
    }

    /** 列表与导出复用金额/状态展示契约；字符串保留分精度，不使用页面整数格式反推导出金额。 */
    private function formatDetailRows(array $rows): array
    {
        foreach ($rows as &$row) {
            $row['status_label'] = ['formed' => '已成团', 'pending' => '拼团中', 'failed' => '未成团／失败'][$row['group_status']];
            if ($row['group_status'] === 'failed' && (int)$row['net_cents'] > 0) $row['status_label'] = '失败待退款';
            $row['paid_amount'] = bcdiv((string)$row['paid_cents'], '100', 2);
            $row['refund_amount'] = bcdiv((string)$row['refund_cents'], '100', 2);
            $row['net_amount'] = bcdiv((string)$row['net_cents'], '100', 2);
            $row['paid_at'] = date('Y-m-d H:i:s', (int)$row['paid_at']);
            unset($row['paid_cents'], $row['refund_cents'], $row['net_cents']);
        }
        unset($row);
        return $rows;
    }

    /**
     * 查询可见结果全部导出，不设人为条数上限。每批1000条按支付时间/订单ID游标遍历，
     * 在同一连接只读快照内读取总数、合计与明细，避免期间成团/退款改变导致漏行或统计漂移。
     */
    private function exportDetails($query, array $filters): array
    {
        $connection = $query->getConnection();
        $pdo = $connection->getPdo();
        if ($pdo && $pdo->inTransaction()) throw new ValidateException('当前导出无法建立独立读取，请稍后重试');
        $connection->execute('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        $connection->startTrans();
        try {
            $count = (int)(clone $query)->count();
            $summary = $this->detailSummary($query);
            $rows = [];
            $cursor = null;
            do {
                $batchQuery = clone $query;
                if ($cursor !== null) $batchQuery->whereRaw('(paid_at < :export_before_time OR (paid_at = :export_same_time AND id < :export_before_id))',
                    ['export_before_time' => $cursor['paid_at'], 'export_same_time' => $cursor['paid_at'], 'export_before_id' => $cursor['id']]);
                $batch = $batchQuery->order('paid_at desc,id desc')->limit(1000)->select()->toArray();
                if ($batch) {
                    $last = $batch[count($batch) - 1];
                    $cursor = ['paid_at' => (int)$last['paid_at'], 'id' => (int)$last['id']];
                    foreach ($this->formatDetailRows($batch) as $row) $rows[] = $row;
                }
            } while (count($batch) === 1000);
            if (count($rows) !== $count) throw new ValidateException('导出记录核对未通过，请刷新后重试');
            $columns = $this->columns();
            $result = ['header' => array_column($columns, 'title'), 'filekey' => array_column($columns, 'key'),
                'filename' => '拼团业绩明细', 'export' => $rows, 'count' => $count, 'summary' => $summary,
                'columns' => $columns, 'source_notes' => $this->sourceNotes(),
                'metadata' => ['generated_at' => date('Y-m-d H:i:s'), 'timezone' => date_default_timezone_get(),
                    'date_basis' => '订单支付日期', 'amount_unit' => '元，保留分精度',
                    'scope_note' => '当前账号授权范围内、符合本次查询条件的全部拼团订单',
                    // 仅公开查询参数，不把身份、角色、SQL或服务器上下文写入导出文件。
                    'filters' => array_intersect_key($filters, array_flip(['activity_ids', 'activity_id', 'store_ids', 'start_date', 'end_date', 'dimension', 'dimension_id', 'status']))]];
            $connection->commit();
            return $result;
        } catch (\Throwable $exception) {
            $connection->rollback();
            throw $exception;
        }
    }

    public function sourceNotes(): array
    {
        return ['统计按支付日期筛选，仅真实已支付拼团订单；未支付、虚拟名额、赠送奖励不计业绩。',
            '成团业绩为当前已成团订单实付扣成功退款；未成团业绩包含拼团中及失败待退款净额；总业绩等于两项合计。',
            '成功退款冲减原支付日期对应业绩；待处理退款不提前扣除；成团只转移金额分类，不重复增加总业绩。',
            '个人排行只统计有合格推广人的订单；无推广人的订单仍计入总业绩、活动及原单购买门店，个人排行不列未归属。',
            '有推广人的门店取订单创建时本次分享快照；无推广人的门店取原单购买门店，不按会员当前归属倒推。原单也无门店时列为未归属。',
            '每笔订单只归一个推广人；团数按团编号去重，同一团可出现在不同推广人的明细中，个人团数不能直接相加。'];
    }

    public function columns(): array
    {
        return array_map(static function ($s) { return ['key' => $s[0], 'title' => $s[1], 'width' => $s[2], 'money' => $s[3] ?? false]; }, [
            ['order_no','订单号',180], ['activity_name','拼团活动',180], ['member_name','购买会员',110],
            ['promoter_name','推广人',110], ['store_name','业绩归属门店',150], ['status_label','拼团状态',120],
            ['paid_amount','实付金额',110,true], ['refund_amount','成功退款',110,true], ['net_amount','净业绩',110,true], ['paid_at','支付时间',170],
        ]);
    }
}
