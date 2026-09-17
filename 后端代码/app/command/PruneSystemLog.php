<?php
declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;
use think\facade\Log;

/**
 * 清理系统操作日志。
 *
 * 仅作用于 eb_system_log（Db::name('system_log')），不触碰订单、服务、会员和业绩事实表。
 * 默认预览；线上定时任务必须显式传入 --execute。
 */
class PruneSystemLog extends Command
{
    private const DEFAULT_RETENTION_DAYS = 15;
    private const DEFAULT_BATCH_SIZE = 10000;
    private const MAX_BATCH_SIZE = 50000;

    protected function configure()
    {
        $this->setName('system-log:prune')
            ->addOption('days', 'd', Option::VALUE_OPTIONAL, '保留天数（至少 1 天）', self::DEFAULT_RETENTION_DAYS)
            ->addOption('batch-size', 'b', Option::VALUE_OPTIONAL, '单批删除行数（1 至 50000）', self::DEFAULT_BATCH_SIZE)
            ->addOption('execute', null, Option::VALUE_NONE, '确认实际删除；缺省时仅预览')
            ->setDescription('分批清理超过保留期的系统操作日志，默认仅预览');
    }

    protected function execute(Input $input, Output $output)
    {
        $days = (int)$input->getOption('days');
        $batchSize = (int)$input->getOption('batch-size');
        $execute = (bool)$input->getOption('execute');

        if ($days < 1) {
            $output->error('保留天数必须至少为 1 天。');
            return 2;
        }
        if ($batchSize < 1 || $batchSize > self::MAX_BATCH_SIZE) {
            $output->error('单批删除行数必须在 1 至 ' . self::MAX_BATCH_SIZE . ' 之间。');
            return 2;
        }

        $cutoff = time() - $days * 86400;
        $candidateCount = $this->expiredQuery($cutoff)->count();
        $mode = $execute ? '执行删除' : '预览';
        $output->writeln(sprintf(
            '%s：保留 %d 天，阈值 %s，可清理 %d 条系统日志。',
            $mode,
            $days,
            date('Y-m-d H:i:s', $cutoff),
            $candidateCount
        ));

        if (!$execute || $candidateCount === 0) {
            if (!$execute && $candidateCount > 0) {
                $output->writeln('未删除任何数据；确认清理请追加 --execute。');
            }
            return 0;
        }

        $deleted = 0;
        do {
            $affected = $this->expiredQuery($cutoff)
                ->order('id', 'asc')
                ->limit($batchSize)
                ->delete();
            $deleted += $affected;
            if ($affected > 0) {
                $output->writeln(sprintf('本批删除 %d 条，累计 %d 条。', $affected, $deleted));
            }
        } while ($affected === $batchSize);

        Log::notice(sprintf(
            'system_log retention prune completed: days=%d cutoff=%d deleted=%d',
            $days,
            $cutoff,
            $deleted
        ));
        $output->info(sprintf('清理完成：共删除 %d 条超过 %d 天的系统日志。', $deleted, $days));
        return 0;
    }

    /**
     * add_time=0 的历史异常数据不自动删除，必须人工核实后单独处理。
     */
    private function expiredQuery(int $cutoff)
    {
        return Db::name('system_log')
            ->where('add_time', '>', 0)
            ->where('add_time', '<', $cutoff);
    }
}
