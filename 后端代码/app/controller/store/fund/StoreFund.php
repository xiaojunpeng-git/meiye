<?php
declare(strict_types=1);
namespace app\controller\store\fund;

use app\controller\store\AuthController;
use app\services\fund\StoreFundServices;
use think\facade\App;

final class StoreFund extends AuthController
{
    public function __construct(App $app, StoreFundServices $services) { parent::__construct($app); $this->services=$services; }
    private function scope(): array { return ['mode'=>'store','store_id'=>(int)$this->storeId]; }
    private function actor(): array { return ['type'=>'STORE_STAFF','id'=>(int)$this->storeStaffId,'name'=>(string)($this->storeStaffInfo['nickname']??$this->storeStaffInfo['real_name']??'门店员工')]; }
    public function subjects() { return $this->success($this->services->subjects()); }
    public function documents() { return $this->success($this->services->listDocuments($this->scope(),$this->request->get())); }
    public function detail(int $id) { try { return $this->success($this->services->detail($this->scope(),$id)); } catch(\Throwable $e){ return $this->fail('收支单不存在或无权查看。',['code'=>$e->getMessage()]); } }
    public function save() { try { return $this->success($this->services->save($this->scope(),$this->actor(),(array)$this->request->post())); } catch(\Throwable $e){ return $this->fail('收支单保存失败，请检查日期、方向和明细。',['code'=>$e->getMessage()]); } }
    public function audit(int $id) { try { return $this->success($this->services->audit($this->scope(),$this->actor(),$id,(bool)$this->request->post('audited',true))); } catch(\Throwable $e){ return $this->fail('审核状态更新失败。',['code'=>$e->getMessage()]); } }
    public function reverse(int $id) { try { return $this->success($this->services->reverse($this->scope(),$this->actor(),$id)); } catch(\Throwable $e){ return $this->fail('冲销失败，只有已审核单据可以冲销。',['code'=>$e->getMessage()]); } }
    public function ledger() { return $this->success($this->services->ledger($this->scope(),$this->request->get())); }
    public function report() { return $this->success($this->services->report($this->scope(),$this->request->get())); }
    public function exportLedger() { return $this->success($this->services->exportLedger($this->scope(),$this->request->get())); }
    public function exportReport() { return $this->success($this->services->exportReport($this->scope(),$this->request->get())); }
    public function exportFile(string $fileKey) { try{return download($this->services->exportFilePath($fileKey),$this->services->exportDownloadName($fileKey));}catch(\Throwable $e){return $this->fail('导出文件不存在或已失效。',['code'=>$e->getMessage()]);} }
}
