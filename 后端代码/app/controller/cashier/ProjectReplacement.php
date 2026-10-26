<?php

namespace app\controller\cashier;

use app\Request;
use app\services\order\CardProjectReplacementServices;
use think\facade\App;

class ProjectReplacement extends AuthController
{
    public function __construct(App $app, CardProjectReplacementServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    public function options($holderId)
    {
        return $this->success($this->services->options((int)$holderId, (int)$this->storeId));
    }

    public function preview(Request $request, $holderId)
    {
        $payload = $request->postMore([
            ['source_cart_info_ids', []],
            ['source_times', []],
            ['sources', []],
            ['target_product_id', 0],
            ['remark', ''],
        ]);
        return $this->success($this->services->preview((int)$holderId, $payload, (int)$this->storeId));
    }

    public function commit(Request $request, $holderId)
    {
        $payload = $request->postMore([
            ['source_cart_info_ids', []],
            ['source_times', []],
            ['sources', []],
            ['target_product_id', 0],
            ['idempotency_key', ''],
            ['remark', ''],
        ]);
        return $this->success('项目替换成功', $this->services->commit((int)$holderId, $payload, (int)$this->storeId, (int)$this->cashierId));
    }

    public function records($holderId)
    {
        return $this->success(['list' => $this->services->records((int)$holderId)]);
    }
}
