<?php

declare(strict_types=1);

namespace app\services\report;

/** 基础报表与对账共用只读导出适配：保留查询快照/权限，只把结果映射到统一 XLSX 列契约。 */
final class BasicReportXlsxExportServices
{
    public function export(array $context,array $payload):array
    {
        if(!empty($payload['unconsumed_task_id']))return (new BasicUnconsumedTaskServices())->exportXlsx($context,$payload);
        $result=BasicReportHostServices::query($context,$payload);
        if(!empty($result['async_report']['pending']))throw new \InvalidArgumentException('报表仍在生成，请完成后再导出');
        $export=self::payload($result,$payload);
        $definition=BasicReportCatalogServices::definition((string)($payload['pageCode']??''));
        $title=(string)($definition['name']??'基础报表');
        $path=tempnam(sys_get_temp_dir(),'basic-report-xlsx-');
        if($path===false)throw new \RuntimeException('报表导出文件创建失败');
        (new StoreReportXlsxWriter())->writeFile($path,$export,$title,['summary_position'=>'last']);
        return ['path'=>$path,'filename'=>$title.'.xlsx'];
    }

    /** 所选列必须来自同一次授权查询；合计、汇总卡片及行说明不在适配层重算。 */
    public static function payload(array $result,array $payload):array
    {
        $requested=$payload['export']??[];
        if(!in_array($requested['scope']??'',['page','query'],true)||!is_array($requested['fields']??null)||!$requested['fields'])throw new \InvalidArgumentException('请选择导出列和有效范围');
        $fieldMap=array_column($result['queryFields']??[],null,'key');$columns=[];$seen=[];
        foreach($requested['fields'] as $key){
            if(!is_string($key)||!isset($fieldMap[$key])||isset($seen[$key]))throw new \InvalidArgumentException('报表导出列无效');
            $seen[$key]=true;$column=$fieldMap[$key];
            $column['source_explanation']=$result['field_sources'][$key]??'该条业务记录的稳定身份。';
            $columns[]=$column;
        }
        $rows=$result['exportRows']??null;
        if(!is_iterable($rows))throw new \InvalidArgumentException('报表完整导出结果不可用');
        $summary=[];
        // 对账主表本身带每种支付方式横向合计，不再叠加纵向总额；其明细仍保留独立合计。
        $includeSummary=($result['table_layout']['summary_position']??'first_row')!=='none';
        if((string)($payload['pageCode']??'')==='basic_store_reconciliation')$includeSummary=!empty($payload['detail']);
        if($includeSummary)foreach($columns as $index=>$column)$summary[$column['key']]=$index===0?'合计':($result['summaries'][$column['key'].':sum']??'-');
        return ['columns'=>$columns,'records'=>$rows,'summary_row'=>$summary,
            'summary_cards'=>$result['summary_cards']??[],'row_sources'=>$result['row_sources']??[],
            'metric_version'=>$result['metric_version']??$result['query_fingerprint']??'-'];
    }
}
