<?php
/** 007只读快照2026-10-25：旧008字典停用保留ID，新007字典用新ID，绝不改写历史订单。 */
function import010SourcesFrom007(): array
{
    $db=(string)\think\facade\Db::query('SELECT DATABASE() d')[0]['d'];
    $local=is_file('/.dockerenv')&&getenv('SOURCE010_TEST')==='1'&&preg_match('/^test_010_source_[a-f0-9]+$/D',$db);
    if(PHP_SAPI!=='cli'||(!$local&&$db!=='mohe_010_app'))throw new RuntimeException('010_TARGET_REQUIRED');
    $expected=['A老客首护','B导购引流','C老带新客','D线上引流','E地推拓客','F美带新客','G异业收客','H沉睡唤醒','K退款顾客','顾客老带新','顾客退款','合作方','自家导购','抖音','美团','觉知','睡眠激活'];
    $source=[[1,'大众',1],[2,'抖音',1],[3,'自然进店',1],[4,'小红书',1],[5,'转介绍',1],[6,'老客续卡',1],[7,'旧卡录入',0],[8,'秒杀',0],[9,'砍价',0],[10,'拼团',0],[11,'售后客',1],[12,'其他',0]];
    \think\facade\Db::execute('CREATE TABLE IF NOT EXISTS codex_010_source007_audit (id INT PRIMARY KEY,before_json LONGTEXT NOT NULL,mapping_json LONGTEXT NOT NULL,applied_at BIGINT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    return \think\facade\Db::transaction(function()use($expected,$source){
        if(\think\facade\Db::table('codex_010_source007_audit')->lock(true)->count())throw new RuntimeException('ALREADY_APPLIED');
        $before=\think\facade\Db::name('cashier_v3_business_source')->order('id')->lock(true)->select()->toArray();
        if(count($before)!==17)throw new RuntimeException('BASELINE_CHANGED');
        foreach($before as $i=>$r)if((int)$r['id']!==$i+1||$r['name']!==$expected[$i])throw new RuntimeException('BASELINE_CHANGED');
        $now=time();\think\facade\Db::name('cashier_v3_business_source')->whereIn('id',array_column($before,'id'))->update(['status'=>0,'version'=>\think\facade\Db::raw('version + 1'),'updated_at'=>$now]);
        $map=[];foreach($source as $r){
            $id=\think\facade\Db::name('cashier_v3_business_source')->insertGetId(['parent_id'=>0,'name'=>$r[1],'status'=>$r[2],'is_fixed'=>0,'sort'=>$r[0],'require_secondary'=>0,'attribution_type'=>'other','version'=>1,'created_at'=>$now,'updated_at'=>$now]);
            $map[]=['source007Id'=>$r[0],'target010Id'=>$id,'name'=>$r[1],'status'=>$r[2]];
        }
        \think\facade\Db::table('codex_010_source007_audit')->insert(['id'=>1,'before_json'=>json_encode($before,JSON_UNESCAPED_UNICODE),'mapping_json'=>json_encode($map,JSON_UNESCAPED_UNICODE),'applied_at'=>$now]);
        return $map;
    });
}
