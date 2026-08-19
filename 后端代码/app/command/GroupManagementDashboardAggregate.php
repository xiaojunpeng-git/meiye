<?php

declare(strict_types=1);

namespace app\command;

use app\services\report\GroupManagementDashboardDailyAggregateServices;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/** Rebuild the disposable group-dashboard daily aggregate from facts. */
final class GroupManagementDashboardAggregate extends Command
{
    protected function configure()
    {
        $this->setName('group-dashboard:aggregate')
            ->addOption('tenant', 't', Option::VALUE_OPTIONAL, '租户 ID', '0')
            ->addOption('store-ids', 's', Option::VALUE_OPTIONAL, '逗号分隔的门店 ID；为空表示该租户全部门店', '')
            ->addOption('from', null, Option::VALUE_OPTIONAL, '开始业务日期 YYYY-MM-DD', '')
            ->addOption('to', null, Option::VALUE_OPTIONAL, '结束业务日期 YYYY-MM-DD', '')
            ->addOption('days', 'd', Option::VALUE_OPTIONAL, '未传日期时重建最近自然日数，默认 7', 7)
            ->addOption('status', null, Option::VALUE_NONE, '仅查看聚合追平状态，不写读模型')
            ->setDescription('从统一业绩事实可重建集团管理看板门店日聚合');
    }

    protected function execute(Input $input, Output $output)
    {
        $tenant = trim((string)$input->getOption('tenant'));
        $stores = $this->storeIds((string)$input->getOption('store-ids'));
        $range = $this->range((string)$input->getOption('from'), (string)$input->getOption('to'), (int)$input->getOption('days'));
        $lock = $this->acquireLock();
        try {
            $service = new GroupManagementDashboardDailyAggregateServices();
            if ((bool)$input->getOption('status')) {
                $result = $service->status($tenant, $stores, $range['start'], $range['end']);
                $output->writeln(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                return (bool)$result['aggregation_caught_up'] ? 0 : 2;
            }

            $result = $service->rebuild($tenant, $stores, $range['start'], $range['end']);
            $output->writeln(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return (bool)$result['aggregation_caught_up'] ? 0 : 1;
        } finally {
            $this->releaseLock($lock);
        }
    }

    /** @return array{handle:resource,path:string} */
    private function acquireLock(): array
    {
        $runtime = dirname(__DIR__, 2) . '/runtime';
        if (!is_dir($runtime) && !@mkdir($runtime, 0775, true) && !is_dir($runtime)) {
            throw new \RuntimeException('无法创建集团看板聚合运行目录');
        }
        $path = $runtime . '/group-dashboard-aggregate.lock';
        $handle = @fopen($path, 'c');
        if (!is_resource($handle) || !flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) fclose($handle);
            throw new \RuntimeException('集团看板日聚合任务正在执行，请勿并行启动');
        }
        return ['handle' => $handle, 'path' => $path];
    }

    /** @param array{handle:resource,path:string} $lock */
    private function releaseLock(array $lock): void
    {
        if (isset($lock['handle']) && is_resource($lock['handle'])) {
            flock($lock['handle'], LOCK_UN);
            fclose($lock['handle']);
        }
    }

    /** @return array{start:string,end:string} */
    private function range(string $from, string $to, int $days): array
    {
        $from = trim($from);
        $to = trim($to);
        if (($from === '') !== ($to === '')) {
            throw new \InvalidArgumentException('--from 与 --to 必须同时提供');
        }
        if ($from !== '') {
            return ['start' => $from, 'end' => $to];
        }
        $days = max(1, min(366, $days));
        $end = new \DateTimeImmutable('today');
        return ['start' => $end->modify('-' . ($days - 1) . ' days')->format('Y-m-d'), 'end' => $end->format('Y-m-d')];
    }

    /** @return array<int,int> */
    private function storeIds(string $raw): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $raw)), static function (int $id): bool {
            return $id > 0;
        })));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }
}
