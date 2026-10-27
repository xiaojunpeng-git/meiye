<?php
declare(strict_types=1);
namespace app\command;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;
use app\services\growth\GrowthProcessServices;

/** 现有站点调度每分钟执行一次；默认不开启模块，关闭状态不写成长事实。 */
final class GrowthProcess extends Command
{
    protected function configure(){ $this->setName('growth:process')->setDescription('处理成长事件、提醒与正式入职派发')->addOption('limit',null,Option::VALUE_OPTIONAL,'单批事件数量',100); }
    protected function execute(Input $input,Output $output){$result=app()->make(GrowthProcessServices::class)->tick((int)$input->getOption('limit'));$output->writeln(json_encode($result,JSON_UNESCAPED_UNICODE));return empty($result['failed_event_ids'])?0:1;}
}
