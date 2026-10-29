<?php
declare(strict_types=1);
namespace app\services\report;
use think\facade\Db;

/**
 * 启用实时工资前的已保存月表，只读金额/提点/人工项，不调用业务指标或工资公式。
 * 月份与门店取原保存记录；姓名/位置缺少保存名称时只用档案补充展示，不据此重算金额。
 */
final class SavedSalaryReportReadServices
{
    private $transaction;
    public function __construct($transaction){$this->transaction=$transaction;}

    public function read(array $stores,array $range,int $employee,array $fields):array
    {
        $month=substr($range['start'],0,7);
        $tables=['salary_info','system_store_staff','system_store','position','position_level','salary_freeze'];
        return $this->transaction->run(function()use($stores,$range,$employee,$fields,$month):array{
            $q=Db::name('salary_info')->alias('h')->leftJoin('system_store_staff ss','ss.id=h.staff_id')
                ->leftJoin('system_store st','st.id=h.store_id')
                ->leftJoin('position p','p.id=h.position_id')
                ->leftJoin('position current_p','current_p.id=ss.position')
                ->leftJoin('position_level l','l.id=ss.position_level')
                ->whereIn('h.store_id',$stores)->where('h.date',strtotime($month.'-01 Asia/Shanghai'))
                // 沿原工资表的计薪资格过滤；档案已删除的真实保存记录不凭空改为现任员工。
                ->where(function($staff){$staff->where('ss.salary_status',1)->whereOrRaw('ss.id IS NULL');});
            if($employee>0)$q->where('ss.employee_id',$employee);
            $saved=$q->field('h.*,ss.employee_id,ss.staff_name,st.name store_name,p.name saved_position_name,current_p.name current_position_name,l.name position_level_name')
                ->order('h.position_id')->order('h.id','desc')->select()->toArray();
            $rows=[];$gaps=[];$savedAt=0;
            foreach($saved as$record){
                // 历史工资门店必须能由已授权保存记录明确关联，不能让同店后续行空白或猜名称。
                if(trim((string)($record['store_name']??''))==='')throw new \LogicException('历史工资门店名称缺失，无法完整展示');
                $values=json_decode((string)$record['salary_info'],true);
                $valid=is_array($values)&&($values===[]||array_keys($values)!==range(0,count($values)-1));
                $row=['row_id'=>'saved_salary:'.$record['id'],'saved_salary_id'=>(int)$record['id'],
                    'month'=>$month,'store_id'=>(int)$record['store_id'],'employee_id'=>(int)($record['employee_id']??0),
                    'store_name'=>(string)($record['store_name']??''),
                    'employee_name'=>(string)($values['xingming']??$record['staff_name']??('历史员工 #'.$record['staff_id'])),
                    'position_name'=>(string)($values['zhiwu']??$record['saved_position_name']??$record['current_position_name']??''),
                    'position_level_name'=>(string)($values['zhiji']??$record['position_level_name']??''),
                    'calculation_status'=>'历史保存工资','editable'=>false,'manual_version'=>0,'manual_values'=>[],
                    'commission_details'=>[],'gaps'=>[],'rule_details'=>[]];
                foreach($fields as$field){
                    $key=$field['key'];
                    if(array_key_exists($key,$row))continue;
                    if($field['type']==='text'){
                        if(in_array($key,['xiao_commission','product_commission','work_commission'],true))
                            $row[$key]=$valid?$this->rate($values[$key]??null):null;
                        else $row[$key]=$valid?(string)($values[$key]??''):'';
                        continue;
                    }
                    $value=$valid?($values[$key]??null):null;
                    // 旧人工项未填写视为空/0，直接展示；绝不重算原应发、实发或提成。
                    if(($value===null||$value==='')&&isset(BasicSalaryManualServices::FIELDS[$key]))$value=0;
                    if(is_int($value)||is_float($value)||(is_string($value)&&preg_match('/^-?\d+(?:\.\d+)?$/D',$value))){
                        $row[$key]=(float)$value;
                        if(!is_finite($row[$key]))$row[$key]=null;
                    }else $row[$key]=null;
                    if($row[$key]===null&&!empty($field['defaultVisible'])){
                        $gap=['field'=>$key,'code'=>'SAVED_SALARY_VALUE_UNAVAILABLE','message'=>$field['label'].'未保存有效结果'];
                        $row['gaps'][]=$gap;$gaps[]=['row_id'=>$row['row_id']]+$gap;
                    }
                }
                if(!$valid){
                    $gap=['field'=>'yingfagongzi','code'=>'SAVED_SALARY_INVALID','message'=>'原保存工资内容不完整，不能改用实时试算'];
                    $row['gaps'][]=$gap;$gaps[]=['row_id'=>$row['row_id']]+$gap;
                }
                $savedAt=max($savedAt,(int)($record['cmd_time']??0));$rows[]=$row;
            }
            $sumFields=[];
            foreach($fields as&$field){
                $field['editable']=false;
                if(!$rows)$field['summable']=false;
                if($field['summable']){
                    foreach($rows as$row)if(($row[$field['key']]??null)===null){$field['summable']=false;break;}
                    if($field['summable'])$sumFields[]=$field['key'];
                }
            }
            unset($field);
            $sources=[];
            foreach($fields as$field)$sources[$field['key']]=in_array($field['key'],['month','store_name','employee_name','position_name','position_level_name'],true)
                    ?'月份、门店取当月保存记录，姓名和位置仅关联档案展示，不参与历史工资重算。'
                    :'读取当月已保存的'.$field['label'].'；不按当前业绩、职位、提点或公式重新计算。';
            $frozen=Db::name('salary_freeze')->where('date',strtotime($month.'-01 Asia/Shanghai'))->column('is_freeze');
            if(count($frozen)>1)throw new \RuntimeException('同月冻结记录重复，请核对');
            return ['rows'=>$rows,'fields'=>$fields,'field_sources'=>$sources,'sum_fields'=>$sumFields,
                'source_kind'=>'saved_salary_snapshot','salary_readonly'=>true,'editable'=>false,
                'salary_state'=>['month'=>$month,'frozen'=>$frozen&&(int)$frozen[0]===1,'version'=>0],
                'complete'=>$gaps===[],'gaps'=>$gaps,'coverage_start'=>null,
                'coverage'=>$rows?'历史工资：读取当月已保存结果，仅查询、导出，不重新统计。'
                    :'该月尚无已保存工资；请先导入原工资表，本表不会改用实时试算。',
                'date_range'=>$range,'data_as_of'=>date('c'),'saved_at'=>$savedAt>0?date('c',$savedAt):null,
                'realtime_start_date'=>\app\services\payroll\SalaryStatisticsSourcePolicy::realtimeStart(),
                'metric_version'=>'saved-month-salary-v1','limitations'=>['历史工资只读，不能编辑或冻结','按已保存整月结果展示，不按查询截止日重新统计'],
                'as_of_semantics'=>'当月完整保存结果，不按截止日或当前规则重算'];
        },$tables);
    }

    /** 原工资提点保存为小数比例；这里只格式化为原界面的百分比，不参与工资计算。 */
    private function rate($value):?string
    {
        if(is_string($value)&&preg_match('/^-?\d+(?:\.\d+)?%$/D',$value))return $value;
        if(!is_int($value)&&!is_float($value)&&!is_string($value))return null;
        if(!preg_match('/^-?\d+(?:\.\d+)?$/D',(string)$value))return null;
        $text=rtrim(rtrim(bcmul((string)$value,'100',6),'0'),'.');
        return ($text===''?'0':$text).'%';
    }
}
