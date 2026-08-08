<?php
declare(strict_types=1);
namespace app\controller\store\product\inventory;
use app\controller\store\AuthController;
use app\services\product\inventory\InventorySalonUsageServices;
use think\facade\App;
final class InventorySalonUsage extends AuthController {
    public function __construct(App $app, InventorySalonUsageServices $services){parent::__construct($app);$this->services=$services;}
    public function issue(){ $d=$this->request->postMore([['idempotency_key',''],['business_date',''],['project_id',0],['project_name',''],['remark',''],['lines',[]]]);return $this->success($this->services->issue((int)$this->storeId,(int)$this->storeStaffId,$d)); }
    public function returnToDefault(){ $d=$this->request->postMore([['idempotency_key',''],['business_date',''],['project_id',0],['project_name',''],['remark',''],['return_location_id',0],['lines',[]]]);return $this->success($this->services->returnToDefault((int)$this->storeId,(int)$this->storeStaffId,$d)); }
    public function index(){ $d=$this->request->getMore([['project_id',0],['from',date('Y-m-01')],['to',date('Y-m-d')]]);return $this->success($this->services->list((int)$this->storeId,(int)$this->storeStaffId,(int)$d['project_id'],(string)$d['from'],(string)$d['to'])); }
    public function projects(){ $d=$this->request->getMore([['keyword',''],['page',1],['limit',50]]);return $this->success($this->services->projects((int)$this->storeId,(int)$this->storeStaffId,(string)$d['keyword'],(int)$d['page'],(int)$d['limit'])); }
}
