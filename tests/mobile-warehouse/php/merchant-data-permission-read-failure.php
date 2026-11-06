<?php
declare(strict_types=1);

namespace think\facade {
    /** 模拟运行期数据库故障；纯测试不连接数据库或持有凭据。 */
    final class Db
    {
        public static function name(string $table)
        {
            throw new \RuntimeException('simulated database unavailable');
        }
    }
}

namespace {
    require_once dirname(__DIR__,3).'/后端代码/app/services/mobile/warehouse/MerchantDataPermissionServices.php';
    $service=new \app\services\mobile\warehouse\MerchantDataPermissionServices();
    try {
        $service->snapshot();
        throw new \LogicException('Database failure silently returned default enabled rules');
    } catch (\RuntimeException $exception) {
        if ($exception->getMessage()!=='暂时无法读取商家端权限配置，请稍后重试' || !$exception->getPrevious() || $exception->getPrevious()->getMessage()!=='simulated database unavailable') throw $exception;
        echo "PASS 运行期读取失败不降级成默认开启权限\n";
    }
}
