<?php
declare(strict_types=1);

namespace app\services\user;

use think\facade\Db;
use think\exception\ValidateException;
use app\jobs\user\UserStoreTransferBatchJob;

/** 冻结确认名单并分批改主归属；每人复用单人调店事务，不写其他业务资料。 */
class UserStoreTransferBatchServices
{
    public const TABLE = 'user_store_transfer_batch';
    public const ITEMS = 'user_store_transfer_batch_item';
    public const CHUNK_SIZE = 50;

    /** 全部未注销、未删除会员，包含停用会员；与页面当前分页和筛选无关。 */
    private function members(int $source)
    {
        return Db::name('user')->where('belong_store_id', $source)->where('is_del', 0)->whereNull('delete_time');
    }

    /** 预览只读；返回当前数量及本人已有批次，刷新页面后仍可查看/继续执行。 */
    public function preview(int $source, int $adminId): array
    {
        $this->assertAdmin($adminId);
        $store = $this->store($source, false);
        $latest = Db::name(self::TABLE)->where('source_store_id', $source)->where('admin_id', $adminId)
            ->order('id', 'desc')->find();
        return ['source' => $store, 'count' => (int)$this->members($source)->count(),
            'stores' => array_values(array_filter(app()->make(UserBelongStoreServices::class)->getTransferStoreOptions(),
                static function (array $row) use ($source): bool { return (int)$row['id'] !== $source; })),
            'batch' => $latest ? $this->status((string)$latest['batch_no'], $adminId) : null];
    }

    /** 确认时 INSERT SELECT 冻结全量 UID 和归属历史版本；同请求重复提交只返回原批次。 */
    public function create(int $source, int $target, string $reason, int $expectedCount, string $key, int $adminId): array
    {
        $this->assertAdmin($adminId);
        $reason = trim($reason);
        if ($source <= 0 || $target <= 0 || $source === $target) throw new ValidateException('请选择与原门店不同的目标门店');
        // 预留五个字给单人调店详情中的“批量转移：”，完整原因仍保存在批次内。
        if (mb_strlen($reason) < 2 || mb_strlen($reason) > 195) throw new ValidateException('转移原因请输入2-195个字符');
        if (!preg_match('/^[A-Za-z0-9_-]{8,80}$/D', $key)) throw new ValidateException('请求标识无效，请重新打开转移窗口');
        $fingerprint = hash('sha256', json_encode([$source, $target, $reason, $expectedCount], JSON_UNESCAPED_UNICODE));
        $batchNo = Db::transaction(function () use ($source, $target, $reason, $expectedCount, $key, $adminId, $fingerprint): string {
            // 同源门店创建串行化，防止不同请求键同时发起两个覆盖同一名单的批次。
            $sourceStore = Db::name('system_store')->where('id', $source)->where('is_del', 0)->lock(true)->find();
            if (!$sourceStore) throw new ValidateException('原门店不存在或已删除');
            $old = Db::name(self::TABLE)->where('admin_id', $adminId)->where('request_key', $key)->find();
            if ($old) {
                if (!hash_equals((string)$old['request_fingerprint'], $fingerprint)) throw new ValidateException('此请求已用于其他转移，请重新打开窗口');
                return (string)$old['batch_no'];
            }
            $targetStore = $this->store($target, true);
            $active = Db::name(self::TABLE)->where('source_store_id', $source)
                ->whereIn('status', ['queued', 'running', 'blocked'])->find();
            if ($active) throw new ValidateException('该门店已有未结束的转移批次，请先查看或继续原批次');
            $batch = 'BST-' . bin2hex(random_bytes(16));
            $now = time();
            Db::name(self::TABLE)->insert(['batch_no' => $batch, 'admin_id' => $adminId, 'request_key' => $key,
                'request_fingerprint' => $fingerprint, 'source_store_id' => $source, 'target_store_id' => $target,
                'source_store_name' => (string)$sourceStore['name'], 'target_store_name' => (string)$targetStore['name'],
                'reason' => $reason, 'status' => 'queued', 'created_at' => $now, 'updated_at' => $now]);
            // 表名只来自 ORM 配置，不接收客户端 SQL/UID 列表；成员版本排除店员绑定历史。
            $itemTable = Db::name(self::ITEMS)->getTable();
            $userTable = Db::name('user')->getTable();
            $historyTable = Db::name('user_belong_store')->getTable();
            $count = Db::execute("INSERT INTO {$itemTable} (batch_no,uid,source_history_id,status)
                SELECT ?,u.uid,COALESCE((SELECT MAX(h.id) FROM {$historyTable} h WHERE h.uid=u.uid AND h.is_store=1),0),'pending'
                FROM {$userTable} u WHERE u.belong_store_id=? AND u.is_del=0 AND u.delete_time IS NULL ORDER BY u.uid", [$batch, $source]);
            if ($count <= 0) throw new ValidateException('该门店暂无可转移的所属会员');
            if ($count !== $expectedCount) throw new ValidateException('所属会员人数已变化，请刷新人数后重新确认');
            Db::name(self::TABLE)->where('batch_no', $batch)->update(['total_count' => $count]);
            return $batch;
        });
        return $this->status($batchNo, $adminId);
    }

    /** 只返回本人批次；异常项独立分页，每页20条，避免大批次一次加载全部会员资料。 */
    public function status(string $batchNo, int $adminId, int $page = 1): array
    {
        $this->assertAdmin($adminId);
        $batch = Db::name(self::TABLE)->where('batch_no', $batchNo)->where('admin_id', $adminId)->find();
        if (!$batch) throw new ValidateException('转移批次不存在或不属于当前账号');
        $query = Db::name(self::ITEMS)->where('batch_no', $batchNo)->whereIn('status', ['failed', 'skipped']);
        $total = (int)(clone $query)->count();
        $page = min(max(1, $page), max(1, (int)ceil($total / 20)));
        $batch['exceptions'] = $query->field('uid,status,result_reason')->order('id')->page($page, 20)->select()->toArray();
        $batch['exception_total'] = $total;
        $batch['exception_page'] = $page;
        return $batch;
    }

    /** 队列投递只在创建事务提交后执行；失败保留批次并显示可恢复状态，不伪报完成。 */
    public function enqueue(string $batchNo): void
    {
        try {
            if (!UserStoreTransferBatchJob::dispatch([$batchNo])) throw new \RuntimeException('队列暂不可用');
        } catch (\Throwable $error) {
            $this->block($batchNo, '任务投递失败，请点击继续执行重试');
        }
    }

    /** 继续相同名单；只重试失败和未执行项，不改已成功/已跳过会员，也不重新扫描原门店。 */
    public function resume(string $batchNo, int $adminId): array
    {
        $this->status($batchNo, $adminId);
        Db::transaction(function () use ($batchNo): void {
            $batch = Db::name(self::TABLE)->where('batch_no', $batchNo)->lock(true)->find();
            if ($batch['status'] === 'completed') return;
            Db::name(self::ITEMS)->where('batch_no', $batchNo)->where('status', 'failed')
                ->update(['status' => 'pending', 'result_reason' => '']);
            $this->refreshCounts($batchNo);
        });
        $this->enqueue($batchNo);
        return $this->status($batchNo, $adminId);
    }

    /** 单批最多50人；批次行锁及逐会员锁保护多worker/重复投递，单人失败使用保存点回滚。 */
    public function processChunk(string $batchNo): bool
    {
        $changed = 0;
        $pending = Db::transaction(function () use ($batchNo, &$changed): bool {
            $batch = Db::name(self::TABLE)->where('batch_no', $batchNo)->lock(true)->find();
            if (!$batch || in_array($batch['status'], ['completed', 'completed_with_exceptions', 'blocked'], true)) return false;
            $this->assertAdmin((int)$batch['admin_id']);
            $this->store((int)$batch['target_store_id'], true);
            Db::name(self::TABLE)->where('batch_no', $batchNo)->update(['status' => 'running', 'last_error' => '', 'updated_at' => time()]);
            $items = Db::name(self::ITEMS)->where('batch_no', $batchNo)->where('status', 'pending')
                ->order('id')->limit(self::CHUNK_SIZE)->lock(true)->select()->toArray();
            foreach ($items as $item) {
                $user = Db::name('user')->where('uid', (int)$item['uid'])->lock(true)->find();
                // 用户锁取得前可能等待其他调店事务；历史也必须用当前读，不能使用事务早先的快照。
                $latestHistory = Db::name('user_belong_store')->where('uid', (int)$item['uid'])->where('is_store', 1)
                    ->order('id', 'desc')->lock(true)->find();
                $history = (int)($latestHistory['id'] ?? 0);
                if (!$user || !empty($user['delete_time']) || (int)$user['is_del'] !== 0) {
                    $result = ['status' => 'skipped', 'result_reason' => '会员已注销或删除'];
                } elseif ((int)$user['belong_store_id'] !== (int)$batch['source_store_id'] || $history !== (int)$item['source_history_id']) {
                    $result = ['status' => 'skipped', 'result_reason' => '确认后会员归属已变化，保留最新归属'];
                } else {
                    try {
                        $transfer = Db::transaction(function () use ($item, $batch): array { return $this->transferMember($item, $batch); });
                        $result = ['status' => 'success', 'history_id' => (int)$transfer['history_id'], 'result_reason' => ''];
                        ++$changed;
                    } catch (ValidateException | \InvalidArgumentException $error) {
                        $result = ['status' => 'failed', 'result_reason' => mb_substr($error->getMessage(), 0, 255)];
                    }
                }
                Db::name(self::ITEMS)->where('id', (int)$item['id'])->update($result);
            }
            return $this->refreshCounts($batchNo);
        });
        if ($changed > 0) app()->make(UserServices::class)->cacheTag()->clear();
        return $pending;
    }

    /** 批量复用单人调店唯一写路径，原因标识批量来源；UID到批次/历史ID的映射保存在明细。 */
    protected function transferMember(array $item, array $batch): array
    {
        return app()->make(UserBelongStoreServices::class)->transferUserStore((int)$item['uid'],
            (int)$batch['target_store_id'], '批量转移：' . (string)$batch['reason'], (int)$batch['admin_id'], false);
    }

    /** 未知故障导致当前批事务回滚，保留已完成批次进度，供同一名单恢复。 */
    public function block(string $batchNo, string $message): void
    {
        Db::name(self::TABLE)->where('batch_no', $batchNo)->whereNotIn('status', ['completed', 'completed_with_exceptions'])
            ->update(['status' => 'blocked', 'last_error' => mb_substr($message, 0, 500), 'updated_at' => time()]);
    }

    /** 数量只从持久化明细汇总，不能依赖浏览器累计或把部分成功描述为全部成功。 */
    private function refreshCounts(string $batchNo): bool
    {
        $counts = Db::name(self::ITEMS)->where('batch_no', $batchNo)->fieldRaw('status,COUNT(*) AS n')->group('status')->select()->toArray();
        $byStatus = array_map('intval', array_column($counts, 'n', 'status'));
        $pending = ($byStatus['pending'] ?? 0) > 0;
        $hasExceptions = ($byStatus['failed'] ?? 0) + ($byStatus['skipped'] ?? 0) > 0;
        Db::name(self::TABLE)->where('batch_no', $batchNo)->update(['success_count' => $byStatus['success'] ?? 0,
            'skipped_count' => $byStatus['skipped'] ?? 0, 'failed_count' => $byStatus['failed'] ?? 0,
            'status' => $pending ? 'queued' : ($hasExceptions ? 'completed_with_exceptions' : 'completed'),
            'last_error' => '', 'updated_at' => time()]);
        return $pending;
    }

    /** 批次绑定创建管理员；管理员停用后不继续在后台执行写入。 */
    private function assertAdmin(int $id): void
    {
        if ($id <= 0 || !Db::name('system_admin')->where('id', $id)->where('status', 1)->where('is_del', 0)->find()) {
            throw new ValidateException('当前管理员已失效，请重新登录后操作');
        }
    }

    /** 原门店允许停业后转出，目标门店必须存在且启用，不接受客户端名称快照。 */
    private function store(int $id, bool $target): array
    {
        $query = Db::name('system_store')->where('id', $id)->where('is_del', 0);
        if ($target) $query->where('is_show', 1);
        $row = $query->field('id,name')->find();
        if (!$row) throw new ValidateException($target ? '目标门店不存在或已停用' : '原门店不存在或已删除');
        return (array)$row;
    }
}
