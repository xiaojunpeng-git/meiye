<?php
declare(strict_types=1);
namespace app\services\report;
use think\facade\Db;

/** 员工看板与人员明细唯一人群口径：后端授权门店内按员工去重，岗位按实际名称精确匹配。 */
final class EmployeeDashboardPopulationServices
{
    public const EDUCATION = ['本科以上','本科','大专','高中','高中以下','其他'];
    public const AGES = ['18-25岁','26-35岁','36-45岁','46-55岁','56岁以上'];
    public const TENURE = ['1年以内','1-3年','3-5年','5-10年','10年以上'];

    /** 当前在职主档与有效任职；资格开关不推定岗位，兼岗保留全部实际岗位。 */
    public function active(array $stores, string $role, string $end): array
    {
        if (!$stores) return [];
        $rows=Db::name('system_store_staff')->alias('ss')->join('employee e','e.id=ss.employee_id')
            ->leftJoin('staff_job_position jp','jp.staff_id=ss.id AND jp.is_del=0 AND jp.status=1 AND jp.end_time=0')
            ->leftJoin('position p','p.id=jp.position_id AND p.status=1')
            ->leftJoin('system_store s','s.id=ss.store_id')
            ->whereIn('ss.store_id',$stores)->where('ss.status',1)->where('ss.is_del',0)->where('e.status',1)->where('e.is_del',0)
            ->field('ss.id staff_id,ss.store_id,ss.employee_id,e.name,e.education,ss.age,ss.birthday_date birthday,ss.join_date entry_time,p.id position_id,p.name position_name,s.name store_name')
            ->order('ss.id')->order('p.id')->select()->toArray();
        $people=[];
        foreach($rows as$row){
            $id=(int)$row['employee_id'];if($id<=0)continue;
            // 多店任职只计一名员工；年龄/工龄沿用授权范围内最小任职ID的档案，列表展示同一代表记录。
            if(!isset($people[$id]))$people[$id]=$row+['positions'=>[],'stores'=>[]];
            if((int)$row['position_id']>0)$people[$id]['positions'][(int)$row['position_id']]=(string)$row['position_name'];
            $people[$id]['stores'][(int)$row['store_id']]=(string)$row['store_name'];
        }
        $out=[];foreach($people as$row){
            if($role!==''&&$role!=='全部岗位'&&!in_array($role,$row['positions'],true))continue;
            $row['profile']=$this->profile($row,$end);$out[]=$row;
        }
        return $out;
    }

    /** 年龄、工龄分档只在这里计算，汇总和下钻共享边界；缺失值不伪造成有效分组。 */
    public function profile(array $row,string $end): array
    {
        $now=strtotime($end.' 23:59:59');$birthday=$this->timestamp($row['birthday']??0);$age=(int)($row['age']??0);
        if($age<=0&&$birthday>0)$age=max(18,(int)date('Y',$now)-(int)date('Y',$birthday)-(date('md',$now)<date('md',$birthday)?1:0));
        $age=$age>0?$age:null;$ageBucket=$age===null?null:($age<=25?0:($age<=35?1:($age<=45?2:($age<=55?3:4))));
        $entry=$this->timestamp($row['entry_time']??0);$years=$entry>0?max(0,($now-$entry)/31557600):null;
        $tenureBucket=$years===null?null:($years<1?0:($years<3?1:($years<5?2:($years<10?3:4))));
        $education=array_search(trim((string)($row['education']??'')),self::EDUCATION,true);if($education===false)$education=5;
        return ['age'=>$age,'age_bucket'=>$ageBucket,'years'=>$years,'tenure_bucket'=>$tenureBucket,'education_bucket'=>$education];
    }

    /** 只读期间 leave 任职结束事实；再入职也保留历史离职事实，调店/停职不计入。 */
    public function departures(array $stores,array $range,string $role): array
    {
        if(!$stores)return [];
        $q=Db::name('staff_tenure_period')->alias('tp')->whereIn('tp.store_id',$stores)->where('tp.is_del',0)->where('tp.status',0)->where('tp.action','leave')
            ->whereBetween('tp.end_time',[strtotime($range['start'].' 00:00:00'),strtotime($range['end'].' 23:59:59')]);
        if($role!==''&&$role!=='全部岗位')$q->join('staff_job_position jp','jp.staff_id=tp.staff_id AND jp.end_time=tp.end_time')->join('position p','p.id=jp.position_id')->where('p.name',$role);
        else $q->leftJoin('staff_job_position jp','jp.staff_id=tp.staff_id AND jp.end_time=tp.end_time')->leftJoin('position p','p.id=jp.position_id');
        $rows=$q->leftJoin('employee e','e.id=tp.employee_id')->leftJoin('system_store_staff ss','ss.id=tp.staff_id')->leftJoin('system_store s','s.id=tp.store_id')
            ->field('tp.employee_id,tp.staff_id,tp.store_id,tp.end_time,e.name,e.education,ss.age,ss.birthday_date birthday,ss.join_date entry_time,s.name store_name,p.id position_id,p.name position_name')
            ->order('tp.end_time','desc')->order('tp.staff_id')->select()->toArray();
        $out=[];foreach($rows as$row){$id=(int)$row['employee_id'];if(!isset($out[$id]))$out[$id]=$row+['positions'=>[],'stores'=>[],'profile'=>$this->profile($row,$range['end'])];$out[$id]['stores'][(int)$row['store_id']]=(string)$row['store_name'];if((int)$row['position_id']>0)$out[$id]['positions'][(int)$row['position_id']]=(string)$row['position_name'];}
        return array_values($out);
    }

    /** 白名单筛选只缩小已授权人群；业务名称由后端映射，前端不得用显示文字推导条件。 */
    public function select(array $people,string $kind,string $bucket=''): array
    {
        if(in_array($kind,['total','active_staff','departures'],true))return $people;
        if($kind==='beautician_count')return array_values(array_filter($people,fn($r)=>in_array('美容师',$r['positions'],true)));
        if(in_array($kind,['education','age','tenure'],true)){
            $labels=$kind==='education'?self::EDUCATION:($kind==='age'?self::AGES:self::TENURE);
            if(!ctype_digit($bucket)||!isset($labels[(int)$bucket]))throw new \InvalidArgumentException('人员分组条件无效');
            return array_values(array_filter($people,fn($r)=>$r['profile'][$kind.'_bucket']===(int)$bucket));
        }
        if($kind==='position'){
            if(!ctype_digit($bucket))throw new \InvalidArgumentException('岗位条件无效');
            return array_values(array_filter($people,fn($r)=>(int)$bucket===0?!$r['positions']:isset($r['positions'][(int)$bucket])));
        }
        throw new \InvalidArgumentException('该指标不支持人员明细');
    }
    private function timestamp($v):int {return is_numeric($v)?((int)$v>1000000000?(int)$v:0):(strtotime((string)$v)?:0);}
}
