<?php
declare(strict_types=1);

namespace app\services\report;

use think\facade\Db;

/**
 * 大范围未消耗查询的私有后台快照。任务只保存认证后的门店范围；
 * 业务金额仍由 Sidebar007UnconsumedV3ReadServices 在一致读取中生成。
 */
final class BasicUnconsumedTaskServices
{
    private const TABLE = 'basic_unconsumed_task';
    private const TTL = 7200;
    private const CODES = ['basic_unconsumed_summary', 'basic_unconsumed_detail'];

    public static function supportsCode(string $code): bool { return in_array($code, self::CODES, true); }

    /** 仅多店查询异步；单店继续使用原有实时读取和统一执行器。 */
    public static function applies(string $code, array $stores): bool
    {
        return count($stores) > 1 && self::supportsCode($code);
    }

    /**
     * 当前认证上下文每次重新决定可见性。浏览器任务号只能继续同一身份、
     * 同一权限版本及同一门店集合，不能把后台文件当成跨账号数据入口。
     */
    public function resolve(string $code, array $stores, array $context, ?string $taskId): array
    {
        if (!self::applies($code, $stores)) throw new \InvalidArgumentException('未消耗任务范围无效');
        $identity = $this->identity($code, $stores, $context);
        if ($taskId === null || $taskId === '') {
            $this->cleanupExpired();
            // 同一账号、权限与范围的在途任务可接续；完成任务只有持任务号的
            // 当前页面复用，新的“查询/刷新”总会获得新的实时数据时点。
            $running = Db::name(self::TABLE)->where('report_code', $code)
                ->where('actor_key', $identity['actor_key'])->where('scope_hash', $identity['scope_hash'])
                ->whereIn('status', ['queued','running'])->where('expires_at', '>', time())
                ->order('created_at', 'desc')->value('task_id');
            if (is_string($running) && preg_match('/^[a-f0-9]{32}$/D', $running)) $taskId = $running;
        }
        if ($taskId === null || $taskId === '') {
            $taskId = bin2hex(random_bytes(16));
            $now = time();
            Db::name(self::TABLE)->insert([
                'task_id' => $taskId, 'report_code' => $code,
                'actor_key' => $identity['actor_key'], 'scope_hash' => $identity['scope_hash'],
                'store_ids_json' => json_encode($stores, JSON_THROW_ON_ERROR),
                'operator_id' => (int)$context['operator_id'],
                'permission_version' => (string)$context['permission_version'],
                'status' => 'queued', 'created_at' => $now, 'expires_at' => $now + self::TTL,
            ]);
            try { BasicUnconsumedTaskQueue::push($taskId); }
            catch (\Throwable $e) {
                Db::name(self::TABLE)->where('task_id', $taskId)->update(['status' => 'failed', 'error_code' => 'QUEUE_UNAVAILABLE']);
                throw new \RuntimeException('未消耗报表后台任务暂不可用，请稍后重试。');
            }
        }
        if (!preg_match('/^[a-f0-9]{32}$/D', $taskId)) throw new \InvalidArgumentException('报表任务编号无效');
        $task = Db::name(self::TABLE)->where('task_id', $taskId)->find();
        if (!$task || (string)$task['report_code'] !== $code
            || (string)$task['actor_key'] !== $identity['actor_key']
            || !hash_equals((string)$task['scope_hash'], $identity['scope_hash'])
            || (int)$task['expires_at'] <= time()) {
            throw new \InvalidArgumentException('报表任务已失效，请重新查询。');
        }
        // 进程被系统强制终止时 PHP 无法进入 catch；超时任务主动失效，
        // 防止页面永久轮询“运行中”且继续显示不完整的私有文件。
        if ((string)$task['status'] === 'running' && (int)$task['started_at'] > 0
            && time() - (int)$task['started_at'] > 2460) {
            $this->fail($taskId, 'WORKER_TIMEOUT');
            $task['status'] = 'failed';
        }
        if ((string)$task['status'] === 'queued' && time() - (int)$task['created_at'] > 120) {
            Db::name(self::TABLE)->where('task_id', $taskId)->where('status', 'queued')
                ->update(['status' => 'failed', 'error_code' => 'WORKER_UNAVAILABLE', 'completed_at' => time()]);
            $task['status'] = 'failed';
        }
        if ((string)$task['status'] === 'failed') throw new \RuntimeException('未消耗报表生成失败，请重新查询或联系管理员。');
        if ((string)$task['status'] !== 'done') {
            $progress = $this->readProgress($taskId);
            return ['pending' => true, 'task_id' => $taskId, 'status' => (string)$task['status'],
                'completed_stores' => $progress['done'], 'total_stores' => count($stores)];
        }
        $path = $this->path($taskId, 'rows');
        if (!is_file($path)) throw new \RuntimeException('报表结果文件已失效，请重新查询。');
        $meta = json_decode((string)$task['metadata_json'], true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($meta) || !isset($meta['fields']) || !isset($meta['field_sources'])) {
            throw new \RuntimeException('报表结果元数据无效，请重新查询。');
        }
        return ['pending' => false, 'task_id' => $taskId,
            'source' => $meta, 'batch_factory' => function () use ($path): \Generator {
                return $this->batches($path);
            }];
    }

    /**
     * 导出复用同一已完成快照与统一筛选/合计执行结果，流式写入私有 XLSX。
     * 不把数十万条会员权益明细序列化给浏览器后再由浏览器拼表。
     */
    public function exportXlsx(array $context, array $payload): array
    {
        $code = (string)($payload['pageCode'] ?? '');
        $taskId = (string)($payload['unconsumed_task_id'] ?? '');
        $export = $payload['export'] ?? null;
        if (!in_array($code, self::CODES, true) || !preg_match('/^[a-f0-9]{32}$/D', $taskId)
            || !is_array($export) || !in_array($export['scope'] ?? '', ['page','query'], true)) {
            throw new \InvalidArgumentException('未消耗报表导出条件无效');
        }
        $result = BasicReportHostServices::query($context, $payload);
        if (!empty($result['async_report']['pending'])) throw new \InvalidArgumentException('报表仍在生成，请完成后再导出');
        $rows = $result['exportRows'] ?? null;
        if (!is_iterable($rows)) throw new \RuntimeException('报表导出结果无效');
        // 使用同一授权任务的迭代器逐行写 XLSX，不能退化为全量加载或另一次事实查询。
        $workbook=BasicReportXlsxExportServices::payload($result,$payload);
        $path=$this->path($taskId,'export.'.bin2hex(random_bytes(8)).'.xlsx');
        $title=$code==='basic_unconsumed_summary'?'门店未消耗统计表':'门店未消耗明细表';
        (new StoreReportXlsxWriter())->writeFile($path,$workbook,$title,['summary_position'=>'last']);
        return ['path'=>$path,'filename'=>$title.'.xlsx'];
    }

    /**
     * 队列工作者从单个数据库一致读取快照分店投影，避免 10,000 行内存窗口。
     * 文件在 runtime 私有目录原子切换，失败或跨日时绝不发布部分结果。
     */
    public function process(string $taskId): bool
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $taskId)) return true;
        $task = Db::name(self::TABLE)->where('task_id', $taskId)->find();
        if (!$task || (int)$task['expires_at'] <= time()) return true;
        $startedAt = time();
        if (Db::name(self::TABLE)->where('task_id', $taskId)->where('status', 'queued')
            ->update(['status' => 'running', 'started_at' => $startedAt]) !== 1) return true;
        $stores = json_decode((string)$task['store_ids_json'], true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($stores) || !$stores || count($stores) !== count(array_unique($stores))) {
            $this->fail($taskId, 'SCOPE_INVALID'); return false;
        }
        $partial = $this->path($taskId, 'partial');
        $stream = fopen($partial, 'xb');
        if ($stream === false) { $this->fail($taskId, 'FILE_UNAVAILABLE'); return false; }
        chmod($partial, 0600);
        $day = (new \DateTimeImmutable('@' . (int)$task['created_at']))
            ->setTimezone(new \DateTimeZone('Asia/Shanghai'))->format('Y-m-d');
        $meta = null; $gaps = []; $gapCount = 0; $done = 0;
        try {
            Db::transaction(function () use ($task, $stores, $day, $stream, $taskId, &$meta, &$gaps, &$gapCount, &$done): void {
                foreach ($stores as $store) {
                    if (!is_int($store) || $store < 1 || $this->today() !== $day) throw new \RuntimeException('REPORT_SCOPE_OR_DAY_CHANGED');
                    $input = ['_report_scope' => ['mode' => 'stores',
                        'operator_id' => (int)$task['operator_id'], 'tenant_id' => '0',
                        'visible_store_ids' => $stores], 'as_of_date' => $day];
                    $source = (new Sidebar007UnconsumedV3ReadServices())->read(
                        (string)$task['report_code'], $stores, $input, null, null, [$store]);
                    if ($meta === null) { $meta = $source; unset($meta['rows'], $meta['gaps'], $meta['data_gaps']); }
                    foreach ($source['rows'] as $row) {
                        $line = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
                        if (fwrite($stream, $line) !== strlen($line)) throw new \RuntimeException('REPORT_SPOOL_WRITE_FAILED');
                    }
                    foreach ($source['data_gaps'] ?? [] as $gap) {
                        ++$gapCount; if (count($gaps) < 100) $gaps[] = $gap;
                    }
                    ++$done;
                    $this->writeProgress($taskId, $done);
                }
                if ($this->today() !== $day) throw new \RuntimeException('REPORT_DAY_CHANGED');
            });
            fflush($stream); fclose($stream); $stream = null;
            if ($meta === null) throw new \RuntimeException('REPORT_SOURCE_EMPTY');
            if ($gapCount > count($gaps)) $gaps[] = ['label' => '另有 ' . ($gapCount - count($gaps)) . ' 项来源缺口；相关金额不计入合计。'];
            $meta['scope'] = ['store_ids' => $stores];
            $meta['complete'] = $gapCount === 0;
            $meta['gaps'] = $gaps; $meta['data_gaps'] = $gaps;
            // 快照时间是数据库一致读取真正开始的时点，不是任务排队时点。
            $meta['data_as_of'] = $startedAt;
            $meta['source_report'] = (string)$task['report_code'];
            if (!rename($partial, $this->path($taskId, 'rows'))) throw new \RuntimeException('REPORT_SPOOL_PUBLISH_FAILED');
            Db::name(self::TABLE)->where('task_id', $taskId)->where('status', 'running')->update([
                'status' => 'done', 'metadata_json' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'completed_at' => time(),
            ]);
            @unlink($this->path($taskId, 'progress'));
            return true;
        } catch (\Throwable $e) {
            if (is_resource($stream)) fclose($stream);
            @unlink($partial); @unlink($this->path($taskId, 'rows'));
            $this->fail($taskId, preg_match('/^[A-Z_]+$/D', $e->getMessage()) ? $e->getMessage() : 'SOURCE_FAILED');
            return false;
        }
    }

    /** 每批最多 200 行，交给统一流式执行器完成筛选、排序、合计和导出。 */
    private function batches(string $path): \Generator
    {
        $stream = fopen($path, 'rb');
        if ($stream === false) throw new \RuntimeException('报表结果文件已失效');
        try {
            $batch = [];
            while (($line = fgets($stream)) !== false) {
                $row = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
                if (!is_array($row)) throw new \RuntimeException('报表结果行无效');
                $batch[] = $row;
                if (count($batch) === 200) { yield $batch; $batch = []; }
            }
            if (!feof($stream)) throw new \RuntimeException('报表结果读取不完整');
            if ($batch) yield $batch;
        } finally { fclose($stream); }
    }

    private function identity(string $code, array $stores, array $context): array
    {
        $actor = (string)($context['basic_actor_key'] ?? '');
        $operator = (int)($context['operator_id'] ?? 0);
        $version = (string)($context['permission_version'] ?? '');
        if ($actor === '' || $operator < 1 || $version === '') throw new \InvalidArgumentException('报表登录上下文无效');
        $ids = array_values(array_unique(array_map('intval', $stores))); sort($ids, SORT_NUMERIC);
        return ['actor_key' => $actor,
            'scope_hash' => hash('sha256', json_encode([$code, $actor, $operator, $version, $ids], JSON_THROW_ON_ERROR))];
    }

    private function directory(): string
    {
        $dir = rtrim((string)app()->getRuntimePath(), '/') . '/basic_unconsumed';
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new \RuntimeException('报表私有目录不可用');
        return $dir;
    }
    private function path(string $id, string $suffix): string { return $this->directory() . '/' . $id . '.' . $suffix; }
    private function today(): string { return (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai')))->format('Y-m-d'); }

    private function writeProgress(string $id, int $done): void
    {
        $temp = $this->path($id, 'progress-new');
        file_put_contents($temp, (string)$done, LOCK_EX);
        rename($temp, $this->path($id, 'progress'));
    }
    private function readProgress(string $id): array
    {
        $path = $this->path($id, 'progress');
        return ['done' => is_file($path) ? max(0, (int)file_get_contents($path)) : 0];
    }
    private function fail(string $id, string $code): void
    {
        Db::name(self::TABLE)->where('task_id', $id)->where('status', 'running')->update([
            'status' => 'failed', 'error_code' => substr($code, 0, 64), 'completed_at' => time(),
        ]);
    }
    /** 清理过期任务及其私有结果，不触碰其他业务文件。 */
    private function cleanupExpired(): void
    {
        // TTL 长于最长 worker 运行窗口；到期的排队、异常中断及完成任务
        // 都可移除，避免无人轮询的任务长期占用私有磁盘和状态表。
        $expired = Db::name(self::TABLE)->where('expires_at', '<', time())
            ->order('expires_at', 'asc')->limit(20)->column('task_id');
        foreach ($expired as $id) {
            if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id)) continue;
            foreach (glob($this->path($id, '*')) ?: [] as $file) @unlink($file);
            Db::name(self::TABLE)->where('task_id', $id)->where('expires_at', '<', time())->delete();
        }
    }
}
