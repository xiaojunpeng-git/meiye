<?php
namespace app\services\metric;

use app\services\BaseServices;

/**
 * 统一指标字典
 *
 * 用户可见口径（ⓘ 弹窗）只用自然语言，禁止技术字段/类名/SQL/实现细节。
 * 研发侧实现备注放在 dev_*，不得进入 getTooltip。
 * 产品未确认的口径：tooltip 只回「口径说明待产品确认」，禁止自行编造。
 */
class MetricDictionaryServices extends BaseServices
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function getDefinitions(): array
    {
        return [
            [
                'code' => 'cash_performance',
                'name' => '现金业绩',
                'user_ready' => true,
                'summary' => '现金业绩是指客户实际支付并已到账的金额。',
                'include' => '包括但不限于：银联扫码收款、大众验券回款、抖音验券回款、企微收款、银行转账、合作方结算回款、现金收款，以及其他客户实际到账的付款金额。',
                'exclude' => '客户后续消耗储值余额时，不重复计入现金业绩，仅计入耗卡业绩。',
                'timing' => '以客户款项实际到账时间作为现金业绩确认时间。',
                'note' => '',
                'aliases' => ['store_income', '实际收款金额', '门店订单金额', 'target_revenue', '本月业绩', 'PK本月业绩'],
                'dev_source' => 'ValidCashOrderServices::sumStoreCashIncome + oldYeji(type=1)；PK 列 monthYeji 展示名已改为现金业绩',
                'dev_time_field' => 'store_order.add_time',
            ],
            [
                'code' => 'actual_performance',
                'name' => '实际业绩',
                'user_ready' => true,
                'summary' => '实际业绩是指扣除分成款后的现金业绩。',
                'include' => '分成款包括合作方分成、渠道分成、外包分成及其他应扣除的分成款项。',
                'exclude' => '',
                'timing' => '',
                'note' => '计算公式：实际业绩 = 现金业绩 − 分成款。',
                'aliases' => ['实收业绩', '实际完成业绩', '本月实际完成', 'PK实际完成业绩'],
                'dev_source' => 'AgentOrderServices::sumActualPerformanceByStores；PK complete 展示名已改为实际业绩',
                'dev_time_field' => '订单 add_time；分成 staff_yeji.created_time',
                'dev_note' => '实现为逐店 max(0, 店现金−店分成) 再求和，不等于整体封顶',
            ],
            [
                'code' => 'card_consume_performance',
                'name' => '耗卡业绩',
                'user_ready' => true,
                'summary' => '耗卡业绩是指客户消费储值余额时所产生的业绩，即储值金额实际被消耗的金额。',
                'include' => '客户每次使用储值余额支付时，按实际消耗金额计入耗卡业绩。',
                'exclude' => '储值时已计入现金业绩，因此消耗储值余额时不再计入现金业绩。次数卡核销计入消耗业绩，不计入耗卡业绩。',
                'timing' => '以储值余额实际被消耗的时间为准。',
                'note' => '',
                'aliases' => ['耗卡', '储值消耗业绩'],
                'dev_source' => '产品定义已确认；聚合出口待对齐',
                'dev_time_field' => '储值消耗时间',
            ],
            [
                'code' => 'consume_amount',
                'name' => '消耗业绩',
                'user_ready' => true,
                'summary' => '消耗业绩是指客户使用次数卡消费后，根据核销记录对应的核销金额确认的业绩。',
                'include' => '每次核销次数卡时，按对应项目的核销金额计入消耗业绩。',
                'exclude' => '消耗业绩仅统计次数卡的核销金额，不统计储值余额消耗。',
                'timing' => '以次数卡核销时间为准。',
                'note' => '',
                'aliases' => ['消耗金额', '客户消耗金额', '核销订单金额', 'target_consume'],
                'dev_source' => 'ReportServices::activeYeji + oldYeji(type=2)',
                'dev_time_field' => 'store_order_writeoff.add_time',
            ],
            // PK 报表列：产品确认展示名与现金/实际业绩一致（编码保留，便于旧引用）
            [
                'code' => 'pk_month_performance',
                'name' => '现金业绩',
                'user_ready' => true,
                'summary' => '现金业绩是指客户实际支付并已到账的金额。',
                'include' => '包括但不限于：银联扫码收款、大众验券回款、抖音验券回款、企微收款、银行转账、合作方结算回款、现金收款，以及其他客户实际到账的付款金额。',
                'exclude' => '客户后续消耗储值余额时，不重复计入现金业绩，仅计入耗卡业绩。',
                'timing' => '以客户款项实际到账时间作为现金业绩确认时间。',
                'note' => '与「现金业绩」同一口径。',
                'aliases' => ['本月业绩', 'PK本月业绩'],
                'dev_source' => 'YejiPkServices::monthYeji（展示名已改为现金业绩；是否与 sumStoreCashIncome 完全对齐见实现核对）',
            ],
            [
                'code' => 'pk_month_complete',
                'name' => '实际业绩',
                'user_ready' => true,
                'summary' => '实际业绩是指扣除分成款后的现金业绩。',
                'include' => '分成款包括合作方分成、渠道分成、外包分成及其他应扣除的分成款项。',
                'exclude' => '',
                'timing' => '',
                'note' => '计算公式：实际业绩 = 现金业绩 − 分成款。与「实际业绩」同一口径。',
                'aliases' => ['实际完成业绩', '本月实际完成', 'PK实际完成业绩', '实收业绩'],
                'dev_source' => 'YejiPkServices：month − month_fencheng（展示名已改为实际业绩）',
            ],
            [
                'code' => 'new_customer',
                'name' => '新增客户数',
                'user_ready' => true,
                'summary' => '新增客户数是指第一次在系统产生现金业绩有效下单的客户。',
                'include' => '统计期内，客户在本系统的首笔现金业绩有效订单；且该首单发生在当前可查看的门店范围内。',
                'exclude' => '以前已经有过现金业绩有效下单的老客户，即使本期又下单也不计入；旧卡录入、欠款等不计现金业绩的单据不作为首次下单。',
                'timing' => '以该客户在全系统的首次现金业绩有效下单时间为准。',
                'note' => '',
                'aliases' => ['本月新增客户', '新增客户', 'new_month'],
                'dev_source' => 'MerchantCustomerMetricServices::listFirstOrderCustomerUids（唯一出口；订单集合=ValidCashOrderServices 现金业绩）',
                'dev_note' => '产品 2026-07-16：首次下单=现金业绩有效订单；指标人数=count(同一 UID 列表)',
            ],
            [
                'code' => 'reservation_customer',
                'name' => '预约客户数',
                'user_ready' => true,
                'summary' => '预约客户数是指统计期内产生预约的去重客户人数。',
                'include' => '在可查看门店范围内，预约日期落在统计期内的预约单所对应的客户（同一客户只计 1 人）。',
                'exclude' => '已删除的预约单；未绑定客户的预约。',
                'timing' => '以预约日期为准（不是下单创建时间）。',
                'note' => '按客户去重，不是按预约单数累计。',
                'aliases' => ['预约客户'],
                'dev_source' => 'MerchantCustomerMetricServices::listReservationCustomerUids',
                'dev_note' => '方案第十二章：周期内产生预约的去重客户数；reservation_time + DISTINCT uid',
            ],
            [
                'code' => 'service_visit',
                'name' => '服务客次',
                'user_ready' => true,
                'summary' => '服务客次是指统计期内完成服务的总客次。',
                'include' => '劳动服务场景下的客次：同一客户同一天通常计 1 次；游客或「朋友」按实际服务次数计。',
                'exclude' => '不是去重客户人数；也不按预约单数直接累计。',
                'timing' => '以服务业绩归属时间统计。',
                'note' => '与门店目标中的「服务客次」同一套算法。数仓「员工统计」里的「服务次数」与此相同。',
                'aliases' => ['服务次数', '客次'],
                'dev_source' => 'MerchantCustomerMetricServices::countServiceVisits → StaffYejiDao::serviceNum(sum_type=2) 逐店相加',
                'dev_note' => '与 StoreTargetServices metric=service 同口径',
            ],
            [
                'code' => 'reservation_order',
                'name' => '预约单数',
                'user_ready' => true,
                'summary' => '预约单数是指统计期内产生的预约单据数量。',
                'include' => '在可查看门店范围内，预约日期落在统计期内的每一张预约单都计 1 次。',
                'exclude' => '已删除的预约单；不是按客户去重（同一客户多张预约会计多次）。',
                'timing' => '以预约日期为准（不是创建时间）。',
                'note' => '与「预约客户数」不同：这里按单数累计，不去重客户。',
                'aliases' => ['预约订单数'],
                'dev_source' => 'MerchantCustomerMetricServices::countReservationOrders',
                'dev_note' => 'store_reservation_order.is_del=0 + reservation_time 区间 + scope 门店 COUNT(*)',
            ],
            [
                'code' => 'deal_customer',
                'name' => '成交客户数',
                'user_ready' => true,
                'summary' => '成交客户数与后台销售分析里的「新客数」相同，不是「新增客户数」。',
                'include' => '在可查看门店范围内，按销售分析新客数同一套规则统计：达到成交门槛的会员消费，同一客户同一天通常只计 1 次；游客或「朋友」按单计次。多店时按各店结果相加。',
                'exclude' => '未达到成交门槛的消费；与「新增客户数」（系统首次现金业绩有效下单）不是同一口径。',
                'timing' => '以下单时间为准。',
                'note' => '人数按销售分析规则去重计次，名单明细暂不开放下钻。',
                'aliases' => ['成交客户', '销售分析新客数', '成交新客'],
                'dev_source' => 'MerchantCustomerMetricServices::countDealCustomers → ReportServices::sourceOrder(isNew=1,isCount=1) 逐店相加',
                'dev_note' => '与 StoreTargetServices::calcCustomerMetric(new_customer) 同参；detail_developing=true',
            ],
            [
                'code' => 'card_recharge_customer',
                'name' => '开卡充值客户数',
                'user_ready' => true,
                'summary' => '开卡充值客户数是指统计期内办过卡或做过余额充值的去重客户人数。',
                'include' => '在可查看门店范围内：购买卡项（开卡）成功，或余额充值成功的客户；同一客户只计 1 人。系统没有单独的「续卡」业务，续充按充值统计。',
                'exclude' => '未支付、已退款、已删除单据；未绑定客户的单据。',
                'timing' => '以下单时间为准。',
                'note' => '开卡与充值取并集后按客户去重。',
                'aliases' => ['开卡客户', '充值客户'],
                'dev_source' => 'MerchantCustomerMetricServices::listCardRechargeCustomerUids',
                'dev_note' => '充值 order_type=1；开卡 order_type=0+product_type=5；无续卡',
            ],
            [
                'code' => 'visit_customer',
                'name' => '到店客户数',
                'user_ready' => true,
                'summary' => '到店客户数是指统计期内有核销到店记录的去重客户人数。',
                'include' => '在可查看门店范围内，核销时间落在统计期内的到店记录所对应的客户（同一客户只计 1 人）。',
                'exclude' => '未绑定客户的核销记录。',
                'timing' => '以核销到店时间为准。',
                'note' => '按客户去重，不是按核销次数累计。',
                'aliases' => ['到店客户'],
                'dev_source' => 'MerchantCustomerMetricServices::listVisitCustomerUids',
                'dev_note' => 'store_order_writeoff.relation_id + add_time + DISTINCT uid',
            ],
            [
                'code' => 'repurchase_customer',
                'name' => '复购客户数',
                'user_ready' => true,
                'summary' => '复购客户数是指以前已经有过成交、在本统计期内再次成交的去重客户人数。',
                'include' => '历史（本期开始前）至少有一笔符合销售分析成交底的消费，且本期内再次有一笔；同一客户只计 1 人。',
                'exclude' => '本期才第一次成交的客户；未达到成交门槛的消费。',
                'timing' => '以下单时间为准。',
                'note' => '「成交」底与销售分析新客数使用同一套订单门槛，但不按新客计次规则折算人数。',
                'aliases' => ['复购客户'],
                'dev_source' => 'MerchantCustomerMetricServices::listRepurchaseCustomerUids',
                'dev_note' => '历史∩本期；订单底对齐 sourceOrder(isNew=1) 过滤',
            ],
            [
                'code' => 'card_recharge_amount',
                'name' => '开卡充值金额',
                'user_ready' => true,
                'summary' => '开卡充值金额是指统计期内办卡和余额充值产生的现金有效金额合计。',
                'include' => '在可查看门店范围内：购买卡项（开卡）成功，或余额充值成功的单据金额。系统没有单独的「续卡」业务，续充按充值统计。',
                'exclude' => '未支付、已退款、已删除单据；旧卡录入等不计现金业绩的部分；项目与普通产品销售不计入本指标。',
                'timing' => '以下单时间为准。',
                'note' => '与「开卡充值客户数」使用同一批单据，这里按金额合计。明细暂不开放下钻。',
                'aliases' => ['开卡金额', '充值金额'],
                'dev_source' => 'MerchantBusinessSecondaryMetricServices::sumCardRechargeAmount（订单底=buildCardRechargeOrderQuery + ValidCash）',
                'dev_note' => '与开卡充值客户同一订单集合；无续卡',
            ],
            [
                'code' => 'product_income',
                'name' => '产品收入',
                'user_ready' => true,
                'summary' => '产品收入是指统计期内销售产品所产生的现金有效金额。',
                'include' => '在可查看门店范围内，普通销售单中产品类商品行的现金有效金额。',
                'exclude' => '服务项目、卡项、余额充值不计入；未支付、已退款、已删除单据不计。',
                'timing' => '以下单时间为准。',
                'note' => '只统计产品，不包含服务项目。明细暂不开放下钻。',
                'aliases' => ['服务/产品收入', '产品销售收入'],
                'dev_source' => 'MerchantBusinessSecondaryMetricServices::sumProductIncome（c.cash_pay_amount + applyScope + applyHasValidCash，对齐 getStoerProductYeji 行级现金）',
                'dev_note' => '产品确认只要产品收入；禁止用 c.pay_price',
            ],
            [
                'code' => 'refund_amount',
                'name' => '退款金额',
                'user_ready' => true,
                'summary' => '退款金额是指统计期内已成功退给客户的金额合计。',
                'include' => '在可查看门店范围内，退款已完成的实际退款金额。',
                'exclude' => '已取消、已撤销的退款申请；尚未退款成功的单据。',
                'timing' => '以退款完成时间为准。',
                'note' => '明细暂不开放下钻。',
                'aliases' => ['商品退款金额'],
                'dev_source' => 'MerchantBusinessSecondaryMetricServices::sumRefundAmount（refund_type=6、is_cancel=0、is_del=0、refunded_time、refunded_price）',
                'dev_note' => '时间字段必须 refunded_time，不是 add_time',
            ],
            [
                'code' => 'staff_sales_yeji',
                'name' => '销售业绩',
                'user_ready' => true,
                'summary' => '销售业绩是指：普通订单的销售人是自己时，销售业绩分配给自己的金额。',
                'include' => '销售人是自己的普通订单中，按分配规则归到自己名下的销售业绩金额。',
                'exclude' => '销售人不是自己的订单；核销劳动业绩不计入销售业绩。',
                'timing' => '按业绩归属到自己的时间统计。',
                'note' => '',
                'aliases' => ['moneyYeji'],
                'dev_source' => 'SatffYejiServices::staffInfo.moneyYeji',
            ],
            [
                'code' => 'staff_labor_yeji',
                'name' => '劳动业绩',
                'user_ready' => true,
                'summary' => '劳动业绩是指：核销订单的手艺人是自己时，核销业绩分配给自己的金额。',
                'include' => '手艺人是自己的核销订单中，按分配规则归到自己名下的核销业绩金额。',
                'exclude' => '手艺人不是自己的核销；普通订单销售业绩不计入劳动业绩。',
                'timing' => '按核销业绩归属到自己的时间统计。',
                'note' => '',
                'aliases' => ['optionYeji'],
                'dev_source' => 'SatffYejiServices::staffInfo.optionYeji',
            ],
            [
                'code' => 'staff_service_num',
                'name' => '客数',
                'user_ready' => true,
                'summary' => '客数是指：核销订单手艺人是自己时统计的客人数。',
                'include' => '同一个客人同一天只算 1 个客数；若当天该客人由多人服务，则这些人平均分这 1 个客数。',
                'exclude' => '手艺人不是自己的核销不计。',
                'timing' => '按核销当天统计，同一客人一天只计一次后再均分。',
                'note' => '',
                'aliases' => ['service_num'],
                'dev_source' => 'SatffYejiServices::staffInfo.service_num',
            ],
            [
                'code' => 'staff_designated_num',
                'name' => '指定客',
                'user_ready' => true,
                'summary' => '指定客是指：核销订单手艺人是自己，并且分配手艺人时选择了「点客」的客人。',
                'include' => '一个客人算 1；有多少个符合条件的客人就计多少，不平均分。',
                'exclude' => '未选点客的服务（如轮客）不计为指定客；手艺人不是自己的不计。',
                'timing' => '按核销时的点客标记统计。',
                'note' => '',
                'aliases' => ['service_zd'],
                'dev_source' => 'SatffYejiServices::staffInfo.service_zd',
            ],
            [
                'code' => 'staff_commission',
                'name' => '提成',
                'user_ready' => true,
                'summary' => '提成按劳动业绩乘以相对应的提成比例计算。',
                'include' => '本人劳动业绩对应档位/比例算出的提成金额。',
                'exclude' => '不以销售业绩单独计提成（本指标口径为劳动业绩 × 提成比例）。',
                'timing' => '与劳动业绩同一统计时间。',
                'note' => '提成 = 劳动业绩 × 相对应的提成比例。',
                'aliases' => ['service_commission'],
                'dev_source' => 'SatffYejiServices::staffInfo.totalCommission',
            ],
            [
                'code' => 'staff_project_num',
                'name' => '项目数',
                'user_ready' => true,
                'summary' => '项目数是指：核销订单手艺人是自己时，按核销项目统计的数量。',
                'include' => '本人参与核销的项目；同一项目多人服务时，项目数在这些手艺人之间平均分。',
                'exclude' => '手艺人不是自己的核销项目不计。',
                'timing' => '按核销业绩归属时间统计。',
                'note' => '保持系统原有算法，不做销售侧改算。',
                'aliases' => ['project_num'],
                'dev_source' => 'SatffYejiServices::staffInfo.project_num → StaffYejiDao::projectNumFractional',
                'dev_note' => '产品 2026-07-16：项目数按原来的（劳动核销侧）',
            ],
        ];
    }

    public function getByCode(string $code): ?array
    {
        foreach ($this->getDefinitions() as $item) {
            if (($item['code'] ?? '') === $code) {
                return $item;
            }
        }
        return null;
    }

    /**
     * 前端 ⓘ 口径说明（仅自然语言，给一线用户看）
     */
    public function getTooltip(string $code): array
    {
        $item = $this->getByCode($code);
        if (!$item) {
            return [
                'code' => $code,
                'name' => '',
                'user_ready' => false,
                'summary' => '指标未定义',
                'include' => '',
                'exclude' => '',
                'timing' => '',
                'note' => '',
            ];
        }

        $ready = !empty($item['user_ready']);
        if (!$ready) {
            return [
                'code' => $item['code'],
                'name' => $item['name'],
                'user_ready' => false,
                'summary' => '口径说明待产品确认，暂不展示技术说明。',
                'include' => '',
                'exclude' => '',
                'timing' => '',
                'note' => '',
                'updated_at' => date('Y-m-d H:i:s'),
                'version' => '2.0.0',
            ];
        }

        return [
            'code' => $item['code'],
            'name' => $item['name'],
            'user_ready' => true,
            'summary' => (string)($item['summary'] ?? ''),
            'include' => (string)($item['include'] ?? ''),
            'exclude' => (string)($item['exclude'] ?? ''),
            'timing' => (string)($item['timing'] ?? ''),
            'note' => (string)($item['note'] ?? ''),
            'updated_at' => date('Y-m-d H:i:s'),
            'version' => '2.0.0',
        ];
    }
}
