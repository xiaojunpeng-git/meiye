<?php
declare(strict_types=1);
namespace app\controller\store\product\inventory;
use app\controller\store\AuthController;
use app\services\product\inventory\InventoryStockCountServices;
use think\facade\App;
final class InventoryStockCount extends AuthController { public function __construct(App $app, InventoryStockCountServices $services) { parent::__construct($app); $this->services=$services; } public function confirm() { $data=$this->request->postMore([['idempotency_key',''],['business_date',''],['remark',''],['lines',[]]]); return $this->success($this->services->confirm((int)$this->storeId,(int)$this->storeStaffId,$data)); } }
