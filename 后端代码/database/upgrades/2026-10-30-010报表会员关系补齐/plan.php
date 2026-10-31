<?php
declare(strict_types=1);
/**
 * 只读计划：补齐已明确属于店4的四名会员缺失关系，不改所属店或任何资产。
 * 本文件没有apply入口。执行器须另行获准，按计划指纹、锁及回滚要求实施。
 */
function report010MemberRelationPlan(array $members, array $relations): array
{
    $expected=[975,6787,9052,9647];$byMember=[];
    foreach($members as $member)$byMember[(int)$member['uid']]=$member;
    $insert=[];$existing=[];$blocked=[];
    foreach($expected as $uid){
        $member=$byMember[$uid]??null;
        if(!$member || (int)$member['belong_store_id']!==4 || (int)$member['status']!==1
            || (int)$member['is_del']!==0 || !in_array((string)($member['delete_time']??''),['','0','0000-00-00 00:00:00'],true)){
            $blocked[]=['uid'=>$uid,'reason'=>'member_scope_or_status_changed'];continue;
        }
        $matching=array_values(array_filter($relations,static function(array $r)use($uid):bool{
            return (int)$r['uid']===$uid&&(int)$r['store_id']===4;
        }));
        if(!$matching)$insert[]=['uid'=>$uid,'store_id'=>4,'status'=>1,'label_id'=>null,'add_time'=>'execution_timestamp'];
        elseif(count($matching)===1&&(int)$matching[0]['status']===1)$existing[]=['uid'=>$uid,'relation_id'=>(int)$matching[0]['id']];
        else $blocked[]=['uid'=>$uid,'reason'=>'existing_relation_inactive_or_duplicate'];
    }
    $frozen=['members'=>$members,'relations'=>$relations];
    return ['target_database'=>'mohe_010_app','target_store'=>4,'business_writes'=>0,
        'before_sha256'=>hash('sha256',json_encode($frozen,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)),
        'insert'=>$insert,'already_present'=>$existing,'blocked'=>$blocked,
        'protected'=>['user.belong_store_id','wallet balances','card holder','entitlement quantities','other store relationships'],
        'execution_requirements'=>[
            'Explicit user authorization for these four relation inserts only.',
            'Transaction REPEATABLE READ; lock the four user rows and their full store_user uid ranges FOR UPDATE, then recheck frozen fingerprint.',
            'No (uid,store_id) unique index exists. Serialize this repair with a named advisory lock; range locks guard concurrent inserts during transaction. Do not add a global index or overwrite existing relations.',
            'Persist before state and planned insert identity before COMMIT; retain inserted primary IDs and exact after fingerprints in receipt.',
            'Retry: one matching active relation is no-op; inactive/duplicate or changed member state blocks.',
            'Rollback deletes only recorded inserted primary IDs if full inserted row fingerprint is unchanged; never delete pre-existing relations or change assets.',
        ]];
}
