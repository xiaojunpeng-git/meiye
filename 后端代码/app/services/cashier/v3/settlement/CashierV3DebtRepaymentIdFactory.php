<?php
declare(strict_types=1);

namespace app\services\cashier\v3\settlement;

/** Stable identifiers for one V3 debt repayment command. */
final class CashierV3DebtRepaymentIdFactory
{
    private $secret;

    public function __construct(string $secret)
    {
        if ($secret === '') {
            throw new \InvalidArgumentException('debt_repayment_namespace_secret_missing');
        }
        $this->secret = $secret;
    }

    public function draftId(string $tenantId, int $debtId, string $workspaceId): string
    {
        return $this->id('DRD', [$tenantId, (string)$debtId, $workspaceId]);
    }

    public function repaymentId(string $tenantId, int $debtId, string $commandKey): string
    {
        return $this->id('DRP', [$tenantId, (string)$debtId, $commandKey]);
    }

    public function repaymentNo(string $tenantId, int $debtId, string $commandKey, string $businessDate): string
    {
        if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $businessDate) !== 1) {
            throw new \InvalidArgumentException('debt_repayment_business_date_invalid');
        }
        return 'RP-' . str_replace('-', '', $businessDate) . '-'
            . strtoupper(substr($this->digest([$tenantId, (string)$debtId, $commandKey]), 0, 20));
    }

    public function collectionId(string $repaymentId, int $lineNo): string
    {
        if ($lineNo <= 0) {
            throw new \InvalidArgumentException('debt_repayment_line_no_invalid');
        }
        return $this->id('DRC', [$repaymentId, (string)$lineNo]);
    }

    public function factId(string $kind, string $repaymentId, string $sourceLineId): string
    {
        if (!in_array($kind, ['payment', 'performance'], true) || $sourceLineId === '') {
            throw new \InvalidArgumentException('debt_repayment_fact_identity_invalid');
        }
        return $this->id('DF', [$kind, $repaymentId, $sourceLineId]);
    }

    private function id(string $prefix, array $parts): string
    {
        return $prefix . '-' . substr($this->digest($parts), 0, 40);
    }

    private function digest(array $parts): string
    {
        return hash_hmac('sha256', implode("\0", $parts), $this->secret);
    }
}
