<?php
declare(strict_types=1);
namespace app\services\growth;
use think\facade\Db;
use mohe\exceptions\AdminException;

/** 完整安装契约来自迁移正本；缺表、缺字段、缺唯一键均关闭成长能力，不阻断旧登录。 */
final class GrowthSchemaServices
{
    private $cached=null;

    /** 元数据检查限当前实例；一次请求只检查一次，不能把同名残表当作安装完成。 */
    public function status(): array
    {
        if($this->cached!==null)return $this->cached;
        $expected=require __DIR__.'/schema-manifest.php';$prefix=(string)config('database.connections.mysql.prefix');$names=array_map(static fn($name)=>$prefix.$name,array_keys($expected));$marks=implode(',',array_fill(0,count($names),'?'));
        $columns=Db::query("SELECT TABLE_NAME,GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION SEPARATOR ',') cols FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (".$marks.') GROUP BY TABLE_NAME',$names);
        $indexes=Db::query("SELECT TABLE_NAME,INDEX_NAME,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') cols FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND NON_UNIQUE=0 AND TABLE_NAME IN (".$marks.') GROUP BY TABLE_NAME,INDEX_NAME',$names);
        $actual=[];$keys=[];foreach($columns as $r)$actual[$r['TABLE_NAME']]=explode(',',$r['cols']);foreach($indexes as $r)$keys[$r['TABLE_NAME']][$r['INDEX_NAME']]=$r['cols'];$missing=[];$missingColumns=[];$missingKeys=[];
        foreach($expected as $name=>$contract){$table=$prefix.$name;if(!isset($actual[$table])){$missing[]=$table;continue;}foreach(array_diff($contract['columns'],$actual[$table]) as $column)$missingColumns[]=$table.'.'.$column;foreach($contract['unique'] as $key=>$fields)if(($keys[$table][$key]??'')!==implode(',',$fields))$missingKeys[]=$table.'.'.$key;}
        $count=count($missing)+count($missingColumns)+count($missingKeys);return $this->cached=['ready'=>$count===0,'missing_count'=>$count,'missing_tables'=>$missing,'missing_columns'=>$missingColumns,'missing_unique_keys'=>$missingKeys];
    }

    /** 所有成长写入依赖完整字段及防重复约束，安装缺口只能修迁移，不能静默跳过保护。 */
    public function assertReady(): void
    {
        if(!$this->status()['ready'])throw new AdminException('成长管理升级尚未完成，请补齐迁移字段及唯一约束后启用');
    }
}
