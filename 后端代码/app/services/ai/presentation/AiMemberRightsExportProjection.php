<?php
namespace app\services\ai\presentation;

use RuntimeException;

/** Minimal immutable export evidence from the very same authorised asset read.
 * No contacts, profile fields, model values, SQL or later re-query enter XLSX.
 * Reuses the shared long-form export columns, worker, precision and cell budget.
 */
final class AiMemberRightsExportProjection
{
    public static function capture(array $members,string $view,array $populationRows=[]): array
    {
        if (!in_array($view,['summary','rights'],true)||!$members||count($members)>100) self::fail();
        // A combined filter-and-detail answer includes its verified selection
        // evidence. Pure follow-ups export only the requested assets.
        $rows=[];$refs=[];
        foreach ($populationRows as $row) {
            $row['row_id']=(string)(count($rows)+1);
            $row['metric_name']='筛选依据：'.$row['metric_name'];
            $rows[]=$row;
        }
        foreach ($members as $member) {
            $detail=$member['detail'];$ref=$member['selection_ref']??null;
            if (!is_string($ref)||!preg_match('/^member:[1-9][0-9]*$/D',$ref)||isset($refs[$ref])) self::fail();
            // The screen renderer is the existing projection contract guard.
            (new AiMemberDetailAnswerRenderer())->render($detail,$member['label'],$view);
            $refs[$ref]=true;
            $date=substr((string)($detail['dataAsOf']??''),0,10);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date)) self::fail();
            $base=['period_name'=>'当前权益','start_date'=>$date,'end_date'=>$date,
                'store_name'=>$member['label'].'；范围：该会员所有门店权益','ranking_direction'=>'','business_date'=>$date];
            foreach (['accountBalance'=>'账户余额','principalBalance'=>'本金余额','giftBalance'=>'赠送余额',
                'activeCardCount'=>'有效卡项','remainingProjectTimes'=>'剩余项目次数','remainingProjectAmount'=>'剩余项目金额'] as $key=>$label) {
                $unit=$key==='activeCardCount'?'张':($key==='remainingProjectTimes'?'次':'元');
                self::append($rows,$base,$label,$detail['summary'][$key],$unit);
            }
            if ($view==='rights') foreach ($detail['cards'] as $card) {
                $label='卡项：'.$card['cardName'].'；状态：'.$card['statusLabel'].'；有效期：'.($card['expiresAt']??'长期有效');
                self::append($rows,$base,$label.'；剩余次数',$card['remainingTimes'],'次');
                self::append($rows,$base,$label.'；剩余金额',$card['remainingAmount'],'元');
            }
        }
        $snapshot=['version'=>1,'member_refs'=>array_keys($refs),'rows'=>$rows];
        $snapshot['hash']=self::hash($snapshot);
        return self::validate($snapshot);
    }

    /** Validate before queue, worker and download; old unsnapshotted answers fail closed. */
    public static function validate($snapshot): array
    {
        if (!is_array($snapshot)||($snapshot['version']??null)!==1||!is_array($snapshot['member_refs']??null)
            ||!$snapshot['member_refs']||count($snapshot['member_refs'])>100||!is_array($snapshot['rows']??null)
            ||!$snapshot['rows']||!is_string($snapshot['hash']??null)||!hash_equals(self::hash($snapshot),$snapshot['hash'])) self::fail();
        $fields=array_keys(\app\services\query\metric\MetricReadViewExportRegistrar::fields());
        foreach ($snapshot['member_refs'] as $ref) if (!is_string($ref)||!preg_match('/^member:[1-9][0-9]*$/D',$ref)) self::fail();
        foreach ($snapshot['rows'] as $i=>$row) {
            if (!is_array($row)||array_diff($fields,array_keys($row))||array_diff(array_keys($row),$fields)
                ||($row['row_id']??null)!==(string)($i+1)) self::fail();
            foreach ($row as $value) if (!is_string($value)) self::fail();
            if (!preg_match('/^-?[0-9]+(?:\.[0-9]{2})?$/D',$row['metric_value'])) self::fail();
        }
        \app\services\query\UnifiedQueryExportTaskServices::assertCellBudget(count($fields),count($snapshot['rows']),false);
        return $snapshot;
    }

    private static function append(array &$rows,array $base,string $name,$value,string $unit): void
    {
        if ($unit==='元') {
            if ((!is_string($value)&&!is_int($value))||!preg_match('/^-?[0-9]+(?:\.[0-9]{1,2})?$/D',(string)$value)) self::fail();
            [$whole,$fraction]=array_pad(explode('.',(string)$value,2),2,'');
            $value=$whole.'.'.str_pad($fraction,2,'0');
        } elseif (!is_int($value)||$value<0) self::fail();
        $rows[]=['row_id'=>(string)(count($rows)+1),'metric_name'=>$name]+$base+['metric_value'=>(string)$value,'unit'=>$unit];
    }
    private static function hash(array $snapshot): string
    { unset($snapshot['hash']);return hash('sha256',\app\services\query\UnifiedQueryJson::encode($snapshot)); }
    private static function fail(): void { throw new RuntimeException('AI_EXPORT_SOURCE_MISMATCH'); }
}
