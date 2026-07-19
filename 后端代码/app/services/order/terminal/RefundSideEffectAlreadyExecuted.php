<?php
declare(strict_types=1);

namespace app\services\order\terminal;

/**
 * 仅表示 refund_side_effect_once 插入唯一键冲突（重复消费）。
 * 业务闭包内其它 1062 不得转换成此异常。
 */
class RefundSideEffectAlreadyExecuted extends \RuntimeException
{
}
