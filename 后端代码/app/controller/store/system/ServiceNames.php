<?php

namespace app\controller\store\system;

use app\controller\store\AuthController;
use app\services\store\ServiceNameSettingsServices;
use think\facade\App;

/**
 * 门店服务名称管理入口。
 *
 * 会话门店和操作人均取自收银 V3 中间件，任何客户端 store_id 都不能扩大
 * 数据范围；名称只作为独立基础资料保存，不触发其他业务写入。
 */
final class ServiceNames extends AuthController
{
    /** @var ServiceNameSettingsServices */
    private $services;

    public function __construct(App $app, ServiceNameSettingsServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    public function index()
    {
        $filters = $this->request->getMore([
            ['keyword', ''],
            ['status', ''],
            ['page', 1],
            ['limit', 100],
        ]);
        try {
            return $this->success('ok', $this->services->page($this->currentStoreId(), $filters));
        } catch (\Throwable $exception) {
            return $this->fail('服务名称列表暂时无法读取，请稍后重试。');
        }
    }

    public function create()
    {
        $data = $this->request->postMore([
            ['name', ''],
            ['expected_version', 0],
            ['request_token', ''],
        ]);
        return $this->write(function () use ($data): array {
            return $this->services->create(
                $this->currentStoreId(),
                $this->currentOperator(),
                (string)$data['name'],
                (int)$data['expected_version'],
                (string)$data['request_token']
            );
        }, '新增成功');
    }

    public function update(int $id)
    {
        $data = $this->request->postMore([
            ['name', ''],
            ['expected_version', 0],
            ['request_token', ''],
        ]);
        return $this->write(function () use ($id, $data): array {
            return $this->services->update(
                $this->currentStoreId(),
                $this->currentOperator(),
                $id,
                (string)$data['name'],
                (int)$data['expected_version'],
                (string)$data['request_token']
            );
        }, '保存成功');
    }

    public function setStatus(int $id)
    {
        $data = $this->request->postMore([
            ['enabled', null],
            ['expected_version', 0],
            ['request_token', ''],
        ]);
        if (!in_array($data['enabled'], [0, 1, '0', '1', true, false], true)) {
            return $this->fail('服务名称状态无效。');
        }
        return $this->write(function () use ($id, $data): array {
            return $this->services->setEnabled(
                $this->currentStoreId(),
                $this->currentOperator(),
                $id,
                (bool)$data['enabled'],
                (int)$data['expected_version'],
                (string)$data['request_token']
            );
        }, '状态已更新');
    }

    public function sort()
    {
        $data = $this->request->postMore([
            ['items', []],
            ['request_token', ''],
        ]);
        return $this->write(function () use ($data): array {
            return $this->services->reorder(
                $this->currentStoreId(),
                $this->currentOperator(),
                is_array($data['items']) ? $data['items'] : [],
                (string)$data['request_token']
            );
        }, '排序已保存');
    }

    public function setSwitch()
    {
        $data = $this->request->postMore([
            ['enabled', null],
            ['expected_version', 0],
            ['request_token', ''],
        ]);
        if (!in_array($data['enabled'], [0, 1, '0', '1', true, false], true)) {
            return $this->fail('服务开关状态无效。');
        }
        return $this->write(function () use ($data): array {
            return $this->services->setSwitch(
                $this->currentStoreId(),
                $this->currentOperator(),
                (bool)$data['enabled'],
                (int)$data['expected_version'],
                (string)$data['request_token']
            );
        }, '服务开关已更新');
    }

    private function write(callable $operation, string $message)
    {
        try {
            return $this->success($message, $operation());
        } catch (\Throwable $exception) {
            return $this->fail($exception->getMessage() ?: '服务名称未能保存，请稍后重试。');
        }
    }

    /** V3 路由在控制器构造之后注入门店，必须从当前请求重新读取。 */
    private function currentStoreId(): int
    {
        $storeId = (int)($this->request->storeId ?? 0);
        if ($storeId <= 0 && $this->request->hasMacro('storeId')) {
            $storeId = (int)$this->request->storeId();
        }
        if ($storeId <= 0) {
            throw new \RuntimeException('缺少会话门店，请重新登录。');
        }
        return $storeId;
    }

    /** @return array{id:int,name:string} */
    private function currentOperator(): array
    {
        $operatorId = (int)($this->request->storeStaffId ?? $this->request->cashierId ?? 0);
        $profile = (array)($this->request->storeStaffInfo ?? $this->request->cashierInfo ?? []);
        return [
            'id' => $operatorId,
            'name' => trim((string)($profile['staff_name'] ?? $profile['account'] ?? '')),
        ];
    }
}
