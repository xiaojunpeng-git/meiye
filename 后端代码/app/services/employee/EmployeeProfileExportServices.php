<?php
declare(strict_types=1);
namespace app\services\employee;

use think\facade\Db;

/** 人员编辑字段导出：仅接收同一列表已授权的当前页，不重新选择人群，不输出认证秘密。 */
final class EmployeeProfileExportServices
{
    public const FIELDS = [
        'employee_id'=>'员工ID','staff_id'=>'任职ID','org_name'=>'所属组织','store_name'=>'当前任职门店',
        'staff_name'=>'员工姓名','phone'=>'手机号码','avatar'=>'员工头像链接','position_names'=>'岗位',
        'cashier_salesperson_enabled'=>'可作为销售人','cashier_craftsman_enabled'=>'可作为手艺人',
        'employment_type_code'=>'人员类型','status'=>'在职状态','scope_mode'=>'数据范围','scope_orgs'=>'可看组织',
        'scope_stores'=>'可看门店','mobile_enabled'=>'手机端','account'=>'登录账号','education'=>'学历',
        'work_member_name'=>'关联企微','is_customer'=>'客服开关','employee_number'=>'工号','id_card'=>'身份证号',
        'age'=>'年龄','join_area'=>'劳动关系所在地','join_date'=>'入职日期','birthday_date'=>'生日日期',
        'contract_begin'=>'合同起始日','contract_end'=>'合同终止日','birthday_type'=>'生日类型',
        'customer_url'=>'客服二维码链接','birthday_area'=>'籍贯','now_area'=>'现居地','salary_status'=>'工资状态',
        'tenure_text'=>'在司工龄','departure_records'=>'离职记录',
    ];

    /** 与编辑弹窗共用完整读取；分页由原人员列表执行，错误不静默跳过员工。 */
    public function page(array $data, bool $includeEmploymentType): array
    {
        $complete=app()->make(EmployeePersonCompleteWriteServices::class);
        $rows=[];
        foreach ($data['list'] ?? [] as $item) {
            $employeeId=(int)($item['employee_id'] ?? 0);
            $staffId=!empty($item['is_organization_direct']) ? 0 : (int)($item['id'] ?? 0);
            if ($employeeId > 0) {
                $detail=$complete->getComplete($employeeId,$staffId,'hq',$includeEmploymentType);
            } else {
                // 未迁入员工主档的历史店员沿用编辑器兼容数据，不借用同编号的其他员工身份。
                $legacy=app()->make(\app\services\store\SystemStoreStaffServices::class)->read($staffId);
                $detail=array_merge($item,(array)($legacy['ps_info'] ?? []),['staff_id'=>$staffId,'employee_id'=>0]);
            }
            $rows[]=$this->project($detail,$includeEmploymentType);
        }
        return ['header'=>array_values(self::FIELDS),'filekey'=>array_keys(self::FIELDS),
            'filename'=>'人员管理完整档案','export'=>$rows,'count'=>(int)($data['count'] ?? 0)];
    }

    /** 固定列白名单防止密码、令牌、权限版本等内部字段随完整详情混入文件。 */
    public function project(array $detail, bool $includeEmploymentType): array
    {
        $row=array_fill_keys(array_keys(self::FIELDS),'');
        foreach ($row as $key=>$_) if(isset($detail[$key]) && is_scalar($detail[$key])) $row[$key]=(string)$detail[$key];
        $scope=(array)($detail['scope'] ?? []);
        $row['scope_mode']=['personal'=>'个人','store'=>'门店','store_selected'=>'指定门店','org'=>'组织','all'=>'全部'][$scope['scope_mode'] ?? ''] ?? '';
        $row['scope_orgs']=$this->names('organization',(array)($scope['org_ids'] ?? []));
        $row['scope_stores']=$this->names('system_store',(array)($scope['store_ids'] ?? []));
        $row['position_names']=implode('、',array_unique(array_filter(array_map(static fn($j)=>(string)($j['position_name'] ?? $j['name'] ?? ''),(array)($detail['jobs'] ?? [])))));
        if(!$row['position_names'])$row['position_names']=(string)($detail['position_label'] ?? '');
        foreach(['cashier_salesperson_enabled','cashier_craftsman_enabled']as$key)$row[$key]=isset($detail[$key])?((int)$detail[$key]===1?'是':'否'):'';
        foreach(['mobile_enabled','is_customer','salary_status']as$key)$row[$key]=isset($detail[$key])?((int)$detail[$key]===1?'开启':'关闭'):'';
        $row['status']=isset($detail['status'])?((int)$detail['status']===1?'在职':'离职'):'';
        $row['employment_type_code']=$includeEmploymentType?(['internal'=>'内部员工','partner'=>'合作方','outsourced'=>'外包'][$detail['employment_type_code'] ?? ''] ?? '未分类'):'无权限查看';
        $row['birthday_type']=[1=>'农历',2=>'新历'][(int)($detail['birthday_type'] ?? 0)] ?? '';
        $workId=(int)($detail['work_member_id'] ?? 0);
        $row['work_member_name']=$workId>0?(string)(Db::name('work_member')->where('id',$workId)->value('name') ?: '#'.$workId):'';
        $row['departure_records']=implode("\n",array_map(static fn($r)=>implode('；',[(string)($r['left_at_text']??''),(string)($r['store_text']??''),(string)($r['operator_name']??''),(string)($r['reason']??'')]),(array)($detail['departure_records']??[])));
        // 身份证、手机号、工号始终作为文本交给 XLSX，保留前导零和长号码精度。
        return $row;
    }

    /** 范围选项使用编辑器保存的稳定ID解析名称，不用前端展示文本推测权限。 */
    private function names(string $table,array $ids): string
    {
        $ids=array_values(array_unique(array_filter(array_map('intval',$ids))));
        if(!$ids)return '';
        $names=Db::name($table)->whereIn('id',$ids)->column('name','id');
        return implode('、',array_map(static fn($id)=>(string)($names[$id]??('#'.$id)),$ids));
    }
}
