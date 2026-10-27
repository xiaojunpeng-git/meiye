<?php
declare(strict_types=1);

/** Exercise the real policy orchestration; only storage/catalog/history ports
 * are isolated. No database, preference, order or financial writes occur.
 */
$backend = getenv('CASHIER_V3_BACKEND') ?: dirname(__DIR__, 3) . '/后端代码';
require $backend . '/vendor/autoload.php';
use app\services\cashier\v3\config\CashierV3BusinessSourcePolicyServices;
use think\exception\ValidateException;

class R57PolicyProbe extends CashierV3BusinessSourcePolicyServices
{
    public $settings = [];
    public $history = [];
    public $requests = [];
    public $invalid = false;
    public $sourceName = 'Resolved source';
    protected function settingsRow(): array { return $this->settings; }
    protected function latestChoice(string $kind, string $tenantId, int $storeId, int $memberId): array
    {
        $this->requests[] = [$kind, $tenantId, $storeId, $memberId];
        return $this->history[$kind] ?? self::emptyChoice();
    }
    protected function resolveConfigured(array $choice): array
    {
        if ($this->invalid || (int)($choice['primarySourceId'] ?? 0) <= 0) throw new ValidateException('invalid fixture');
        return array_replace(self::emptyChoice(), $choice, ['primarySourceNameSnapshot' => $this->sourceName, 'displayNameSnapshot' => $this->sourceName, 'rewardAmountCents' => 0]);
    }
}
$checks = 0;
function r57check(bool $valid, string $message): void { global $checks; if (!$valid) throw new RuntimeException($message); $checks++; }
$policy = new R57PolicyProbe();
r57check($policy->read()['mode'] === 'previous' && !$policy->read()['configured'], 'Unmigrated compatibility');
$policy->settings = ['source_enabled' => 1, 'inherit_previous' => 0, 'purchase_primary_id' => 11, 'recharge_primary_id' => 22, 'repayment_primary_id' => 33];
foreach (['purchase', 'recharge', 'repayment'] as $kind) {
    r57check($policy->initialChoice($kind, 'tenant', 8, 19)['primarySourceId'] === 0, 'Manual choices start empty');
}
r57check($policy->requests === [], 'Manual mode does not even read history');
$policy->settings['source_enabled'] = 0;
foreach (['purchase' => 11, 'recharge' => 22, 'repayment' => 33] as $kind => $id) {
    r57check($policy->initialChoice($kind, 'tenant', 8, 0)['primarySourceId'] === $id, 'Fixed mode also works for guests');
    r57check($policy->resolveChoice($kind, 'tenant', 8, 19, ['primarySourceId' => 999])['primarySourceId'] === $id, 'Fixed mode ignores forged user choice');
}
r57check(!$policy->read()['enabled'] && !$policy->read()['inheritPrevious'], 'Disabled policy never inherits');
$policy->settings['source_enabled'] = 1; $policy->settings['inherit_previous'] = 1;
$policy->history = ['purchase' => ['primarySourceId' => 41], 'recharge' => ['primarySourceId' => 42], 'repayment' => ['primarySourceId' => 43]];
foreach (['purchase' => 41, 'recharge' => 42, 'repayment' => 43] as $kind => $id) {
    r57check($policy->initialChoice($kind, 'tenant', 8, 19)['primarySourceId'] === $id, 'History separated by kind');
    r57check(end($policy->requests) === [$kind, 'tenant', 8, 19], 'History receives exact tenant/store/member identity');
}
$calls = count($policy->requests);
foreach ([['', 8, 19], ['tenant', 0, 19], ['tenant', 8, 0]] as $identity) r57check($policy->initialChoice('purchase', ...$identity)['primarySourceId'] === 0, 'Invalid identity cannot inherit');
r57check(count($policy->requests) === $calls, 'No cross-context history fallback');
$policy->invalid = true;
r57check($policy->initialChoice('purchase', 'tenant', 8, 19)['primarySourceId'] === 0, 'Disabled or removed previous source becomes empty');
$policy->invalid = false;
$selected = $policy->resolveChoice('purchase', 'tenant', 8, 19, ['primarySourceId' => 75, 'rewardAmountCents' => 9900]);
r57check($selected['primarySourceId'] === 75 && $selected['rewardAmountCents'] === 0, 'Current explicit choice wins without historical reward inheritance');
$policy->sourceName = 'G异业合作';
r57check($policy->resolveChoice('purchase', 'tenant', 8, 19, ['primarySourceId' => 75, 'rewardAmountCents' => 9900])['rewardAmountCents'] === 9900, 'Explicit current G reward survives');
r57check($policy->initialChoice('purchase', 'tenant', 8, 19)['rewardAmountCents'] === 0, 'Previous G choice never inherits reward');
foreach ([99, -100, 100.0, '100', null] as $reward) {
    try { $policy->resolveChoice('purchase', 'tenant', 8, 19, ['primarySourceId' => 75, 'rewardAmountCents' => $reward]); throw new RuntimeException('Invalid reward accepted'); }
    catch (ValidateException $expected) { $checks++; }
}
$policy->settings['source_enabled'] = 0;
r57check($policy->resolveChoice('purchase', 'tenant', 8, 19, ['primarySourceId' => 75, 'rewardAmountCents' => 9900])['rewardAmountCents'] === 0, 'Fixed default cannot inherit submitted reward');
$policy->invalid = true;
$choices = $policy->initialChoices('tenant', 8, 19);
foreach (['purchase', 'recharge', 'repayment'] as $kind) {
    r57check($choices[$kind]['primarySourceId'] === 0 && !empty($choices[$kind]['configurationError']), 'Invalid fixed configuration blocks only the new business');
}
foreach (['refund', '', 'unknown'] as $kind) {
    try { $policy->initialChoice($kind, 'tenant', 8, 19); throw new RuntimeException('Invalid kind accepted'); }
    catch (ValidateException $expected) { $checks++; }
}
echo json_encode(['result' => 'PASS', 'checks' => $checks, 'businessWrites' => 0]), PHP_EOL;
