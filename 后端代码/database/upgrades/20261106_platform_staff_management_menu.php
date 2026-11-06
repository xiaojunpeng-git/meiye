<?php
declare(strict_types=1);
use think\facade\Db;

/**
 * 将既有人员权限原位移入组织管理：保留ID及岗位规则，只更新目录、名称和路径。
 * 不为岗位补授权；旧父目录的其他功能不变。重复执行无额外写入。
 */
return static function (): array {
    return Db::transaction(static function (): array {
        $people = Db::name('system_menus')->where('unique_auth','setting-staff-index')->where('type',1)->where('is_del',0)->lock(true)->select()->toArray();
        $parents = Db::name('system_menus')->where('unique_auth','admin-organizational-structure')->where('type',1)->where('is_del',0)->lock(true)->select()->toArray();
        if (count($people)!==1 || count($parents)!==1 || (int)$parents[0]['is_show']!==1) throw new RuntimeException('需要唯一且有效的人员菜单和组织管理目录');
        $row=$people[0];$parent=$parents[0];
        if ((int)$row['id']===(int)$parent['id']) throw new RuntimeException('人员菜单与父目录不能相同');
        $path=trim((string)$parent['path'],'/');$path=($path!==''?$path.'/':'').$parent['id'];
        $values=['pid'=>(int)$parent['id'],'menu_name'=>'人员管理','menu_path'=>'/admin/setting/staff/index','path'=>$path,'is_show'=>1,'header'=>$parent['header']];
        $changes=[];foreach($values as$k=>$v)if((string)$row[$k] !== (string)$v)$changes[$k]=$v;
        $oldPrefix=trim((string)$row['path'],'/').'/'.$row['id'];$newPrefix=$path.'/'.$row['id'];$descendants=0;
        // 同步权限子节点的目录路径，但不改子节点身份、操作权限或任何岗位选项。
        if($oldPrefix!==$newPrefix){
            $children=Db::name('system_menus')->where('type',1)->where(function($q)use($oldPrefix){$q->where('path',$oldPrefix)->whereOr('path','like',$oldPrefix.'/%');})->lock(true)->select()->toArray();
            foreach($children as$child){Db::name('system_menus')->where('id',$child['id'])->update(['path'=>$newPrefix.substr($child['path'],strlen($oldPrefix))]);$descendants++;}
        }
        if($changes)Db::name('system_menus')->where('id',$row['id'])->update($changes);
        return ['menu_id'=>(int)$row['id'],'parent_id'=>(int)$parent['id'],'changed_fields'=>array_keys($changes),'descendants_updated'=>$descendants,'grants_changed'=>0];
    });
};
