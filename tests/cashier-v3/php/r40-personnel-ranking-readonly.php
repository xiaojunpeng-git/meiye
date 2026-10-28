<?php
/** 本地只读对账：两个角色按已分配事实排行，逐页下钻必须等于对应排行金额。 */
$root = '/var/www/html';
require $root . '/vendor/autoload.php';
(new think\App($root . '/'))->initialize();
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\dashboard\CashierV3BusinessDashboardReadModel;
use think\facade\Db;
if (config('database.connections.mysql.hostname') !== 'mysql' || config('database.connections.mysql.database') !== 'ruihao') throw new RuntimeException('LOCAL_ONLY');
function checkR40($v, string $message): void { if (!$v) throw new RuntimeException($message); }
Db::execute('SET TRANSACTION READ ONLY');
Db::startTrans();
try {
    $storeId = (int)Db::name('system_store')->where('name', '扬州欧尚店')->value('id');
    checkR40($storeId > 0, 'local store missing');
    $operator = new CashierV3OperatorScope($storeId, 1, '', '0');
    $scope = new CashierV3DataScopeContext(1, 1, $storeId, '0', '', [$storeId], 'stores', [], false, '', '', ['cashier.v3.management.dashboard'], []);
    $denied = new CashierV3DataScopeContext(1, 1, $storeId, '0', '', [], 'none', [], false, '', '', [], []);
    $reader = new CashierV3BusinessDashboardReadModel();
    $base = ['startDate' => '2026-10-01', 'endDate' => '2026-10-28'];
    $results = [];
    foreach (['sales' => 'staff_sales_yeji', 'craftsman' => 'staff_labor_yeji'] as $role => $metric) {
        $desc = $reader->dashboard($base + ['rankingRole' => $role, 'sortOrder' => 'desc'], $operator, $scope);
        $asc = $reader->dashboard($base + ['rankingRole' => $role, 'sortOrder' => 'asc'], $operator, $scope);
        $rank = $desc['ranking'];
        checkR40($rank['sortBy'] === $metric && $rank['role'] === $role, 'wrong role metric');
        checkR40(count($rank['records']) > 0, 'fixture has no ranking');
        $descValues = array_column($rank['records'], 'amountCents');
        $ascValues = array_column($asc['ranking']['records'], 'amountCents');
        $expectedDesc = $descValues; rsort($expectedDesc, SORT_NUMERIC);
        $expectedAsc = $ascValues; sort($expectedAsc, SORT_NUMERIC);
        checkR40($descValues === $expectedDesc && $ascValues === $expectedAsc, 'sort mismatch');
        checkR40($desc['cards'] === $asc['cards'] && $desc['trend'] === $asc['trend'], 'ranking sort changed dashboard indicators');
        $maxPages = 0;
        foreach ($rank['records'] as $row) {
            $page = 1; $sum = 0; $count = 0;
            do {
                $detail = $reader->detail($base + ['rankingRole' => $role, 'rankingEntityId' => $row['id'], 'page' => $page], $operator, $scope);
                checkR40($detail['metricCode'] === $metric && $detail['rankingEntityId'] === $row['id'], 'detail dimensions mismatch');
                foreach ($detail['records'] as $fact) {
                    checkR40($fact['businessDate'] >= $base['startDate'] && $fact['businessDate'] <= $base['endDate'], 'detail date outside scope');
                    $sum += $fact['amountCents']; ++$count;
                }
                ++$page;
            } while ($count < $detail['total'] && $page <= 500);
            checkR40($count === $detail['total'] && $sum === $row['amountCents'], 'ranking/detail amount mismatch');
            $maxPages = max($maxPages, $page - 1);
        }
        $empty = $reader->detail($base + ['rankingRole' => $role, 'rankingEntityId' => 2147483647], $operator, $scope);
        checkR40($empty['total'] === 0, 'unknown employee must return no facts');
        $emptyRange = $reader->detail(['startDate' => '2000-01-01', 'endDate' => '2000-01-02', 'rankingRole' => $role, 'rankingEntityId' => $rank['records'][0]['id']], $operator, $scope);
        checkR40($emptyRange['total'] === 0 && $emptyRange['records'] === [], 'date range did not restrict detail');
        $results[$role] = ['employees' => count($rank['records']), 'maxDetailPages' => $maxPages, 'allRowsReconciled' => true];
    }
    // 两个开关状态仅决定卡片显隐，退款和实际业绩仍复用同一后端指标。
    $enabled = (new CashierV3BusinessDashboardReadModel(static function (): bool { return true; }))->dashboard($base + ['metricCode' => 'refund_performance'], $operator, $scope);
    $disabled = (new CashierV3BusinessDashboardReadModel(static function (): bool { return false; }))->dashboard($base, $operator, $scope);
    $cards = []; foreach ($enabled['cards'] as $card) $cards[$card['metricCode']] = $card;
    $disabledCodes = array_column($disabled['cards'], 'metricCode');
    checkR40(isset($cards['debt_amount']) && !in_array('debt_amount', $disabledCodes, true), 'debt switch visibility');
    checkR40($cards['refund_performance']['name'] === '退款金额', 'refund label');
    checkR40($cards['cash_performance']['valueCents'] - $cards['refund_performance']['valueCents'] === $cards['actual_performance']['valueCents'], 'refund/actual mismatch');
    $trendTotal = array_sum(array_column($enabled['trend']['points'], 'valueCents'));
    checkR40($trendTotal === $cards['refund_performance']['valueCents'], 'refund trend mismatch');
    $refundPage = 1; $refundTotal = 0; $refundRows = 0;
    do {
        $part = $reader->detail($base + ['metricCode' => 'refund_performance', 'page' => $refundPage], $operator, $scope);
        foreach ($part['records'] as $fact) { $refundTotal += $fact['amountCents']; ++$refundRows; }
        ++$refundPage;
    } while ($refundRows < $part['total'] && $refundPage <= 500);
    checkR40($refundRows === $part['total'] && $refundTotal === $cards['refund_performance']['valueCents'], 'refund detail mismatch');
    $results['refund'] = ['cents' => $refundTotal, 'detailRows' => $refundRows, 'debtSwitchBothStates' => true];
    foreach ([$base + ['rankingRole' => 'unknown'], $base + ['rankingRole' => 'sales', 'rankingEntityId' => 0]] as $index => $bad) {
        try { $index === 0 ? $reader->dashboard($bad, $operator, $scope) : $reader->detail($bad, $operator, $scope); throw new RuntimeException('invalid input accepted'); }
        catch (CashierV3CommandException $expected) {}
    }
    try { $reader->detail($base + ['rankingRole' => 'sales', 'rankingEntityId' => 1], $operator, $denied); throw new RuntimeException('unauthorized scope accepted'); }
    catch (CashierV3CommandException $expected) {}
    echo json_encode(['result' => 'PASS', 'readOnly' => true, 'roles' => $results, 'invalidRoleAndScopeRejected' => true], JSON_UNESCAPED_UNICODE), PHP_EOL;
} finally { Db::rollback(); }
