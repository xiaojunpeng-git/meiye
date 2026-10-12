<?php
declare(strict_types=1);

namespace app\services\cashier\v3\settlement;

use app\services\cashier\v3\CashierV3CommandException;

/** 本次补交的奖励独立于还款本金；统一校验销售/充值补交，不继承原单奖励。 */
final class CashierV3RepaymentReward
{
    /** 输入必须为非负整数分，缺省兼容旧客户端零奖励，禁止小数截断或浮点换算。 */
    public static function cents($value): int
    {
        if ((!is_int($value) && !is_string($value))
            || !preg_match('/^(0|[1-9][0-9]{0,11})$/D', (string)$value)
            || (int)$value > 100000000000) {
            throw CashierV3CommandException::invalidContext('奖励金额无效，请重新输入。', ['reason' => 'repayment_reward_invalid']);
        }
        return (int)$value;
    }

    /** 必须在来源由服务端目录或原单历史快照核定后调用，浏览器名称不能授予奖励资格。 */
    public static function forSource($value, string $authoritativeName): int
    {
        $cents = self::cents($value);
        if ($cents > 0 && !preg_match('/^G(?:\s|异业|$)/u', trim($authoritativeName))) {
            throw CashierV3CommandException::invalidContext('只有异业收客来源可以填写奖励金额。', ['reason' => 'repayment_reward_source_invalid']);
        }
        return $cents;
    }
}
