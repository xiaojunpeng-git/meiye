<?php

namespace app\services\cashier\v3\hang;

/**
 * Keeps only the selected customer-source identifiers and display snapshot in a
 * pending hang. This is not a checkout authority: the final sale transaction
 * captures the selected source under its own checkout request identity.
 */
final class CashierV3HangCheckoutSourceSnapshot
{
    public static function normalize($candidate, int $memberId): array
    {
        $source = is_array($candidate) ? $candidate : [];
        $primary = self::nonNegativeInteger($source['primarySourceId'] ?? 0);
        $secondary = self::nonNegativeInteger($source['secondarySourceId'] ?? 0);
        $reward = self::nonNegativeInteger($source['rewardAmountCents'] ?? 0, 1000000000000);
        // A guest or an unselected primary source cannot inherit a previous
        // member's source merely because a browser reused the same workbench.
        if ($memberId <= 0 || $primary === 0) {
            return self::emptySnapshot();
        }
        return [
            'primarySourceId' => $primary,
            'primarySourceNameSnapshot' => self::boundedText($source['primarySourceNameSnapshot'] ?? '', 128),
            'secondarySourceId' => $secondary,
            'secondarySourceNameSnapshot' => $secondary > 0
                ? self::boundedText($source['secondarySourceNameSnapshot'] ?? '', 128)
                : '',
            'rewardAmountCents' => $reward,
        ];
    }

    private static function emptySnapshot(): array
    {
        return [
            'primarySourceId' => 0,
            'primarySourceNameSnapshot' => '',
            'secondarySourceId' => 0,
            'secondarySourceNameSnapshot' => '',
            'rewardAmountCents' => 0,
        ];
    }

    private static function nonNegativeInteger($value, int $maximum = 2147483647): int
    {
        $parsed = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => $maximum],
        ]);
        return $parsed === false ? 0 : (int)$parsed;
    }

    private static function boundedText($value, int $maximumLength): string
    {
        return mb_substr(trim((string)$value), 0, $maximumLength, 'UTF-8');
    }
}
