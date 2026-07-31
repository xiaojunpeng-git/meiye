<?php
declare(strict_types=1);
namespace app\controller\store\product\inventory;
use app\controller\store\AuthController; use app\services\product\inventory\InventoryStockCountQueryServices; use think\facade\App;
final class InventoryStockCountQuery extends AuthController { public function __construct(App $app, InventoryStockCountQueryServices $services){parent::__construct($app);$this->services=$services;} public function index(){ $data=$this->request->getMore([['keyword',''],['page',1],['limit',20]]); return $this->success($this->services->list((int)$this->storeId,(int)$this->storeStaffId,(string)$data['keyword'],(int)$data['page'],(int)$data['limit']));}}
