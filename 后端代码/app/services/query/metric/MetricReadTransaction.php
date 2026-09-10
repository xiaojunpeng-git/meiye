<?php

namespace app\services\query\metric;

use think\facade\Db;

/** One connection, explicit isolation and no nested/business write transaction. */
final class MetricReadTransaction
{
    private $budgetMs; private $checkpoint;
    public function __construct(int $budgetMs=20000,?callable $checkpoint=null)
    {
        if ($budgetMs<1 || $budgetMs>20000) throw new MetricQueryContractException('METRIC_READ_BUDGET_INVALID','当前查询预算不可用。');
        $this->budgetMs=$budgetMs; $this->checkpoint=$checkpoint;
    }
    public function run(callable $callback)
    {
        $deadline=microtime(true)+$this->budgetMs/1000;
        if ($this->checkpoint) call_user_func($this->checkpoint);
        $connection = Db::connect();
        $pdo = $connection->getPdo();
        if ($pdo && $pdo->inTransaction()) {
            throw new MetricQueryContractException('METRIC_READ_TRANSACTION_NESTED', '当前查询无法建立独立的一致读取。');
        }
        // SET TRANSACTION affects only the following transaction, not other requests.
        $tables = ['cashier_v3_payment_sale_allocation_fact', 'cashier_v3_sale_fact', 'cashier_v3_payment_fact',
            'cashier_v3_recharge_debt_repayment', 'cashier_v3_balance_fact', 'cashier_v3_performance_fact', 'cashier_v3_entitlement_service_fact',
            'cashier_v3_order_lifecycle_operation', 'system_store'];
        $prefix = (string)$connection->getConfig('prefix');
        $names = array_map(static function (string $table) use ($prefix): string { return $prefix . $table; }, $tables);
        $prior=$connection->query('SELECT @@SESSION.max_execution_time AS execution_limit',[],true);
        $original=(int)($prior[0]['execution_limit']??-1);
        if ($original<0) throw new MetricQueryContractException('METRIC_SQL_TIMEOUT_UNAVAILABLE','当前数据源尚未验证查询时限。');
        $check=function () use($connection,$deadline,$original):void {
            if ($this->checkpoint) call_user_func($this->checkpoint);
            $remaining=(int)floor(($deadline-microtime(true))*1000);
            if ($remaining<1) throw new MetricQueryContractException('METRIC_READ_DEADLINE','本次数据查询已到时限。');
            $limit=$original>0?min($original,$remaining):$remaining;
            $connection->execute('SET SESSION max_execution_time = '.(int)$limit);
        };
        $started=false;
        try {
            $check();
            $engines = $connection->query('SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('.implode(',',array_fill(0,count($names),'?')).')', $names, true);
            if (count($engines) !== count($tables)) throw new MetricQueryContractException('METRIC_READ_ENGINE_UNVERIFIED', '当前数据源尚未具备一致读取条件。');
            foreach ($engines as $engine) {
                if (strtoupper((string)($engine['ENGINE'] ?? '')) !== 'INNODB') throw new MetricQueryContractException('METRIC_READ_ENGINE_UNVERIFIED', '当前数据源尚未具备一致读取条件。');
            }
            $check();
            $connection->execute('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $connection->startTrans(); $started=true;
            $reader = new GroupPerformanceMetricReadServices(static function (string $table) use ($connection,$check) {
                $check();
                return $connection->name($table);
            });
            $result = $callback($reader);
            $check();
            $connection->commit();
            $started=false;
            return $result;
        } catch (\Throwable $exception) {
            if ($started) $connection->rollback();
            throw $exception;
        } finally {
            $connection->execute('SET SESSION max_execution_time = '.$original);
        }
    }
}
