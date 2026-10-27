<?php
declare(strict_types=1);
namespace app\services\payroll;
use think\facade\Db;

/**
 * 独立职位的工资规则适配器。优先本职位规则；来源只从现存任职外键证明，不猜名称或编号。
 * 旧配置只读继承，编辑时复制为目标职位规则；不会修改旧职位及其他级别的工资数据。
 */
final class PositionSalaryRuleResolver
{
    private $identities;
    public function __construct(?array $identities=null) { $this->identities=$identities; }

    /** 新模型配置独立存放，避免编辑学徒规则时意外改变其他职位继承的旧共通规则。 */
    public static function allRules(): array
    {
        $legacy=Db::name('position_yeji')->order('id')->select()->toArray();
        foreach ($legacy as &$rule) { $rule['id']=-(int)$rule['id'];$rule['_legacy']=true; }
        unset($rule);
        $native=Db::name('position_salary_rule')->order('id')->select()->toArray();
        foreach ($native as &$rule) { $rule['_native']=true;$rule['position_level_id']=0; }
        unset($rule);
        return array_merge($legacy,$native);
    }

    /** 来源身份存在多个候选时只保留无职级共通规则，无法证明的部分继续报告缺口。 */
    public function identity(int $position): array
    {
        if ($this->identities===null) {
            $this->identities=[];
            foreach (Db::name('staff_job_position')->alias('j')->join('system_store_staff s','s.id=j.staff_id AND s.employee_id=j.employee_id AND s.store_id=j.store_id')
                ->where('j.status',1)->where('j.is_del',0)->where('j.end_time',0)->where('s.position','>',0)
                ->field('j.position_id,s.position,s.position_level')->select()->toArray() as $row)
                $this->identities[(int)$row['position_id']][(int)$row['position']][(int)$row['position_level']]=true;
        }
        $sources=$this->identities[$position] ?? [];
        if (count($sources)!==1) return ['position_id'=>$position,'level_id'=>0,'ambiguous'=>count($sources)>1];
        $source=(int)array_key_first($sources); $levels=array_keys($sources[$source]);
        $positive=array_values(array_filter($levels,static function($level){return $level>0;}));
        return ['position_id'=>$source,'level_id'=>count($positive)===1 ? (int)$positive[0] : 0,'ambiguous'=>count($positive)>1];
    }

    /** 稳定业务签名允许本职位有意覆盖继承项；状态0的本职位规则也会阻止旧项重新出现。 */
    public static function signature(array $rule): string
    {
        $categories=array_filter(explode(',',(string)$rule['cate_ids']),'strlen');sort($categories,SORT_NUMERIC);
        return implode('|',[(int)$rule['type'],(int)$rule['range_type'],(int)$rule['time_type'],(int)$rule['has_recharge'],implode(',',$categories),trim((string)$rule['range'])]);
    }

    public function resolve(int $position,array $rules,?array $source=null): array
    {
        $source=$source ?? $this->identity($position);$resolved=[];
        if (!empty($source['ambiguous'])) foreach ($rules as $rule) {
            if (empty($rule['_native']) && (int)$rule['position_id']===(int)$source['position_id'] && (int)$rule['position_level_id']>0)
                throw new \DomainException('职位来源存在多个职级候选，需核对工资规则映射');
        }
        foreach ($rules as $rule) {
            if (!empty($rule['_native'])) continue;
            if ((int)$rule['position_id']!==(int)$source['position_id']) continue;
            $level=(int)$rule['position_level_id'];
            if ($level!==0 && $level!==(int)$source['level_id']) continue;
            $rule['inherited']=true;
            $rule['target_position_id']=$position;
            $resolved[self::signature($rule)]=$rule;
        }
        foreach ($rules as $rule) if (!empty($rule['_native']) && (int)$rule['position_id']===$position && (int)$rule['position_level_id']===0) {
            $rule['inherited']=false;$rule['target_position_id']=$position;
            $resolved[self::signature($rule)]=$rule;
        }
        return array_values($resolved);
    }

    /** 按任职有效日组合规则；等价转换合并，同日冲突不任意选一条计薪。 */
    public function forJobs(array $person,array $range,array $rules): array
    {
        $jobs=$person['job_segments'] ?? [$person];$merged=[];
        $zone=new \DateTimeZone('Asia/Shanghai');
        for ($date=new \DateTimeImmutable($range['start'],$zone);$date->format('Y-m-d')<=$range['end'];$date=$date->modify('+1 day')) {
            $day=$date->format('Y-m-d');$signatures=[];
            foreach ($jobs as $job) {
                if ((int)($job['start_time'] ?? 0)>$date->setTime(23,59,59)->getTimestamp()
                    || ((int)($job['end_time'] ?? 0)>0 && (int)$job['end_time']<=$date->getTimestamp())) continue;
                $position=(int)$job['position_id'];
                $source=$position===(int)($person['legacy_position'] ?? 0)
                    ? ['position_id'=>$position,'level_id'=>(int)($person['position_level'] ?? 0),'ambiguous'=>false] : null;
                $applicable=array_values(array_filter($this->resolve($position,$rules,$source),static function($rule){return (int)$rule['status']===1;}));
                $signature=implode(',',array_column($applicable,'id'));
                $signatures[$signature]=true;
                foreach ($applicable as $rule) {
                    $id=(int)$rule['id'];if (!isset($merged[$id])) $merged[$id]=$rule+['_effective_days'=>[]];
                    $merged[$id]['_effective_days'][$day]=$day;
                }
            }
            if (count($signatures)>1) throw new \DomainException('同日存在不同职位工资规则，需核对任职生效时点：'.$day);
        }
        foreach ($merged as &$rule) $rule['_effective_days']=array_values($rule['_effective_days']);
        unset($rule);
        return array_values($merged);
    }
}
