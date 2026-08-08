<?php
declare(strict_types=1);

namespace app\controller\admin\v1\product\inventory;

use app\controller\admin\AuthController;
use app\services\product\inventory\InventoryPlatformWarehouseServices;
use app\services\product\inventory\InventoryV3ExcelImportServices;
use think\exception\ValidateException;
use think\facade\App;

final class InventoryPlatformExcelImport extends AuthController
{
    public function __construct(App $app, InventoryV3ExcelImportServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    public function template()
    {
        $direction = (string)$this->request->get('direction', '');
        $locations = (new InventoryPlatformWarehouseServices())->locations((array)$this->adminInfo);
        $allowed = array_values(array_filter(array_map(static function (array $row): string {
            return (string)($row['location_type'] ?? '') === 'HQ' ? 'HQ:' . (int)$row['id'] : 'STORE:' . (int)$row['store_id'];
        }, $locations), static fn (string $key): bool => !str_ends_with($key, ':0')));
        $selected = $this->request->get('store_ids', []);
        if (is_string($selected)) $selected = array_filter(array_map('trim', explode(',', $selected)), static fn (string $value): bool => $value !== '');
        if (is_array($selected)) {
            $selected = array_map(static function ($value): string {
                $value = trim((string)$value);
                return str_contains($value, ':') ? $value : 'STORE:' . (int)$value;
            }, $selected);
        }
        return $this->services->downloadPlatformTemplate($direction, $allowed, is_array($selected) ? $selected : []);
    }

    public function upload()
    {
        return $this->success($this->services->storeUploadedFile($this->request->file('file')));
    }

    public function import()
    {
        [$direction, $file, $realName, $storeIds] = $this->request->postMore([
            ['direction', ''], ['file', ''], ['real_name', ''], ['store_ids', []],
        ], true);
        $locations = (new InventoryPlatformWarehouseServices())->locations((array)$this->adminInfo);
        $allowed = array_values(array_filter(array_map(static function (array $row): string {
            return (string)($row['location_type'] ?? '') === 'HQ' ? 'HQ:' . (int)$row['id'] : 'STORE:' . (int)$row['store_id'];
        }, $locations), static fn (string $key): bool => !str_ends_with($key, ':0')));
        return $this->success($this->services->importPlatformFile($direction, $allowed, (array)$this->adminInfo, (string)$file, (string)$realName));
    }
}
