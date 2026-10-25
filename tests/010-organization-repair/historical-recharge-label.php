<?php
// 只验证历史支付快照解读，不连接数据库、不执行任何收款动作。
require '/var/www/html/vendor/autoload.php';
$class = new ReflectionClass(app\services\cashier\v3\order\CashierV3OrderCenterRecordQueryServices::class);
$service = $class->newInstanceWithoutConstructor();
$method = $class->getMethod('rechargePaymentLabel'); $method->setAccessible(true);
$cases = [
    [['recharge_type'=>'cash'], '现金'],
    [['recharge_type'=>'combination','combination_info'=>'[]'], '组合支付'],
    [['recharge_type'=>'combination','combination_info'=>'invalid'], '组合支付'],
    [['recharge_type'=>'bonus_only'], '无收款（仅赠送）'],
    [['combination_info'=>json_encode([['paymentMethod'=>'wechat','name'=>'不能覆盖渠道'],['payment_method'=>'cash']])], '微信、现金'],
    [['recharge_type'=>'combination','combination_info'=>json_encode([['name'=>'银联扫码（记账收款）'],['name'=>'大众验券（记账收款）']])], '银联扫码（记账收款）、大众验券（记账收款）'],
    [['combination_info'=>json_encode([['name'=>'\\u94f6\\u8054\\u626b\\u7801'],['name'=>'\\u5927\\u4f17\\u9a8c\\u5238']])], '银联扫码、大众验券'],
    [['combination_info'=>json_encode([['name'=>'现金'],['name'=>'现金'],null])], '现金'],
    [['combination_info'=>json_encode([['type'=>1,'activePay'=>2]])], '未标注'],
];
foreach($cases as $i=>$case) { $actual=$method->invoke($service,$case[0]); if($actual!==$case[1])throw new RuntimeException('case '.$i.': '.$actual); }
echo 'PASS '.count($cases).' payment-label cases'.PHP_EOL;
