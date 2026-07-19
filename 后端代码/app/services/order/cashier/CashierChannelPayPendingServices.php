<?php
declare(strict_types=1);

namespace app\services\order\cashier;

use app\jobs\order\OrderStatusJob;
use app\services\BaseServices;
use mohe\traits\ServicesTrait;
use think\exception\ValidateException;
use think\facade\Db;
use think\facade\Log;

/**
 * 收银渠道（微信/支付宝）已扣款、自动核销失败 → 待人工处理
 */
class CashierChannelPayPendingServices extends BaseServices
{
    use ServicesTrait;

    public const STATUS_PENDING = 0;
    public const STATUS_DONE = 1;

    /** 已成功落库待处理时给收银端的提示 */
    public const MSG_WAIT_PLATFORM = '已扣款，请勿重新结账，等待平台处理';

    /** 落库失败时：仍禁止重结，但不得谎称「等待平台处理」 */
    public const MSG_RECORD_FAIL = '已扣款，请勿重新结账；系统未能登记待处理工单，请立即联系平台（勿再次支付）';

    /** 订单状态：登记待处理工单失败（供异步轮询识别） */
    public const ORDER_STATUS_RECORD_FAIL = 'channel_pay_pending_record_fail';

    /**
     * 登记成功前提：015 主表 + 016 handle_* + 016 审计表缺一不可。
     */
    public function assertSchemaReady(): void
    {
        if (!$this->tableReady()) {
            throw new ValidateException('待处理表未初始化，请先执行升级包 20260718-015（上站勿漏项 H）');
        }
        if (!$this->hasHandleColumns()) {
            throw new ValidateException('处理字段未初始化，请先执行升级包 20260718-016（上站勿漏项 H）');
        }
        if (!$this->auditTableReady()) {
            throw new ValidateException('审计表未初始化，请先执行升级包 20260718-016（上站勿漏项 H）');
        }
    }

    /**
     * 独立落库（必须成功）；同一 oid 更新原因。
     * 写表失败抛异常，禁止调用方在无记录时使用 MSG_WAIT_PLATFORM。
     *
     * @return int 待处理记录 ID
     */
    public function record(array $orderInfo, string $payType, string $reason, string $tradeNo = ''): int
    {
        $oid = (int)($orderInfo['id'] ?? 0);
        if ($oid <= 0) {
            throw new ValidateException('渠道已扣款待处理记录写入失败：订单ID无效');
        }
        $reason = trim($reason);
        if ($reason === '') {
            $reason = '自动核销/院装失败';
        }
        if (mb_strlen($reason) > 480) {
            $reason = mb_substr($reason, 0, 477) . '...';
        }
        $tradeNo = trim($tradeNo !== '' ? $tradeNo : (string)($orderInfo['trade_no'] ?? ''));
        $now = time();
        // 015+016 齐全才算可登记；缺任一项走 MSG_RECORD_FAIL，不得提示等待平台处理
        $this->assertSchemaReady();
        $row = [
            'oid' => $oid,
            'order_id' => (string)($orderInfo['order_id'] ?? ''),
            'trade_no' => $tradeNo,
            'pay_type' => $payType,
            'reason' => $reason,
            'status' => self::STATUS_PENDING,
            'store_id' => (int)($orderInfo['store_id'] ?? 0),
            'update_time' => $now,
            'handle_admin_id' => 0,
            'handle_admin_name' => '',
            'handle_time' => 0,
            'handle_remark' => '',
        ];

        try {
            $pendingId = 0;
            Db::transaction(function () use ($oid, $row, $now, $reason, &$pendingId) {
                $exist = Db::name('cashier_channel_pay_pending')->where('oid', $oid)->lock(true)->find();
                if ($exist) {
                    $pendingId = (int)$exist['id'];
                    Db::name('cashier_channel_pay_pending')->where('id', $pendingId)->update($row);
                    $this->writeAudit(
                        $pendingId,
                        $oid,
                        'reopen',
                        0,
                        'system',
                        $reason,
                        (int)($exist['status'] ?? 0),
                        self::STATUS_PENDING
                    );
                } else {
                    $row['add_time'] = $now;
                    $pendingId = (int)Db::name('cashier_channel_pay_pending')->insertGetId($row);
                    $this->writeAudit(
                        $pendingId,
                        $oid,
                        'create',
                        0,
                        'system',
                        $reason,
                        -1,
                        self::STATUS_PENDING
                    );
                }
            });

            OrderStatusJob::dispatch([
                $oid,
                'channel_pay_pending',
                [
                    'change_message' => mb_strlen('渠道已扣款待人工处理：' . $reason) > 250
                        ? (mb_substr('渠道已扣款待人工处理：' . $reason, 0, 247) . '...')
                        : ('渠道已扣款待人工处理：' . $reason),
                    'change_manager_type' => 'system',
                ],
            ]);

            return $pendingId;
        } catch (\Throwable $e) {
            Log::error('写入收银渠道已扣款待处理失败：' . $e->getMessage() . ' oid=' . $oid);
            if ($e instanceof ValidateException) {
                throw $e;
            }
            throw new ValidateException('渠道已扣款待处理记录写入失败：' . $e->getMessage());
        }
    }

    /** 订单是否存在未处理的渠道待人工记录 */
    public function hasPending(int $oid): bool
    {
        if ($oid <= 0 || !$this->tableReady()) {
            return false;
        }
        try {
            return (int)Db::name('cashier_channel_pay_pending')
                    ->where('oid', $oid)
                    ->where('status', self::STATUS_PENDING)
                    ->value('id') > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 登记失败信号（同步写入订单状态，供 MicroPay 轮询识别）
     */
    public function markRecordFailSignal(int $oid, string $detail = ''): void
    {
        if ($oid <= 0) {
            return;
        }
        $msg = self::MSG_RECORD_FAIL;
        $detail = trim($detail);
        if ($detail !== '') {
            $msg .= '：' . $detail;
        }
        if (mb_strlen($msg) > 250) {
            $msg = mb_substr($msg, 0, 247) . '...';
        }
        try {
            /** @var \app\services\order\StoreOrderStatusServices $statusServices */
            $statusServices = app()->make(\app\services\order\StoreOrderStatusServices::class);
            $statusServices->saveStatus(
                $oid,
                self::ORDER_STATUS_RECORD_FAIL,
                [
                    'change_message' => $msg,
                    'change_manager_type' => 'system',
                ],
                0,
                'system'
            );
        } catch (\Throwable $e) {
            Log::error('写入渠道待处理登记失败信号异常 oid=' . $oid . '：' . $e->getMessage());
        }
    }

    /** 是否存在「待处理工单登记失败」信号 */
    public function hasRecordFailSignal(int $oid): bool
    {
        if ($oid <= 0) {
            return false;
        }
        try {
            return (int)Db::name('store_order_status')
                    ->where('oid', $oid)
                    ->where('change_type', self::ORDER_STATUS_RECORD_FAIL)
                    ->value('id') > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 收银轮询/异步场景：解析应展示的已扣款提示
     * @return array{alert: bool, message: string}
     */
    public function resolveCashierAlert(int $oid): array
    {
        if ($oid <= 0) {
            return ['alert' => false, 'message' => ''];
        }
        if ($this->hasPending($oid)) {
            return ['alert' => true, 'message' => self::MSG_WAIT_PLATFORM];
        }
        if ($this->hasRecordFailSignal($oid)) {
            return ['alert' => true, 'message' => self::MSG_RECORD_FAIL];
        }
        return ['alert' => false, 'message' => ''];
    }

    /**
     * 平台列表
     * @return array{list: array, count: int}
     */
    public function getAdminList(array $where, int $page, int $limit): array
    {
        $this->assertSchemaReady();
        $page = max(1, $page);
        $limit = max(1, min(100, $limit));

        $query = Db::name('cashier_channel_pay_pending')->alias('p')
            ->leftJoin('store_order o', 'o.id = p.oid')
            ->leftJoin('system_store s', 's.id = p.store_id')
            ->field([
                'p.*',
                'o.pay_price',
                'o.uid',
                'o.paid',
                'o.pay_time',
                'o.real_name',
                'o.user_phone',
                's.name as store_name',
            ]);

        if ($where['status'] !== '' && $where['status'] !== null) {
            $query->where('p.status', (int)$where['status']);
        }
        if (!empty($where['store_id'])) {
            $query->where('p.store_id', (int)$where['store_id']);
        }
        if (!empty($where['pay_type'])) {
            $query->where('p.pay_type', (string)$where['pay_type']);
        }
        if (!empty($where['keyword'])) {
            $kw = trim((string)$where['keyword']);
            $query->where(function ($q) use ($kw) {
                $q->where('p.order_id', 'like', '%' . $kw . '%')
                    ->whereOr('p.trade_no', 'like', '%' . $kw . '%')
                    ->whereOr('p.reason', 'like', '%' . $kw . '%');
            });
        }
        if (!empty($where['add_time']) && is_array($where['add_time']) && count($where['add_time']) === 2) {
            $start = strtotime((string)$where['add_time'][0] . ' 00:00:00');
            $end = strtotime((string)$where['add_time'][1] . ' 23:59:59');
            if ($start && $end) {
                $query->whereBetween('p.add_time', [$start, $end]);
            }
        }

        $count = (clone $query)->count();
        $list = $query->order('p.status', 'asc')
            ->order('p.id', 'desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        foreach ($list as &$row) {
            $row['status_text'] = ((int)$row['status'] === self::STATUS_DONE) ? '已处理' : '待处理';
            $row['pay_type_text'] = $this->payTypeText((string)$row['pay_type']);
            $row['add_time_text'] = !empty($row['add_time']) ? date('Y-m-d H:i:s', (int)$row['add_time']) : '-';
            $row['handle_time_text'] = !empty($row['handle_time']) ? date('Y-m-d H:i:s', (int)$row['handle_time']) : '-';
            $row['pay_time_text'] = !empty($row['pay_time']) ? date('Y-m-d H:i:s', (int)$row['pay_time']) : '-';
        }
        unset($row);

        return ['list' => $list, 'count' => (int)$count];
    }

    /**
     * 详情（含审计）
     */
    public function getAdminInfo(int $id): array
    {
        if ($id <= 0) {
            throw new ValidateException('参数错误');
        }
        $this->assertSchemaReady();
        $row = Db::name('cashier_channel_pay_pending')->alias('p')
            ->leftJoin('store_order o', 'o.id = p.oid')
            ->leftJoin('system_store s', 's.id = p.store_id')
            ->field([
                'p.*',
                'o.pay_price',
                'o.uid',
                'o.paid',
                'o.pay_time',
                'o.real_name',
                'o.user_phone',
                's.name as store_name',
            ])
            ->where('p.id', $id)
            ->find();
        if (!$row) {
            throw new ValidateException('记录不存在');
        }
        $row['status_text'] = ((int)$row['status'] === self::STATUS_DONE) ? '已处理' : '待处理';
        $row['pay_type_text'] = $this->payTypeText((string)$row['pay_type']);
        $row['add_time_text'] = !empty($row['add_time']) ? date('Y-m-d H:i:s', (int)$row['add_time']) : '-';
        $row['handle_time_text'] = !empty($row['handle_time']) ? date('Y-m-d H:i:s', (int)$row['handle_time']) : '-';
        $row['pay_time_text'] = !empty($row['pay_time']) ? date('Y-m-d H:i:s', (int)$row['pay_time']) : '-';

        $audits = [];
        if ($this->auditTableReady()) {
            $audits = Db::name('cashier_channel_pay_pending_audit')
                ->where('pending_id', $id)
                ->order('id', 'desc')
                ->select()
                ->toArray();
            foreach ($audits as &$a) {
                $a['create_time_text'] = !empty($a['create_time']) ? date('Y-m-d H:i:s', (int)$a['create_time']) : '-';
                $a['action_text'] = $this->auditActionText((string)$a['action']);
            }
            unset($a);
        }
        $row['audits'] = $audits;
        return $row;
    }

    /**
     * 标记处理完成
     */
    public function markDone(int $id, int $adminId, string $adminName, string $remark = ''): void
    {
        if ($id <= 0) {
            throw new ValidateException('参数错误');
        }
        $this->assertSchemaReady();
        $remark = trim($remark);
        if ($remark === '') {
            throw new ValidateException('请填写处理说明');
        }
        if (mb_strlen($remark) > 480) {
            $remark = mb_substr($remark, 0, 477) . '...';
        }
        $adminName = trim($adminName) !== '' ? trim($adminName) : ('admin#' . $adminId);
        $now = time();

        Db::transaction(function () use ($id, $adminId, $adminName, $remark, $now) {
            $row = Db::name('cashier_channel_pay_pending')->where('id', $id)->lock(true)->find();
            if (!$row) {
                throw new ValidateException('记录不存在');
            }
            if ((int)$row['status'] === self::STATUS_DONE) {
                throw new ValidateException('该记录已处理完成');
            }
            Db::name('cashier_channel_pay_pending')->where('id', $id)->update([
                'status' => self::STATUS_DONE,
                'handle_admin_id' => $adminId,
                'handle_admin_name' => $adminName,
                'handle_time' => $now,
                'handle_remark' => $remark,
                'update_time' => $now,
            ]);
            $this->writeAudit(
                $id,
                (int)$row['oid'],
                'done',
                $adminId,
                $adminName,
                $remark,
                self::STATUS_PENDING,
                self::STATUS_DONE
            );
            OrderStatusJob::dispatch([
                (int)$row['oid'],
                'channel_pay_pending_done',
                [
                    'change_message' => mb_strlen('渠道已扣款待处理已完成：' . $remark) > 250
                        ? (mb_substr('渠道已扣款待处理已完成：' . $remark, 0, 247) . '...')
                        : ('渠道已扣款待处理已完成：' . $remark),
                    'change_manager_id' => $adminId,
                    'change_manager_type' => 'admin',
                ],
            ]);
        });
    }

    protected function writeAudit(
        int $pendingId,
        int $oid,
        string $action,
        int $operatorId,
        string $operatorName,
        string $remark,
        int $beforeStatus,
        int $afterStatus
    ): void {
        if (!$this->auditTableReady()) {
            throw new ValidateException('审计表未初始化，请先执行升级包 20260718-016（上站勿漏项 H）');
        }
        Db::name('cashier_channel_pay_pending_audit')->insert([
            'pending_id' => $pendingId,
            'oid' => $oid,
            'action' => $action,
            'operator_id' => $operatorId,
            'operator_name' => $operatorName,
            'remark' => mb_strlen($remark) > 480 ? (mb_substr($remark, 0, 477) . '...') : $remark,
            'before_status' => $beforeStatus,
            'after_status' => $afterStatus,
            'create_time' => time(),
        ]);
    }

    protected function tableReady(): bool
    {
        try {
            $rows = Db::query("SHOW TABLES LIKE 'eb_cashier_channel_pay_pending'");
            return !empty($rows);
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function auditTableReady(): bool
    {
        try {
            $rows = Db::query("SHOW TABLES LIKE 'eb_cashier_channel_pay_pending_audit'");
            return !empty($rows);
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function hasHandleColumns(): bool
    {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }
        try {
            $cols = Db::query("SHOW COLUMNS FROM `eb_cashier_channel_pay_pending` LIKE 'handle_admin_id'");
            $ready = !empty($cols);
        } catch (\Throwable $e) {
            $ready = false;
        }
        return $ready;
    }

    protected function payTypeText(string $payType): string
    {
        $map = [
            'weixin' => '微信',
            'alipay' => '支付宝',
            'alipay_pay' => '支付宝',
        ];
        return $map[$payType] ?? ($payType !== '' ? $payType : '-');
    }

    protected function auditActionText(string $action): string
    {
        $map = [
            'create' => '登记待处理',
            'reopen' => '再次待处理',
            'done' => '处理完成',
        ];
        return $map[$action] ?? $action;
    }
}
