<?php
// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2020 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------


namespace app\command;


use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Log;
use mohe\services\CacheService;
use mohe\services\FileService;

class ClearCache extends Command
{
    protected function configure()
    {
        // 指令配置
        $this->setName('clear:cache')
            ->setDescription('已删除缓存');
    }

    protected function execute(Input $input, Output $output)
    {
        try {
            FileService::delDir(root_path() . 'runtime');
        } catch (\Exception $e) {
            Log::info('删除缓存错误：' . $e->getMessage());
        }
        $output->info('执行成功:已删除缓存');
    }

}
