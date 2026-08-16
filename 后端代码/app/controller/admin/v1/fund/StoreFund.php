<?php
declare(strict_types=1);
namespace app\controller\admin\v1\fund;

use app\controller\admin\AuthController;
use app\services\fund\FundPlatformScopeServices;
use app\services\fund\StoreFundServices;
use think\facade\App;

final class StoreFund extends AuthController
{
    public function __construct(App $app, StoreFundServices $services, FundPlatformScopeServices $scopeServices) { parent::__construct($app); $this->services=$services; $this->scopeServices=$scopeServices; }
    private function selectedScope(): array { return $this->scopeServices->selectedStoreScope((int)$this->adminType,(int)$this->agentId,(array)$this->adminInfo,(int)$this->request->param('store_id',0)); }
    private function actor(): array { return ['type'=>'ADMIN','id'=>(int)$this->adminId,'name'=>(string)($this->adminInfo['real_name']??$this->adminInfo['account']??'平台管理员')]; }
    public function scope() { return $this->success($this->scopeServices->pickerTree((int)$this->adminType,(int)$this->agentId,(array)$this->adminInfo)); }
    public function subjects() { return $this->success($this->services->subjects((bool)$this->request->get('include_disabled',false))); }
    public function saveSubject() { try{return $this->success($this->services->saveSubject((array)$this->request->post()));}catch(\Throwable $e){return $this->fail('科目保存失败。',['code'=>$e->getMessage()]);} }
    public function documents() { try{return $this->success($this->services->listDocuments($this->selectedScope(),$this->request->get()));}catch(\Throwable $e){return $this->fail('请选择有权限的门店后查询。',['code'=>$e->getMessage()]);} }
    public function detail(int $id) { try{return $this->success($this->services->detail($this->selectedScope(),$id));}catch(\Throwable $e){return $this->fail('收支单不存在。',['code'=>$e->getMessage()]);} }
    public function save() { try{return $this->success($this->services->save($this->selectedScope(),$this->actor(),(array)$this->request->post()));}catch(\Throwable $e){return $this->fail('收支单保存失败。',['code'=>$e->getMessage()]);} }
    public function audit(int $id) { try{return $this->success($this->services->audit($this->selectedScope(),$this->actor(),$id,(bool)$this->request->post('audited',true)));}catch(\Throwable $e){return $this->fail('审核状态更新失败。',['code'=>$e->getMessage()]);} }
    public function reverse(int $id) { try{return $this->success($this->services->reverse($this->selectedScope(),$this->actor(),$id));}catch(\Throwable $e){return $this->fail('冲销失败。',['code'=>$e->getMessage()]);} }
    public function ledger() { try{return $this->success($this->services->ledger($this->selectedScope(),$this->request->get()));}catch(\Throwable $e){return $this->fail('请选择有权限的门店后查询。',['code'=>$e->getMessage()]);} }
    public function report() { try{return $this->success($this->services->report($this->selectedScope(),$this->request->get()));}catch(\Throwable $e){return $this->fail('请选择有权限的门店后查询。',['code'=>$e->getMessage()]);} }
    public function exportLedger() { try{return $this->success($this->services->exportLedger($this->selectedScope(),$this->request->get()));}catch(\Throwable $e){return $this->fail('请选择有权限的门店后导出。',['code'=>$e->getMessage()]);} }
    public function exportReport() { try{return $this->success($this->services->exportReport($this->selectedScope(),$this->request->get()));}catch(\Throwable $e){return $this->fail('请选择有权限的门店后导出。',['code'=>$e->getMessage()]);} }
    public function exportFile(string $fileKey) { try{return download($this->services->exportFilePath($fileKey),$this->services->exportDownloadName($fileKey));}catch(\Throwable $e){return $this->fail('导出文件不存在或已失效。',['code'=>$e->getMessage()]);} }
}
