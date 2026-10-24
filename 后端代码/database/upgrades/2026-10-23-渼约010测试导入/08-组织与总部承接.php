<?php
/** 010 一次性迁移候选。调用前须独立完整备份；只在显式 CLI 调用时运行。
 * 先走产品旧组织迁移与正式切源门禁，再把总部旧营业身份归档为组织直属。
 * 原门店/任职主键与历史订单不修改、不删除，不新增门店授权。
 */
use think\facade\Db;
use app\services\organization\OrganizationManageServices;
use app\services\organization\OrganizationMigrateServices;
use app\services\organization\OrganizationScopeService;
use app\services\organization\StaffTenureServices;
use app\services\organization\StaffJobPositionServices;

function migrate010Organization(): array
{
    $db = (string)Db::query('SELECT DATABASE() AS d')[0]['d'];
    $local = is_file('/.dockerenv') && getenv('ORG010_SYNTHETIC_TEST') === '1'
        && preg_match('/^test_010_org_[a-f0-9]+$/D', $db);
    if (PHP_SAPI !== 'cli' || (!$local && $db !== 'mohe_010_app')) throw new RuntimeException('010_TARGET_REQUIRED');
    $require = static function (bool $ok, string $code): void { if (!$ok) throw new RuntimeException($code); };
    $require((int)Db::name('organization')->count() === 0, 'EMPTY_ORGANIZATION_BASELINE_REQUIRED');
    $require((int)Db::name('organization_employee')->count() === 0, 'EMPTY_MEMBERSHIP_BASELINE_REQUIRED');
    $require((int)Db::name('system_config')->where('menu_name','organization_source_mode')->count() === 0, 'UNCHANGED_SOURCE_CONFIG_REQUIRED');
    $storeIds = array_map('intval', Db::name('system_store')->where('is_del',0)->order('id')->column('id'));
    $require($storeIds === [1,2,3,4,6,7,8,9,10,11,31], 'EXPECTED_TEN_STORES_AND_LEGACY_HQ_REQUIRED');
    $expected = [1=>22,2=>23,3=>23,4=>22,6=>23,7=>22,8=>22,9=>23,10=>23,11=>22,31=>19];
    foreach ($expected as $sid=>$rid) {
        $actual = Db::name('system_store')->alias('s')->join('system_region_agent a','a.id=s.region_id')
            ->where('s.id',$sid)->value('a.manage_region_id');
        $require((int)$actual === $rid, 'STORE_REGION_BASELINE_MISMATCH');
    }
    $hq = Db::name('system_store_staff')->where('store_id',31)->where('is_del',0)->where('status',1)->order('id')->select()->toArray();
    $require(array_map('intval',array_column($hq,'id')) === [12,13,14,15,16,17,20,106,141,238,246,267], 'HQ_PERSON_BASELINE_MISMATCH');
    foreach ($hq as $s) {
        $require((int)$s['employee_id'] === (int)$s['id'], 'HQ_PERSON_IDENTITY_MISMATCH');
        $require((int)Db::name('employee')->where('id',$s['employee_id'])->where('status',1)->where('is_del',0)->count()===1,'HQ_PERSON_NOT_ACTIVE');
    }
    $history = Db::name('store_order')->where('store_id',31)->order('id')->select()->toArray();
    $historyHash = hash('sha256',json_encode($history));
    // 必须复用真实迁移对账，不能伪造批次/hash 或跳过旧区域权限映射。
    $migrated = app()->make(OrganizationMigrateServices::class)->migrateFromLegacy(false);
    $require(!empty($migrated['migrate_batch']['reconcile_ok']), 'LEGACY_MIGRATION_NOT_VERIFIED');
    Db::name('system_config')->insert(['menu_name'=>'organization_source_mode','value'=>json_encode('organization'),'type'=>'text','status'=>1]);
    OrganizationScopeService::publishSourceMode('organization');
    $require(app()->make(OrganizationScopeService::class)->isUsingOrganizationSource(), 'SOURCE_CUTOVER_NOT_VERIFIED');

    $manage = app()->make(OrganizationManageServices::class);
    $result = $manage->withOrganizationStructureLock(function () use ($manage,$hq,$require,$historyHash) {
        Db::startTrans();
        try {
            // 切源与承接分阶段提交；部署须暂停 010 写入并完整备份。锁内再次校验任职，拒绝并发漂移。
            $currentHq=Db::name('system_store_staff')->where('store_id',31)->where('is_del',0)->where('status',1)->order('id')->lock(true)->select()->toArray();
            $require($currentHq === $hq, 'HQ_PERSON_CHANGED_DURING_MIGRATION');
            foreach ($hq as $person) {
                $require((int)Db::name('system_store_staff')->where('employee_id',$person['employee_id'])->where('store_id','<>',31)->where('status',1)->where('is_del',0)->count()===0,'HQ_OTHER_ACTIVE_STORE_REQUIRES_REVIEW');
            }
            $root=(int)Db::name('organization')->where('legacy_manage_region_id',19)->value('id');
            $hqOrg=$manage->applySaveOrganization(0,['pid'=>$root,'name'=>'总部','sort'=>30],0,'010数据迁移');
            $jobs=app()->make(StaffJobPositionServices::class);
            $tenure=app()->make(StaffTenureServices::class);
            foreach ($hq as $s) {
                $eid=(int)$s['employee_id'];$sid=(int)$s['id'];$now=time();
                $auth=(int)Db::name('employee')->where('id',$eid)->lock(true)->value('auth_version');
                Db::name('organization_employee')->insert(['org_id'=>$hqOrg,'employee_id'=>$eid,'status'=>1,'source'=>'010_007_hq_conversion','add_time'=>$now,'update_time'=>$now]);
                $oldJobs=$jobs->listActiveJobs($sid);
                // 结束旧营业任职而非员工离职；沿用产品权限撤销、期间记录和审计。
                $tenure->clearCurrentStoreInTx($eid,$sid,31,$auth,['id'=>0,'real_name'=>'010数据迁移'],
                    ['operator_id'=>0,'operator_name'=>'010数据迁移','request_token'=>'010-hq-org-'.$sid]);
                foreach ($oldJobs as $job) {
                    // 原岗位承接为无店直属岗位，不创建任何新的门店任职。
                    Db::name('staff_job_position')->insert(['employee_id'=>$eid,'store_id'=>0,'staff_id'=>0,'position_id'=>(int)$job['position_id'],
                        'status'=>1,'start_time'=>$now,'end_time'=>0,'reason'=>'010总部组织承接','request_token'=>'010-hq-job-'.$job['id'],
                        'operator_name'=>'010数据迁移','add_time'=>$now,'update_time'=>$now]);
                }
                $jobs->projectStaffRoles($sid);
                $jobs->afterEmployeeAuthChanged($eid);
            }
            $oldStore=Db::name('system_store')->where('id',31)->lock(true)->find();
            // 归档历史门店占位记录；原主键及 139 笔历史订单仍保存，后续不可再作为营业门店选择。
            Db::name('system_store')->where('id',31)->update(['is_show'=>0,'is_del'=>1]);
            Db::name('organization_store')->where('store_id',31)->delete();
            Db::name('organization_change_log')->insert(['org_id'=>$hqOrg,'action'=>'legacy_hq_to_organization','target_type'=>'store','target_id'=>31,
                'before_data'=>json_encode(['is_show'=>$oldStore['is_show'],'is_del'=>$oldStore['is_del']]),
                'after_data'=>json_encode(['org_id'=>$hqOrg,'employee_ids'=>array_column($hq,'employee_id'),'history_preserved'=>true]),
                'remark'=>'用户确认总部为组织；历史门店记录归档，订单来源保持不变','operator_name'=>'010数据迁移','add_time'=>time()]);
            OrganizationScopeService::clearIntegrityCache();
            $native=app()->make(OrganizationScopeService::class)->validateOrganizationNativeIntegrity(true);
            $require(!empty($native['passed']), 'FINAL_ORGANIZATION_INTEGRITY_FAILED');
            $require(hash('sha256',json_encode(Db::name('store_order')->where('store_id',31)->order('id')->select()->toArray()))===$historyHash,'HQ_HISTORY_CHANGED');
            Db::commit();
            return ['hq_org_id'=>$hqOrg,'hq_people'=>count($hq),'business_stores'=>10,'history_hash'=>$historyHash];
        } catch (Throwable $e) {Db::rollback();throw $e;}
    });
    OrganizationScopeService::clearCache();
    OrganizationScopeService::clearIntegrityCache();
    return $result;
}
