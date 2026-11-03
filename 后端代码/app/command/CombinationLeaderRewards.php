<?php
declare(strict_types=1);
namespace app\command;
use app\services\activity\combination\CombinationLeaderRewardServices;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;

/** 每分钟处理持久奖励队列；无真实支付调用，重复执行不重复发放或撤销。 */
final class CombinationLeaderRewards extends Command
{
    protected function configure() { $this->setName('combination:leader-rewards')->setDescription('处理拼团团长奖励与撤销回执')->addOption('limit',null,Option::VALUE_OPTIONAL,'每批团数',50); }
    protected function execute(Input $input,Output $output) { $output->writeln(json_encode((new CombinationLeaderRewardServices())->tick((int)$input->getOption('limit')),JSON_UNESCAPED_UNICODE)); return 0; }
}
