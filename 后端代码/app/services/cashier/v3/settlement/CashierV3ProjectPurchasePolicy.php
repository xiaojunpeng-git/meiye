<?php
declare(strict_types=1);

namespace app\services\cashier\v3\settlement;

use app\services\cashier\v3\CashierV3CommandException;

/** One purchased-project fulfillment decision, frozen with the final checkout. */
final class CashierV3ProjectPurchasePolicy
{
    public const CONFIG_KEY = 'cashier_project_auto_complete_switch';

    /** Missing configuration preserves existing store buy-and-complete behavior. */
    public static function enabled(): bool
    {
        return (int)sys_config(self::CONFIG_KEY, 1) === 1;
    }

    /** Member self-service always defers; store clients cannot override the switch. */
    public static function resolve(bool $memberActor, array $browserSnapshot): bool
    {
        if ($memberActor) return false;
        $enabled = self::enabled();
        if ((!$enabled && !array_key_exists('projectAutoComplete', $browserSnapshot))
            || (array_key_exists('projectAutoComplete', $browserSnapshot)
                && $browserSnapshot['projectAutoComplete'] !== $enabled)) {
            throw new CashierV3CommandException('CASHIER_PROJECT_MODE_CHANGED',
                '购买项目的核销设置已改变，请返回收银刷新后重新确认本次内容。', 'failed');
        }
        return $enabled;
    }

    /** Old requests/orders default to their original immediate-service meaning. */
    public static function deferred(array $header): bool
    {
        return ($header['source_document_type'] ?? '') === 'member_checkout'
            || (int)($header['project_auto_complete'] ?? 1) === 0;
    }

    /** Pending rights must have a real member owner; never issue guest rights. */
    public static function assertMember(int $memberId): void
    {
        if ($memberId <= 0) {
            throw new CashierV3CommandException('CASHIER_PROJECT_MEMBER_REQUIRED',
                '购买后待用的项目需要归属会员，请选择会员。', 'failed');
        }
    }
}
