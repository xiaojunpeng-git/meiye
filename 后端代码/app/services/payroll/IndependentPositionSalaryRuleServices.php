<?php
declare(strict_types=1);
namespace app\services\payroll;
use think\facade\Db;
use mohe\exceptions\AdminException;

/** 独立职位配置写入口：职位锁防重复，乐观校验拒绝旧表单，审计与配置同事务提交。 */
final class IndependentPositionSalaryRuleServices
{
    public function save(int $id,array $data,int $version,int $operator): int
    {
        if ($id<0 || !is_array($data['cate_ids'] ?? null)) throw new AdminException('来源规则只读或品项分类格式无效');
        // 新模型不再保存职级；旧来源字段只在读取适配时使用。
        unset($data['position_level_id']);
        $ids=array_values(array_unique(array_map('intval',$data['cate_ids'])));sort($ids,SORT_NUMERIC);
        if ($ids && min($ids)<1) throw new AdminException('品项分类无效');
        $data['cate_ids']=implode(',',$ids);
        if (!in_array((int)$data['type'],[1,2,3,4],true) || !in_array((int)$data['range_type'],[1,2],true)
            || !in_array((int)$data['time_type'],[1,2],true) || !in_array((int)$data['has_recharge'],[1,2],true)
            || !in_array((int)$data['status'],[0,1],true)
            || !preg_match('/^(\d+(?:\.\d{1,2})?)-(\d+(?:\.\d{1,2})?)$/D',(string)$data['range'],$range)
            || bccomp($range[1],$range[2],2)>0
            || !preg_match('/^\d+(?:\.\d{1,4})?$/D',(string)$data['commission']) || bccomp((string)$data['commission'],'100',4)>0)
            throw new AdminException('工资规则类型、区间或比例无效');
        return Db::transaction(function()use($id,$data,$version,$operator){
            if (!Db::name('position')->where('id',(int)$data['position_id'])->lock(true)->find()) throw new AdminException('职位不存在');
            $before=$id ? $this->locked($id,$version) : [];
            if ($before && (int)$before['position_id']!==(int)$data['position_id']) throw new AdminException('工资规则不属于当前职位');
            foreach (Db::name('position_salary_rule')->where('position_id',(int)$data['position_id'])->select()->toArray() as $other)
                if ((int)$other['id']!==$id && PositionSalaryRuleResolver::signature($other)===PositionSalaryRuleResolver::signature($data))
                    throw new AdminException('该职位已存在同一区间规则，请编辑已有配置');
            $data['version']=$before ? (int)$before['version']+1 : 1;
            if ($id) Db::name('position_salary_rule')->where('id',$id)->update($data);
            else $id=(int)Db::name('position_salary_rule')->insertGetId($data);
            $this->audit($id,$operator,$before ? 'update' : 'create',$before,$data);
            return $id;
        });
    }

    /** 状态切换和删除同样不能修改继承源；保存版本与弹窗操作使用同一冲突边界。 */
    public function change(int $id,int $version,int $operator,?int $status): void
    {
        if ($id<1 || ($status!==null && !in_array($status,[0,1],true))) throw new AdminException('来源规则只读或状态无效');
        Db::transaction(function()use($id,$version,$operator,$status){
            $before=$this->locked($id,$version);
            if ($status===null) Db::name('position_salary_rule')->where('id',$id)->delete();
            else Db::name('position_salary_rule')->where('id',$id)->update(['status'=>$status,'version'=>$version+1]);
            $this->audit($id,$operator,$status===null ? 'delete' : 'status',$before,$status===null ? [] : ['status'=>$status,'version'=>$version+1]);
        });
    }

    private function locked(int $id,int $version): array
    {
        $row=Db::name('position_salary_rule')->where('id',$id)->lock(true)->find();
        if (!$row || $version<1 || (int)$row['version']!==$version) throw new AdminException('工资规则已变化，请刷新后重试');
        return $row;
    }
    private function audit(int $id,int $operator,string $action,array $before,array $after): void
    {
        Db::name('position_salary_rule_audit')->insert(['rule_id'=>$id,'operator_id'=>$operator,'action'=>$action,
            'before_json'=>json_encode($before,JSON_UNESCAPED_UNICODE),'after_json'=>json_encode($after,JSON_UNESCAPED_UNICODE),'created_at'=>time()]);
    }
}
