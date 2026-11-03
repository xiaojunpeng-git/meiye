<?php
declare(strict_types=1);
namespace app\services\member;

/**
 * 手机订单在创建时冻结记账分类；真实支付渠道、交易号与余额资金性质不在这里改写。
 * 空标记代表旧单（含升级前待付款单），不能按付款日期或当前开关追溯重分类。
 */
final class MemberOrderAccounting
{
    public const CODE = 'third_party';
    public const NAME = '第三方支付';

    /** 只接受控制器写入的可信会员端来源，不能从会员档案/客户端字段推断收银身份。 */
    public static function forChannel(string $channel): string
    {
        return in_array($channel, ['h5', 'wechat', 'routine', 'app'], true) ? self::CODE : '';
    }

    public static function isThirdParty(array $order): bool
    {
        return ($order['accounting_method'] ?? '') === self::CODE;
    }

    /** 收款代码只用于外部实际到账；余额必须仍走 balance_payment，不得产生收款事实。 */
    public static function collectionMethod(array $order, string $originalChannel): string
    {
        return self::isThirdParty($order) ? self::CODE : $originalChannel;
    }

    /**
     * 订单列表、明细和导出共用记账展示。分类从创建冻结字段取值；余额仍明确标为
     * member_balance，数额和现金业绩完全沿用权威结算结果，零元单不制造收款行。
     */
    public static function project(array $mapped, array $order, string $actualChannel = ''): array
    {
        if (!self::isThirdParty($order)) return $mapped;
        $mapped['accountingMethodCode'] = self::CODE;
        $mapped['paymentMethod'] = $mapped['payment_method'] = self::NAME;
        foreach ($mapped['paymentDetails'] ?? [] as $index => $payment) {
            $balance = ($payment['paymentMethodCode'] ?? '') === 'member_balance';
            $mapped['paymentDetails'][$index]['methodName'] = self::NAME;
            $mapped['paymentDetails'][$index]['accountingMethodCode'] = self::CODE;
            $mapped['paymentDetails'][$index]['fundsNature'] = $balance ? 'member_balance' : 'external_collection';
            $mapped['paymentDetails'][$index]['actualPaymentChannel'] = $balance ? 'balance' : $actualChannel;
        }
        return $mapped;
    }

}
