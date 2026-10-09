<?php
declare(strict_types=1);
namespace app\controller\store\fund;

use app\controller\store\AuthController;
use app\services\fund\StoreFundServices;
use app\services\cashier\v3\permission\CashierV3FeatureResolver;
use think\facade\App;

final class StoreFund extends AuthController
{
    public function __construct(App $app, StoreFundServices $services) { parent::__construct($app); $this->services=$services; }
    private function scope(): array { return ['mode'=>'store','store_id'=>(int)$this->storeId]; }
    private function actor(): array { return ['type'=>'STORE_STAFF','id'=>(int)$this->storeStaffId,'name'=>(string)($this->storeStaffInfo['nickname']??$this->storeStaffInfo['real_name']??'门店员工')]; }
    public function subjects() { return $this->success($this->services->subjects()); }
    public function documents() { return $this->success($this->services->listDocuments($this->scope(),$this->request->get())); }
    public function detail(int $id) { try { return $this->success($this->services->detail($this->scope(),$id)); } catch(\Throwable $e){ return $this->fail('收支单不存在或无权查看。',['code'=>$e->getMessage()]); } }
    /** 本店未审核单可由有费用权限人员编辑；操作者与授权标记不能从表单继承。 */
    public function save()
    {
        try {
            $this->assertFundWritePermission();
            $actor = $this->actor();
            $actor['can_edit_store_documents'] = true;
            return $this->success($this->services->save($this->scope(), $actor, (array)$this->request->post()));
        } catch (\Throwable $e) {
            $message = $e->getMessage() === 'fund_document_revision_conflict'
                ? '单据已发生变化，请重新打开后编辑。' : '收支单保存失败，请检查日期、方向和明细。';
            return $this->fail($message, ['code' => $e->getMessage()]);
        }
    }
    /** 审核与撤销均可由本店有权限人员办理；授权标记只在服务端生成，不能从请求继承。 */
    public function audit(int $id)
    {
        try {
            $audited = (bool)$this->request->post('audited', true);
            $actor = $this->actor();
            $this->assertFundWritePermission();
            $actor['can_audit_store_documents'] = true;
            return $this->success($this->services->audit($this->scope(), $actor, $id, $audited));
        } catch (\Throwable $e) {
            return $this->fail('审核状态更新失败。', ['code' => $e->getMessage()]);
        }
    }

    /** V3 费用入口沿用 member 能力；可见不代表可操作，委派只读身份不得写入。 */
    private function assertFundWritePermission(): void
    {
        $profile = (array)$this->storeStaffInfo;
        if ((int)$this->storeId <= 0 || (int)$this->storeStaffId <= 0) {
            throw new \RuntimeException('fund_document_operator_forbidden');
        }
        if (($profile['type'] ?? '') === 'cashier_v3_delegated' || !empty($profile['_cashier_v3_delegated'])) {
            throw new \RuntimeException('fund_document_operator_forbidden');
        }
        // 保存、审核、撤销及删除统一核验费用能力，旧会话也不能只凭接口可见获准写入。
        $features = app()->make(CashierV3FeatureResolver::class)->resolveGrantedFeatures($profile);
        if (!in_array('cashier.v3.member', $features, true)) {
            throw new \RuntimeException('fund_document_operator_forbidden');
        }
    }

    /** 有费用权限即可删除本店未审核单；身份和删除授权均由服务端生成，不继承请求体。 */
    public function deleteDraft(int $id)
    {
        try {
            $this->assertFundWritePermission();
            $actor = $this->actor();
            $actor['can_delete_store_documents'] = true;
            return $this->success($this->services->deleteDraft($this->scope(), $actor, $id));
        } catch (\Throwable $e) {
            $message = $e->getMessage() === 'fund_document_delete_requires_draft'
                ? '已审核收支单不能删除，请先撤销审核。' : '收支单删除失败，单据不存在或无权操作。';
            return $this->fail($message, ['code' => $e->getMessage()]);
        }
    }
    public function reverse(int $id) { try { return $this->success($this->services->reverse($this->scope(),$this->actor(),$id)); } catch(\Throwable $e){ return $this->fail('冲销失败，只有已审核单据可以冲销。',['code'=>$e->getMessage()]); } }
    public function ledger() { return $this->success($this->services->ledger($this->scope(),$this->request->get())); }
    public function report() { return $this->success($this->services->report($this->scope(),$this->request->get())); }
    public function exportLedger() { return $this->success($this->services->exportLedger($this->scope(),$this->request->get())); }
    public function exportReport() { return $this->success($this->services->exportReport($this->scope(),$this->request->get())); }
    public function exportFile(string $fileKey) { try{return download($this->services->exportFilePath($fileKey),$this->services->exportDownloadName($fileKey));}catch(\Throwable $e){return $this->fail('导出文件不存在或已失效。',['code'=>$e->getMessage()]);} }
}
