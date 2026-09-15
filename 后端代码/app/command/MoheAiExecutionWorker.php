<?php
namespace app\command;

use app\services\ai\execution\AiRunExecutionQueue;
use app\services\ai\execution\AiRuntimeFactory;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\queue\Worker;

/**
 * The only supported AI execution consumer.  It owns a per-instance queue
 * and writes a compact liveness heartbeat; generic/default queue workers are
 * deliberately not accepted as proof that AI work can be admitted.
 */
final class MoheAiExecutionWorker extends Command
{
    protected function configure()
    {
        $this->setName('mohe-ai:execution-worker')
            ->addOption('once',null,Option::VALUE_NONE,'Process at most one AI queue message')
            ->addOption('sleep','s',Option::VALUE_OPTIONAL,'Idle sleep seconds',1)
            ->setDescription('Run the dedicated per-instance 魔核 AI execution queue');
    }

    protected function execute(Input $input,Output $output)
    {
        $runtime=AiRuntimeFactory::make();
        $sleep=max(1,min(5,(int)$input->getOption('sleep')));
        $workerId='ai-exec-'.getmypid().'-'.bin2hex(random_bytes(12));
        $host=(string)php_uname('n');
        if (!preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D',$host)) $host='host-'.substr(hash('sha256',$host),0,32);
        putenv('MOHE_AI_EXECUTION_WORKER_ID='.$workerId);
        $connection=(string)config('queue.default'); $queue=AiRunExecutionQueue::name($runtime['instance']);
        if (!AiRunExecutionQueue::supported()) { $output->writeln('AI_EXECUTION_QUEUE_UNAVAILABLE'); return 1; }
        do {
            try {
                $runtime['runs']->heartbeatExecutionConsumer($workerId,getmypid(),$host);
                // One job at a time provides a real, auditable execution slot;
                // the Run store remains the cross-process capacity authority.
                // Console listing instantiates configured command classes
                // without the container.  Resolve the queue Worker only once
                // the actual command is executing, after ThinkPHP has bound
                // the application container.
                app(Worker::class)->runNextJob($connection,$queue,0,$sleep,1);
                $runtime['runs']->heartbeatExecutionConsumer($workerId,getmypid(),$host);
            } catch (\Throwable $error) {
                $output->writeln('AI_EXECUTION_WORKER_FAILED'); return 1;
            }
            if ($input->getOption('once')) break;
        } while (true);
        return 0;
    }
}
