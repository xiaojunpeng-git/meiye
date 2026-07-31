<?php
declare(strict_types=1);

namespace app\controller\store\product\inventory;

use app\controller\store\AuthController;
use app\services\product\inventory\InventoryStoreCatalogServices;
use think\facade\App;

class InventoryStoreCatalog extends AuthController
{
    public function __construct(App $app, InventoryStoreCatalogServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    public function search()
    {
        [$keyword, $limit] = $this->request->getMore([
            ['keyword', ''],
            ['limit', 20],
        ], true);
        return $this->success([
            'list' => $this->services->search((int)$this->storeId, (string)$keyword, (int)$limit),
        ]);
    }
}
