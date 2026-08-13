<?php

namespace app\controller\store\system;

use app\controller\store\AuthController;
use app\services\store\RoomSettingsServices;
use think\facade\App;

final class RoomSettings extends AuthController
{
    /** @var RoomSettingsServices */
    private $services;

    public function __construct(App $app, RoomSettingsServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    public function index()
    {
        $storeId = $this->currentStoreId();
        $filters = $this->request->getMore([
            ['keyword', ''],
            ['status', ''],
            ['page', 1],
            ['limit', 20],
        ]);
        try {
            return $this->success('ok', $this->services->page($storeId, $filters));
        } catch (\Throwable $exception) {
            return $this->fail('房间列表暂时无法读取，请稍后重试。');
        }
    }

    public function create()
    {
        $data = $this->request->postMore([['name', '']]);
        return $this->write(function () use ($data): array {
            return $this->services->create($this->currentStoreId(), (string)$data['name']);
        }, '新增成功');
    }

    public function update(int $id)
    {
        $data = $this->request->postMore([['name', '']]);
        return $this->write(function () use ($id, $data): array {
            return $this->services->update($this->currentStoreId(), $id, (string)$data['name']);
        }, '保存成功');
    }

    public function setStatus(int $id)
    {
        $data = $this->request->postMore([['enabled', null]]);
        if (!in_array($data['enabled'], [0, 1, '0', '1', true, false], true)) {
            return $this->fail('房间状态无效。');
        }
        return $this->write(function () use ($id, $data): array {
            return $this->services->setEnabled($this->currentStoreId(), $id, (bool)$data['enabled']);
        }, '房间状态已更新');
    }

    public function sort()
    {
        $storeId = $this->currentStoreId();
        $data = $this->request->postMore([['room_ids', []]]);
        try {
            $this->services->reorder($storeId, is_array($data['room_ids']) ? $data['room_ids'] : []);
            return $this->success('排序已保存');
        } catch (\Throwable $exception) {
            return $this->fail($exception->getMessage() ?: '房间排序未能保存，请刷新后重试。');
        }
    }

    private function write(callable $operation, string $message)
    {
        try {
            return $this->success($message, $operation());
        } catch (\Throwable $exception) {
            return $this->fail($exception->getMessage() ?: '操作未能完成，请稍后重试。');
        }
    }

    /**
     * V3 路由的门店身份由路由中间件在控制器构造之后注入；不能使用
     * AuthController 初始化时缓存的旧 storeId（旧值可能是 0）。
     */
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
}
