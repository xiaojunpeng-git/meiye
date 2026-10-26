<?php

namespace app\controller\admin\v1\system;

use app\Request;
use app\controller\admin\AuthController;
use app\services\order\WriteoffPerformanceModeServices;
use think\facade\App;

class WriteoffPerformanceMode extends AuthController
{
    public function __construct(App $app, WriteoffPerformanceModeServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    public function read()
    {
        return $this->success($this->services->getModeInfo());
    }

    public function update(Request $request)
    {
        [$mode, $idempotencyKey] = $request->postMore([
            ['mode', 'commission'],
            ['idempotency_key', ''],
        ], true);
        $adminId = (int)($this->adminId ?? 0);
        return $this->success('保存成功', $this->services->setMode((string)$mode, $adminId, (string)$idempotencyKey));
    }
}
