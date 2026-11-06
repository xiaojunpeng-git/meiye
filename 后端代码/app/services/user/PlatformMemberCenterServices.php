<?php
declare(strict_types=1);
namespace app\services\user;

use app\services\cashier\v3\bootstrap\CashierV3Bootstrap;
use app\services\cashier\v3\manifest\CashierV3ActionManifest;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use app\services\cashier\v3\member\CashierV3MemberModule;
use app\services\cashier\v3\cashier\CashierV3EntitlementResourceVersionProvider;
use app\services\cashier\v3\cashier\CashierV3CashierReadinessGuard;
use app\services\cashier\v3\query\UnifiedQueryModule;
use app\services\query\provider\MemberUnifiedQueryContextFactory;
use app\services\query\provider\PlatformMemberUnifiedQueryProvider;
use app\services\query\UnifiedQueryCommandCoordinator;
use app\services\query\UnifiedQueryPreferenceServices;
use app\services\organization\OrganizationScopeService;
use think\facade\Db;
use think\exception\ValidateException;

/** 平台只开放会员页白名单。复用门店权威查询/编辑/档案/事件服务，不开放通用收银命令。 */
class PlatformMemberCenterServices
{
    private const WRITES = ['create-member' => 'create', 'update-member' => 'edit', 'deactivate-member' => 'deactivate', 'restore-member' => 'deactivate'];
    private const READS = ['open-member-editor','open-member-restore','open-member-detail','load-member-detail-tab','query-member-card-history','query-query-entities','query-unified-query-export-task'];

    public function bootstrap(int $adminId): array
    {
        $scope = (new PlatformMemberScopeServices())->build($adminId);
        $ids = array_map('intval', array_column($scope['stores'], 'id'));
        return ['stores' => $scope['stores'], 'permissions' => $scope['permissions'], 'creatorSchema' => CashierV3MemberModule::memberCreatorSchema(), 'tree' => app()->make(OrganizationScopeService::class)->buildPickerTree($ids),
            'stateContextId' => 'platform-member:' . $adminId . ':' . $scope['data']->permissionVersion()];
    }

    public function action(int $adminId, string $action, array $payload): array
    {
        // 本轮明确仅向平台开放余额增减，不增加余额功能权限，也不注册到门店命令网关。
        // 认证身份、会员状态和组织范围由专用应用服务重新校验。
        if (in_array($action, ['open-member-balance-adjustment', PlatformMemberBalanceAdjustmentServices::ACTION], true)) {
            $service = new PlatformMemberBalanceAdjustmentServices();
            $data = $action === 'open-member-balance-adjustment'
                ? ['balanceAdjustment' => $service->read($adminId, $payload)] : $service->adjust($adminId, $payload);
            return ['result' => ['status'=>'success', 'message'=>$action === 'open-member-balance-adjustment' ? '读取成功' : '修改成功'], 'data'=>$data];
        }
        $metadata = UnifiedQueryModule::runtime()['commandCoordinator']->supportedActions();
        $isWrite = isset(self::WRITES[$action]);
        $isMetadata = in_array($action, $metadata, true);
        if (!$isWrite && !$isMetadata && !in_array($action, array_merge(self::READS, ['query-members', 'query-unified-query-capabilities']), true)) throw new ValidateException('平台会员页不支持该操作');
        if (isset($payload['pageCode']) && $payload['pageCode'] !== PlatformMemberUnifiedQueryProvider::PAGE_CODE) throw new ValidateException('查询页面不匹配');
        $operation = self::WRITES[$action] ?? (in_array($action, ['open-member-editor', 'query-query-entities'], true) ? 'edit' : ($action === 'open-member-restore' ? 'deactivate' : 'read'));
        $targetStore = in_array($action, ['create-member','query-query-entities'], true) ? (int)($payload['targetStoreId'] ?? 0) : 0;
        if ($action === 'create-member' && $targetStore <= 0) throw new ValidateException('请先选择新增会员的归属门店');
        if (($isWrite && $action !== 'create-member') || in_array($action, ['open-member-editor','open-member-restore'], true)) {
            // 保留已上线的历史归属兼容；与余额操作复用同一真实门店解析。
            $targetStore = (new PlatformMemberScopeServices())->activeMemberStoreId((int)($payload['memberId'] ?? 0));
        }
        $scope = (new PlatformMemberScopeServices())->build($adminId, $operation, $targetStore);
        $payload['pageCode'] = PlatformMemberUnifiedQueryProvider::PAGE_CODE;
        $runtime = UnifiedQueryModule::runtime();
        $context = (new MemberUnifiedQueryContextFactory($runtime['contextFactory'] instanceof MemberUnifiedQueryContextFactory
            ? \app\services\query\UnifiedQueryRuntime::service('contextFactory') : $runtime['contextFactory']))->make($scope['operator'], $scope['data'], $payload);
        // 筛选只缩小平台授权范围；来源为服务端管理范围，不接受客户端覆盖 trusted scope。
        if (!empty($payload['selectedStoreIds'])) {
            $allowed = array_map('intval', array_column($scope['stores'], 'id'));
            $selected = array_values(array_unique(array_map('intval', (array)$payload['selectedStoreIds'])));
            if (array_diff($selected, $allowed)) throw new ValidateException('查询门店超出当前权限范围');
            $d = $scope['data']; $o = $scope['operator'];
            $narrow = new \app\services\cashier\v3\CashierV3DataScopeContext($d->operatorId(), $d->employeeId(), $o->storeId(), $d->tenantId(), $o->organizationId(), $selected,
                \app\services\cashier\v3\CashierV3DataScopeContext::MODE_STORES, $d->employeeDataScope(), $d->isSuperAdmin(), $d->superAdminBasis(), $d->permissionVersion(), $d->grantedFeatures(), $d->operatorProfile());
            $context = (new MemberUnifiedQueryContextFactory(\app\services\query\UnifiedQueryRuntime::service('contextFactory')))->make($o, $narrow, $payload);
        }
        $dispatcher = CashierV3Bootstrap::dispatcher();
        // 宿主范围/并发/幂等字段由本桥处理，不能污染统一查询 UI 白名单或被保存成筛选。
        $corePayload = $payload;
        unset($corePayload['selectedStoreIds'], $corePayload['targetStoreId'], $corePayload['requestId'], $corePayload['memberRevision'], $corePayload['commandContexts'], $corePayload['idempotencyKey']);
        if ($action === 'query-query-entities') {
            if (($corePayload['entityType'] ?? '') !== 'person') throw new ValidateException('仅支持会员专属服务人选择');
            $corePayload['selectorEntry'] = 'member_exclusive_service_staff';
        }
        $callScope = ['operator_scope' => $scope['operator'], 'data_scope' => $scope['data'], 'operator' => $scope['admin'], 'payload' => $corePayload];
        if ($action === 'query-members') {
            $data = $runtime['providers']->resolve(PlatformMemberUnifiedQueryProvider::PAGE_CODE)->query($context, $corePayload);
        } elseif ($action === 'query-unified-query-capabilities') {
            $version = $this->preferenceVersion($adminId, false);
            $data = ['unifiedQueryCapability' => $runtime['capabilities']->build($context, PlatformMemberUnifiedQueryProvider::PAGE_CODE, $version, time())];
        } elseif ($isWrite || $isMetadata) {
            $result = (new MemberManagementCommandServices())->execute('ADMIN', $adminId, $action, $payload, function () use ($adminId, $action, $payload, $corePayload, $callScope, $dispatcher, $scope, $isMetadata, $runtime, $context): array {
                if ($isMetadata) {
                    // 单管理员行锁把首次偏好创建与并发设置写入串行化，避免空行丢失更新。
                    Db::name('system_admin')->where('id', $adminId)->lock(true)->find();
                    $version = $this->preferenceVersion($adminId, true);
                    $expected = (int)($payload['commandContexts'][0]['expectedVersion'] ?? $payload['commandContexts'][0]['expected_version'] ?? 0);
                    if ($expected !== $version) throw new ValidateException('查询设置已变化，请刷新后重试');
                    $context['query_preference_version'] = $version;
                    return $runtime['commandCoordinator']->dispatch($action, $context, $corePayload);
                }
                $uid = (int)($payload['memberId'] ?? 0);
                if ($uid > 0) {
                    $member = CashierV3MemberModule::findManageableMember((string)$uid, $scope['operator'], $scope['data'], true, $action === 'restore-member');
                    if (!hash_equals($this->revision($member), (string)($payload['memberRevision'] ?? ''))) throw new ValidateException('会员资料已更新，请重新打开编辑后重试');
                }
                $recorder = new CashierV3BusinessEventRecorder();
                $execution = $recorder->newExecution($action, (string)$payload['requestId'], $scope['operator'], $scope['data'], 'platform-member:' . $adminId);
                $contract = CashierV3ActionManifest::eventContractFor($action);
                $handler = $dispatcher->handlers()->requireCommand($action);
                $result = $handler($callScope + ['idempotency_key' => (string)$payload['requestId'], 'event_recorder' => $recorder, 'event_execution' => $execution, 'event_contract' => $contract]);
                $recorder->assertRequiredPersistedInTx($execution, $contract);
                // 同步同一资源提供器，使已打开门店编辑窗也能识别平台的资料变化。
                $changedId = (int)($result['data']['member']['memberId'] ?? $result['data']['member']['id'] ?? $uid);
                if ($changedId > 0 && $action !== 'deactivate-member') (new CashierV3EntitlementResourceVersionProvider(new CashierV3CashierReadinessGuard()))->synchronizeProjectionVersion('member', (string)$changedId, $scope['operator'], $scope['data']);
                return $result;
            });
            $data = (array)($result['data'] ?? []);
        } else {
            $handler = $dispatcher->handlers()->requireProjection($action);
            $result = $handler($callScope);
            $data = (array)($result['data'] ?? []);
            if (in_array($action, ['open-member-editor', 'open-member-restore'], true)) {
                $member = Db::name('user')->where('uid', (int)($payload['memberId'] ?? 0))->find();
                if ($member && isset($data['member'])) {
                    $data['member']['memberRevision'] = $this->revision($member);
                    $data['member']['storeId'] = $targetStore;
                    $data['member']['storeName'] = $targetStore > 0
                        ? (string)Db::name('system_store')->where('id', $targetStore)->value('name') : '';
                }
            }
        }
        // 平台适配器单独维护查询偏好版本，不借用门店全局桥接状态。
        return ['result' => ['status' => 'success', 'message' => '操作完成'], 'data' => $data,
            'versions' => [['kind' => 'query_preference', 'id' => PlatformMemberUnifiedQueryProvider::PAGE_CODE, 'version' => $this->preferenceVersion($adminId, false)]]];
    }

    /** 平台独立页面码隔离偏好与导出；金额/次数读取仍用同一会员 Provider。 */
    private function preferenceVersion(int $adminId, bool $lock): int
    {
        $query = Db::name(UnifiedQueryPreferenceServices::TABLE)->where(['tenant_id' => \app\services\cashier\v3\CashierV3ScopeResolver::TENANT_SCOPE_ID, 'account_id' => $adminId, 'page_code' => PlatformMemberUnifiedQueryProvider::PAGE_CODE]);
        if ($lock) $query->lock(true);
        return max(1, (int)$query->value('current_version'));
    }

    private function revision(array $member): string
    {
        $uid = (int)$member['uid'];
        return (new MemberManagementCommandServices())->fingerprint([$member,
            Db::name('user_label_relation')->where('uid', $uid)->order('id')->select()->toArray(),
            (new \app\services\cashier\v3\member\CashierV3MemberExclusiveSelectionServices())->records($uid)]);
    }

}
