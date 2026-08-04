<?php
declare(strict_types=1);

function checkoutFactInput(): array
{
    $common = static function (string $suffix, string $sourceLine): array {
        return [
            'factId' => 'FACT-' . $suffix,
            'naturalKey' => 'checkout:ORDER-9001:' . strtolower($suffix) . ':v1',
            'factVersion' => 1,
            'reversalOf' => '',
            'status' => 'effective',
            'sourceLineId' => $sourceLine,
        ];
    };

    return [
        'contractVersion' => 'cashier-v3-checkout-fact-plan-v1',
        'commandIdempotencyKey' => 'CHECKOUT-00000000-0000-4000-8000-000000009001',
        'context' => [
            'tenantId' => 'TENANT-1',
            'tenantNameSnapshot' => '测试租户',
            'organizationId' => 'ORG-3',
            'organizationNameSnapshot' => '测试组织',
            'organizationPathSnapshot' => '/ROOT/ORG-3/',
            'storeId' => 7,
            'storeNameSnapshot' => '七号门店',
            'memberId' => 501,
            'memberNameSnapshot' => '测试会员',
            'operatorId' => 21,
            'operatorNameSnapshot' => '测试收银员',
            'businessDate' => '2026-07-29',
            'businessTimezone' => 'Asia/Shanghai',
            'occurredAt' => 1785283200,
            'settledAt' => 1785283210,
            'recordedAt' => 1785283211,
            'checkoutRequestId' => 'CKR-9001',
            'orderId' => 'ORDER-9001',
            'orderNoSnapshot' => 'XS-20260729-9001',
            'sourceDocumentType' => 'cashier_checkout',
            'businessEventNo' => 'EVT-CHECKOUT-9001',
        ],
        'saleFacts' => [array_merge($common('SALE-1', 'SALE-LINE-1'), [
            'sourceType' => 'project',
            'itemId' => 'PROJECT-301',
            'itemCodeSnapshot' => 'P-301',
            'itemNameSnapshot' => '面部护理',
            'categoryIdSnapshot' => 'CATEGORY-30',
            'categoryNameSnapshot' => '护理项目',
            'quantity' => 1,
            'originalAmountCents' => 12000,
            'discountAmountCents' => 2000,
            'saleAmountCents' => 10000,
        ])],
        'paymentFacts' => [array_merge($common('PAYMENT-WECHAT', 'PAYMENT-LINE-WECHAT'), [
            'paymentMethod' => 'wechat',
            'paymentAuthorityKey' => 'PAYMENT-AUTH-9001-WECHAT',
            'collectionReference' => 'WX-9001',
            'amountCents' => 10000,
        ])],
        'balanceFacts' => [array_merge($common('BALANCE-1', 'BALANCE-LINE-1'), [
            'balanceChangeType' => 'order_payment',
            'balanceAccountId' => 'BALANCE-501',
            'accountVersion' => 8,
            'principalDeltaCents' => -400,
            'bonusDeltaCents' => -100,
            'principalAfterCents' => 19600,
            'bonusAfterCents' => 900,
        ])],
        'performanceFacts' => [
            array_merge($common('PERF-SALES-INTERNAL', 'SALE-LINE-1'), [
                'performanceType' => 'sales_performance_allocated',
                'employeeId' => 701,
                'employeeNameSnapshot' => '内部销售',
                'employeeTypeSnapshot' => 'internal',
                'employeeTypeAuthorityVersion' => 3,
                'roleSnapshot' => 'salesperson',
                'allocationWeightNumerator' => 4,
                'allocationWeightDenominator' => 5,
                'allocationBaseAmountCents' => 10000,
                'amountCents' => 8000,
                'ruleCodeSnapshot' => 'SALES-WEIGHT',
                'ruleNameSnapshot' => '销售权重分配',
                'ruleVersionSnapshot' => 'v3',
            ]),
            array_merge($common('PERF-SALES-PARTNER', 'SALE-LINE-1'), [
                'performanceType' => 'sales_performance_allocated',
                'employeeId' => 702,
                'employeeNameSnapshot' => '合作销售',
                'employeeTypeSnapshot' => 'partner',
                'employeeTypeAuthorityVersion' => 5,
                'roleSnapshot' => 'salesperson',
                'allocationWeightNumerator' => 1,
                'allocationWeightDenominator' => 5,
                'allocationBaseAmountCents' => 10000,
                'amountCents' => 2000,
                'ruleCodeSnapshot' => 'SALES-WEIGHT',
                'ruleNameSnapshot' => '销售权重分配',
                'ruleVersionSnapshot' => 'v3',
            ]),
            array_merge($common('PERF-ACTUAL', 'SALE-LINE-1'), [
                'performanceType' => 'actual_performance_recorded',
                'employeeId' => 0,
                'employeeNameSnapshot' => '',
                'employeeTypeSnapshot' => '',
                'employeeTypeAuthorityVersion' => 0,
                'roleSnapshot' => 'checkout_result',
                'allocationWeightNumerator' => 1,
                'allocationWeightDenominator' => 1,
                'allocationBaseAmountCents' => 10000,
                'amountCents' => 8000,
                'ruleCodeSnapshot' => 'ACTUAL-EXTERNAL-DEDUCTION',
                'ruleNameSnapshot' => '实际业绩已落事实',
                'ruleVersionSnapshot' => 'v1',
            ]),
            array_merge($common('PERF-CONSUMPTION', 'SERVICE-LINE-1'), [
                'performanceType' => 'consumption_performance_recorded',
                'employeeId' => 0,
                'employeeNameSnapshot' => '',
                'employeeTypeSnapshot' => '',
                'employeeTypeAuthorityVersion' => 0,
                'roleSnapshot' => 'service_result',
                'allocationWeightNumerator' => 1,
                'allocationWeightDenominator' => 1,
                'allocationBaseAmountCents' => 6000,
                'amountCents' => 6000,
                'ruleCodeSnapshot' => 'CONSUMPTION-STANDARD',
                'ruleNameSnapshot' => '项目消耗业绩规则',
                'ruleVersionSnapshot' => 'v4',
            ]),
            array_merge($common('PERF-LABOR', 'SERVICE-LINE-1'), [
                'performanceType' => 'labor_performance_allocated',
                'employeeId' => 801,
                'employeeNameSnapshot' => '内部手艺人',
                'employeeTypeSnapshot' => 'internal',
                'employeeTypeAuthorityVersion' => 2,
                'roleSnapshot' => 'primary_craftsman',
                'allocationWeightNumerator' => 1,
                'allocationWeightDenominator' => 1,
                'allocationBaseAmountCents' => 6000,
                'amountCents' => 6000,
                'ruleCodeSnapshot' => 'LABOR-PRIMARY',
                'ruleNameSnapshot' => '劳动业绩分配',
                'ruleVersionSnapshot' => 'v2',
            ]),
        ],
    ];
}

function checkoutFactReversalInput(): array
{
    $input = checkoutFactInput();
    $input['commandIdempotencyKey'] = 'CHECKOUT-00000000-0000-4000-8000-000000009002';
    $input['context']['businessEventNo'] = 'EVT-CHECKOUT-9001-REVERSAL';
    $input['context']['occurredAt'] = 1785369600;
    $input['context']['settledAt'] = 1785369610;
    $input['context']['recordedAt'] = 1785369611;
    $input['context']['businessDate'] = '2026-07-30';
    $input['saleFacts'] = [];
    $input['balanceFacts'] = [];
    $input['performanceFacts'] = array_values(array_filter(
        $input['performanceFacts'],
        static function (array $row): bool {
            return in_array($row['factId'], ['FACT-PERF-SALES-PARTNER', 'FACT-PERF-ACTUAL'], true);
        }
    ));
    $input['paymentFacts'][0]['factId'] = 'FACT-PAYMENT-WECHAT-REV-1';
    $input['paymentFacts'][0]['naturalKey'] = 'checkout:ORDER-9001:payment-wechat:reversal:v2';
    $input['paymentFacts'][0]['factVersion'] = 2;
    $input['paymentFacts'][0]['reversalOf'] = 'FACT-PAYMENT-WECHAT';
    $input['paymentFacts'][0]['amountCents'] = -10000;
    foreach ($input['performanceFacts'] as &$row) {
        $original = $row['factId'];
        $row['factId'] .= '-REV-1';
        $row['naturalKey'] .= ':reversal:v2';
        $row['factVersion'] = 2;
        $row['reversalOf'] = $original;
        $row['allocationBaseAmountCents'] *= -1;
        $row['amountCents'] *= -1;
    }
    unset($row);
    return $input;
}
