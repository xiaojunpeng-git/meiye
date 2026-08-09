<?php

namespace app\command;

use app\services\query\UnifiedQueryExportWorkerServices;
use app\services\query\UnifiedQueryRuntime;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

class UnifiedQueryExportWorker extends Command
{
    protected function configure()
    {
        $this->setName('unified-query:export-worker')
            ->addOption('limit', 'l', Option::VALUE_OPTIONAL, '每轮最大任务数', 20)
            ->addOption('loop', null, Option::VALUE_NONE, '常驻循环扫描')
            ->addOption('sleep', 's', Option::VALUE_OPTIONAL, '循环间隔秒', 2)
            ->addOption('task', 't', Option::VALUE_OPTIONAL, '仅处理指定 task_no', '')
            ->setDescription('生成统一查询 XLSX 导出任务');
    }

    protected function execute(Input $input, Output $output)
    {
        $runtime = UnifiedQueryRuntime::runtime();
        $worker = new UnifiedQueryExportWorkerServices(
            $runtime['exports'],
            $runtime['providers'],
            $runtime['workerContextResolvers']
        );
        $limit = max(1, min(100, (int)$input->getOption('limit')));
        $loop = (bool)$input->getOption('loop');
        $sleep = max(1, min(60, (int)$input->getOption('sleep')));
        $taskNo = trim((string)$input->getOption('task'));
        do {
            $result = $worker->processPending($limit, $taskNo);
            $output->writeln(sprintf(
                '[%s] scanned=%d succeeded=%d failed=%d',
                date('Y-m-d H:i:s'),
                $result['scanned'],
                $result['succeeded'],
                $result['failed']
            ));
            foreach ($result['tasks'] as $task) {
                $output->writeln(sprintf(
                    '  task=%s status=%s rows=%d%s',
                    (string)$task['taskId'],
                    (string)$task['status'],
                    (int)($task['rowCount'] ?? 0),
                    isset($task['diagnostic']) ? ' diagnostic=' . $task['diagnostic'] : ''
                ));
            }
            if (!$loop || $taskNo !== '') {
                break;
            }
            sleep($sleep);
        } while (true);
        return $result['failed'] > 0 ? 1 : 0;
    }
}
