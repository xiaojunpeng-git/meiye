<?php
namespace app\command;

use app\services\ai\AiGatewayServices;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;

/** Run on the explicitly selected instance; never connects to a customer discovered by name. */
final class MoheAiSupervisor extends Command
{
    protected function configure()
    {
        $this->setName('mohe-ai:supervise')->setDescription('Supervise AI deadlines and 24-hour private retention')
            ->addOption('watch',null,Option::VALUE_NONE,'Supervise once per second until stopped');
    }
    protected function execute(Input $input,Output $output)
    {
        $service=new AiGatewayServices();
        $lastMonitorAt=0;
        do {
            try { $service->supervise(); }
            catch (\Throwable $error) {
                // No raw exception: database messages can contain deployment secrets.
                $output->writeln('AI_SUPERVISION_FAILED'); return 1;
            }
            // Journal only stable alert codes at a bounded cadence. A failed
            // monitor read must be visible to operations, never treated as healthy.
            if (time()-$lastMonitorAt>=60) {
                $lastMonitorAt=time();
                try {
                    $health=$service->monitorStatus();
                    if (($health['status']??null)==='alert') {
                        $codes=array_column($health['alerts']??[],'code');
                        $output->writeln('AI_MONITOR_ALERT '.implode(',',array_filter($codes,static function($code){return is_string($code)&&preg_match('/^[A-Z_]+$/D',$code);} )));
                    }
                } catch (\Throwable $ignored) { $output->writeln('AI_MONITOR_UNAVAILABLE'); }
            }
            if (!$input->getOption('watch')) { $output->writeln('AI_SUPERVISION_OK'); return 0; }
            usleep(1000000);
        } while (true);
    }
}
