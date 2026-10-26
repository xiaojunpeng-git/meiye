<?php
declare(strict_types=1);

namespace app\services\order\cashier;

use app\jobs\activity\LuckLotteryJob;
use app\jobs\activity\StoreBargainJob;
use app\jobs\activity\StorePromotionsJob;
use app\jobs\notice\PrintJob;
use app\jobs\order\CashierCreateSideEffectRetryJob;
use app\jobs\order\CreateInvoiceJob;
use app\jobs\order\OrderCreateAfterJob;
use app\jobs\order\OrderJob;
use app\jobs\order\OrderStatusJob;
use app\jobs\order\UnpaidOrderCancelJob;
use app\jobs\order\UnpaidOrderSend;
use app\jobs\product\ProductLogJob;
use app\jobs\store\StoreUserJob;
use app\jobs\system\SystemFormDataJob;
use app\jobs\user\UserBelongStoreJob;
use app\jobs\user\UserJob;
use app\services\BaseServices;
use app\services\order\StoreOrderCreateServices;
use app\services\order\StoreOrderServices;
use app\services\order\StoreOrderStatusServices;
use mohe\traits\ServicesTrait;
use think\exception\ValidateException;
use think\facade\Db;
use think\facade\Log;

/**
 * 现金/余额/组合：支付提交后创建副作用（分步租约状态机 + 幂等确认 + 下游任务账本 FSM）
 *
 * - 主表步骤：FOR UPDATE 领取 → 事务外执行 → 按 token 落完成/失败（claimStep/finishClaim）
 * - 幂等行：仅 status=1 表示投递计划已提交；status=0 可租约接管并实际执行（reserveEffectIdem）
 * - 下游任务账本：oid+step+task_key 唯一行，独立租约状态机（TASK_PENDING/RUNNING/DONE/FAIL）
 *   → 每个不可重复下游任务可单独 begin/complete/fail，崩溃后按租约到期原子接管重试，
 *     且只重试未完成任务，绝不重放已完成任务或整段监听
 * - order.create 全量副作用（含打印/归属/发票/积分/清购物车/未支付短信与自动取消等）
 *   全部收口进任务账本，监听器不再有「未入账」的裸投递
 * - 表结构就绪：进程级缓存，热路径不跑 SHOW TABLES/COLUMNS
 */
class CashierCreateSideEffectServices extends BaseServices
{
    use ServicesTrait;

    public const STATUS_PENDING = 0;
    public const STATUS_DONE = 1;
    public const STATUS_RETRY = 2;

    public const STEP_PENDING = 0;
    public const STEP_DONE = 1;
    public const STEP_FAIL = 2;
    public const STEP_RUNNING = 3;

    public const IDEM_RESERVED = 0;
    public const IDEM_CONFIRMED = 1;

    /**
     * 下游任务账本状态机（021）
     */
    public const TASK_PENDING = 0;
    public const TASK_DONE = 1;
    public const TASK_RUNNING = 2;
    public const TASK_FAIL = 3;

    public const STEP_PROMOTIONS = 'promotions';
    public const STEP_DEL_CART = 'del_cart';
    public const STEP_ORDER_CREATE = 'order_create';

    /** order_create 步骤下的全量下游任务键 */
    public const TASK_CREATE_STATUS = 'create_status';
    public const TASK_PRINT = 'print';
    public const TASK_STORE_USER = 'store_user';
    public const TASK_USER_BELONG = 'user_belong';
    public const TASK_COMPUTE_TRUE_PRICE = 'compute_true_price';
    public const TASK_UPDATE_USER = 'update_user';
    public const TASK_AFTER_DEL_CART = 'after_del_cart';
    public const TASK_DEL_ORDER_CACHE = 'del_order_cache';
    public const TASK_CREATE_INVOICE = 'create_invoice';
    public const TASK_BARGAIN_STATUS = 'bargain_status';
    public const TASK_USER_NEWCOMER = 'user_newcomer';
    public const TASK_LUCK_LOTTERY = 'luck_lottery';
    public const TASK_PRODUCT_LOG = 'product_log';
    public const TASK_SYSTEM_FORM = 'system_form';
    public const TASK_UNPAID_SEND = 'unpaid_send';
    public const TASK_UNPAID_CANCEL = 'unpaid_cancel';

    public const TABLE = 'cashier_create_side_effect';
    public const IDEM_TABLE = 'cashier_create_side_effect_idem';
    public const TASK_TABLE = 'cashier_create_side_effect_task';

    public const LEASE_SECONDS = 120;

    /** @var array<string,bool|null> 进程级 schema 缓存；null=未探测 */
    protected static $schemaCache = [
        'main' => null,
        'lease' => null,
        'idem' => null,
        'idem_lease' => null,
        'task' => null,
        'task_lease' => null,
    ];

    /**
     * 支付事务内登记（与订单同事务；回滚则记录消失）
     */
    public function recordPending(array $order, array $group, array $activity, $promotionsGive): void
    {
        $oid = (int)($order['id'] ?? 0);
        if ($oid <= 0) {
            throw new ValidateException('创建副作用登记失败：订单ID无效');
        }
        $this->assertSchemaReadyForWrite();
        $now = time();
        $payload = json_encode([
            'promotions_give' => $promotionsGive ?: [],
            'group' => $group,
            'activity' => $activity,
            'order' => $order,
            // 收银创建目前不支持随下单开票，固定 0；预留字段供后续 deliver 优先取用真实值
            'invoice_id' => 0,
        ], JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            throw new ValidateException('创建副作用登记失败：payload 编码失败');
        }
        $row = [
            'oid' => $oid,
            'order_id' => (string)($order['order_id'] ?? ''),
            'store_id' => (int)($order['store_id'] ?? 0),
            'payload' => $payload,
            'step_promotions' => self::STEP_PENDING,
            'token_promotions' => '',
            'lease_promotions' => 0,
            'step_del_cart' => self::STEP_PENDING,
            'token_del_cart' => '',
            'lease_del_cart' => 0,
            'step_order_create' => self::STEP_PENDING,
            'token_order_create' => '',
            'lease_order_create' => 0,
            'status' => self::STATUS_PENDING,
            'last_error' => '',
            'retry_count' => 0,
            'update_time' => $now,
        ];
        $exist = Db::name(self::TABLE)->where('oid', $oid)->find();
        if ($exist) {
            Db::name(self::TABLE)->where('id', (int)$exist['id'])->update($row);
        } else {
            $row['add_time'] = $now;
            Db::name(self::TABLE)->insert($row);
        }
        // 支付主事务内任务入账（仅规划 PENDING 行，不执行打印/日志等）
        if ($this->taskTableReady()) {
            $this->ensureTaskRows($oid, self::STEP_PROMOTIONS, '', [self::STEP_PROMOTIONS], $now);
            $this->ensureTaskRows($oid, self::STEP_DEL_CART, '', [self::STEP_DEL_CART], $now);
            $taskKeys = $this->orderCreateTaskKeys($order, $activity, 0);
            $this->ensureTaskRows($oid, self::STEP_ORDER_CREATE, '', $taskKeys, $now);
        }
        // 预插入幂等行（按 step 字典序），避免多 worker 并发 INSERT 在 uk_oid_step 上 gap-lock 死锁
        if ($this->idemTableReady() && $this->idemLeaseReady()) {
            $steps = [self::STEP_DEL_CART, self::STEP_ORDER_CREATE, self::STEP_PROMOTIONS];
            sort($steps, SORT_STRING);
            foreach ($steps as $step) {
                try {
                    Db::name(self::IDEM_TABLE)->insert([
                        'oid' => $oid,
                        'step' => $step,
                        'claim_token' => '',
                        'lease_until' => 0,
                        'status' => self::IDEM_RESERVED,
                        'add_time' => $now,
                        'update_time' => $now,
                    ]);
                } catch (\Throwable $e) {
                    $msg = $e->getMessage();
                    if (stripos($msg, 'Duplicate') === false && stripos($msg, '1062') === false) {
                        throw $e;
                    }
                }
            }
        }
    }

    /**
     * 支付提交后：只投递 worker，禁止在收银 HTTP 路径同步执行打印/日志/缓存等副作用。
     *
     * @return array{status:int,oid:int,failed_steps:array,scheduled?:bool}
     */
    public function scheduleAfterCommit(int $oid, ?array $memoryPending = null): array
    {
        if ($oid <= 0) {
            return ['status' => self::STATUS_DONE, 'oid' => $oid, 'failed_steps' => []];
        }
        try {
            if (!$this->schemaReadyCached()) {
                Log::error('cashier create side effect schema incomplete oid=' . $oid);
                return ['status' => self::STATUS_RETRY, 'oid' => $oid, 'failed_steps' => ['schema']];
            }
            $row = Db::name(self::TABLE)->where('oid', $oid)->find();
            if (!$row && $memoryPending) {
                $this->recordPending(
                    $memoryPending['order'] ?? [],
                    $memoryPending['group'] ?? [],
                    $memoryPending['activity'] ?? ['type' => 0, 'activity_id' => 0],
                    $memoryPending['promotions_give'] ?? []
                );
            }
            Db::name(self::TABLE)->where('oid', $oid)->where('status', '<>', self::STATUS_DONE)->update([
                'status' => self::STATUS_RETRY,
                'update_time' => time(),
            ]);
            CashierCreateSideEffectRetryJob::dispatchSece(0, [$oid]);
            return ['status' => self::STATUS_RETRY, 'oid' => $oid, 'failed_steps' => [], 'scheduled' => true];
        } catch (\Throwable $e) {
            Log::error('cashier create side effect schedule fail oid=' . $oid . ' err=' . $e->getMessage());
            return ['status' => self::STATUS_RETRY, 'oid' => $oid, 'failed_steps' => ['schedule']];
        }
    }

    /**
     * 支付提交后执行；绝不向外抛业务异常。
     *
     * @return array{status:int,oid:int,failed_steps:array}
     */
    public function processAfterCommit(int $oid, ?array $memoryPending = null, ?string $forceFailStep = null): array
    {
        if ($oid <= 0) {
            return ['status' => self::STATUS_DONE, 'oid' => $oid, 'failed_steps' => []];
        }
        try {
            if (!$this->schemaReadyCached()) {
                Log::error('cashier create side effect schema incomplete oid=' . $oid);
                return ['status' => self::STATUS_RETRY, 'oid' => $oid, 'failed_steps' => ['schema']];
            }
            $row = Db::name(self::TABLE)->where('oid', $oid)->find();
            if (!$row && $memoryPending) {
                $this->recordPending(
                    $memoryPending['order'] ?? [],
                    $memoryPending['group'] ?? [],
                    $memoryPending['activity'] ?? ['type' => 0, 'activity_id' => 0],
                    $memoryPending['promotions_give'] ?? []
                );
            }
            $failed = [];
            foreach ([self::STEP_PROMOTIONS, self::STEP_DEL_CART, self::STEP_ORDER_CREATE] as $step) {
                $claim = $this->claimStep($oid, $step);
                if ($claim === null) {
                    continue;
                }
                try {
                    if ($forceFailStep && $forceFailStep === $step) {
                        throw new ValidateException('SMOKE_FORCE_FLUSH_FAIL:' . $step);
                    }
                    $mode = $this->reserveEffectIdem($oid, $step, $claim['token']);
                    if ($mode === 'blocked') {
                        $this->finishClaim($oid, $step, $claim['token'], self::STEP_FAIL, '幂等行租约被其他持有者占用');
                        $failed[] = $step;
                        continue;
                    }
                    if ($mode === 'execute') {
                        $this->runStep($step, $claim['payload'], $oid, $claim['token']);
                        if (!$this->isEffectConfirmed($oid, $step)) {
                            throw new ValidateException('幂等未确认（监听/投递未原子消费）');
                        }
                        // order_create：runStep/onOrderCreateEvent 只入账；须在此投递任务（worker 路径）
                        if ($step === self::STEP_ORDER_CREATE) {
                            $this->flushStepTasks($oid, $step, $claim['payload']);
                        }
                    } else {
                        // skip_done：计划已提交，只刷未完成/失败/租约过期的下游任务，禁止整单重放
                        $this->flushStepTasks($oid, $step, $claim['payload']);
                    }
                    // 子任务未全部 DONE：父步骤必须保持 FAIL/待补偿并调度重试，禁止提前 STEP_DONE
                    if (!$this->allStepTasksDone($oid, $step)) {
                        $summary = $this->summarizeIncompleteTasks($oid, $step);
                        $this->finishClaim($oid, $step, $claim['token'], self::STEP_FAIL, $summary);
                        $failed[] = $step;
                        continue;
                    }
                    if (!$this->finishClaim($oid, $step, $claim['token'], self::STEP_DONE, '')) {
                        throw new ValidateException('步骤完成标记失败（token 不匹配）');
                    }
                } catch (\Throwable $e) {
                    $failed[] = $step;
                    $msg = mb_substr($e->getMessage(), 0, 480);
                    $this->finishClaim($oid, $step, $claim['token'], self::STEP_FAIL, $msg);
                    Log::error('cashier create side effect step fail oid=' . $oid . ' step=' . $step . ' err=' . $msg);
                }
            }
            $status = $this->refreshAggregateStatus($oid);
            if ($failed) {
                OrderStatusJob::dispatch([
                    $oid,
                    'create_side_effect_retry',
                    [
                        'change_message' => '支付成功后创建副作用待补偿：' . implode(',', $failed),
                        'change_manager_type' => 'system',
                    ],
                ]);
                // 首次尽快投递 worker；后续由 RetryJob 内按 retry_count 退避
                $delay = ((int)(Db::name(self::TABLE)->where('oid', $oid)->value('retry_count') ?: 0) > 0) ? 30 : 1;
                CashierCreateSideEffectRetryJob::dispatchSece($delay, [$oid]);
            }
            return ['status' => $status, 'oid' => $oid, 'failed_steps' => $failed];
        } catch (\Throwable $e) {
            Log::error('cashier create side effect process fatal oid=' . $oid . ' err=' . $e->getMessage());
            return ['status' => self::STATUS_RETRY, 'oid' => $oid, 'failed_steps' => ['fatal']];
        }
    }

    public function retry(int $oid): array
    {
        if ($oid <= 0 || !$this->tableReady()) {
            return ['status' => self::STATUS_RETRY, 'oid' => $oid, 'failed_steps' => ['invalid']];
        }
        $row = Db::name(self::TABLE)->where('oid', $oid)->find();
        if (!$row) {
            return ['status' => self::STATUS_DONE, 'oid' => $oid, 'failed_steps' => []];
        }
        if ((int)$row['status'] === self::STATUS_DONE) {
            return ['status' => self::STATUS_DONE, 'oid' => $oid, 'failed_steps' => []];
        }
        Db::name(self::TABLE)->where('oid', $oid)->update([
            'retry_count' => (int)$row['retry_count'] + 1,
            'update_time' => time(),
        ]);
        return $this->processAfterCommit($oid);
    }

    /**
     * order.create 监听入口：仅原子确认幂等 + 规划全量下游任务入账（任务行）。
     * HTTP 收银路径不在此同步执行打印/日志/缓存等；由父步骤 FAIL→RetryJob→flushStepTasks 在 worker 执行。
     *
     * @return string reject|admit|resume
     */
    public function onOrderCreateEvent(string $idemKey, string $claimToken, array $orderInfo, array $group = [], array $activity = [], $invoiceId = 0): string
    {
        if ($idemKey === '' || $claimToken === '') {
            return 'reject';
        }
        if (!$this->schemaReadyCached()) {
            return 'reject';
        }
        if (!preg_match('/^(\d+):order_create$/', $idemKey, $m)) {
            return 'reject';
        }
        $oid = (int)$m[1];
        $activity = $activity ?: ['type' => 0, 'activity_id' => 0];
        $invoiceId = (int)$invoiceId;
        $taskKeys = $this->orderCreateTaskKeys($orderInfo, $activity, $invoiceId);
        $admit = $this->admitStepTasks($oid, self::STEP_ORDER_CREATE, $claimToken, $taskKeys);
        if ($admit === 'reject') {
            return 'reject';
        }
        // 只入账不投递：父步骤汇总未完成任务后会 STEP_FAIL 并调度 CashierCreateSideEffectRetryJob
        return $admit;
    }

    /**
     * 步骤下全部下游任务是否均为 DONE（无任务行视为未完成，防止空完成）
     */
    public function allStepTasksDone(int $oid, string $step): bool
    {
        if ($oid <= 0 || $step === '' || !$this->taskTableReady()) {
            return false;
        }
        $rows = Db::name(self::TASK_TABLE)->where(['oid' => $oid, 'step' => $step])->field('status')->select()->toArray();
        if (!$rows) {
            return false;
        }
        foreach ($rows as $row) {
            if ((int)$row['status'] !== self::TASK_DONE) {
                return false;
            }
        }
        return true;
    }

    /**
     * 未完成任务摘要（写入父步骤 last_error）
     */
    public function summarizeIncompleteTasks(int $oid, string $step): string
    {
        $rows = Db::name(self::TASK_TABLE)
            ->where(['oid' => $oid, 'step' => $step])
            ->where('status', '<>', self::TASK_DONE)
            ->field('task_key,status,last_error')
            ->limit(20)
            ->select()
            ->toArray();
        if (!$rows) {
            return '下游任务未完成（无任务行）';
        }
        $parts = [];
        foreach ($rows as $r) {
            $parts[] = (string)$r['task_key'] . ':' . (int)$r['status'] . ($r['last_error'] ? '(' . mb_substr((string)$r['last_error'], 0, 40) . ')' : '');
        }
        return mb_substr('下游任务未完成 ' . implode(',', $parts), 0, 480);
    }

    /**
     * 兼容旧名：仅表示「当前 claim 是否已是/可成为计划持有者」，不再作为投递放行布尔。
     * @deprecated 请用 onOrderCreateEvent
     */
    public function listenerMayProceed(string $idemKey, string $claimToken = ''): bool
    {
        if ($idemKey === '') {
            return true;
        }
        if ($claimToken === '' || !preg_match('/^(\d+):(promotions|del_cart|order_create)$/', $idemKey, $m)) {
            return false;
        }
        $oid = (int)$m[1];
        $step = $m[2];
        $row = Db::name(self::IDEM_TABLE)->where(['oid' => $oid, 'step' => $step])->find();
        if (!$row) {
            return false;
        }
        if ((int)$row['status'] === self::IDEM_CONFIRMED) {
            return true;
        }
        return (string)$row['claim_token'] === $claimToken && (int)($row['lease_until'] ?? 0) > time();
    }

    public function tableReady(): bool
    {
        return $this->cachedSchema('main', function () {
            return !empty(Db::query("SHOW TABLES LIKE 'eb_cashier_create_side_effect'"));
        });
    }

    public function leaseSchemaReady(): bool
    {
        return $this->cachedSchema('lease', function () {
            return !empty(Db::query("SHOW COLUMNS FROM `eb_cashier_create_side_effect` LIKE 'token_promotions'"));
        });
    }

    public function idemTableReady(): bool
    {
        return $this->cachedSchema('idem', function () {
            return !empty(Db::query("SHOW TABLES LIKE 'eb_cashier_create_side_effect_idem'"));
        });
    }

    public function idemLeaseReady(): bool
    {
        return $this->cachedSchema('idem_lease', function () {
            return !empty(Db::query("SHOW COLUMNS FROM `eb_cashier_create_side_effect_idem` LIKE 'lease_until'"));
        });
    }

    public function taskTableReady(): bool
    {
        return $this->cachedSchema('task', function () {
            return !empty(Db::query("SHOW TABLES LIKE 'eb_cashier_create_side_effect_task'"));
        });
    }

    /**
     * 021：下游任务账本增加 lease_until（独立租约状态机：PENDING/RUNNING/DONE/FAIL）
     */
    public function taskLeaseReady(): bool
    {
        return $this->cachedSchema('task_lease', function () {
            return !empty(Db::query("SHOW COLUMNS FROM `eb_cashier_create_side_effect_task` LIKE 'lease_until'"));
        });
    }

    public static function resetSchemaCache(): void
    {
        self::$schemaCache = [
            'main' => null,
            'lease' => null,
            'idem' => null,
            'idem_lease' => null,
            'task' => null,
            'task_lease' => null,
        ];
    }

    protected function schemaReadyCached(): bool
    {
        return $this->tableReady()
            && $this->leaseSchemaReady()
            && $this->idemTableReady()
            && $this->idemLeaseReady()
            && $this->taskTableReady()
            && $this->taskLeaseReady();
    }

    protected function assertSchemaReadyForWrite(): void
    {
        if (!$this->tableReady()) {
            throw new ValidateException('创建副作用表未初始化，请先执行升级包 20260718-017（上站勿漏项 H）');
        }
        if (!$this->leaseSchemaReady()) {
            throw new ValidateException('创建副作用租约字段未初始化，请先执行升级包 20260718-018（上站勿漏项 H）');
        }
        if (!$this->idemTableReady() || !$this->idemLeaseReady()) {
            throw new ValidateException('创建副作用幂等租约未初始化，请先执行升级包 20260718-019（上站勿漏项 H）');
        }
        if (!$this->taskTableReady()) {
            throw new ValidateException('创建副作用下游投递账本未初始化，请先执行升级包 20260718-020（上站勿漏项 H）');
        }
        if (!$this->taskLeaseReady()) {
            throw new ValidateException('创建副作用下游任务租约字段未初始化，请先执行升级包 20260718-021（上站勿漏项 H）');
        }
    }

    /**
     * @param callable():bool $probe
     */
    protected function cachedSchema(string $key, callable $probe): bool
    {
        if (self::$schemaCache[$key] !== null) {
            return (bool)self::$schemaCache[$key];
        }
        try {
            self::$schemaCache[$key] = (bool)$probe();
        } catch (\Throwable $e) {
            self::$schemaCache[$key] = false;
        }
        return (bool)self::$schemaCache[$key];
    }

    /**
     * @return array{token:string,payload:array}|null
     */
    protected function claimStep(int $oid, string $step): ?array
    {
        $col = $this->stepColumn($step);
        $tokenCol = $this->tokenColumn($step);
        $leaseCol = $this->leaseColumn($step);
        if ($col === '' || $tokenCol === '' || $leaseCol === '') {
            return null;
        }
        $token = '';
        $payload = [];
        Db::transaction(function () use ($oid, $col, $tokenCol, $leaseCol, &$token, &$payload) {
            $row = Db::name(self::TABLE)->where('oid', $oid)->lock(true)->find();
            if (!$row) {
                return;
            }
            $now = time();
            $stepStatus = (int)($row[$col] ?? 0);
            $lease = (int)($row[$leaseCol] ?? 0);
            if ($stepStatus === self::STEP_DONE) {
                return;
            }
            if ($stepStatus === self::STEP_RUNNING && $lease > $now) {
                return;
            }
            if (!in_array($stepStatus, [self::STEP_PENDING, self::STEP_FAIL, self::STEP_RUNNING], true)) {
                return;
            }
            $token = bin2hex(random_bytes(16));
            $decoded = json_decode((string)$row['payload'], true);
            $payload = is_array($decoded) ? $decoded : [];
            $affected = Db::name(self::TABLE)
                ->where('oid', $oid)
                ->where(function ($q) use ($col, $leaseCol, $now, $stepStatus) {
                    if ($stepStatus === self::STEP_RUNNING) {
                        $q->where($col, self::STEP_RUNNING)->where($leaseCol, '<=', $now);
                    } else {
                        $q->where($col, $stepStatus);
                    }
                })
                ->update([
                    $col => self::STEP_RUNNING,
                    $tokenCol => $token,
                    $leaseCol => $now + self::LEASE_SECONDS,
                    'update_time' => $now,
                    'status' => self::STATUS_RETRY,
                ]);
            if ($affected <= 0) {
                $token = '';
            }
        });
        if ($token === '') {
            return null;
        }
        return ['token' => $token, 'payload' => $payload];
    }

    protected function finishClaim(int $oid, string $step, string $token, int $stepStatus, string $error): bool
    {
        $col = $this->stepColumn($step);
        $tokenCol = $this->tokenColumn($step);
        $leaseCol = $this->leaseColumn($step);
        if ($col === '' || $token === '') {
            return false;
        }
        $update = [
            $col => $stepStatus,
            $leaseCol => 0,
            'update_time' => time(),
        ];
        if ($stepStatus === self::STEP_DONE) {
            $update[$tokenCol] = '';
        }
        if ($error !== '') {
            $update['last_error'] = $error;
        }
        $affected = Db::name(self::TABLE)
            ->where('oid', $oid)
            ->where($tokenCol, $token)
            ->where($col, self::STEP_RUNNING)
            ->update($update);
        $this->refreshAggregateStatus($oid);
        return $affected > 0;
    }

    /**
     * @return 'execute'|'skip_done'|'blocked'
     */
    protected function reserveEffectIdem(int $oid, string $step, string $claimToken): string
    {
        $mode = 'blocked';
        Db::transaction(function () use ($oid, $step, $claimToken, &$mode) {
            $row = Db::name(self::IDEM_TABLE)->where(['oid' => $oid, 'step' => $step])->lock(true)->find();
            $now = time();
            $leaseUntil = $now + self::LEASE_SECONDS;
            if (!$row) {
                Db::name(self::IDEM_TABLE)->insert([
                    'oid' => $oid,
                    'step' => $step,
                    'claim_token' => $claimToken,
                    'lease_until' => $leaseUntil,
                    'status' => self::IDEM_RESERVED,
                    'add_time' => $now,
                    'update_time' => $now,
                ]);
                $mode = 'execute';
                return;
            }
            if ((int)$row['status'] === self::IDEM_CONFIRMED) {
                $mode = 'skip_done';
                return;
            }
            $oldLease = (int)($row['lease_until'] ?? 0);
            $oldToken = (string)($row['claim_token'] ?? '');
            if ($oldToken === $claimToken) {
                Db::name(self::IDEM_TABLE)->where('id', (int)$row['id'])->update([
                    'lease_until' => $leaseUntil,
                    'update_time' => $now,
                ]);
                $mode = 'execute';
                return;
            }
            if ($oldLease > $now) {
                $mode = 'blocked';
                return;
            }
            $affected = Db::name(self::IDEM_TABLE)
                ->where('id', (int)$row['id'])
                ->where('status', self::IDEM_RESERVED)
                ->where('lease_until', '<=', $now)
                ->update([
                    'claim_token' => $claimToken,
                    'lease_until' => $leaseUntil,
                    'update_time' => $now,
                ]);
            $mode = $affected > 0 ? 'execute' : 'blocked';
        });
        return $mode;
    }

    /**
     * 原子消费：将幂等行 0→1，并规划下游任务账本行（同事务）。
     * resume（已确认）时仍会补齐缺失的任务行（例如任务键清单在部署间迭代新增），
     * 但绝不重置已存在任务行的状态。
     *
     * @param string[] $taskKeys
     * @return 'admit'|'resume'|'reject'
     */
    public function admitStepTasks(int $oid, string $step, string $claimToken, array $taskKeys): string
    {
        $result = 'reject';
        Db::transaction(function () use ($oid, $step, $claimToken, $taskKeys, &$result) {
            $row = Db::name(self::IDEM_TABLE)->where(['oid' => $oid, 'step' => $step])->lock(true)->find();
            if (!$row) {
                return;
            }
            $now = time();
            if ((int)$row['status'] === self::IDEM_CONFIRMED) {
                // 计划已提交：任意后续 worker 只能 resume 补齐缺失任务行，不可改写 claim
                $this->ensureTaskRows($oid, $step, $claimToken, $taskKeys, $now);
                $result = 'resume';
                return;
            }
            if ((string)$row['claim_token'] !== $claimToken) {
                return;
            }
            if ((int)($row['lease_until'] ?? 0) <= $now) {
                // 租约已过期：即使 token 仍写在行上，也不允许旧持有者继续投递（可能已被逻辑接管）
                return;
            }
            $affected = Db::name(self::IDEM_TABLE)
                ->where('id', (int)$row['id'])
                ->where('status', self::IDEM_RESERVED)
                ->where('claim_token', $claimToken)
                ->where('lease_until', '>', $now)
                ->update([
                    'status' => self::IDEM_CONFIRMED,
                    'update_time' => $now,
                ]);
            if ($affected <= 0) {
                return;
            }
            $this->ensureTaskRows($oid, $step, $claimToken, $taskKeys, $now);
            $result = 'admit';
        });
        return $result;
    }

    /**
     * 规划下游任务行：按 (oid,step,task_key) 唯一键幂等插入，已存在则跳过（不覆盖状态）。
     */
    protected function ensureTaskRows(int $oid, string $step, string $claimToken, array $taskKeys, int $now): void
    {
        foreach ($taskKeys as $taskKey) {
            $taskKey = (string)$taskKey;
            if ($taskKey === '') {
                continue;
            }
            $exist = Db::name(self::TASK_TABLE)->where([
                'oid' => $oid,
                'step' => $step,
                'task_key' => $taskKey,
            ])->find();
            if ($exist) {
                continue;
            }
            try {
                Db::name(self::TASK_TABLE)->insert([
                    'oid' => $oid,
                    'step' => $step,
                    'task_key' => $taskKey,
                    'claim_token' => $claimToken,
                    'status' => self::TASK_PENDING,
                    'lease_until' => 0,
                    'last_error' => '',
                    'add_time' => $now,
                    'update_time' => $now,
                ]);
            } catch (\Throwable $e) {
                // 唯一键冲突：并发下已被其他持有者插入，忽略
            }
        }
    }

    public function isEffectConfirmed(int $oid, string $step): bool
    {
        $row = Db::name(self::IDEM_TABLE)->where(['oid' => $oid, 'step' => $step])->find();
        return $row && (int)$row['status'] === self::IDEM_CONFIRMED;
    }

    /**
     * 兼容旧 confirm 调用：已确认则 true；否则按 token 确认（无任务规划，仅用于非监听路径兜底）
     */
    public function confirmEffectIdem(int $oid, string $step, string $claimToken): bool
    {
        if ($this->isEffectConfirmed($oid, $step)) {
            return true;
        }
        if ($oid <= 0 || $step === '' || $claimToken === '') {
            return false;
        }
        $affected = Db::name(self::IDEM_TABLE)
            ->where([
                'oid' => $oid,
                'step' => $step,
                'claim_token' => $claimToken,
                'status' => self::IDEM_RESERVED,
            ])
            ->where('lease_until', '>', time())
            ->update([
                'status' => self::IDEM_CONFIRMED,
                'update_time' => time(),
            ]);
        return $affected > 0;
    }

    /* ==================== 下游任务账本 FSM（021） ==================== */

    /**
     * 领取一个下游任务的执行权。
     * 可领取条件：status ∈ (PENDING, FAIL) 或 (RUNNING 且租约已过期)。
     * 领取后：status=RUNNING，claim_token=新 run_token，lease_until=now+LEASE，清空 last_error。
     *
     * @return string|null 领取成功返回 run_token；跳过（已完成）或被抢返回 null
     */
    public function beginTask(int $oid, string $step, string $taskKey): ?string
    {
        if ($oid <= 0 || $step === '' || $taskKey === '') {
            return null;
        }
        if (!$this->taskLeaseReady()) {
            return null;
        }
        $runToken = bin2hex(random_bytes(16));
        $acquired = false;
        Db::transaction(function () use ($oid, $step, $taskKey, $runToken, &$acquired) {
            $row = Db::name(self::TASK_TABLE)->where([
                'oid' => $oid,
                'step' => $step,
                'task_key' => $taskKey,
            ])->lock(true)->find();
            if (!$row) {
                return;
            }
            $now = time();
            $status = (int)$row['status'];
            $lease = (int)($row['lease_until'] ?? 0);
            if ($status === self::TASK_DONE) {
                return;
            }
            if ($status === self::TASK_RUNNING && $lease > $now) {
                return;
            }
            if (!in_array($status, [self::TASK_PENDING, self::TASK_FAIL, self::TASK_RUNNING], true)) {
                return;
            }
            $query = Db::name(self::TASK_TABLE)->where('id', (int)$row['id']);
            if ($status === self::TASK_RUNNING) {
                $query->where('status', self::TASK_RUNNING)->where('lease_until', '<=', $now);
            } else {
                $query->where('status', $status);
            }
            $affected = $query->update([
                'status' => self::TASK_RUNNING,
                'claim_token' => $runToken,
                'lease_until' => $now + self::LEASE_SECONDS,
                'last_error' => '',
                'update_time' => $now,
            ]);
            $acquired = $affected > 0;
        });
        return $acquired ? $runToken : null;
    }

    /**
     * 按 run_token 落完成：仅当当前仍是 RUNNING 且 token 匹配才生效。
     */
    public function completeTask(int $oid, string $step, string $taskKey, string $runToken): bool
    {
        if ($oid <= 0 || $step === '' || $taskKey === '' || $runToken === '') {
            return false;
        }
        $affected = Db::name(self::TASK_TABLE)
            ->where([
                'oid' => $oid,
                'step' => $step,
                'task_key' => $taskKey,
                'status' => self::TASK_RUNNING,
                'claim_token' => $runToken,
            ])
            ->update([
                'status' => self::TASK_DONE,
                'lease_until' => 0,
                'update_time' => time(),
            ]);
        return $affected > 0;
    }

    /**
     * 按 run_token 落失败：状态回 FAIL（可重试），记录错误信息。
     */
    public function failTask(int $oid, string $step, string $taskKey, string $runToken, string $error): bool
    {
        if ($oid <= 0 || $step === '' || $taskKey === '' || $runToken === '') {
            return false;
        }
        $affected = Db::name(self::TASK_TABLE)
            ->where([
                'oid' => $oid,
                'step' => $step,
                'task_key' => $taskKey,
                'status' => self::TASK_RUNNING,
                'claim_token' => $runToken,
            ])
            ->update([
                'status' => self::TASK_FAIL,
                'lease_until' => 0,
                'last_error' => mb_substr($error, 0, 480),
                'update_time' => time(),
            ]);
        return $affected > 0;
    }

    /**
     * begin → 执行 $fn → complete；抛出则 failTask（保留可重试）并记录日志。
     *
     * @return bool 是否成功完成（false：已完成/被抢占领取失败，或执行失败）
     */
    public function runTask(int $oid, string $step, string $taskKey, callable $fn): bool
    {
        $runToken = $this->beginTask($oid, $step, $taskKey);
        if ($runToken === null) {
            return false;
        }
        try {
            $fn();
            return $this->completeTask($oid, $step, $taskKey, $runToken);
        } catch (\Throwable $e) {
            $this->failTask($oid, $step, $taskKey, $runToken, $e->getMessage());
            Log::error('cashier create side effect task fail oid=' . $oid . ' step=' . $step . ' task=' . $taskKey . ' err=' . $e->getMessage());
            return false;
        }
    }

    /**
     * Job 消费者辅助：解析 "{oid}:{step}" 幂等键后领取任务。
     */
    public function beginTaskByIdem(string $idemKey, string $taskKey): ?string
    {
        $parsed = $this->parseIdemKey($idemKey);
        if ($parsed === null) {
            return null;
        }
        [$oid, $step] = $parsed;
        return $this->beginTask($oid, $step, $taskKey);
    }

    public function completeTaskByIdem(string $idemKey, string $taskKey, string $runToken): bool
    {
        $parsed = $this->parseIdemKey($idemKey);
        if ($parsed === null) {
            return false;
        }
        [$oid, $step] = $parsed;
        return $this->completeTask($oid, $step, $taskKey, $runToken);
    }

    public function failTaskByIdem(string $idemKey, string $taskKey, string $runToken, string $error): bool
    {
        $parsed = $this->parseIdemKey($idemKey);
        if ($parsed === null) {
            return false;
        }
        [$oid, $step] = $parsed;
        return $this->failTask($oid, $step, $taskKey, $runToken, $error);
    }

    /**
     * @return array{0:int,1:string}|null [oid, step]
     */
    protected function parseIdemKey(string $idemKey): ?array
    {
        if ($idemKey === '' || !preg_match('/^(\d+):(promotions|del_cart|order_create)$/', $idemKey, $m)) {
            return null;
        }
        return [(int)$m[1], $m[2]];
    }

    /* ==================== 步骤内投递 ==================== */

    protected function flushStepTasks(int $oid, string $step, array $payload): void
    {
        if ($step === self::STEP_ORDER_CREATE) {
            $order = $payload['order'] ?? [];
            $order['id'] = $order['id'] ?? $oid;
            $this->deliverOrderCreateTasks($oid, [
                'order' => $order,
                'group' => $payload['group'] ?? [],
                'activity' => $payload['activity'] ?? ['type' => 0, 'activity_id' => 0],
                'invoice_id' => (int)($payload['invoice_id'] ?? 0),
            ]);
            return;
        }
        if ($step === self::STEP_PROMOTIONS) {
            $this->runTask($oid, $step, self::STEP_PROMOTIONS, function () use ($payload) {
                StorePromotionsJob::dispatchDo('changeGiveLimit', [$payload['promotions_give'] ?? []]);
            });
            return;
        }
        if ($step === self::STEP_DEL_CART) {
            $this->runTask($oid, $step, self::STEP_DEL_CART, function () use ($payload) {
                /** @var StoreOrderCreateServices $orderServices */
                $orderServices = app()->make(StoreOrderCreateServices::class);
                $orderServices->delCart($payload['group'] ?? []);
            });
        }
    }

    /**
     * @return string[]
     */
    protected function orderCreateTaskKeys(array $orderInfo, array $activity = [], int $invoiceId = 0): array
    {
        $keys = [
            self::TASK_CREATE_STATUS,
            self::TASK_COMPUTE_TRUE_PRICE,
            self::TASK_UPDATE_USER,
            self::TASK_AFTER_DEL_CART,
            self::TASK_DEL_ORDER_CACHE,
            self::TASK_USER_NEWCOMER,
            self::TASK_PRODUCT_LOG,
            self::TASK_SYSTEM_FORM,
            self::TASK_UNPAID_SEND,
            self::TASK_UNPAID_CANCEL,
        ];
        $uid = (int)($orderInfo['uid'] ?? 0);
        $storeId = (int)($orderInfo['store_id'] ?? 0);
        if ($uid && $storeId) {
            $keys[] = self::TASK_STORE_USER;
            $keys[] = self::TASK_USER_BELONG;
        }
        if ($storeId && (int)($orderInfo['type'] ?? 0) != 10) {
            $keys[] = self::TASK_PRINT;
        }
        if ($invoiceId > 0) {
            $keys[] = self::TASK_CREATE_INVOICE;
        }
        if ((int)($activity['type'] ?? 0) === 2 && (int)($activity['activity_id'] ?? 0)) {
            $keys[] = self::TASK_BARGAIN_STATUS;
        }
        if ((int)($orderInfo['type'] ?? 0) === 8 && (int)($orderInfo['activity_id'] ?? 0)) {
            $keys[] = self::TASK_LUCK_LOTTERY;
        }
        return $keys;
    }

    /**
     * order_create 步骤全量下游任务投递：逐个任务 runTask（或 Job 自带 begin/complete/fail），
     * 单个任务失败只记 FAIL 可重试，绝不中断其余任务投递。
     */
    public function deliverOrderCreateTasks(int $oid, array $context): void
    {
        if ($oid <= 0) {
            return;
        }
        $orderInfo = $context['order'] ?? [];
        $group = $context['group'] ?? [];
        $activity = $context['activity'] ?? ['type' => 0, 'activity_id' => 0];
        $invoiceId = (int)($context['invoice_id'] ?? 0);
        $uid = (int)($orderInfo['uid'] ?? 0);
        $storeId = (int)($orderInfo['store_id'] ?? 0);
        $step = self::STEP_ORDER_CREATE;
        $idemKey = $oid . ':' . $step;
        $taskKeys = $this->orderCreateTaskKeys($orderInfo, $activity, $invoiceId);

        foreach ($taskKeys as $taskKey) {
            try {
                switch ($taskKey) {
                    case self::TASK_CREATE_STATUS:
                        $this->runTask($oid, $step, $taskKey, function () use ($oid) {
                            $this->deliverCreateStatus($oid);
                        });
                        break;
                    case self::TASK_COMPUTE_TRUE_PRICE:
                        $this->runTask($oid, $step, $taskKey, function () use ($uid, $oid) {
                            app()->make(OrderJob::class)->computeOrderProductTruePrice($uid, $oid);
                        });
                        break;
                    case self::TASK_UPDATE_USER:
                        $this->runTask($oid, $step, $taskKey, function () use ($orderInfo, $group) {
                            app()->make(OrderCreateAfterJob::class)->updateUser($orderInfo, $group);
                        });
                        break;
                    case self::TASK_AFTER_DEL_CART:
                        $this->runTask($oid, $step, $taskKey, function () use ($group) {
                            app()->make(OrderCreateAfterJob::class)->delCart($group);
                        });
                        break;
                    case self::TASK_DEL_ORDER_CACHE:
                        $this->runTask($oid, $step, $taskKey, function () use ($uid, $orderInfo) {
                            app()->make(OrderCreateAfterJob::class)->delOrderCache($uid, (string)($orderInfo['unique'] ?? ''));
                        });
                        break;
                    case self::TASK_CREATE_INVOICE:
                        $this->runTask($oid, $step, $taskKey, function () use ($uid, $oid, $invoiceId) {
                            app()->make(CreateInvoiceJob::class)->doJob($uid, $oid, $invoiceId);
                        });
                        break;
                    case self::TASK_BARGAIN_STATUS:
                        $this->runTask($oid, $step, $taskKey, function () use ($uid, $activity) {
                            app()->make(StoreBargainJob::class)->setBargainUserStatus($uid, (int)($activity['activity_id'] ?? 0));
                        });
                        break;
                    case self::TASK_USER_NEWCOMER:
                        $this->runTask($oid, $step, $taskKey, function () use ($uid, $orderInfo) {
                            app()->make(UserJob::class)->updateUserNewcomer($uid, $orderInfo);
                        });
                        break;
                    case self::TASK_LUCK_LOTTERY:
                        $this->runTask($oid, $step, $taskKey, function () use ($oid) {
                            app()->make(LuckLotteryJob::class)->updateLotteryRecord($oid);
                        });
                        break;
                    case self::TASK_PRODUCT_LOG:
                        $this->runTask($oid, $step, $taskKey, function () use ($uid, $oid) {
                            app()->make(ProductLogJob::class)->doJob('order', ['uid' => $uid, 'order_id' => $oid]);
                        });
                        break;
                    case self::TASK_SYSTEM_FORM:
                        $this->runTask($oid, $step, $taskKey, function () use ($oid) {
                            app()->make(SystemFormDataJob::class)->doJob($oid);
                        });
                        break;
                    case self::TASK_UNPAID_SEND:
                        $this->runTask($oid, $step, $taskKey, function () use ($oid) {
                            UnpaidOrderSend::dispatchSece(600, [$oid]);
                        });
                        break;
                    case self::TASK_UNPAID_CANCEL:
                        $this->runTask($oid, $step, $taskKey, function () use ($oid, $activity) {
                            /** @var StoreOrderServices $storeOrderServices */
                            $storeOrderServices = app()->make(StoreOrderServices::class);
                            $secs = $storeOrderServices->getOrderCancelTime((int)($activity['type'] ?? 0));
                            UnpaidOrderCancelJob::dispatchSece((int)($secs * 3600), [$oid]);
                        });
                        break;
                    case self::TASK_STORE_USER:
                        // Job 内 begin→work→complete/fail；同步执行以便账本立即落成（队列积压时仍可恢复）
                        app()->make(StoreUserJob::class)->doJob($uid, $storeId, $idemKey, $taskKey);
                        break;
                    case self::TASK_USER_BELONG:
                        app()->make(UserBelongStoreJob::class)->doJob($uid, $storeId, 'order', 0, $idemKey, $taskKey);
                        break;
                    case self::TASK_PRINT:
                        // 真实 orderPrint；无打印机配置时 Job 内 failTask 可重试，不阻断其余任务
                        app()->make(PrintJob::class)->doJob($oid, 1, $idemKey, $taskKey);
                        break;
                    default:
                        break;
                }
            } catch (\Throwable $e) {
                // 单个任务的意外致命错误（如 DB 连接抖动）不得中断其余任务投递
                Log::error('cashier create side effect deliver task fatal oid=' . $oid . ' task=' . $taskKey . ' err=' . $e->getMessage());
            }
        }
    }

    protected function deliverCreateStatus(int $oid): void
    {
        /** @var StoreOrderStatusServices $statusServices */
        $statusServices = app()->make(StoreOrderStatusServices::class);
        $existCreate = (int)Db::name('store_order_status')
            ->where(['oid' => $oid, 'change_type' => 'create'])
            ->count();
        if ($existCreate === 0) {
            $statusServices->saveStatus($oid, 'create', [
                'change_message' => '订单生成',
                'change_manager_type' => 'user',
            ]);
        }
    }

    /**
     * 异步任务是否还需投递：未完成且当前无有效领取租约
     */
    protected function taskNeedsDispatch(int $oid, string $step, string $taskKey): bool
    {
        $row = Db::name(self::TASK_TABLE)->where([
            'oid' => $oid,
            'step' => $step,
            'task_key' => $taskKey,
        ])->find();
        if (!$row) {
            return false;
        }
        $status = (int)$row['status'];
        if ($status === self::TASK_DONE) {
            return false;
        }
        if ($status === self::TASK_RUNNING && (int)($row['lease_until'] ?? 0) > time()) {
            return false;
        }
        return true;
    }

    protected function refreshAggregateStatus(int $oid): int
    {
        $row = Db::name(self::TABLE)->where('oid', $oid)->find();
        if (!$row) {
            return self::STATUS_DONE;
        }
        $steps = [
            (int)$row['step_promotions'],
            (int)$row['step_del_cart'],
            (int)$row['step_order_create'],
        ];
        $status = self::STATUS_DONE;
        foreach ($steps as $s) {
            if ($s !== self::STEP_DONE) {
                $status = self::STATUS_RETRY;
                break;
            }
        }
        Db::name(self::TABLE)->where('oid', $oid)->update([
            'status' => $status,
            'update_time' => time(),
        ]);
        return $status;
    }

    protected function stepColumn(string $step): string
    {
        return [
            self::STEP_PROMOTIONS => 'step_promotions',
            self::STEP_DEL_CART => 'step_del_cart',
            self::STEP_ORDER_CREATE => 'step_order_create',
        ][$step] ?? '';
    }

    protected function tokenColumn(string $step): string
    {
        return [
            self::STEP_PROMOTIONS => 'token_promotions',
            self::STEP_DEL_CART => 'token_del_cart',
            self::STEP_ORDER_CREATE => 'token_order_create',
        ][$step] ?? '';
    }

    protected function leaseColumn(string $step): string
    {
        return [
            self::STEP_PROMOTIONS => 'lease_promotions',
            self::STEP_DEL_CART => 'lease_del_cart',
            self::STEP_ORDER_CREATE => 'lease_order_create',
        ][$step] ?? '';
    }

    protected function runStep(string $step, array $payload, int $oid, string $claimToken): void
    {
        switch ($step) {
            case self::STEP_PROMOTIONS:
                $admit = $this->admitStepTasks($oid, $step, $claimToken, [self::STEP_PROMOTIONS]);
                if ($admit === 'reject') {
                    throw new ValidateException('promotions 幂等消费拒绝');
                }
                $this->flushStepTasks($oid, $step, $payload);
                return;
            case self::STEP_DEL_CART:
                $admit = $this->admitStepTasks($oid, $step, $claimToken, [self::STEP_DEL_CART]);
                if ($admit === 'reject') {
                    throw new ValidateException('del_cart 幂等消费拒绝');
                }
                $this->flushStepTasks($oid, $step, $payload);
                return;
            case self::STEP_ORDER_CREATE:
                $order = $payload['order'] ?? [];
                $order['_side_effect_idem'] = $oid . ':' . self::STEP_ORDER_CREATE;
                $order['_side_effect_claim'] = $claimToken;
                event('order.create', [
                    $order,
                    $payload['group'] ?? [],
                    $payload['activity'] ?? ['type' => 0, 'activity_id' => 0],
                    (int)($payload['invoice_id'] ?? 0),
                ]);
                return;
            default:
                throw new ValidateException('未知副作用步骤');
        }
    }
}
