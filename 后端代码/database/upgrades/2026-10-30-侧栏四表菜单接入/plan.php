<?php
declare(strict_types=1);

/**
 * 侧栏四表的菜单准备计划。没有数据库连接或写入，先供固定候选验收与逐实例
 * 发布预检使用；现有角色规则不在此扩大，新叶子可由菜单配置正常授权。
 */
function roundFourSidebarMenuDefinitions(): array
{
    $items=[];
    foreach (['sales_data'=>'销售数据','sales_pk'=>'PK数据','sales_analysis'=>'销售分析','store_efficiency'=>'门店效能'] as $key=>$label) {
        $items[]=['code'=>'basic_'.$key,'title'=>$label,'parent_auth'=>'admin-report',
            'auth'=>'admin-report-basic_'.$key,'path'=>'/report/basic-reports/basic_'.$key,
            'store_feature'=>'cashier.v3.basic_report.basic_'.$key];
    }
    return $items;
}

/** 只比对精确auth；已有叶子保留人工排序，冲突须先解决，不能覆盖用户配置。 */
function roundFourSidebarMenuPlan(array $existing): array
{
    $byAuth=[];
    foreach($existing as $row){
        if(!empty($row['is_del']))continue;
        $key=(string)($row['unique_auth']??'');
        if($key==='')continue;
        if(isset($byAuth[$key]))throw new LogicException('菜单权限身份重复：'.$key);
        $byAuth[$key]=$row;
    }
    $root=$byAuth['admin-report']??null;
    if(!$root)throw new LogicException('缺少平台报表菜单根节点');
    $changes=[];
    foreach(roundFourSidebarMenuDefinitions() as $item){
        $found=$byAuth[$item['auth']]??null;
        if($found){
            if((int)$found['pid']!==(int)$root['id']||(string)$found['menu_path']!==$item['path'])
                throw new LogicException('已有报表菜单位置或路由冲突：'.$item['auth']);
            $changes[]=['action'=>'keep','id'=>(int)$found['id'],'definition'=>$item];
        }else $changes[]=['action'=>'create','parent_id'=>(int)$root['id'],'definition'=>$item];
    }
    return ['changes'=>$changes,'role_rule_changes'=>[],
        'before_sha256'=>hash('sha256',json_encode($existing,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)),
        'execution'=>'仅计划；须在固定版本验收、目标实例授权及备份完成后执行菜单迁移'];
}
