<?php
declare(strict_types=1);
namespace app\services\report;

/** 流式明细：只保存当前页和末页，金额合计与去重贯穿全部事实，避免集团下钻复制全部原单。 */
final class HumanEfficiencyDetailCollector
{
    private $input; private $roots; private $count=0; private $pageRows=[]; private $lastRows=[]; private $seen=[];
    private $totals=['cash_cents'=>0,'consume_cents'=>0,'items'=>0,'visits'=>0];
    public function __construct(array $input,array $roots,array $categories)
    {
        $this->input=$input+['metric'=>'cash','category_id'=>0,'page'=>1];$this->roots=$roots;
        if(!in_array($this->input['metric'],['cash','consume','items','visits','active','buyers','orders','people','stores'],true))throw new \InvalidArgumentException('明细指标不正确');
        if((int)$this->input['category_id']&&!in_array((int)$this->input['category_id'],array_column($categories,'id'),true))throw new \InvalidArgumentException('分类不在当前可见范围');
    }
    /** 角色、工龄和稳定行键均由后端实体确定，不接受姓名或分类文案拼条件。 */
    public function matches(array $row):bool
    {
        // 内部管理看板只传入已由范围与单元格核实的稳定人员键，多人合计复用同一去重集合。
        if (($this->input['entity_id'] ?? '') === 'summary:board') return in_array($row['id'], $this->input['entity_ids'] ?? [], true);
        $key=(string)$this->input['entity_id'];
        if($key===$row['id'])return true;
        if($key==='summary:'.($row['role_code']??''))return true;
        return ($row['role_code']??'')==='beauty'&&$key==='summary:cohort:'.($row['tenure_group']??'');
    }
    public function add(array $row,array $fact):void
    {
        if(!$this->matches($row))return;
        $metric=$this->input['metric'];if(in_array($metric,['people','stores'],true))return;
        $amount=(int)($fact['cash_cents']??0);$category=(int)$this->input['category_id'];
        if($category){$amount=0;foreach($fact['sales_category_cents']??[] as $id=>$value)if(($this->roots[$id]??0)===$category)$amount+=(int)$value;}
        if($metric==='cash'&&$amount===0)return;
        if($metric==='consume'&&(int)($fact['consumption_cents']??0)===0)return;
        if($metric==='items'&&(float)($fact['project_count']??0)===0.0)return;
        if($metric==='visits'&&(int)($fact['service_visit_tenths']??0)===0)return;
        if(in_array($metric,['active','buyers','orders'],true)){
            $member=(int)($fact['member_id']??0);
            $identity=$metric==='orders'?($fact['store_id'].':'.((string)($fact['order_id']??'')!==''?$fact['order_id']:($fact['checkout_request_id']??''))):(string)$member;
            if($metric!=='orders'&&$member<=0)return;
            if(isset($this->seen[$identity]))return;
            if(in_array($metric,['buyers','orders'],true)&&$amount<=0)return;
            if($metric==='active'&&(empty($fact['service_fact_id'])||!($fact['active_eligible']??((int)($fact['service_people_tenths']??0)>0))))return;
            $this->seen[$identity]=true;
        }
        $this->push(['id'=>$fact['row_key']??$fact['fact_id']??$fact['id']??'',
            'business_date'=>$fact['business_date'],'store_name'=>$fact['store_name']??'',
            'employee_name'=>$fact['employee_name']??$row['name'],'member_name'=>$fact['member_name']??$fact['member_name_snapshot']??'',
            'document_no'=>$fact['order_no_snapshot']??$fact['checkout_request_id']??$fact['order_id']??'',
            'item_name'=>$fact['item_name']??'','cash_cents'=>$amount,'consume_cents'=>(int)($fact['consumption_cents']??0),
            'items'=>(float)($fact['project_count']??0),'visits'=>(int)($fact['service_visit_tenths']??0)/10]);
    }
    /** 人员/门店小型目录同样经过流式分页，不暴露私有事实集合。 */
    public function push(array $row):void
    {
        $size=$this->input['entity_id']==='summary:board'?20:10;
        $this->count++;$page=(int)ceil($this->count/$size);
        if(($this->count-1)%$size===0)$this->lastRows=[];
        $this->lastRows[]=$row;
        if($page===max(1,(int)$this->input['page']))$this->pageRows[]=$row;
        foreach($this->totals as $key=>$_)$this->totals[$key]+=$row[$key]??0;
    }
    public function result(string $name):array
    {
        $size=$this->input['entity_id']==='summary:board'?20:10;
        $pages=max(1,(int)ceil($this->count/$size));$requested=max(1,(int)$this->input['page']);
        return ['title'=>$name,'rows'=>$requested>$pages?$this->lastRows:$this->pageRows,'total'=>$this->count,
            'page'=>min($pages,$requested),'pages'=>$pages]+$this->totals;
    }
}
