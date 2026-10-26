<?php

/**
 * 充值订单的“正常”范围必须与显式“已支付”采用同一有效性约束。
 * 本合同只检查查询分支；真实数据的过滤和作废留痕另由本地只读集成复测验证。
 */
$source = file_get_contents(__DIR__ . '/../../../后端代码/app/services/cashier/v3/order/CashierV3OrderCenterRecordQueryServices.php');
if ($source === false) {
    throw new RuntimeException('无法读取充值订单查询源码');
}

$normalBranch = "\$criteria['status'] === 'paid' || (\$criteria['dataScope'] === 'normal' && \$criteria['status'] === '')";
$start = strpos($source, $normalBranch);
$end = $start === false ? false : strpos($source, "} elseif (\$criteria['status'] === 'voided')", $start);
if ($start === false || $end === false) {
    throw new RuntimeException('正常范围未复用有效充值过滤分支');
}
$branch = substr($source, $start, $end - $start);
foreach (["->where('r.paid', 1)", "->where('r.terminal_action', 0)", "->where('r.refund_price', '<=', 0)", "->whereNotExists(\$this->rechargeLifecycleOperationQuery(\$tenantId, ['refund', 'void']))"] as $guard) {
    if (strpos($branch, $guard) === false) {
        throw new RuntimeException('正常充值过滤缺少条件：' . $guard);
    }
}
echo "recharge normal scope contract PASS\n";
