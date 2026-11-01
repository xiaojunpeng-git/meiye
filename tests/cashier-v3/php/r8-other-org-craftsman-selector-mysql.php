<?php
declare(strict_types=1);

/** 本地真实员工只读回归：候选资格必须与结账锁内人员类型校验一致。 */
require '/var/www/html/vendor/autoload.php';
(new \think\App('/var/www/html/'))->initialize();

use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\member\CashierV3QueryEntitySelectorServices;
use think\facade\Db;

if (config('database.connections.mysql.hostname') !== 'mysql'
    || config('database.connections.mysql.database') !== 'ruihao') {
    throw new RuntimeException('This regression only reads the local ruihao fixture');
}

Db::execute('START TRANSACTION READ ONLY');
try {
    $corrected = (array)Db::name('employee')->where('id', 1803)->find();
    $valid = (array)Db::name('employee')->where('id', 445)->find();
    if (($corrected['name'] ?? '') !== '范玲丽'
        || ($corrected['employment_type_code'] ?? '') !== 'internal'
        || (int)($corrected['employment_type_version'] ?? 0) <= 0
        || ($valid['name'] ?? '') !== '范玲玲'
        || (int)($valid['employment_type_version'] ?? 0) <= 0) {
        throw new RuntimeException('Local employee fixture changed');
    }

    $selector = new CashierV3QueryEntitySelectorServices();
    // Exercise the shared public reader after removing the superseded private implementation.
    $query = static function ($keyword, $operator) {
        return (new \app\services\cashier\v3\member\CashierV3OtherCraftsmanQueryServices())->query(
            ['selectorContext' => ['scope' => 'cashier_other_craftsmen'], 'keyword' => $keyword, 'page' => 1, 'pageSize' => 20], $operator);
    };
    $scope = new CashierV3OperatorScope(39, 1, '165', '0');
    $includedAfterCorrection = $query('范玲丽', $scope);
    $correctedMatches = array_values(array_filter($includedAfterCorrection['records'], static function (array $row): bool {
        return (int)$row['employeeId'] === 1803;
    }));
    if (count($correctedMatches) !== 1
        || $correctedMatches[0]['employeeTypeCode'] !== 'internal'
        || (int)$correctedMatches[0]['employeeTypeAuthorityVersion'] !== (int)$corrected['employment_type_version']) {
        throw new RuntimeException('Corrected organization employee or authority fields missing');
    }
    echo "PASS corrected organization employee is selectable with real authority fields\n";

    $included = $query('范玲玲', $scope);
    $matching = array_values(array_filter($included['records'], static function (array $row): bool {
        return (int)$row['employeeId'] === 445;
    }));
    if (count($matching) !== 1
        || $matching[0]['employeeTypeCode'] !== $valid['employment_type_code']
        || (int)$matching[0]['employeeTypeAuthorityVersion'] !== (int)$valid['employment_type_version']) {
        throw new RuntimeException('Eligible organization employee or authority fields missing');
    }
    echo "PASS classified organization employee retains real type and version\n";
    echo "R8_OTHER_ORG_CRAFTSMAN_SELECTOR: 2 PASS; database writes: 0\n";
} finally {
    Db::execute('ROLLBACK');
}
