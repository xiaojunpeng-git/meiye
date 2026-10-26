<?php

namespace app\services\query\provider;

use app\services\query\UnifiedQueryPageRegistrar;
use app\services\query\UnifiedQueryPageRegistry;

class MemberUnifiedQueryPageRegistrar implements UnifiedQueryPageRegistrar
{
    public function register(UnifiedQueryPageRegistry $registry): void
    {
        $registry->registerPage(MemberUnifiedQueryProvider::PAGE_CODE, '会员列表', [
            UnifiedQueryPageRegistry::field('member_name', '会员姓名', 'text', true, true),
            UnifiedQueryPageRegistry::field('phone', '完整手机号', 'text', true, true),
            UnifiedQueryPageRegistry::field('member_no', '会员编号', 'text', true, true),
            UnifiedQueryPageRegistry::field('birthday', '生日', 'date', true, false),
            UnifiedQueryPageRegistry::field('birthday_month_day', '生日（月日）', 'text', false, false),
            UnifiedQueryPageRegistry::field('member_status', '会员状态', 'text', true, false),
            UnifiedQueryPageRegistry::field('member_level', '会员等级', 'text', true, false),
            UnifiedQueryPageRegistry::field('member_tag', '会员标签', 'text', true, false),
            UnifiedQueryPageRegistry::field('store', '归属门店', 'text', true, false),
            UnifiedQueryPageRegistry::field('exclusive_service_staff', '专属服务人', 'text', true, false),
            UnifiedQueryPageRegistry::field('account_balance', '账户余额', 'amount', true, false, [], '', true),
            UnifiedQueryPageRegistry::field('active_card_count', '有效卡项数量', 'integer', true, false),
            UnifiedQueryPageRegistry::field('remaining_project_times', '剩余项目次数', 'integer', true, false),
            UnifiedQueryPageRegistry::field('remaining_project_amount', '剩余项目金额', 'amount', true, false),
            UnifiedQueryPageRegistry::field('debt_amount', '欠款金额', 'amount', true, false, [], '', true),
            UnifiedQueryPageRegistry::field('total_consumption_amount', '总消费金额', 'amount', true, false, [], '', true),
            UnifiedQueryPageRegistry::field('visit_count', '到店次数', 'integer', true, false, [], '', true),
            UnifiedQueryPageRegistry::field('latest_purchase_date', '最近购买日期', 'date', true, false),
            UnifiedQueryPageRegistry::field('last_service_staff', '上次服务人员', 'text', true, false),
            UnifiedQueryPageRegistry::field('latest_visit_date', '最近到店日期', 'date', true, false),
            UnifiedQueryPageRegistry::field('created_at', '建档时间', 'datetime', true, false),
        ], 'member_id', [
            'keywordFields' => ['member_name', 'phone', 'member_no'],
            'requiredFeature' => 'cashier.v3.member',
            'exportFeature' => 'cashier.v3.member',
        ]);
    }
}
