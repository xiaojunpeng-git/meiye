<?php
namespace app\services\ai\presentation;

use RuntimeException;

/** Minimal immutable export evidence from the very same authorised asset read.
 * No contacts, profile fields, model values, SQL or later re-query enter XLSX.
 * Reuses the shared long-form export columns, worker, precision and cell budget.
 */
final class AiMemberRightsExportProjection
{
    /** The two visible tables are generated from the same frozen detail file as the audit rows. */
    public static function streamedTables(array $view): array
    {
        $snapshot=self::validate($view['member_rights_export']??null);
        if ($snapshot['version']!==3) self::fail();
        $population=(new AiAnswerRenderer())->render($view,false);
        $preview=AiExportTableProjection::fromAnswer($population);
        if (count($preview)!==1||$preview[0]['rows']===[]) self::fail();
        $metric=$preview[0]['rows'][0]['metric']??null;
        $unit=$preview[0]['rows'][0]['unit']??null;
        if (!is_string($metric)||!is_string($unit)) self::fail();
        $records=static function()use($snapshot):\Generator {
            yield from (new AiMemberRightsDetailStore())->rows($snapshot['details']);
        };
        $selectionRows=(static function()use($records,$metric,$unit):\Generator {
            foreach ($records() as $record) yield ['label'=>$record['label'],'metric'=>$metric,
                'value'=>number_format(\app\services\query\metric\MetricMoneyFormatter::integerYuan($record['selected_amount_cents']),0,'.',','),
                'unit'=>$unit];
        })();
        $rightsRows=(static function()use($records):\Generator {
            $renderer=new AiMemberDetailAnswerRenderer();
            foreach ($records() as $record) yield $renderer->renderSummaryRow($record);
        })();
        return [
            ['title'=>'会员筛选依据','summary'=>$preview[0]['summary'],
                'columns'=>$preview[0]['columns'],'rows'=>$selectionRows],
            ['title'=>'会员权益汇总','summary'=>$snapshot['rights_table']['summary'],
                'columns'=>$snapshot['rights_table']['columns'],'rows'=>$rightsRows],
        ];
    }

    /** Exact cents and visible rows are siblings of one immutable source, never a later query. */
    public static function streamedAuditRows(array $view): \Generator
    {
        $snapshot=self::validate($view['member_rights_export']??null);
        if ($snapshot['version']!==3) self::fail();
        $range=['start_date'=>$view['query']['start_date'],'end_date'=>$view['query']['end_date']];
        $metric=\app\services\query\metric\MetricReadViewServices::metricCapabilities()['member_remaining_project_amount']['name']??null;
        if (!is_string($metric)||$metric==='') self::fail();
        $rowId=0;
        foreach ((new AiMemberRightsDetailStore())->rows($snapshot['details']) as $record) {
            $detail=$record['detail'];$label=$record['label'];$date=substr((string)($detail['dataAsOf']??''),0,10);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date)) self::fail();
            yield ['row_id'=>(string)++$rowId,'metric_name'=>'筛选依据：'.$metric,
                'period_name'=>'本期']+$range+[
                'store_name'=>$label.'；范围：当前授权范围','ranking_direction'=>'',
                'business_date'=>'','metric_value'=>bcdiv((string)$record['selected_amount_cents'],'100',2),'unit'=>'元'];
            $member=$detail['member'];
            $store=(int)($member['storeId']??0)>0?trim((string)($member['storeName']??'')):'未设置';
            if ($store==='') $store='原门店不可用';
            $base=['period_name'=>'当前权益','start_date'=>$date,'end_date'=>$date,
                'store_name'=>$label.'；所属门店：'.$store.'；范围：该会员所有门店权益',
                'ranking_direction'=>'','business_date'=>$date];
            foreach (['accountBalance'=>'账户余额','principalBalance'=>'本金余额','giftBalance'=>'赠送余额',
                'activeCardCount'=>'有效卡项','remainingProjectTimes'=>'剩余项目次数','remainingProjectAmount'=>'剩余项目金额'] as $key=>$name) {
                $unit=$key==='activeCardCount'?'张':($key==='remainingProjectTimes'?'次':'元');
                yield ['row_id'=>(string)++$rowId,'metric_name'=>$name]+$base+[
                    'metric_value'=>self::exactValue($detail['summary'][$key],$unit),'unit'=>$unit];
            }
        }
    }

    /** A complete file-backed summary uses one verified rights row per member;
     * the screen answer is only a preview and must not be treated as the file.
     */
    public static function captureStreamed(array $detailSnapshot,array $previewAnswer): array
    {
        if (!is_int($detailSnapshot['count']??null)||$detailSnapshot['count']<1) self::fail();
        $tables=AiExportTableProjection::fromAnswer($previewAnswer);
        if (count($tables)!==1) self::fail();
        $table=$tables[0];unset($table['rows']);
        $snapshot=['version'=>3,'details'=>$detailSnapshot,'rights_table'=>$table];
        $snapshot['hash']=self::hash($snapshot);
        return self::validate($snapshot);
    }

    public static function capture(array $members,string $view,array $populationRows=[],?array $rightsAnswer=null): array
    {
        $summaryLimit=\app\services\query\metric\RegisteredMetricReadServices::rightsDetailLimit();
        if (!in_array($view,['summary','rights'],true)||!$members
            ||count($members)>($view==='summary'?$summaryLimit
                :\app\services\query\metric\RegisteredMetricReadServices::AI_DETAIL_LIMIT)) self::fail();
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
            // Identity metadata travels with every exported metric; the
            // entitlement scope remains all stores, not the belonging store.
            $belongingStore=(int)($detail['member']['storeId']??0)>0
                ? trim((string)($detail['member']['storeName']??'')) : '未设置';
            if ($belongingStore==='') $belongingStore='原门店不可用';
            $base=['period_name'=>'当前权益','start_date'=>$date,'end_date'=>$date,
                'store_name'=>$member['label'].'；所属门店：'.$belongingStore.'；范围：该会员所有门店权益',
                'ranking_direction'=>'','business_date'=>$date];
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
        // New answers freeze the same untrimmed rights tables shown on screen.
        // Older retained answers keep their existing v1 long-form export path.
        $snapshot=['version'=>$rightsAnswer===null?1:2,'member_refs'=>array_keys($refs),'rows'=>$rows];
        if ($rightsAnswer!==null) $snapshot['tables']=AiExportTableProjection::fromAnswer($rightsAnswer);
        $snapshot['hash']=self::hash($snapshot);
        return self::validate($snapshot);
    }

    /** Validate before queue, worker and download; old unsnapshotted answers fail closed. */
    public static function validate($snapshot): array
    {
        if (is_array($snapshot) && ($snapshot['version']??null)===3) {
            $details=$snapshot['details']??null;$table=$snapshot['rights_table']??null;
            if (!is_array($details)||!is_string($details['ref']??null)
                ||!preg_match('/^mrd_[a-f0-9]{48}$/D',$details['ref'])
                ||!is_int($details['count']??null)||$details['count']<1
                ||!is_string($details['sha256']??null)||!preg_match('/^[a-f0-9]{64}$/D',$details['sha256'])
                ||!is_array($table)||!is_string($table['title']??null)
                ||!is_string($table['summary']??null)||!is_array($table['columns']??null)
                ||!is_string($snapshot['hash']??null)||!hash_equals(self::hash($snapshot),$snapshot['hash'])) self::fail();
            AiExportTableProjection::validate([$table+['rows'=>[]]]);
            return $snapshot;
        }
        if (!is_array($snapshot)||!in_array($snapshot['version']??null,[1,2],true)||!is_array($snapshot['member_refs']??null)
            ||!$snapshot['member_refs']
            ||count($snapshot['member_refs'])>\app\services\query\metric\RegisteredMetricReadServices::rightsDetailLimit()
            ||!is_array($snapshot['rows']??null)
            ||!$snapshot['rows']||!is_string($snapshot['hash']??null)||!hash_equals(self::hash($snapshot),$snapshot['hash'])) self::fail();
        $fields=array_keys(\app\services\query\metric\MetricReadViewExportRegistrar::fields());
        foreach ($snapshot['member_refs'] as $ref) if (!is_string($ref)||!preg_match('/^member:[1-9][0-9]*$/D',$ref)) self::fail();
        foreach ($snapshot['rows'] as $i=>$row) {
            if (!is_array($row)||array_diff($fields,array_keys($row))||array_diff(array_keys($row),$fields)
                ||($row['row_id']??null)!==(string)($i+1)) self::fail();
            foreach ($row as $value) if (!is_string($value)) self::fail();
            if (!preg_match('/^-?[0-9]+(?:\.[0-9]{2})?$/D',$row['metric_value'])) self::fail();
        }
        if ($snapshot['version']===2) AiExportTableProjection::validate($snapshot['tables']??null);
        \app\services\query\UnifiedQueryExportTaskServices::assertCellBudget(count($fields),count($snapshot['rows']),false);
        return $snapshot;
    }

    private static function append(array &$rows,array $base,string $name,$value,string $unit): void
    {
        $value=self::exactValue($value,$unit);
        $rows[]=['row_id'=>(string)(count($rows)+1),'metric_name'=>$name]+$base+['metric_value'=>$value,'unit'=>$unit];
    }
    private static function exactValue($value,string $unit): string
    {
        if ($unit==='元') {
            if ((!is_string($value)&&!is_int($value))||!preg_match('/^-?[0-9]+(?:\.[0-9]{1,2})?$/D',(string)$value)) self::fail();
            [$whole,$fraction]=array_pad(explode('.',(string)$value,2),2,'');
            $value=$whole.'.'.str_pad($fraction,2,'0');
        } elseif (!is_int($value)||$value<0) self::fail();
        return (string)$value;
    }
    private static function hash(array $snapshot): string
    { unset($snapshot['hash']);return hash('sha256',\app\services\query\UnifiedQueryJson::encode($snapshot)); }
    private static function fail(): void { throw new RuntimeException('AI_EXPORT_SOURCE_MISMATCH'); }
}
