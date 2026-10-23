<?php
declare(strict_types=1);

namespace think\facade {
    /** Process-local read double: no framework boot, SQL connection or business write.
     * Unexpected facade calls fail rather than silently permitting a write path.
     */
    final class Db
    {
        public static $columns = [];
        public static $members = [];
        public static $relations = [];
        public static $trigger = 'denied';
        public static $schemaError = false;
        public static $calls = [];
        public static $reads = 0;
        public static $locks = 0;
        public static $transaction = true;

        public static function query(string $sql, array $bindings = []): array
        {
            self::$calls[] = $sql;
            if (strpos($sql, 'information_schema.COLUMNS') !== false) {
                if (self::$schemaError) throw new \RuntimeException('TEST_SCHEMA_ACCESS_DENIED');
                if ($bindings !== ['eb_user']) throw new \LogicException('UNEXPECTED_SCHEMA_QUERY');
                return array_values(self::$columns);
            }
            if (strpos($sql, 'information_schema.TRIGGERS') !== false) {
                if (self::$trigger === 'denied') throw new \RuntimeException('TEST_TRIGGER_ACCESS_DENIED');
                if (self::$trigger === 'missing') return [];
                return [[
                    'ACTION_TIMING' => 'BEFORE', 'EVENT_MANIPULATION' => 'UPDATE', 'EVENT_OBJECT_TABLE' => 'eb_user',
                    'ACTION_STATEMENT' => self::$trigger === 'valid'
                        ? 'IF NOT (NEW.now_money <=> OLD.now_money) OR NOT (NEW.ben_money <=> OLD.ben_money) OR NOT (NEW.give_money <=> OLD.give_money) THEN SET NEW.balance_version = OLD.balance_version + 1; ELSE SET NEW.balance_version = OLD.balance_version; END IF'
                        : 'SET NEW.balance_version = OLD.balance_version;',
                ]];
            }
            throw new \LogicException('UNEXPECTED_SQL');
        }

        public static function name(string $table): \MbrReadQuery { return new \MbrReadQuery($table); }
        public static function connect(): \MbrReadConnection { return new \MbrReadConnection(); }
    }
}

namespace {
    $backend = getenv('CHECKOUT_BALANCE_BACKEND_ROOT') ?: '/workspace/后端代码';
    require_once $backend . '/vendor/autoload.php';

    use app\services\cashier\v3\CashierV3DataScopeContext;
    use app\services\cashier\v3\CashierV3OperatorScope;
    use app\services\cashier\v3\CashierV3ResourceScope;
    use app\services\cashier\v3\CashierV3CommandException;
    use app\services\cashier\v3\checkout\provider\CashierV3MemberBalanceProvider;
    use app\services\cashier\v3\checkout\provider\CashierV3MemberBalanceWriterAdapter;
    use app\services\cashier\v3\checkout\provider\CashierV3MemberBalanceContractException;
    use think\facade\Db;

    /** PDO identity double only for the real transaction guard's instanceof check.
     * It never constructs a DSN and cannot connect to local or remote MySQL.
     */
    final class MbrReadPdo extends \PDO
    {
        public function __construct() {}
        public function inTransaction(): bool { return Db::$transaction; }
    }
    final class MbrReadConnection
    {
        public function getPdo(): \PDO { return new MbrReadPdo(); }
    }
    final class MbrReadRows
    {
        private $rows;
        public function __construct(array $rows) { $this->rows = $rows; }
        public function toArray(): array { return $this->rows; }
    }

    /** Minimal select-only query double. The AI store_user subquery is evaluated,
     * not ignored, so dropping/changing its membership gate breaks the contract.
     */
    final class MbrReadQuery
    {
        private $table;
        private $conditions = [];
        public function __construct(string $table) { $this->name($table); }
        public function name(string $table): self
        {
            if (!in_array($table, ['user', 'store_user'], true)) throw new \LogicException('UNEXPECTED_TABLE');
            $this->table = $table;
            return $this;
        }
        public function alias(string $alias): self { return $this; }
        public function field(string $fields): self { return $this; }
        public function lock(bool $lock): self { if ($lock) Db::$locks++; return $this; }
        public function where(string $key, $value): self
        {
            $this->conditions[] = [$this->key($key), [$value]];
            return $this;
        }
        public function whereIn(string $key, $values): self
        {
            if ($values instanceof \Closure) {
                $subquery = new self('store_user');
                $values($subquery);
                $values = array_column($subquery->rows(), 'uid');
            }
            if (!is_array($values)) throw new \LogicException('UNEXPECTED_WHERE_IN');
            $this->conditions[] = [$this->key($key), $values];
            return $this;
        }
        private function key(string $key): string
        {
            $parts = explode('.', $key);
            $key = end($parts);
            if (!in_array($key, ['uid', 'store_id', 'status'], true)) throw new \LogicException('UNEXPECTED_FILTER');
            return $key;
        }
        public function rows(): array
        {
            $rows = $this->table === 'user' ? Db::$members : Db::$relations;
            foreach ($this->conditions as [$key, $values]) {
                $rows = array_values(array_filter($rows, static function (array $row) use ($key, $values): bool {
                    return in_array($row[$key] ?? null, $values, true);
                }));
            }
            return $rows;
        }
        public function find(): array { Db::$reads++; return $this->rows()[0] ?? []; }
        public function select(): MbrReadRows { Db::$reads++; return new MbrReadRows($this->rows()); }
    }

    $passed = 0;
    $failed = 0;
    function mbrCheck(string $name, bool $ok): void
    {
        global $passed, $failed;
        if ($ok) { $passed++; echo "PASS {$name}\n"; }
        else { $failed++; echo "FAIL {$name}\n"; }
    }
    function mbrReject(string $name, callable $call, string $reason): void
    {
        try { $call(); mbrCheck($name, false); }
        catch (CashierV3MemberBalanceContractException $error) { mbrCheck($name, $error->reason() === $reason); }
        catch (\Throwable $error) { mbrCheck($name . ' unexpected ' . get_class($error), false); }
    }
    function mbrScope(string $mode = 'stores', array $stores = [7], string $tenant = 'TEST-TENANT', int $forcedStore = 7, int $operator = 91): CashierV3DataScopeContext
    {
        return new CashierV3DataScopeContext($operator, 0, $forcedStore, $tenant, 'TEST-ORG', $stores, $mode, [], false, '', 'test', [], []);
    }
    function mbrReset(): void
    {
        Db::$columns = [];
        foreach (['uid', 'now_money', 'ben_money', 'give_money', 'balance_version', 'status', 'is_del', 'delete_time', 'belong_store_id'] as $column) {
            Db::$columns[$column] = ['COLUMN_NAME' => $column, 'DATA_TYPE' => 'int', 'COLUMN_TYPE' => 'int',
                'IS_NULLABLE' => 'NO', 'COLUMN_DEFAULT' => '0', 'NUMERIC_SCALE' => 0];
        }
        Db::$columns['balance_version'] = ['COLUMN_NAME' => 'balance_version', 'DATA_TYPE' => 'bigint', 'COLUMN_TYPE' => 'bigint unsigned',
            'IS_NULLABLE' => 'NO', 'COLUMN_DEFAULT' => '1', 'NUMERIC_SCALE' => 0];
        foreach (['now_money', 'ben_money', 'give_money'] as $column) {
            Db::$columns[$column]['DATA_TYPE'] = 'decimal';
            Db::$columns[$column]['NUMERIC_SCALE'] = 2;
        }
        Db::$members = [['uid' => 11, 'now_money' => '120.00', 'ben_money' => '100.00', 'give_money' => '20.00',
            'balance_version' => 4, 'status' => 1, 'is_del' => 0, 'delete_time' => null, 'belong_store_id' => 7]];
        Db::$relations = [['uid' => 11, 'store_id' => 7, 'status' => 1]];
        Db::$trigger = 'denied'; Db::$schemaError = false; Db::$calls = [];
        Db::$reads = 0; Db::$locks = 0; Db::$transaction = true;
    }
    function mbrTriggerQueries(): int
    {
        return count(array_filter(Db::$calls, static function (string $sql): bool { return strpos($sql, 'information_schema.TRIGGERS') !== false; }));
    }
    /** Reflect actual method boundaries instead of a broad whole-file text match.
     * Behavioral tests below exercise the same methods; these assertions also pin
     * delegated writer/lock call paths before any atomic balance mutation can run.
     */
    function mbrMethod(string $class, string $method): string
    {
        $reflection = new \ReflectionMethod($class, $method);
        return implode('', array_slice(file($reflection->getFileName()), $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1));
    }

    $provider = new CashierV3MemberBalanceProvider();
    $operator = new CashierV3OperatorScope(7, 91, 'TEST-ORG', 'TEST-TENANT');
    $scope = mbrScope();
    $tenantScope = CashierV3ResourceScope::of(CashierV3ResourceScope::TYPE_TENANT, 'TEST-TENANT');
    $readers = [
        'readSnapshot' => static function () use ($provider, $operator, $scope) { return $provider->readSnapshot(11, $operator, $scope); },
        'readSnapshots' => static function () use ($provider, $operator, $scope) { return $provider->readSnapshots([11], $operator, $scope); },
        'readAiBalanceCents' => static function () use ($provider) { return $provider->readAiBalanceCents([11], [7]); },
    ];
    foreach ($readers as $name => $read) {
        $method = mbrMethod(CashierV3MemberBalanceProvider::class, $name);
        mbrCheck($name . ' selects only read readiness', strpos($method, '$this->readReadinessStatus()') !== false
            && strpos($method, '$this->readinessStatus()') === false);
    }
    foreach (['resolveScopeWithDataScope', 'lockAndReadVersionWithDataScope', 'lockSnapshotInTx'] as $method) {
        mbrCheck($method . ' retains strict readiness', strpos(mbrMethod(CashierV3MemberBalanceProvider::class, $method), '$this->readinessStatus()') !== false);
    }
    mbrCheck('bump delegates to strict locked real-row version', strpos(mbrMethod(CashierV3MemberBalanceProvider::class, 'bumpVersionWithDataScope'), '$this->lockAndReadVersionWithDataScope(') !== false);
    mbrCheck('writer readiness still asks strict provider', strpos(mbrMethod(CashierV3MemberBalanceWriterAdapter::class, 'readinessStatus'), '$this->provider->readinessStatus()') !== false);
    foreach (['deductInTx', 'creditRechargeInTx'] as $method) {
        $body = mbrMethod(CashierV3MemberBalanceWriterAdapter::class, $method);
        mbrCheck($method . ' retains transaction readiness and lock gates', strpos($body, 'CashierV3TransactionGuard::assertInTransaction(') !== false
            && strpos($body, '$this->readinessStatus()') !== false && strpos($body, '$this->provider->lockSnapshotInTx(') !== false);
    }

    foreach (['denied', 'missing', 'invalid', 'valid'] as $trigger) {
        mbrReset(); Db::$trigger = $trigger;
        $readStatus = $provider->readReadinessStatus();
        mbrCheck('schema-only readiness with trigger ' . $trigger, $readStatus['ready'] === true
            && $readStatus['dependency'] === 'member_balance_read' && mbrTriggerQueries() === 0);
        $strict = $provider->readinessStatus();
        mbrCheck('strict trigger gate ' . $trigger, $strict['ready'] === ($trigger === 'valid')
            && $strict['dependency'] === 'member_balance_authority' && mbrTriggerQueries() === 1
            && $strict['shadowVersionForbidden'] === true && $strict['versionAdvance'] === 'database_trigger_on_real_balance_change');
        foreach ($readers as $name => $read) {
            mbrReset(); Db::$trigger = $trigger;
            $result = $read();
            $snapshot = $name === 'readSnapshot' ? $result : ($result[11] ?? null);
            mbrCheck($name . ' works without trigger inspection ' . $trigger, mbrTriggerQueries() === 0 && Db::$reads === 1 && Db::$locks === 0
                && ($name === 'readAiBalanceCents' ? $snapshot === 12000
                    : $snapshot['totalCents'] === 12000 && $snapshot['accountVersion'] === 4 && $snapshot['operatorId'] === 91));
        }
    }
    foreach (['denied', 'missing', 'invalid'] as $trigger) {
        $strictCalls = [
            'lockSnapshotInTx' => static function () use ($provider, $operator, $scope) { return $provider->lockSnapshotInTx(11, $operator, $scope); },
            'lockAndReadVersionWithDataScope' => static function () use ($provider, $tenantScope, $scope) { return $provider->lockAndReadVersionWithDataScope($tenantScope, 'member_balance', '11', $scope); },
            'bumpVersionWithDataScope' => static function () use ($provider, $tenantScope, $scope) { return $provider->bumpVersionWithDataScope($tenantScope, 'member_balance', '11', 'test', $scope); },
        ];
        foreach ($strictCalls as $name => $call) {
            mbrReset(); Db::$trigger = $trigger;
            mbrReject($name . ' refuses trigger ' . $trigger, $call, 'member_balance_provider_not_ready');
            mbrCheck($name . ' fails before account read ' . $trigger, mbrTriggerQueries() === 1 && Db::$reads === 0 && Db::$locks === 0);
        }
        mbrReset(); Db::$trigger = $trigger;
        mbrCheck('resolveScope still refuses trigger ' . $trigger, $provider->resolveScopeWithDataScope('member_balance', '11', $operator, $scope) === null
            && mbrTriggerQueries() === 1 && Db::$reads === 0);
    }
    // Exercise real writer entry points too. This select-only double deliberately
    // cannot provide an atomic service or writable ledger; rejection must already
    // expose the strict provider trigger failure, before any member/ledger write.
    $writer = new CashierV3MemberBalanceWriterAdapter($provider);
    foreach (['denied', 'missing', 'invalid'] as $trigger) {
        mbrReset(); Db::$trigger = $trigger;
        $status = $writer->readinessStatus();
        mbrCheck('writer reports strict provider rejection ' . $trigger, $status['ready'] === false
            && $status['provider']['ready'] === false && mbrTriggerQueries() === 1);
        foreach (['deductCheckoutInTx', 'deductSalesDebtInTx', 'creditRechargeInTx'] as $method) {
            mbrReset(); Db::$trigger = $trigger;
            $request = $method === 'creditRechargeInTx'
                ? ['memberId' => 11, 'expectedVersion' => 4, 'principalCents' => 100, 'bonusCents' => 0, 'sourceRechargeId' => 1, 'commandIdempotencyKey' => 'TEST-CREDIT']
                : ['memberId' => 11, 'expectedVersion' => 4, 'amountCents' => 100, 'sourceOrderId' => 1, 'commandIdempotencyKey' => 'TEST-DEBIT', 'paymentMode' => 'balance_only'];
            mbrReject($method . ' refuses trigger ' . $trigger, static function () use ($writer, $method, $request, $operator, $scope) {
                return $writer->$method($request, $operator, $scope);
            }, 'member_balance_writer_not_ready');
            mbrCheck($method . ' strict gate before account/atomic access ' . $trigger, mbrTriggerQueries() === 1 && Db::$reads === 0 && Db::$locks === 0);
        }
    }
    mbrReset(); Db::$trigger = 'valid';
    $locked = $provider->lockSnapshotInTx(11, $operator, $scope);
    mbrCheck('strict ready lock still locks real account', $locked['totalCents'] === 12000 && Db::$locks === 1 && mbrTriggerQueries() === 1);
    mbrCheck('bump observes rather than increments authority', $provider->bumpVersionWithDataScope($tenantScope, 'member_balance', '11', 'test', $scope) === 4
        && Db::$members[0]['balance_version'] === 4);
    mbrReset(); Db::$transaction = false;
    try { $provider->lockSnapshotInTx(11, $operator, $scope); mbrCheck('transaction guard retained', false); }
    catch (CashierV3CommandException $error) { mbrCheck('transaction guard retained', Db::$calls === [] && Db::$reads === 0); }

    // Every rejected schema is exercised through all three public read methods;
    // lack of trigger visibility must not turn a broken account schema into ready.
    mbrReset(); $required = array_keys(Db::$columns);
    $badSchemas = [];
    foreach ($required as $column) $badSchemas['missing ' . $column] = static function () use ($column): void { unset(Db::$columns[$column]); };
    foreach (['DATA_TYPE' => 'int', 'COLUMN_TYPE' => 'bigint', 'IS_NULLABLE' => 'YES', 'COLUMN_DEFAULT' => '0'] as $key => $value) {
        $badSchemas['version ' . $key] = static function () use ($key, $value): void { Db::$columns['balance_version'][$key] = $value; };
    }
    foreach (['now_money', 'ben_money', 'give_money'] as $column) foreach (['DATA_TYPE' => 'float', 'NUMERIC_SCALE' => 0, 'IS_NULLABLE' => 'YES'] as $key => $value) {
        $badSchemas[$column . ' ' . $key] = static function () use ($column, $key, $value): void { Db::$columns[$column][$key] = $value; };
    }
    $badSchemas['schema query denied'] = static function (): void { Db::$schemaError = true; };
    foreach ($badSchemas as $name => $mutate) {
        mbrReset(); $mutate(); $status = $provider->readReadinessStatus();
        mbrCheck('schema rejects ' . $name, !$status['ready'] && in_array('member_balance_schema_not_ready', $status['reasons'], true) && mbrTriggerQueries() === 0);
        foreach ($readers as $reader => $read) {
            mbrReset(); $mutate();
            mbrReject($reader . ' rejects schema ' . $name, $read, 'member_balance_provider_not_ready');
            mbrCheck($reader . ' rejects before account access ' . $name, Db::$reads === 0 && Db::$locks === 0 && mbrTriggerQueries() === 0);
        }
    }
    foreach (['none', 'self_participant', 'outside stores', 'tenant mismatch', 'forced store mismatch'] as $case) {
        $denied = $case === 'tenant mismatch' ? mbrScope('stores', [7], 'OTHER')
            : ($case === 'forced store mismatch' ? mbrScope('stores', [8], 'TEST-TENANT', 8)
            : ($case === 'outside stores' ? mbrScope('stores', [8]) : mbrScope($case)));
        $reason = in_array($case, ['tenant mismatch', 'forced store mismatch'], true)
            ? 'member_balance_data_scope_session_mismatch' : 'member_balance_data_scope_store_denied';
        foreach (['single', 'batch'] as $mode) {
            mbrReset();
            mbrReject($mode . ' permission ' . $case, static function () use ($provider, $operator, $denied, $mode) {
                return $mode === 'single' ? $provider->readSnapshot(11, $operator, $denied) : $provider->readSnapshots([11], $operator, $denied);
            }, $reason);
            mbrCheck($mode . ' permission fails before metadata/data ' . $case, Db::$calls === [] && Db::$reads === 0);
        }
    }
    foreach (['inactive', 'deleted', 'delete_time', 'invariant', 'negative money', 'version'] as $case) foreach ($readers as $name => $read) {
        mbrReset();
        if ($case === 'inactive') Db::$members[0]['status'] = 0;
        if ($case === 'deleted') Db::$members[0]['is_del'] = 1;
        if ($case === 'delete_time') Db::$members[0]['delete_time'] = '2026-01-01 00:00:00';
        if ($case === 'invariant') Db::$members[0]['now_money'] = '121.00';
        if ($case === 'negative money') Db::$members[0]['ben_money'] = '-1.00';
        if ($case === 'version') Db::$members[0]['balance_version'] = 0;
        $reason = $case === 'invariant' ? 'member_balance_invariant_invalid' : ($case === 'negative money' ? 'member_balance_money_invalid'
            : ($case === 'version' && $name !== 'readAiBalanceCents' ? 'member_balance_version_invalid' : 'member_balance_member_not_found'));
        mbrReject($name . ' preserves ' . $case, $read, $reason);
    }
    foreach (['wrong store', 'inactive relation', 'absent relation'] as $case) {
        mbrReset();
        if ($case === 'wrong store') Db::$relations[0]['store_id'] = 8;
        if ($case === 'inactive relation') Db::$relations[0]['status'] = 0;
        if ($case === 'absent relation') Db::$relations = [];
        mbrReject('AI membership ' . $case, $readers['readAiBalanceCents'], 'member_balance_member_not_found');
    }
    foreach (['readSnapshots', 'readAiBalanceCents'] as $method) foreach ([[], range(1, 101), ['11'], [0]] as $ids) {
        mbrReset();
        mbrReject($method . ' bounds ' . json_encode($ids === range(1, 101) ? '101 ids' : $ids), static function () use ($provider, $method, $ids, $operator, $scope) {
            return $method === 'readSnapshots' ? $provider->readSnapshots($ids, $operator, $scope) : $provider->readAiBalanceCents($ids, [7]);
        }, 'member_balance_identity_invalid');
        mbrCheck($method . ' invalid cohort has no database calls', Db::$calls === [] && Db::$reads === 0);
    }
    mbrReset(); Db::$members = [];
    foreach ($readers as $name => $read) mbrReject($name . ' missing account fails closed', $read, 'member_balance_member_not_found');
    echo "CHECKOUT_BALANCE_READ_ONLY_READINESS passed={$passed} failed={$failed}\n";
    exit($failed > 0 ? 1 : 0);
}
