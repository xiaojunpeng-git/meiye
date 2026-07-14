<?php

namespace app\command;

use app\services\order\StoreReservationOrderServices;
use think\console\Command;
use think\console\Input;
use think\console\Output;

/**
 * 预约服务进度提醒（YUYUE_JINDU）：距结束5分钟 / 服务到时
 */
class ReservationJindu extends Command
{
    protected function configure()
    {
        $this->setName('reservationJindu')->setDescription('预约服务进度订阅消息提醒');
    }

    protected function execute(Input $input, Output $output)
    {
        /** @var StoreReservationOrderServices $service */
        $service = app()->make(StoreReservationOrderServices::class);
        $count = $service->processReservationJinduNotices();
        $output->writeln('reservation jindu processed: ' . $count);
    }
}
