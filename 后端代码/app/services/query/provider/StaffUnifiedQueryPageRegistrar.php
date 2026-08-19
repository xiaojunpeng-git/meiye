<?php

namespace app\services\query\provider;

use app\services\query\UnifiedQueryPageRegistrar;
use app\services\query\UnifiedQueryPageRegistry;

class StaffUnifiedQueryPageRegistrar implements UnifiedQueryPageRegistrar
{
    public function register(UnifiedQueryPageRegistry $registry): void
    {
        $fields = [
            ['staff_id', 'ID', 'integer', false, false],
            ['store_name', '所属门店', 'text', true, false],
            ['staff_name', '员工姓名', 'text', true, true],
            ['nickname', '昵称', 'text', false, true],
            ['phone', '员工手机', 'text', true, true],
            ['roles', '店员身份', 'text', false, false],
            ['position_label', '岗位', 'text', true, false],
            ['position_level_label', '职级', 'text', false, false],
            ['is_manager', '店长', 'text', false, false],
            ['cashier_salesperson_enabled', '可作为销售人', 'text', true, false],
            ['cashier_craftsman_enabled', '可作为手艺人', 'text', true, false],
            ['craftsman_performance_type', '手艺人服务业绩类型', 'text', true, false],
            ['employment_type_code', '人员类型', 'text', true, false],
            ['status', '在职状态', 'text', true, false],
            ['mobile_enabled', '手机端', 'text', true, false],
            ['is_fencheng', '参与分成', 'text', false, false],
            ['employee_number', '工号', 'text', false, false],
            ['join_date', '入职日期', 'date', false, false],
            ['id_card', '身份证号码', 'text', false, false],
            ['birthday_date', '生日日期', 'date', false, false],
            ['age', '年龄', 'integer', false, false],
            ['join_area', '劳动关系所在地', 'text', false, false],
            ['birthday_area', '籍贯', 'text', false, false],
            ['now_area', '现居地', 'text', false, false],
            ['contract_begin', '合同起始日', 'date', false, false],
            ['contract_end', '合同终止日', 'date', false, false],
            ['uid', '商城用户ID', 'integer', false, false],
            ['account', '账号', 'text', false, false],
            ['has_pwd', '密码', 'text', false, false],
            ['is_customer', '客服', 'text', false, false],
            ['is_reservable', '可被预约', 'text', false, false],
            ['customer_num', '专属客户数', 'integer', false, false],
            ['department', '部门', 'text', false, false],
            ['salary_status', '工资状态', 'text', false, false],
            ['birthday_type', '生日类型', 'text', false, false],
        ];
        $definitions = [];
        foreach ($fields as $field) {
            $definitions[] = UnifiedQueryPageRegistry::field(
                $field[0],
                $field[1],
                $field[2],
                $field[3],
                $field[4]
            );
        }
        $registry->registerPage(
            StaffUnifiedQueryProvider::PAGE_CODE,
            '员工列表',
            $definitions,
            'staff_id',
            [
                'keywordFields' => ['staff_name', 'nickname', 'phone'],
                'requiredFeature' => 'cashier.v3.management_center',
                'exportFeature' => 'cashier.v3.staff.export',
            ]
        );
    }
}
