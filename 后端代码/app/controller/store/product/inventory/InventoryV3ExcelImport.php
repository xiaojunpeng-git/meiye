<?php
declare(strict_types=1);

namespace app\controller\store\product\inventory;

use app\controller\store\AuthController;
use app\services\product\inventory\InventoryV3ExcelImportServices;
use think\facade\App;

class InventoryV3ExcelImport extends AuthController
{
    public function __construct(App $app, InventoryV3ExcelImportServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    public function template()
    {
        return $this->services->downloadTemplate((string)$this->request->get('direction', ''), (int)$this->storeId);
    }

    public function upload()
    {
        return $this->success($this->services->storeUploadedFile($this->request->file('file')));
    }

    public function import()
    {
        [$direction, $file, $realName] = $this->request->postMore([
            ['direction', ''], ['file', ''], ['real_name', ''],
        ], true);
        return $this->success($this->services->importFile(
            (string)$direction, (int)$this->storeId, (int)$this->storeStaffId, (string)$file, (string)$realName
        ));
    }

    public function index()
    {
        [$page, $limit, $direction] = $this->request->getMore([['page', 1], ['limit', 20], ['direction', '']], true);
        return $this->success($this->services->list((int)$this->storeId, (int)$page, (int)$limit, (string)$direction));
    }

    public function detail(int $id)
    {
        return $this->success($this->services->detail((int)$this->storeId, $id));
    }
}
