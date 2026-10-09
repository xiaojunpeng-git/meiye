<?php
declare(strict_types=1);

/** 本地回滚验证：不改已有客户单据，不持久化测试单据或报表金额。 */
require '/var/www/html/vendor/autoload.php';

use app\controller\store\fund\StoreFund;
use app\services\cashier\v3\permission\CashierV3FeatureResolver;
use app\services\fund\StoreFundServices;
use think\facade\Db;

$app = new think\App('/var/www/html/');
$app->initialize();
if ((string)config('database.connections.mysql.hostname') !== 'mysql') {
    throw new RuntimeException('This probe requires the local mysql container.');
}
$checks = 0;
$assert = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException($label);
    $checks++;
};
$denied = static function (callable $operation, string $code) use ($assert): void {
    try { $operation(); } catch (RuntimeException | InvalidArgumentException $error) {
        $assert($error->getMessage() === $code, 'Unexpected denial: '.$error->getMessage());
        return;
    }
    throw new RuntimeException('Expected rejection: '.$code);
};

// 独立检查控制器判权，防止把“同店”误当成“有操作权限”。
$resolver = $app->make(CashierV3FeatureResolver::class);
$fakeResolver = new class extends CashierV3FeatureResolver {
    public $features = [];
    public function resolveGrantedFeatures(array $operatorProfile): array { return $this->features; }
};
$app->instance(CashierV3FeatureResolver::class, $fakeResolver);
$controller = (new ReflectionClass(StoreFund::class))->newInstanceWithoutConstructor();
$guard = new ReflectionMethod(StoreFund::class, 'assertFundWritePermission');
$guard->setAccessible(true);
$setContext = static function (array $profile, int $storeId = 23, int $staffId = 179) use ($controller): void {
    foreach (['storeId'=>$storeId, 'storeStaffId'=>$staffId, 'storeStaffInfo'=>$profile] as $key=>$value) {
        $property = new ReflectionProperty($controller, $key);
        $property->setAccessible(true);
        $property->setValue($controller, $value);
    }
};
try {
    $setContext(['type'=>'cashier_v3']);
    $denied(static function () use ($guard,$controller) { $guard->invoke($controller); }, 'fund_document_operator_forbidden');
    $fakeResolver->features = ['cashier.v3.member'];
    $guard->invoke($controller);
    $assert(true, 'Authorized V3 staff');
    $setContext(['type'=>'cashier_v3_organization', '_cashier_v3_organization'=>1]);
    $guard->invoke($controller);
    $assert(true, 'Authorized organization store context');
    foreach ([['type'=>'cashier_v3_delegated'], ['type'=>'cashier_v3_organization','_cashier_v3_delegated'=>1]] as $profile) {
        $setContext($profile);
        $denied(static function () use ($guard,$controller) { $guard->invoke($controller); }, 'fund_document_operator_forbidden');
    }
    $setContext(['type'=>'cashier_v3'], 0, 179);
    $denied(static function () use ($guard,$controller) { $guard->invoke($controller); }, 'fund_document_operator_forbidden');
    $setContext(['type'=>'cashier_v3'], 23, 0);
    $denied(static function () use ($guard,$controller) { $guard->invoke($controller); }, 'fund_document_operator_forbidden');
    // 审核、撤销及删除不借旧路由未登记的 API 放行；非 V3 令牌也要有有效费用能力。
    $setContext(['type'=>'store']);
    $fakeResolver->features = [];
    $denied(static function () use ($guard,$controller) { $guard->invoke($controller); }, 'fund_document_operator_forbidden');
    $fakeResolver->features = ['cashier.v3.member'];
    $guard->invoke($controller);
    $assert(true, 'Audit and deletion require server-resolved feature');
} finally {
    $app->instance(CashierV3FeatureResolver::class, $resolver);
}

$service = new StoreFundServices();
$scope = ['mode'=>'store','store_id'=>23];
$creator = ['type'=>'STORE_STAFF','id'=>1200,'name'=>'R46 local creator'];
$operator = ['type'=>'STORE_STAFF','id'=>179,'name'=>'R46 local operator'];
$authorized = $operator + ['can_audit_store_documents'=>true];
$editor = $operator + ['can_edit_store_documents'=>true];
$deleter = $operator + ['can_delete_store_documents'=>true];
$subject = (array)Db::name('store_fund_subject')->where('is_enabled',1)->find();
if (!$subject) throw new RuntimeException('No active local subject.');
$filters = ['date_from'=>'2026-10-09','date_to'=>'2026-10-09'];
$input = ['business_date'=>'2026-10-09','direction'=>'INCOME','summary'=>'R46 rollback-only audit probe',
    'lines'=>[['subject_id'=>(int)$subject['id'],'amount_cents'=>12345,'summary'=>'Snapshot stays intact']]];
$id = 0;
$controllerId = 0;
Db::startTrans();
try {
    $document = $service->save($scope,$creator,$input);
    $id = (int)$document['id'];
    // 既有新单修订从 0 开始，明确传入 0 可以匹配；客户端伪造编辑标记不授予权限。
    $zeroRevisionInput = $input + ['id'=>$id,'audit_revision'=>0,'can_edit_store_documents'=>true];
    $denied(static function () use ($service,$scope,$operator,$zeroRevisionInput) { $service->save($scope,$operator,$zeroRevisionInput); }, 'fund_document_operator_forbidden');
    $zeroRevisionSaved = $service->save($scope,$editor,$zeroRevisionInput);
    $assert((int)$zeroRevisionSaved['audit_revision'] === 1, 'Explicit initial revision is checked and advanced');
    $denied(static function () use ($service,$scope,$editor,$zeroRevisionInput) { $service->save($scope,$editor,$zeroRevisionInput); }, 'fund_document_revision_conflict');
    $approved = $service->audit($scope,$creator,$id,true);
    $assert($approved['document_status'] === 'APPROVED', 'Creator approval');
    $before = $service->report($scope,$filters)['summary'];
    $ledger = $service->ledger($scope,$filters);
    $assert(in_array($id,array_map('intval',array_column($ledger['list'],'document_id')),true), 'Approved document enters ledger');
    $denied(static function () use ($service,$scope,$operator,$id) { $service->audit($scope,$operator,$id,false); }, 'fund_document_operator_forbidden');
    $denied(static function () use ($service,$authorized,$id) { $service->audit(['mode'=>'store','store_id'=>175],$authorized,$id,false); }, 'fund_document_not_found');
    $denied(static function () use ($service,$authorized,$id) { $service->audit(['mode'=>'platform','store_ids'=>[23]],$authorized,$id,false); }, 'fund_document_operator_forbidden');
    $revoked = $service->audit($scope,$authorized,$id,false);
    $assert($revoked['document_status'] === 'DRAFT', 'Other authorized staff revoke');
    $assert((int)$revoked['audit_revision'] === (int)$approved['audit_revision']+1, 'One revision per transition');
    $assert($revoked['lines'] === $approved['lines'], 'Original amounts and subject snapshots preserved');
    $assert($revoked['created_by_id'] === $approved['created_by_id'] && $revoked['document_no'] === $approved['document_no'], 'Creator and document identity preserved');
    $after = $service->report($scope,$filters)['summary'];
    $assert($before['income_amount_cents']-$after['income_amount_cents'] === 12345, 'Report removes exact cents');
    $ledgerAfter = $service->ledger($scope,$filters);
    $assert(!in_array($id,array_map('intval',array_column($ledgerAfter['list'],'document_id')),true), 'Revoked document leaves ledger');
    $assert($ledger['current_balance_cents']-$ledgerAfter['current_balance_cents'] === 12345, 'Ledger balance matches report');
    $replayed = $service->audit($scope,$authorized,$id,false);
    $assert($replayed['audit_revision'] === $revoked['audit_revision'], 'Same-target retry is idempotent');
    // 审核同样允许已判权的本店非录入人；跨店、平台和未判权调用不能借标记放行。
    $denied(static function () use ($service,$scope,$operator,$id) { $service->audit($scope,$operator,$id,true); }, 'fund_document_operator_forbidden');
    $denied(static function () use ($service,$authorized,$id) { $service->audit(['mode'=>'store','store_id'=>175],$authorized,$id,true); }, 'fund_document_not_found');
    $denied(static function () use ($service,$authorized,$id) { $service->audit(['mode'=>'platform','store_ids'=>[23]],$authorized,$id,true); }, 'fund_document_operator_forbidden');
    $denied(static function () use ($service,$scope,$operator,$input,$id) { $service->save($scope,$operator,$input+['id'=>$id]); }, 'fund_document_operator_forbidden');
    // 编辑授权独立于审核授权，只在本店 DRAFT 生效，并保护详情修订及事务原子性。
    $editInput = $input + ['id'=>$id,'audit_revision'=>(int)$revoked['audit_revision']];
    $editInput['store_id'] = 175;
    $editInput['summary'] = 'R46 authorized draft edit';
    $editInput['lines'][0]['summary'] = '222';
    $draftReport = $service->report($scope,$filters);
    $draftLedger = $service->ledger($scope,$filters);
    $denied(static function () use ($service,$authorized,$scope,$editInput) { $service->save($scope,$authorized,$editInput); }, 'fund_document_operator_forbidden');
    $denied(static function () use ($service,$editor,$editInput) { $service->save(['mode'=>'store','store_id'=>175],$editor,$editInput); }, 'fund_document_not_editable');
    $platformEdit = $editInput;
    $platformEdit['store_id'] = 23;
    $denied(static function () use ($service,$editor,$platformEdit) { $service->save(['mode'=>'platform','store_ids'=>[23]],$editor,$platformEdit); }, 'fund_document_operator_forbidden');
    $missingRevision = $editInput;
    unset($missingRevision['audit_revision']);
    $denied(static function () use ($service,$scope,$editor,$missingRevision) { $service->save($scope,$editor,$missingRevision); }, 'fund_document_revision_conflict');
    foreach ([-1,'bad'] as $invalidRevision) {
        $invalidInput = $editInput;
        $invalidInput['audit_revision'] = $invalidRevision;
        $denied(static function () use ($service,$scope,$editor,$invalidInput) { $service->save($scope,$editor,$invalidInput); }, 'fund_document_revision_conflict');
    }
    $edited = $service->save($scope,$editor,$editInput);
    $assert($edited['document_status'] === 'DRAFT' && (int)$edited['store_id'] === 23, 'Edit stays draft in forced store');
    $assert($edited['created_by_id'] === $approved['created_by_id'] && $edited['document_no'] === $approved['document_no'], 'Edit preserves original creator and identity');
    $assert($edited['summary'] === $editInput['summary'] && $edited['lines'][0]['summary'] === '222', 'Other authorized staff save actual draft content');
    $assert((int)$edited['lines'][0]['amount_cents'] === 12345 && $edited['lines'][0]['subject_code_snapshot'] === $approved['lines'][0]['subject_code_snapshot'], 'Unchanged amount and subject preserved');
    $assert((int)$edited['audit_revision'] === (int)$revoked['audit_revision']+1, 'Edit advances resource revision once');
    $assert($service->report($scope,$filters) === $draftReport && $service->ledger($scope,$filters) === $draftLedger, 'Draft edit does not affect report or ledger');
    $denied(static function () use ($service,$scope,$editor,$editInput) { $service->save($scope,$editor,$editInput); }, 'fund_document_revision_conflict');
    $assert($service->detail($scope,$id) === $edited, 'Stale save cannot overwrite current draft');
    $invalidLineInput = $editInput;
    $invalidLineInput['audit_revision'] = (int)$edited['audit_revision'];
    $invalidLineInput['lines'][0]['amount_cents'] = 0;
    $denied(static function () use ($service,$scope,$editor,$invalidLineInput) { $service->save($scope,$editor,$invalidLineInput); }, 'fund_document_line_invalid');
    $assert($service->detail($scope,$id) === $edited, 'Invalid line rolls back revision and all detail replacement');
    $restored = $service->audit($scope,$authorized,$id,true);
    $assert($restored['document_status'] === 'APPROVED', 'Other authorized staff approve');
    $assert((int)$restored['audited_by_id'] === 179 && $restored['audited_by_type'] === 'STORE_STAFF', 'Actual approving operator recorded');
    $assert($restored['created_by_id'] === $approved['created_by_id'] && $restored['lines'] === $edited['lines'], 'Approval preserves creator and edited detail snapshots');
    $assert((int)$restored['audit_revision'] === (int)$edited['audit_revision']+1, 'Approval advances one revision');
    $assert($service->report($scope,$filters)['summary'] === $before, 'Reapproval restores exact report totals');
    $assert($service->ledger($scope,$filters)['current_balance_cents'] === $ledger['current_balance_cents'], 'Approval restores exact ledger balance');
    $approvalReplay = $service->audit($scope,$authorized,$id,true);
    $assert($approvalReplay['audit_revision'] === $restored['audit_revision'], 'Repeated approval is idempotent');
    $assert($service->report($scope,$filters)['summary'] === $before, 'Repeated approval does not double count');
    $denied(static function () use ($service,$scope,$creator,$id) { $service->deleteDraft($scope,$creator,$id); }, 'fund_document_delete_requires_draft');
    $denied(static function () use ($service,$scope,$deleter,$id) { $service->deleteDraft($scope,$deleter,$id); }, 'fund_document_delete_requires_draft');
    $assert($service->detail($scope,$id)['document_status'] === 'APPROVED', 'Rejected deletion preserves approval');
    $editInput['audit_revision'] = (int)$restored['audit_revision'];
    $denied(static function () use ($service,$scope,$editor,$editInput) { $service->save($scope,$editor,$editInput); }, 'fund_document_not_editable');

    $service->audit($scope,$creator,$id,false);
    $draft = $service->detail($scope,$id);
    $reportBeforeDelete = $service->report($scope,$filters);
    $ledgerBeforeDelete = $service->ledger($scope,$filters);
    $listBeforeDelete = $service->listDocuments($scope,$filters);
    $denied(static function () use ($service,$scope,$operator,$id) { $service->deleteDraft($scope,$operator,$id); }, 'fund_document_operator_forbidden');
    $denied(static function () use ($service,$scope,$editor,$id) { $service->deleteDraft($scope,$editor,$id); }, 'fund_document_operator_forbidden');
    // 本店非录入人须经独立删除判权；字符串标记、审核权限、跨店和平台不能借此放行。
    $denied(static function () use ($service,$scope,$authorized,$id) { $service->deleteDraft($scope,$authorized,$id); }, 'fund_document_operator_forbidden');
    $denied(static function () use ($service,$scope,$operator,$id) { $service->deleteDraft($scope,$operator+['can_delete_store_documents'=>'true'],$id); }, 'fund_document_operator_forbidden');
    $denied(static function () use ($service,$deleter,$id) { $service->deleteDraft(['mode'=>'store','store_id'=>175],$deleter,$id); }, 'fund_document_not_found');
    $denied(static function () use ($service,$deleter,$id) { $service->deleteDraft(['mode'=>'platform','store_ids'=>[23]],$deleter,$id); }, 'fund_document_operator_forbidden');
    $denied(static function () use ($service,$scope,$deleter,$id) { $service->deleteDraft($scope,array_replace($deleter,['id'=>0]),$id); }, 'fund_document_operator_forbidden');
    $denied(static function () use ($service,$scope,$deleter,$id) { $service->deleteDraft($scope,array_replace($deleter,['type'=>'ADMIN']),$id); }, 'fund_document_operator_forbidden');
    $deleted = $service->deleteDraft($scope,$deleter,$id);
    $assert($deleted === ['id'=>$id,'deleted'=>true], 'Other authorized staff delete store draft');
    $raw = (array)Db::name('store_fund_document')->where('id',$id)->find();
    $assert($raw['document_status'] === 'DELETED', 'Delete is logical, not physical');
    $assert($raw['created_by_id'] === $draft['created_by_id'] && $raw['document_no'] === $draft['document_no'], 'Noncreator deletion preserves original identity');
    $assert((int)$raw['audit_revision'] === (int)$draft['audit_revision']+1, 'Delete increments one resource revision');
    $assert(Db::name('store_fund_document_line')->where('document_id',$id)->order('line_no')->select()->toArray() === $draft['lines'], 'Deleted detail amounts and snapshots retained');
    $assert((int)$service->listDocuments($scope,$filters)['count'] === (int)$listBeforeDelete['count']-1, 'Deleted row removed from pagination total');
    $assert((int)$service->listDocuments($scope,$filters+['document_status'=>'DELETED'])['count'] === 0, 'Client cannot expose deleted rows via status filter');
    $denied(static function () use ($service,$scope,$id) { $service->detail($scope,$id); }, 'fund_document_not_found');
    foreach ([true,false] as $audited) {
        $denied(static function () use ($service,$scope,$creator,$id,$audited) { $service->audit($scope,$creator,$id,$audited); }, 'fund_document_not_found');
        $denied(static function () use ($service,$scope,$authorized,$id,$audited) { $service->audit($scope,$authorized,$id,$audited); }, 'fund_document_not_found');
    }
    $denied(static function () use ($service,$scope,$creator,$input,$id) { $service->save($scope,$creator,$input+['id'=>$id]); }, 'fund_document_not_editable');
    $denied(static function () use ($service,$scope,$editor,$editInput) { $service->save($scope,$editor,$editInput); }, 'fund_document_not_editable');
    $assert($service->deleteDraft($scope,$creator,$id) === $deleted, 'Deletion retry is idempotent');
    $assert($service->deleteDraft($scope,$deleter,$id) === $deleted, 'Authorized noncreator deletion retry is idempotent');
    $denied(static function () use ($service,$scope,$operator,$id) { $service->deleteDraft($scope,$operator,$id); }, 'fund_document_operator_forbidden');
    $assert((int)Db::name('store_fund_document')->where('id',$id)->value('audit_revision') === (int)$raw['audit_revision'], 'Deletion retry does not advance revision');
    $assert($service->report($scope,$filters) === $reportBeforeDelete, 'Draft deletion does not change report');
    $assert($service->ledger($scope,$filters) === $ledgerBeforeDelete, 'Draft deletion does not change ledger');

    // 真实控制器到数据库的回滚链路：客户端即使伪造身份/权限，仍须先通过服务端能力检查。
    $controllerDocument = $service->save($scope,$creator,$input);
    $controllerId = (int)$controllerDocument['id'];
    $controller->services = $service;
    $requestProperty = new ReflectionProperty($controller, 'request');
    $requestProperty->setAccessible(true);
    $requestProperty->setValue($controller, (new think\Request())->withPost([
        'store_id'=>175,'created_by_id'=>1200,'can_delete_store_documents'=>true,
        'actor'=>['type'=>'STORE_STAFF','id'=>1200,'can_delete_store_documents'=>true],
    ]));
    $app->instance(CashierV3FeatureResolver::class, $fakeResolver);
    try {
        $fakeResolver->features = [];
        $setContext(['type'=>'cashier_v3']);
        $response = $controller->deleteDraft($controllerId)->getData();
        $assert($response['status'] === 400 && $response['data']['code'] === 'fund_document_operator_forbidden', 'Controller rejects forged body without feature');
        $fakeResolver->features = ['cashier.v3.member'];
        foreach ([['type'=>'cashier_v3_delegated'], ['type'=>'cashier_v3','_cashier_v3_delegated'=>1]] as $profile) {
            $setContext($profile);
            $response = $controller->deleteDraft($controllerId)->getData();
            $assert($response['status'] === 400 && $response['data']['code'] === 'fund_document_operator_forbidden', 'Controller rejects delegated readonly deletion');
        }
        $setContext(['type'=>'cashier_v3'], 175);
        $response = $controller->deleteDraft($controllerId)->getData();
        $assert($response['status'] === 400 && $response['data']['code'] === 'fund_document_not_found', 'Controller uses session store instead of request store');
        $assert($service->detail($scope,$controllerId)['document_status'] === 'DRAFT', 'Denied controller requests preserve draft');
        $setContext(['type'=>'cashier_v3']);
        $service->audit($scope,$creator,$controllerId,true);
        $response = $controller->deleteDraft($controllerId)->getData();
        $assert($response['status'] === 400 && $response['data']['code'] === 'fund_document_delete_requires_draft', 'Controller refuses approved document for noncreator');
        $service->audit($scope,$creator,$controllerId,false);
        $response = $controller->deleteDraft($controllerId)->getData();
        $assert($response['status'] === 200 && $response['data'] === ['id'=>$controllerId,'deleted'=>true], 'Controller grants actual authorized noncreator deletion');
        $assert(Db::name('store_fund_document')->where('id',$controllerId)->value('document_status') === 'DELETED', 'Controller deletion persisted inside test transaction');
        $assert((int)Db::name('store_fund_document')->where('id',$controllerId)->value('created_by_id') === 1200, 'Controller never overwrites creator with acting user');
    } finally {
        $app->instance(CashierV3FeatureResolver::class, $resolver);
    }
} finally {
    Db::rollback();
}
$assert(!Db::name('store_fund_document')->where('id',$id)->find(), 'Probe rolled back');
$assert(!Db::name('store_fund_document')->where('id',$controllerId)->find(), 'Controller probe rolled back');
echo json_encode(['result'=>'PASS','checks'=>$checks,'rollback'=>true],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT), PHP_EOL;
