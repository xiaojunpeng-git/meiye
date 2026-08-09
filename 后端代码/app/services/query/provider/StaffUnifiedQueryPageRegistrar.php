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
            ['staff_name', '店员名称', 'text', true, true],
            ['nickname', '昵称', 'text', true, true],
            ['phone', '手机号', 'text', true, true],
            ['roles', '店员身份', 'text', true, false],
            ['position_label', '职位', 'text', true, false],
            ['position_level_label', '职级', 'text', true, false],
            ['is_manager', '店长', 'text', true, false],
            ['cashier_salesperson_enabled', '可作为销售人', 'text', true, false],
            ['cashier_craftsman_enabled', '可作为手艺人', 'text', true, false],
            ['status', '在职状态', 'text', true, false],
            ['is_fencheng', '参与分成', 'text', true, false],
            ['employee_number', '工号', 'text', true, false],
            ['join_date', '入职日期', 'date', true, false],
            ['id_card', '身份证号码', 'text', true, false],
            ['birthday_date', '生日日期', 'date', true, false],
            ['age', '年龄', 'integer', true, false],
            ['join_area', '劳动关系所在地', 'text', true, false],
            ['birthday_area', '籍贯', 'text', true, false],
            ['now_area', '现居地', 'text', true, false],
            ['contract_begin', '合同起始日', 'date', true, false],
            ['contract_end', '合同终止日', 'date', true, false],
            ['uid', '商城用户ID', 'integer', true, false],
            ['account', '账号', 'text', true, false],
            ['has_pwd', '密码', 'text', true, false],
            ['is_customer', '客服', 'text', true, false],
            ['is_reservable', '可被预约', 'text', true, false],
            ['customer_num', '专属客户数', 'integer', true, false],
            ['department', '部门', 'text', true, false],
            ['salary_status', '工资状态', 'text', true, false],
            ['birthday_type', '生日类型', 'text', true, false],
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
                'exportFeature' => 'cashier.v3.management_center',
            ]
        );
    }
}
