<?php

namespace app\services\cashier\v3\cashier;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3ResultCode;
use think\facade\Db;

/**
 * C2 权益购物车草稿的独立表就绪门禁。
 *
 * 这些表只服务 C2 A1，不能加入 C1 全局门禁，否则迁移前会误阻断其他 V3 action。
 */
final class CashierV3CashierReadinessGuard
{
    public const CARD_DEFINITION_WRITER_LOCK_CONTRACT = 'ready-v1';
    public const COMPONENT_SKU_IDENTITY_WRITER_LOCK_CONTRACT = 'ready-v1';
    public const GATEWAY_CATALOG_PRELOCK_CONTRACT = 'ready-v1';

    public const REQUIRED_TABLES = [
        'eb_cashier_v3_workspace_draft',
        'eb_cashier_v3_workspace_line',
        'eb_cashier_v3_entitlement_resource_version',
    ];

    private const REQUIRED_COLUMNS = [
        'eb_cashier_v3_workspace_draft' => [
            'id', 'workspace_id', 'state_context_id', 'store_id', 'operator_id', 'member_id',
            'customer_mode', 'draft_status', 'line_fingerprint', 'add_time', 'update_time',
        ],
        'eb_cashier_v3_workspace_line' => [
            'id', 'workspace_id', 'line_key', 'line_role', 'member_id', 'holder_id',
            'source_detail_id', 'project_id', 'quantity', 'source_version', 'detail_version',
            'service_object', 'is_experience', 'craftsmen_json', 'salespeople_json', 'display_snapshot_json',
            'catalog_product_id', 'catalog_sku_id', 'catalog_product_type',
            'unit_price_cents', 'original_unit_price_cents', 'authority_fingerprint',
            'authority_snapshot_json', 'sort_no', 'add_time', 'update_time',
        ],
        'eb_cashier_v3_entitlement_resource_version' => [
            'id', 'resource_kind', 'resource_id', 'member_id', 'source_fingerprint',
            'current_version', 'last_action', 'add_time', 'update_time',
        ],
        'eb_user' => [
            'uid', 'nickname', 'real_name', 'phone', 'avatar', 'bar_code', 'belong_store_id',
            'now_money', 'status', 'is_del', 'delete_time',
        ],
        'eb_system_store' => ['id', 'name'],
        'eb_store_product' => [
            'id', 'pid', 'type', 'relation_id', 'product_type', 'store_name', 'cate_id',
            'keyword', 'unit_name', 'sort', 'is_show', 'is_del', 'is_verify', 'is_inventory',
            'allow_negative_stock', 'card_num', 'card_num_type',
        ],
        'eb_store_product_attr_value' => [
            'id', 'product_id', 'product_type', 'unique', 'suk', 'price', 'ot_price',
            'stock', 'code', 'bar_code', 'is_show', 'type', 'write_times', 'write_valid',
            'write_days', 'write_start', 'write_end',
        ],
        'eb_store_product_category' => ['id', 'cate_name', 'type', 'relation_id', 'is_show'],
        'eb_store_card_related' => [
            'id', 'card_product_id', 'product_id', 'product_type', 'product_attr_unique',
            'cost', 'price', 'write_times', 'status',
        ],
        'eb_user_card_holder' => [
            'id', 'uid', 'oid', 'card_name', 'card_no', 'store_id', 'write_surplus_times',
            'write_times', 'product_type', 'write_start', 'write_end', 'is_del',
        ],
        'eb_store_order' => [
            'id', 'uid', 'store_id', 'paid', 'is_del', 'is_system_del', 'is_user_del',
            'refund_status', 'terminal_action', 'card_upgrade_use_oid', 'order_id', 'mark',
            'pay_price', 'cash_pay_price', 'yue_pay_price', 'debt_amount', 'repaid_debt_amount',
        ],
        'eb_store_order_cart_info' => [
            'id', 'oid', 'cart_id', 'product_id', 'cart_type', 'product_type', 'cart_info',
            'write_times', 'write_surplus_times', 'is_writeoff', 'write_start', 'write_end',
            'pay_price', 'debt_amount', 'repaid_debt_amount', 'is_gift',
        ],
        'eb_store_reservation_order' => ['id', 'cart_info_id', 'status', 'is_del', 'is_system_del'],
        'eb_store_debt' => ['id', 'order_id', 'status', 'total_debt', 'repaid_debt'],
        'eb_system_store_staff' => ['id', 'employee_id', 'store_id', 'staff_name', 'status', 'is_del'],
        'eb_employee' => ['id', 'name', 'status', 'is_del'],
    ];

    private const REQUIRED_LEADING_INDEX = [
        'eb_user_card_holder' => ['uid', 'oid'],
        'eb_store_order_cart_info' => ['oid'],
        'eb_store_reservation_order' => ['cart_info_id'],
        'eb_store_debt' => ['order_id'],
        'eb_store_product_attr_value' => ['product_id'],
        'eb_store_card_related' => ['card_product_id', 'product_id'],
    ];

    private const REQUIRED_PRIMARY_INDEX = [
        'eb_user' => 'uid',
        'eb_system_store' => 'id',
        'eb_store_product' => 'id',
        'eb_store_product_attr_value' => 'id',
        'eb_store_product_category' => 'id',
        'eb_store_card_related' => 'id',
        'eb_user_card_holder' => 'id',
        'eb_store_order' => 'id',
        'eb_store_order_cart_info' => 'id',
        'eb_store_reservation_order' => 'id',
        'eb_store_debt' => 'id',
        'eb_cashier_v3_workspace_draft' => 'id',
        'eb_cashier_v3_workspace_line' => 'id',
        'eb_cashier_v3_entitlement_resource_version' => 'id',
        'eb_system_store_staff' => 'id',
        'eb_employee' => 'id',
    ];

    /** @var callable|null */
    private $inspector;

    /** @var callable|null */
    private $schemaInspector;

    public function setInspector(callable $inspector): void
    {
        $this->inspector = $inspector;
    }

    public function setSchemaInspector(callable $inspector): void
    {
        $this->schemaInspector = $inspector;
    }

    public function assertReady(): void
    {
        $existing = $this->inspector !== null
            ? (array)call_user_func($this->inspector)
            : $this->queryExistingTables();
        $missing = array_values(array_diff(self::REQUIRED_TABLES, $existing));
        if ($missing) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::COMMAND_TABLE_NOT_READY,
                '会员权益购物车底座尚未就绪，请联系管理员完成升级后再试。',
                CashierV3ResultCode::STATUS_FAILED,
                ['missing_tables' => $missing]
            );
        }

        // C2 草稿或依赖权威源缺列时禁止用 PHP 默认值冒充正常状态。测试仅替换表 inspector
        // 时保持原有缺表门禁；需要验证结构的测试可显式注入 schemaInspector。
        if ($this->inspector !== null && $this->schemaInspector === null) {
            return;
        }
        $schema = $this->schemaInspector !== null
            ? (array)call_user_func($this->schemaInspector)
            : $this->queryRequiredSchema();
        $missingColumns = [];
        foreach (self::REQUIRED_COLUMNS as $table => $columns) {
            $actual = array_values((array)($schema['columns'][$table] ?? []));
            foreach ($columns as $column) {
                if (!in_array($column, $actual, true)) {
                    $missingColumns[] = $table . '.' . $column;
                }
            }
        }
        $badEngines = [];
        foreach (array_keys(self::REQUIRED_COLUMNS) as $table) {
            if (strtoupper((string)($schema['engines'][$table] ?? '')) !== 'INNODB') {
                $badEngines[] = $table;
            }
        }
        $badPrimaryIndexes = [];
        foreach (self::REQUIRED_PRIMARY_INDEX as $table => $column) {
            if ((string)($schema['primary_indexes'][$table] ?? '') !== $column) {
                $badPrimaryIndexes[] = $table . '(' . $column . ')';
            }
        }
        $missingIndexes = [];
        foreach (self::REQUIRED_LEADING_INDEX as $table => $columns) {
            foreach ($columns as $column) {
                if (!in_array($column, (array)($schema['leading_indexes'][$table] ?? []), true)) {
                    $missingIndexes[] = $table . '(' . $column . ')';
                }
            }
        }
        if ($missingColumns || $badEngines || $badPrimaryIndexes || $missingIndexes) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::COMMAND_TABLE_NOT_READY,
                '会员权益权威数据结构尚未就绪，请联系管理员核对升级。',
                CashierV3ResultCode::STATUS_FAILED,
                [
                    'missing_columns' => $missingColumns,
                    'non_innodb_tables' => $badEngines,
                    'invalid_primary_indexes' => $badPrimaryIndexes,
                    'missing_leading_indexes' => $missingIndexes,
                ]
            );
        }
    }

    /**
     * 销售卡项要以卡商品主行作为 catalog_card_definition 的精确串行点。
     * 旧关系写入口完成共锁审计前，销售目录保持 fail-closed，不影响权益只读路径。
     */
    public function assertSaleCatalogReady(): void
    {
        $this->assertReady();
        if (self::CARD_DEFINITION_WRITER_LOCK_CONTRACT !== 'ready-v1'
            || self::COMPONENT_SKU_IDENTITY_WRITER_LOCK_CONTRACT !== 'ready-v1') {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ACTION_DEPENDENCY_NOT_READY,
                '销售目录并发保护尚未完成，当前不能添加本次购买项目。',
                CashierV3ResultCode::STATUS_FAILED,
                [
                    'reason' => 'sale_catalog_writer_lock_not_ready',
                    'required_contracts' => [
                        'catalog-card-definition-parent-lock-v1',
                        'catalog-component-sku-identity-parent-lock-v1',
                    ],
                ]
            );
        }
        if (self::GATEWAY_CATALOG_PRELOCK_CONTRACT !== 'ready-v1') {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ACTION_DEPENDENCY_NOT_READY,
                '销售目录预锁计划尚未完成，当前不能添加本次购买项目。',
                CashierV3ResultCode::STATUS_FAILED,
                [
                    'reason' => 'sale_catalog_gateway_lock_not_ready',
                    'required_contracts' => [
                        'gateway-catalog-prelock-before-workspace-v1',
                    ],
                ]
            );
        }
    }

    /** @return string[] */
    private function queryExistingTables(): array
    {
        $placeholders = implode(',', array_fill(0, count(self::REQUIRED_TABLES), '?'));
        $rows = Db::query(
            "SELECT TABLE_NAME AS t FROM information_schema.TABLES"
            . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (" . $placeholders . ")",
            self::REQUIRED_TABLES
        );
        $out = [];
        foreach ((array)$rows as $row) {
            $table = (string)($row['t'] ?? $row['TABLE_NAME'] ?? '');
            if ($table !== '') {
                $out[] = $table;
            }
        }
        return $out;
    }

    /**
     * @return array{
     *   columns:array<string,string[]>,
     *   engines:array<string,string>,
     *   primary_indexes:array<string,string>,
     *   leading_indexes:array<string,string[]>
     * }
     */
    private function queryRequiredSchema(): array
    {
        $tables = array_keys(self::REQUIRED_COLUMNS);
        $placeholders = implode(',', array_fill(0, count($tables), '?'));
        $tableRows = Db::query(
            "SELECT TABLE_NAME AS t, ENGINE AS e FROM information_schema.TABLES"
            . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (" . $placeholders . ")",
            $tables
        );
        $engines = [];
        foreach ((array)$tableRows as $row) {
            $table = (string)($row['t'] ?? $row['TABLE_NAME'] ?? '');
            if ($table !== '') {
                $engines[$table] = (string)($row['e'] ?? $row['ENGINE'] ?? '');
            }
        }
        $columnRows = Db::query(
            "SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.COLUMNS"
            . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (" . $placeholders . ")",
            $tables
        );
        $columns = [];
        foreach ((array)$columnRows as $row) {
            $table = (string)($row['t'] ?? $row['TABLE_NAME'] ?? '');
            $column = (string)($row['c'] ?? $row['COLUMN_NAME'] ?? '');
            if ($table !== '' && $column !== '') {
                $columns[$table][] = $column;
            }
        }

        $primaryRows = Db::query(
            "SELECT TABLE_NAME AS t, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols,"
            . " SUM(SUB_PART IS NOT NULL) AS prefix_parts FROM information_schema.STATISTICS"
            . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (" . $placeholders . ")"
            . " AND INDEX_NAME = 'PRIMARY' GROUP BY TABLE_NAME",
            $tables
        );
        $primaryIndexes = [];
        foreach ((array)$primaryRows as $row) {
            $table = (string)($row['t'] ?? $row['TABLE_NAME'] ?? '');
            $prefixParts = (int)($row['prefix_parts'] ?? $row['PREFIX_PARTS'] ?? 0);
            if ($table !== '' && $prefixParts === 0) {
                $primaryIndexes[$table] = (string)($row['cols'] ?? $row['COLS'] ?? '');
            }
        }

        $indexTables = array_keys(self::REQUIRED_LEADING_INDEX);
        $indexPlaceholders = implode(',', array_fill(0, count($indexTables), '?'));
        $indexRows = Db::query(
            "SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.STATISTICS"
            . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (" . $indexPlaceholders . ")"
            . " AND SEQ_IN_INDEX = 1",
            $indexTables
        );
        $leading = [];
        foreach ((array)$indexRows as $row) {
            $table = (string)($row['t'] ?? $row['TABLE_NAME'] ?? '');
            $column = (string)($row['c'] ?? $row['COLUMN_NAME'] ?? '');
            if ($table !== '' && $column !== '') {
                $leading[$table][] = $column;
            }
        }
        return [
            'columns' => $columns,
            'engines' => $engines,
            'primary_indexes' => $primaryIndexes,
            'leading_indexes' => $leading,
        ];
    }
}
