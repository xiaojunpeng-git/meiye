<?php

namespace app\services\cashier\v3\checkout\provider;

/**
 * Explicit activation token for old debt writers. The token must be supplied
 * by the writer adapters after their transaction and lock-order tests pass;
 * this class never infers readiness by scanning method names.
 */
final class CashierV3LegacyDebtGuardIntegrationProbe
{
    private const REQUIRED_PATHS = ['create', 'repay', 'adjustment'];

    /** @var array<string,string> */
    private $contracts;

    public function __construct(array $contracts = [])
    {
        $this->contracts = [];
        foreach ($contracts as $path => $version) {
            if (is_string($path) && is_string($version)) {
                $this->contracts[$path] = $version;
            }
        }
    }

    public function readinessStatus(): array
    {
        $missing = [];
        foreach (self::REQUIRED_PATHS as $path) {
            if (($this->contracts[$path] ?? '')
                !== CashierV3EntitlementProviderContracts::DEBT_GUARD_WRITER) {
                $missing[] = $path;
            }
        }
        return [
            'dependency' => 'legacy_debt_writers',
            'contractVersion' => CashierV3EntitlementProviderContracts::DEBT_GUARD_WRITER,
            'ready' => $missing === [],
            'reasons' => $missing === [] ? [] : ['legacy_debt_guard_paths_not_integrated'],
            'missingPaths' => $missing,
            'contracts' => $this->contracts,
        ];
    }
}
