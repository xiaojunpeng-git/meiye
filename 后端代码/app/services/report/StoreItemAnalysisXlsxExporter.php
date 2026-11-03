<?php

namespace app\services\report;

/** 保留门店品项分析的既有分组/冻结布局，XLSX 编码复用统一文件层。 */
class StoreItemAnalysisXlsxExporter
{
    public function render(array $export): string
    {
        $columns=array_values($export['columns']??[]);$groups=$export['column_groups']??[];
        if(!$columns||!$groups)throw new \InvalidArgumentException('门店品项分析导出列定义无效');
        $byKey=[];
        foreach($groups as $group)foreach($group['column_keys']??[] as $key){
            if(isset($byKey[$key]))throw new \InvalidArgumentException('门店品项分析导出列分组重复');
            $byKey[$key]=$group;
        }
        $fixed=0;
        foreach($columns as $index=>$column){
            if(!isset($byKey[$column['key']]))throw new \InvalidArgumentException('门店品项分析导出列分组不完整');
            $basic=($byKey[$column['key']]['tone']??'')==='basic';
            $columns[$index]['_xlsx_numeric']=!$basic;
            if($index===$fixed&&$basic)$fixed++;
        }
        $export['columns']=$columns;
        return (new StoreReportXlsxWriter())->render($export,'门店品项分析',
            ['summary_position'=>'first','guide_header_row'=>3,'fixed_columns'=>$fixed]);
    }
}
