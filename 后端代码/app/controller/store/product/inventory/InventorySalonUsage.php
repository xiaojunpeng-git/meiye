<?php
declare(strict_types=1);
namespace app\controller\store\product\inventory;
use app\controller\store\AuthController;
use app\services\product\inventory\InventorySalonUsageServices;
use think\facade\App;
final class InventorySalonUsage extends AuthController {
    public function __construct(App $app, InventorySalonUsageServices $services){parent::__construct($app);$this->services=$services;}
    public function issue(){ $d=$this->request->postMore([['idempotency_key',''],['business_date',''],['project_id',0],['project_name',''],['remark',''],['lines',[]]]);return $this->success($this->services->issue((int)$this->storeId,(int)$this->storeStaffId,$d)); }
    /** 院装退回入口已停用；作废由服务层保证整单退库与状态原子提交。 */
    public function void(int $id){ try { $d=$this->request->postMore([['idempotency_key',''],['reason','']]); return $this->success($this->services->voidForStore((int)$this->storeId,(int)$this->storeStaffId,$id,$d)); } catch (\InvalidArgumentException|\RuntimeException $e) { return $this->fail($this->voidMessage($e->getMessage()),['code'=>$e->getMessage()]); } }
    private function voidMessage(string $code): string { return ['inventory_salon_usage_void_has_return'=>'该领用单已有退回记录，不能再次作废退库。','inventory_salon_usage_void_document_missing'=>'领用单不存在或不属于当前门店。','inventory_salon_usage_void_state_invalid'=>'当前领用单状态不可作废。','inventory_manual_reversal_already_voided'=>'该领用单已作废，请刷新列表。'][$code] ?? '院装领用作废未完成，请刷新后重试。'; }
    public function index(){ $d=$this->request->getMore([['project_id',0],['from',date('Y-m-01')],['to',date('Y-m-d')]]);return $this->success($this->services->list((int)$this->storeId,(int)$this->storeStaffId,(int)$d['project_id'],(string)$d['from'],(string)$d['to'])); }
    public function detail(int $id){ try{return $this->success($this->services->detail((int)$this->storeId,(int)$this->storeStaffId,$id));}catch(\InvalidArgumentException $exception){return $this->fail('院装单据不存在或无权查看。',['code'=>$exception->getMessage()]);} }
    public function projects(){ $d=$this->request->getMore([['keyword',''],['page',1],['limit',50]]);return $this->success($this->services->projects((int)$this->storeId,(int)$this->storeStaffId,(string)$d['keyword'],(int)$d['page'],(int)$d['limit'])); }
}
