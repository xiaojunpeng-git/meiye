<?php
/** 仅将用户指定的008来源字典固定快照承接到010；不改旧cash_source、历史交易或来源使用策略。 */
function import010SourcesFrom008(): int
{
    $db=(string)\think\facade\Db::query('SELECT DATABASE() d')[0]['d'];
    $local=is_file('/.dockerenv')&&getenv('SOURCE010_TEST')==='1'&&preg_match('/^test_010_source_[a-f0-9]+$/D',$db);
    if(PHP_SAPI!=='cli'||(!$local&&$db!=='mohe_010_app'))throw new RuntimeException('010_TARGET_REQUIRED');
    $rows=json_decode('[[1,0,"A老客首护",1,1,0],[2,0,"B导购引流",1,0,1],[3,0,"C老带新客",1,0,2],[4,0,"D线上引流",1,0,3],[5,0,"E地推拓客",1,0,4],[6,0,"F美带新客",1,0,5],[7,0,"G异业收客",1,0,6],[8,0,"H沉睡唤醒",1,0,7],[9,0,"K退款顾客",1,0,8],[10,0,"顾客老带新",0,0,9],[11,0,"顾客退款",0,0,12],[12,5,"合作方",1,0,0],[13,5,"自家导购",1,0,0],[14,4,"抖音",1,0,0],[15,4,"美团",1,0,0],[16,8,"觉知",1,0,0],[17,8,"睡眠激活",1,0,0]]',true);
    return \think\facade\Db::transaction(function()use($rows){
        if(\think\facade\Db::name('cashier_v3_business_source')->lock(true)->count())throw new RuntimeException('EMPTY_SOURCE_BASELINE_REQUIRED');
        // 拒绝已有来源引用，防止同ID被重新解释为008来源。
        foreach(\think\facade\Db::query("SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.columns WHERE table_schema=DATABASE() AND COLUMN_NAME IN ('business_source_primary_id','primary_source_id')") as $c){
            if(\think\facade\Db::table($c['TABLE_NAME'])->where($c['COLUMN_NAME'],'>',0)->count())throw new RuntimeException('EXISTING_SOURCE_REFERENCES_REQUIRE_REVIEW');
        }
        $now=time();foreach($rows as $r)\think\facade\Db::name('cashier_v3_business_source')->insert(['id'=>$r[0],'parent_id'=>$r[1],'name'=>$r[2],'status'=>$r[3],'is_fixed'=>$r[4],'sort'=>$r[5],'require_secondary'=>0,'attribution_type'=>'other','version'=>1,'created_at'=>$now,'updated_at'=>$now]);
        return count($rows);
    });
}
