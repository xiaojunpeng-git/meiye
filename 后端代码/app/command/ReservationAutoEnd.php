<?php

namespace app\command;

use app\services\cashier\v3\reservation\CashierV3ReservationModule;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;

/** 部署时按实例托管 --watch 常驻任务，逐秒检查到期服务；单次模式供人工只读核验后执行。 */
final class ReservationAutoEnd extends Command
{
    protected function configure()
    {
        $this->setName('reservation:auto-end')->setDescription('结束到期的 V3 预约服务')
            ->addOption('watch', null, Option::VALUE_NONE, '每秒扫描，到期立即结束服务');
    }

    protected function execute(Input $input, Output $output)
    {
        do {
            $result = CashierV3ReservationModule::autoEndDue();
            if (!empty($result['ended']) || !empty($result['failed']) || !$input->getOption('watch')) {
                $output->writeln(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
            if (!$input->getOption('watch')) return !empty($result['failed']) ? 1 : 0;
            usleep(1000000);
        } while (true);
    }
}
