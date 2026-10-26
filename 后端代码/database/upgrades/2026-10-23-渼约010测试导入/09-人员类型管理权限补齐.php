<?php
/** 010 补齐产品既有隐藏权限定义；不改任何角色、账号或人员分类。 */
function repair010EmploymentTypeMenu(): int
{
    $db=(string)\think\facade\Db::query('SELECT DATABASE() d')[0]['d'];
    $local=is_file('/.dockerenv')&&getenv('MENU010_SYNTHETIC_TEST')==='1'&&preg_match('/^test_010_menu_[a-f0-9]+$/D',$db);
    if(PHP_SAPI!=='cli'||(!$local&&$db!=='mohe_010_app'))throw new RuntimeException('010_TARGET_REQUIRED');
    return \think\facade\Db::transaction(function(){
        $parents=\think\facade\Db::name('system_menus')->where('unique_auth','setting-staff-index')->where('type',1)->where('is_del',0)->lock(true)->select()->toArray();
        if(count($parents)!==1||\think\facade\Db::name('system_menus')->where('unique_auth','setting-staff-employment-type')->where('is_del',0)->count())throw new RuntimeException('MENU_BASELINE_MISMATCH_OR_ALREADY_APPLIED');
        $pid=(int)$parents[0]['id'];
        // 与 2026-07-29 正式升级的隐藏权限节点一致，超管自动读取，普通账号仍需角色显式授权。
        return (int)\think\facade\Db::name('system_menus')->insertGetId([
            'pid'=>$pid,'type'=>1,'icon'=>'','menu_name'=>'人员类型管理','module'=>'admin',
            'controller'=>'','action'=>'','api_url'=>'','methods'=>'','params'=>'[]','sort'=>0,
            'is_show'=>0,'is_show_path'=>0,'access'=>1,'menu_path'=>'','path'=>(string)$pid,
            'auth_type'=>2,'header'=>'','is_header'=>0,'unique_auth'=>'setting-staff-employment-type','is_del'=>0
        ]);
    });
}
