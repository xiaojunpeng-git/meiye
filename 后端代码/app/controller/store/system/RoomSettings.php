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
        $filters = $this->request->getMore([
            ['keyword', ''],
            ['status', ''],
            ['page', 1],
            ['limit', 20],
        ]);
        try {
            return $this->success('ok', $this->services->page((int)$this->storeId, $filters));
        } catch (\Throwable $exception) {
            return $this->fail('房间列表暂时无法读取，请稍后重试。');
        }
    }

    public function create()
    {
        $data = $this->request->postMore([['name', '']]);
        return $this->write(function () use ($data): array {
            return $this->services->create((int)$this->storeId, (string)$data['name']);
        }, '新增成功');
    }

    public function update(int $id)
    {
        $data = $this->request->postMore([['name', '']]);
        return $this->write(function () use ($id, $data): array {
            return $this->services->update((int)$this->storeId, $id, (string)$data['name']);
        }, '保存成功');
    }

    public function setStatus(int $id)
    {
        $data = $this->request->postMore([['enabled', null]]);
        if (!in_array($data['enabled'], [0, 1, '0', '1', true, false], true)) {
            return $this->fail('房间状态无效。');
        }
        return $this->write(function () use ($id, $data): array {
            return $this->services->setEnabled((int)$this->storeId, $id, (bool)$data['enabled']);
        }, '房间状态已更新');
    }

    public function sort()
    {
        $data = $this->request->postMore([['room_ids', []]]);
        try {
            $this->services->reorder((int)$this->storeId, is_array($data['room_ids']) ? $data['room_ids'] : []);
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
}
