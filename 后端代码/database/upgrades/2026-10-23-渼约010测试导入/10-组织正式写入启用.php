<?php
/** 010 正式组织已迁移并通过完整性校验后，开放产品写入门禁；保留账号权限与旧源禁止写入。 */
function enable010OrganizationWrites(): void
{
    $db=(string)\think\facade\Db::query('SELECT DATABASE() d')[0]['d'];
    $local=is_file('/.dockerenv')&&getenv('ORG010_WRITE_TEST')==='1'&&preg_match('/^test_010_org_[a-f0-9]+$/D',$db);
    if(PHP_SAPI!=='cli'||(!$local&&$db!=='mohe_010_app'))throw new RuntimeException('010_TARGET_REQUIRED');
    $scope=app()->make(\app\services\organization\OrganizationScopeService::class);
    if(!$scope->isUsingOrganizationSource()||empty($scope->validateOrganizationNativeIntegrity(true)['passed']))throw new RuntimeException('VERIFIED_FORMAL_ORGANIZATION_REQUIRED');
    \think\facade\Db::transaction(function(){
        $names=['organization_workspace_write_enabled'=>1,'organization_workspace_write_allow_legacy'=>0];
        if(\think\facade\Db::name('system_config')->whereIn('menu_name',array_keys($names))->lock(true)->count())throw new RuntimeException('WRITE_CONFIG_BASELINE_CHANGED');
        foreach($names as $name=>$value)\think\facade\Db::name('system_config')->insert(['menu_name'=>$name,'value'=>json_encode($value),'type'=>'text','status'=>1,'is_store'=>0]);
    });
}
