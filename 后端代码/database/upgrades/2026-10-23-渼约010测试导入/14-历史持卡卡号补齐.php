<?php
/**
 * 007旧表没有card_no，010导入后补新系统七位唯一卡号。
 * 仅补空号，不改变会员、来源单、金额、次数、状态；逐卡审计，事务失败全部回滚。
 */
function fill010ImportedCardNumbers(): array
{
    $D='think\\facade\\Db';$db=(string)$D::query('SELECT DATABASE() d')[0]['d'];
    $local=is_file('/.dockerenv')&&getenv('CARDNO010_TEST')==='1'&&preg_match('/^test_010_cardno_[a-f0-9]+$/D',$db);
    if(PHP_SAPI!=='cli'||(!$local&&$db!=='mohe_010_app'))throw new RuntimeException('010_TARGET_REQUIRED');
    $index=$D::query("SHOW INDEX FROM eb_user_card_holder WHERE Key_name='uk_user_card_holder_card_no'");
    if(count($index)!==1||(int)$index[0]['Non_unique']!==0||$index[0]['Column_name']!=='card_no')throw new RuntimeException('CARD_NO_UNIQUE_INDEX_REQUIRED');
    // DDL仅创建独立审计表，不混入事务承诺；业务更新与审计写入从下面同一事务开始。
    $D::execute('CREATE TABLE IF NOT EXISTS codex_010_card_no_audit (holder_id INT UNSIGNED NOT NULL PRIMARY KEY, before_card_no VARCHAR(128) NULL, after_card_no CHAR(7) NOT NULL UNIQUE, unchanged_row_hash CHAR(64) NOT NULL, applied_at INT UNSIGNED NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    return $D::transaction(function()use($D,$local){
        if($D::table('codex_010_card_no_audit')->count())throw new RuntimeException('ALREADY_APPLIED');
        $rows=$D::name('user_card_holder')->order('id')->lock(true)->select()->toArray();
        $missing=array_values(array_filter($rows,fn($r)=>trim((string)$r['card_no'])===''));
        $ids=array_map(fn($r)=>(int)$r['id'],$missing);
        if(!$local&&(count($missing)!==12324||hash('sha256',implode(',',$ids))!=='7550e09bed53d708e220dde0d0ec943ea643724879e23d9461112faf9d998a88'))throw new RuntimeException('MISSING_CARD_BASELINE_CHANGED');
        $hash=function($r){unset($r['card_no']);return hash('sha256',json_encode($r,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));};
        $before=[];foreach($rows as $r)$before[(int)$r['id']]=$hash($r);
        $numbers=app()->make(\app\services\user\CardNumberServices::class);$numbers->clearSchemaCache();
        foreach($missing as $r){
            $id=(int)$r['id'];
            $numbers->withAllocateRetry(function($number)use($D,$r,$id,$before){
                $D::name('user_card_holder')->where('id',$id)->update(['card_no'=>$number]);
                $D::table('codex_010_card_no_audit')->insert(['holder_id'=>$id,'before_card_no'=>$r['card_no'],'after_card_no'=>$number,'unchanged_row_hash'=>$before[$id],'applied_at'=>time()]);
            },null,'010-import-backfill');
        }
        foreach($D::name('user_card_holder')->order('id')->select()->toArray() as $r){
            if(!isset($before[(int)$r['id']])||$before[(int)$r['id']]!==$hash($r))throw new RuntimeException('NON_CARD_NUMBER_FIELD_CHANGED');
            if(in_array((int)$r['id'],$ids,true)&&!$numbers->isValidCardNo($r['card_no']))throw new RuntimeException('CARD_NUMBER_INVALID');
        }
        return ['filled'=>count($missing),'audited'=>(int)$D::table('codex_010_card_no_audit')->count(),'other_fields_unchanged'=>true];
    });
}
