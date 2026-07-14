<?php
// +----------------------------------------------------------------------
// | 旧库(morestore) → 新库(lin12) 数据迁移命令
// +----------------------------------------------------------------------
namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;

class Migrate extends Command
{
    /** 正式库库名（只读，禁止写入） */
    const SOURCE_DB = 'morestore';

    /** 测试库库名（迁移写入目标） */
    const TARGET_DB = 'lin12';

    /** 新站定制卡壳商品 ID（迁移时保留，不被旧库覆盖） */
    const CUSTOM_CARD_PRODUCT_ID = 8154;

    /** @var \think\db\Connection */
    protected $oldDb;

    /** @var \think\db\Connection */
    protected $newDb;

    /** @var int */
    protected $batch = 500;

    protected $stats = [
        'update' => 0,
        'insert' => 0,
        'skip' => 0,
        'delete' => 0,
    ];

    /** @var int[]|null */
    protected $butlerUserGroupIds = null;

    /** @var array<string, int>|null */
    protected $positionIdByName = null;

    /** 旧次卡 product_id(保留为卡项) => ['service_id','card_id','attr_unique','write_times',...] */
    protected $timesCardProductMap = [];

    protected function configure()
    {
        $this->setName('migrate')
            ->setDescription('全量迁移业务数据：morestore(只读) → lin12；菜单/配置以新站为准')
            ->addOption('repair-cart-info', null, Option::VALUE_NONE, '仅修复 store_order_cart_info.cart_info 缺失字段（不重新全量迁移）')
            ->addOption('repair-service-branches', null, Option::VALUE_NONE, '补全次卡关联项目的门店副本（收银台「项目」Tab 可单独下单）')
            ->addOption('repair-card-related', null, Option::VALUE_NONE, '将平台卡项关联同步到门店副本（收银台卡项弹窗选项目）')
            ->addOption('repair-product-attrs', null, Option::VALUE_NONE, '补全项目/卡项缺失的默认规格，并同步订单/卡包 SKU（收银台加购、手机预约）')
            ->addOption('repair-order-cart-sku', null, Option::VALUE_NONE, '同步 store_order_cart_info.sku_unique 与当前商品规格（手机卡包预约）')
            ->addOption('module', 'm', Option::VALUE_REQUIRED, '仅迁移指定模块: base|product|user|card|order|finance|reservation');
    }

    /**
     * 各模块表清单：数组顺序 = 清空顺序（先子表后主表），复制时自动反转
     */
    protected function moduleTables()
    {
        return [
            // 门店、员工
            'base' => [
                'system_store_staff',
                'system_store',
            ],
            // 商品
            'product' => [
                'store_card_related',
                'store_product_attr_value',
                'store_product_attr',
                'store_product_cate',
                'store_product_relation',
                'store_product_description',
                'store_product',
                'store_product_category',
                'store_product_label',
            ],
            // 会员主数据
            'user' => [
                'user_label_relation',
                'user_level',
                'user_address',
                'user_belong_store',
                'user_relation',
                'user_spread',
                'user_sign',
                'user_visit_store',
                'user_visit',
                'user_search',
                'user_invoice',
                'user_task_finish',
                'user_friends',
                'store_user',
                'user',
                'user_label',
                'user_label_cate',
                'user_group',
                'system_user_level',
            ],
            // 用户卡项 / 会员卡（user_card_holder 由订单迁移后从卡项订单生成）
            'card' => [
                'user_card',
                'member_card',
                'member_card_batch',
                'member_ship',
                'member_right',
            ],
            // 资金 / 账单 / 业绩 / 门店流水
            'finance' => [
                'yeji_commission',
                'staff_yeji',
                'user_brokerage_frozen',
                'user_brokerage',
                'user_bill',
                'user_money',
                'user_recharge',
                'user_extract',
                'store_finance_flow',
                'store_extract',
            ],
            // 订单（含组合支付、欠款、核销等）
            'order' => [
                'store_debt_repay',
                'store_debt_item',
                'store_debt',
                'store_order_promotions',
                'store_order_economize',
                'store_order_invoice',
                'store_order_refund',
                'store_order_writeoff',
                'store_order_status',
                'combination_order',
                'store_order_cart_info',
                'other_order',
                'other_order_status',
                'store_hang_order',
                'store_delivery_order',
                'store_order',
            ],
            // 预约
            'reservation' => [
                'store_reservation_order',
            ],
        ];
    }

    protected function execute(Input $input, Output $output)
    {
        $this->oldDb = Db::connect('old');
        $this->newDb = Db::connect('migrate');

        if (!$this->assertMigrationSafety($output)) {
            return 1;
        }

        $output->writeln('<info>源库(只读): ' . $this->getDbName($this->oldDb) . '</info>');
        $output->writeln('<info>目标库(写入): ' . $this->getDbName($this->newDb) . '</info>');
        $output->writeln('<info>业务库(不受影响): ' . env('database.hostname', '') . '/' . env('database.database', '') . '</info>');

        if ($input->getOption('repair-cart-info')) {
            try {
                $this->repairOrderCartInfoPayload($output);
            } catch (\Throwable $e) {
                $output->writeln('<error>修复失败: ' . $e->getMessage() . '</error>');
                $output->writeln($e->getTraceAsString());
                return 1;
            }
            $output->writeln('<info>cart_info 修复完成</info>');
            return 0;
        }

        if ($input->getOption('repair-service-branches')) {
            try {
                $this->timesCardProductMap = [];
                $this->repairServiceStoreBranchProducts($output);
            } catch (\Throwable $e) {
                $output->writeln('<error>修复失败: ' . $e->getMessage() . '</error>');
                $output->writeln($e->getTraceAsString());
                return 1;
            }
            $output->writeln('<info>项目门店副本修复完成</info>');
            return 0;
        }

        if ($input->getOption('repair-card-related')) {
            try {
                $this->repairCardRelatedForStoreBranches($output);
            } catch (\Throwable $e) {
                $output->writeln('<error>修复失败: ' . $e->getMessage() . '</error>');
                $output->writeln($e->getTraceAsString());
                return 1;
            }
            $output->writeln('<info>卡项门店关联修复完成</info>');
            return 0;
        }

        if ($input->getOption('repair-product-attrs')) {
            try {
                $this->repairProductDefaultAttrs($output);
                $this->resetStats();
                $this->repairOrderCartStoreBranchProducts($output);
                $this->resetStats();
                $this->repairOrderCartSkuUnique($output);
            } catch (\Throwable $e) {
                $output->writeln('<error>修复失败: ' . $e->getMessage() . '</error>');
                $output->writeln($e->getTraceAsString());
                return 1;
            }
            $output->writeln('<info>商品规格与订单 SKU 修复完成</info>');
            return 0;
        }

        if ($input->getOption('repair-order-cart-sku')) {
            try {
                $this->repairOrderCartStoreBranchProducts($output);
                $this->resetStats();
                $this->repairOrderCartSkuUnique($output);
            } catch (\Throwable $e) {
                $output->writeln('<error>修复失败: ' . $e->getMessage() . '</error>');
                $output->writeln($e->getTraceAsString());
                return 1;
            }
            $output->writeln('<info>订单 SKU 修复完成</info>');
            return 0;
        }

        $module = trim((string)$input->getOption('module'));
        if ($module !== '') {
            try {
                $this->runModuleMigration($output, $module);
            } catch (\Throwable $e) {
                $output->writeln('<error>迁移失败: ' . $e->getMessage() . '</error>');
                $output->writeln($e->getTraceAsString());
                return 1;
            }
            $output->writeln('<info>模块 [' . $module . '] 迁移完成</info>');
            return 0;
        }

        $output->writeln('<info>开始全量迁移...</info>');

        try {
            $this->runFullMigration($output);
        } catch (\Throwable $e) {
            $output->writeln('<error>迁移失败: ' . $e->getMessage() . '</error>');
            $output->writeln($e->getTraceAsString());
            return 1;
        }

        $output->writeln('<info>全量迁移完成</info>');
        return 0;
    }

    /**
     * 安全校验：morestore 只读，仅 lin12 可写
     */
    protected function assertMigrationSafety(Output $output)
    {
        $sourceName = strtolower($this->getDbName($this->oldDb));
        $targetName = strtolower($this->getDbName($this->newDb));
        $appDb = strtolower((string)env('database.database', ''));

        if (strpos($sourceName, self::SOURCE_DB) === false) {
            $output->writeln('<error>源库必须是 ' . self::SOURCE_DB . '（正式库只读），请检查 .env 中 OLD_DATABASE</error>');
            return false;
        }
        if (strpos($targetName, self::TARGET_DB) === false) {
            $output->writeln('<error>目标库必须是 ' . self::TARGET_DB . '（测试库），请检查 .env 中 MIGRATE_DATABASE</error>');
            return false;
        }
        if (strpos($targetName, self::SOURCE_DB) !== false) {
            $output->writeln('<error>禁止向正式库 ' . self::SOURCE_DB . ' 写入</error>');
            return false;
        }
        if ($appDb !== '' && strpos($targetName, $appDb) !== false) {
            $output->writeln('<error>禁止向业务库 DATABASE=' . env('database.database') . ' 写入，请检查 MIGRATE_DATABASE</error>');
            return false;
        }
        if (strpos($sourceName, self::TARGET_DB) !== false) {
            $output->writeln('<error>源库不能是测试库 ' . self::TARGET_DB . '</error>');
            return false;
        }

        return true;
    }

    /**
     * 一条命令跑完：业务表全量覆盖到 lin12（菜单/配置保留新站，不迁移旧站）
     */
    protected function runFullMigration(Output $output)
    {
        $output->writeln('<comment>菜单与系统配置保留新站(lin12)现有数据，不从 morestore 迁移</comment>');

        foreach (['base', 'product', 'user', 'card', 'order', 'finance', 'reservation'] as $name) {
            $output->writeln('');
            $this->runModuleMigration($output, $name, false);
        }
    }

    /**
     * 单模块迁移（--module=product 等）
     */
    protected function runModuleMigration(Output $output, string $module, bool $standalone = true)
    {
        $module = strtolower($module);
        $allowed = ['base', 'product', 'user', 'card', 'order', 'finance', 'reservation'];
        if (!in_array($module, $allowed, true)) {
            throw new \InvalidArgumentException('未知模块: ' . $module . '，可选: ' . implode('|', $allowed));
        }

        if ($standalone) {
            $output->writeln('<comment>单模块迁移: ' . $module . '（菜单/配置不受影响）</comment>');
        }

        if ($module === 'reservation') {
            $this->migrateReservation($output);
            return;
        }

        if ($module === 'product') {
            $this->migrateProductModule($output);
            $this->resetStats();
            $this->clearLegacyTimesCardRelations($output);
            $this->timesCardProductMap = [];
            $this->migrateLegacyTimesCardProducts($output);
            $this->resetStats();
            $this->syncStoreBranchProductTypesFromParent($output);
            $this->resetStats();
            $this->repairServiceStoreBranchProducts($output);
            $this->resetStats();
            $this->repairCardRelatedForStoreBranches($output);
            $this->resetStats();
            $this->repairProductDefaultAttrs($output);
            $this->resetStats();
            return;
        }

        $this->migrateTableModule($output, $module);

        if ($module === 'user') {
            $this->migrateStoreUserRelations($output);
            $this->resetStats();
            $this->migrateStaffFromUserGroups($output);
            $this->resetStats();
        }
        if ($module === 'order') {
            $this->ensureOrderCartInfoColumns($output);
            $this->ensureStoreOrderYejiColumns($output);
            $this->timesCardProductMap = [];
            $this->transformLegacyTimesCardOrders($output);
            $this->resetStats();
            $this->repairOrderCartInfoPayload($output);
            $this->resetStats();
            $this->repairOrderCartStoreBranchProducts($output);
            $this->resetStats();
            $this->repairOrderCartSkuUnique($output);
            $this->resetStats();
            $this->migrateUserCardHolder($output);
            $this->resetStats();
        }
    }

    /**
     * 次卡转卡项前，仅清空待转换旧次卡(product_type=4)的关联，保留原生卡项(product_type=5)关联
     */
    protected function clearLegacyTimesCardRelations(Output $output)
    {
        if (!$this->tableExists($this->newDb, 'store_card_related')) {
            return;
        }

        $preserveIds = $this->loadCustomCardPreserveProductIds();
        $legacyCardIds = $this->newDb->name('store_product')
            ->where('product_type', 4)
            ->where('is_del', 0)
            ->column('id');
        $legacyCardIds = array_values(array_diff(array_map('intval', $legacyCardIds), $preserveIds));
        if (!$legacyCardIds) {
            $output->writeln('  无需清空 store_card_related（无待转换旧次卡）');
            return;
        }

        $branchCardIds = $this->newDb->name('store_product')
            ->whereIn('pid', $legacyCardIds)
            ->where('type', 1)
            ->column('id');
        $allCardIds = array_values(array_unique(array_merge($legacyCardIds, array_map('intval', $branchCardIds))));

        $deleted = (int)$this->newDb->name('store_card_related')->whereIn('card_product_id', $allCardIds)->delete();
        $this->stats['delete'] += $deleted;
        $output->writeln(sprintf('  清空旧次卡关联 store_card_related: %d 条（原生卡项关联保留）', $deleted));
    }

    protected function resetStats()
    {
        $this->stats = ['update' => 0, 'insert' => 0, 'skip' => 0, 'delete' => 0];
    }

    protected function printStats(Output $output, $label)
    {
        $output->writeln(sprintf(
            '<info>[%s] 更新 %d | 新增 %d | 跳过 %d | 删除 %d</info>',
            $label,
            $this->stats['update'],
            $this->stats['insert'],
            $this->stats['skip'],
            $this->stats['delete']
        ));
    }

    protected function getDbName($db)
    {
        $config = $db->getConfig();
        return ($config['hostname'] ?? '') . '/' . ($config['database'] ?? '');
    }

    protected function tableColumns($db, $table)
    {
        $full = $this->fullTable($db, $table);
        $rows = $db->query('SHOW COLUMNS FROM `' . str_replace('`', '', $full) . '`');
        return array_column($rows, 'Field');
    }

    protected function fullTable($db, $table)
    {
        $prefix = $db->getConfig('prefix') ?? '';
        return $prefix . $table;
    }

    protected function pickFields(array $row, array $columns, array $exclude = ['id'])
    {
        $data = [];
        foreach ($columns as $col) {
            if (in_array($col, $exclude, true)) {
                continue;
            }
            if (array_key_exists($col, $row)) {
                $data[$col] = $row[$col];
            }
        }
        return $data;
    }

    /**
     * 配置分类：新有旧无保留；都有则旧覆盖；旧有新增
     */
    protected function migrateConfigTab(Output $output)
    {
        $output->writeln('<info>--- 迁移 system_config_tab ---</info>');
        $columns = array_intersect(
            $this->tableColumns($this->oldDb, 'system_config_tab'),
            $this->tableColumns($this->newDb, 'system_config_tab')
        );

        $oldRows = $this->oldDb->name('system_config_tab')->select()->toArray();
        $newIndex = $this->buildConfigTabIndex();

        foreach ($oldRows as $row) {
            $key = $this->configTabKey($row);
            if ($key === '') {
                $this->stats['skip']++;
                continue;
            }
            if (isset($newIndex[$key])) {
                $data = $this->pickFields($row, $columns, ['id']);
                $this->newDb->name('system_config_tab')->where('id', $newIndex[$key]['id'])->update($data);
                $this->stats['update']++;
            } else {
                $data = $this->pickFields($row, $columns, ['id']);
                $this->newDb->name('system_config_tab')->insert($data);
                $this->stats['insert']++;
            }
        }

        $oldKeys = array_filter(array_map([$this, 'configTabKey'], $oldRows));
        foreach ($this->newDb->name('system_config_tab')->select()->toArray() as $newRow) {
            $key = $this->configTabKey($newRow);
            if ($key !== '' && !in_array($key, $oldKeys, true)) {
                $this->stats['skip']++;
            }
        }

        $this->printStats($output, 'config_tab');
    }

    protected function buildConfigTabIndex()
    {
        $index = [];
        foreach ($this->newDb->name('system_config_tab')->select()->toArray() as $row) {
            $key = $this->configTabKey($row);
            if ($key !== '') {
                $index[$key] = $row;
            }
        }
        return $index;
    }

    protected function buildConfigTabIdMap()
    {
        $oldRows = $this->oldDb->name('system_config_tab')->select()->toArray();
        $newIndex = $this->buildConfigTabIndex();
        $map = [];
        foreach ($oldRows as $row) {
            $key = $this->configTabKey($row);
            if ($key !== '' && isset($newIndex[$key])) {
                $map[(int)$row['id']] = (int)$newIndex[$key]['id'];
            }
        }
        return $map;
    }

    protected function configTabKey(array $row)
    {
        $eng = trim((string)($row['eng_title'] ?? ''));
        if ($eng !== '') {
            return 'eng:' . $eng;
        }
        $title = trim((string)($row['title'] ?? ''));
        return $title !== '' ? 'title:' . $title : '';
    }

    /**
     * 系统配置：新有旧无保留；都有则旧 value 覆盖；旧有新增
     */
    protected function migrateConfig(Output $output)
    {
        $output->writeln('<info>--- 迁移 system_config ---</info>');
        $tabIdMap = $this->buildConfigTabIdMap();
        $columns = array_intersect(
            $this->tableColumns($this->oldDb, 'system_config'),
            $this->tableColumns($this->newDb, 'system_config')
        );

        $oldRows = $this->oldDb->name('system_config')->select()->toArray();
        $newByMenu = $this->newDb->name('system_config')->column('*', 'menu_name');

        foreach ($oldRows as $row) {
            $menuName = (string)($row['menu_name'] ?? '');
            if ($menuName === '') {
                $this->stats['skip']++;
                continue;
            }

            if (isset($newByMenu[$menuName])) {
                // 共有项：以旧库业务值为准，保留新库结构字段
                $data = [];
                foreach (['value', 'input_value'] as $field) {
                    if (in_array($field, $columns, true) && array_key_exists($field, $row)) {
                        $data[$field] = $row[$field];
                    }
                }
                // 可选：旧库其它同名字段也覆盖（不含 id、menu_name）
                $extra = $this->pickFields($row, $columns, ['id', 'menu_name', 'value', 'input_value']);
                $data = array_merge($extra, $data);

                if ($data) {
                    $this->newDb->name('system_config')->where('menu_name', $menuName)->update($data);
                }
                $this->stats['update']++;
            } else {
                $data = $this->pickFields($row, $columns, ['id']);
                if (isset($data['tab_id'], $tabIdMap[(int)$data['tab_id']])) {
                    $data['tab_id'] = $tabIdMap[(int)$data['tab_id']];
                }
                $this->newDb->name('system_config')->insert($data);
                $this->stats['insert']++;
            }
        }

        foreach ($newByMenu as $menuName => $newRow) {
            $found = false;
            foreach ($oldRows as $oldRow) {
                if (($oldRow['menu_name'] ?? '') === $menuName) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $this->stats['skip']++;
            }
        }

        $this->printStats($output, 'config');
    }

    /**
     * 菜单：新有旧无保留；都有则旧覆盖；旧有新增（处理 pid 树）
     */
    protected function migrateMenu(Output $output)
    {
        $output->writeln('<info>--- 迁移 system_menus ---</info>');
        $columns = array_intersect(
            $this->tableColumns($this->oldDb, 'system_menus'),
            $this->tableColumns($this->newDb, 'system_menus')
        );

        $oldRows = $this->oldDb->name('system_menus')->where('is_del', 0)->select()->toArray();
        $newRows = $this->newDb->name('system_menus')->select()->toArray();

        $newByAuth = [];
        $newByPath = [];
        foreach ($newRows as $row) {
            $auth = trim((string)($row['unique_auth'] ?? ''));
            if ($auth !== '') {
                $newByAuth[$auth] = $row;
            }
            $path = trim((string)($row['menu_path'] ?? ''));
            if ($path !== '') {
                $newByPath[$path] = $row;
            }
        }

        $menuIdMap = [];
        $matchedNewIds = [];

        // 1. 匹配项：旧库覆盖新库（保留新库 id）
        foreach ($oldRows as $row) {
            $matched = $this->matchMenuRow($row, $newByAuth, $newByPath);
            if (!$matched) {
                continue;
            }
            $menuIdMap[(int)$row['id']] = (int)$matched['id'];
            $matchedNewIds[(int)$matched['id']] = true;
            $data = $this->pickFields($row, $columns, ['id']);
            if ($data) {
                $this->newDb->name('system_menus')->where('id', $matched['id'])->update($data);
            }
            $this->stats['update']++;
        }

        // 2. 旧库独有：按 path 深度插入
        usort($oldRows, function ($a, $b) {
            return substr_count((string)$a['path'], '/') <=> substr_count((string)$b['path'], '/');
        });

        foreach ($oldRows as $row) {
            if (isset($menuIdMap[(int)$row['id']])) {
                continue;
            }
            $data = $this->pickFields($row, $columns, ['id']);
            $oldPid = (int)($row['pid'] ?? 0);
            $data['pid'] = $oldPid > 0 && isset($menuIdMap[$oldPid]) ? $menuIdMap[$oldPid] : $oldPid;

            $newId = (int)$this->newDb->name('system_menus')->insertGetId($data);
            $menuIdMap[(int)$row['id']] = $newId;
            $this->stats['insert']++;
        }

        // 3. 新库独有（保留）
        foreach ($newRows as $newRow) {
            if (!isset($matchedNewIds[(int)$newRow['id']])) {
                $this->stats['skip']++;
            }
        }

        $this->printStats($output, 'menu');
    }

    protected function matchMenuRow(array $row, array $newByAuth, array $newByPath)
    {
        $auth = trim((string)($row['unique_auth'] ?? ''));
        if ($auth !== '' && isset($newByAuth[$auth])) {
            return $newByAuth[$auth];
        }
        $path = trim((string)($row['menu_path'] ?? ''));
        if ($path !== '' && isset($newByPath[$path])) {
            return $newByPath[$path];
        }
        return null;
    }

    /**
     * 通用表复制迁移（先清空 lin12 再写入）
     */
    protected function migrateTableModule(Output $output, $module)
    {
        $groups = $this->moduleTables();
        if (!isset($groups[$module])) {
            throw new \InvalidArgumentException('未知模块: ' . $module);
        }
        $tables = $groups[$module];

        $output->writeln('<info>--- 迁移模块: ' . $module . ' ---</info>');

        foreach ($tables as $table) {
            if (!$this->tableExists($this->newDb, $table)) {
                continue;
            }
            $this->newDb->execute('DELETE FROM `' . $this->fullTable($this->newDb, $table) . '`');
            $this->stats['delete']++;
            $output->writeln('  清空: ' . $table);
        }

        foreach (array_reverse($tables) as $table) {
            if (!$this->tableExists($this->oldDb, $table)) {
                $output->writeln('  跳过(旧库无表): ' . $table);
                continue;
            }
            if (!$this->tableExists($this->newDb, $table)) {
                $output->writeln('  跳过(新库无表): ' . $table);
                continue;
            }
            $count = $this->copyTable($table, $this->batch, true);
            $output->writeln(sprintf('  复制 %s: %d 条', $table, $count));
        }

        $this->printStats($output, $module);
    }

    /**
     * 商品迁移：保留新站定制卡（ID=8154）及其子商品，不被旧库覆盖
     */
    protected function migrateProductModule(Output $output)
    {
        $groups = $this->moduleTables();
        $tables = $groups['product'];
        $preserveIds = $this->loadCustomCardPreserveProductIds();
        $backup = $this->backupCustomCardProductData($preserveIds);

        $output->writeln('<info>--- 迁移模块: product（保留定制卡 ID ' . self::CUSTOM_CARD_PRODUCT_ID . '）---</info>');
        if ($preserveIds) {
            $output->writeln('  保留商品 ID: ' . implode(',', $preserveIds));
        } else {
            $output->writeln('<comment>  新库暂无定制卡商品，迁移后请确认 ID ' . self::CUSTOM_CARD_PRODUCT_ID . ' 存在</comment>');
        }

        foreach ($tables as $table) {
            if (!$this->tableExists($this->newDb, $table)) {
                continue;
            }
            $this->clearProductTableExceptCustomCard($table, $preserveIds);
            $this->stats['delete']++;
            $output->writeln('  清空: ' . $table . ($preserveIds ? '（已排除定制卡）' : ''));
        }

        foreach (array_reverse($tables) as $table) {
            if (!$this->tableExists($this->oldDb, $table)) {
                $output->writeln('  跳过(旧库无表): ' . $table);
                continue;
            }
            if (!$this->tableExists($this->newDb, $table)) {
                $output->writeln('  跳过(新库无表): ' . $table);
                continue;
            }
            $count = $this->copyProductTable($table, $this->batch, $preserveIds);
            $output->writeln(sprintf('  复制 %s: %d 条', $table, $count));
        }

        if ($backup) {
            $this->restoreCustomCardProductData($backup, $output);
        }

        $this->printStats($output, 'product');
    }

    /**
     * 定制卡壳 8154 + pid=8154 的子商品
     */
    protected function loadCustomCardPreserveProductIds()
    {
        if (!$this->tableExists($this->newDb, 'store_product')) {
            return [self::CUSTOM_CARD_PRODUCT_ID];
        }
        $ids = $this->newDb->name('store_product')
            ->where(function ($query) {
                $query->where('id', self::CUSTOM_CARD_PRODUCT_ID)->whereOr('pid', self::CUSTOM_CARD_PRODUCT_ID);
            })
            ->column('id');
        $ids[] = self::CUSTOM_CARD_PRODUCT_ID;
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        sort($ids);
        return $ids;
    }

    protected function backupCustomCardProductData(array $preserveIds)
    {
        if (!$preserveIds || !$this->tableExists($this->newDb, 'store_product')) {
            return [];
        }

        $backup = ['_preserve_ids' => $preserveIds];
        $productTables = [
            'store_product',
            'store_product_attr',
            'store_product_attr_value',
            'store_product_cate',
            'store_product_relation',
            'store_product_description',
        ];

        foreach ($productTables as $table) {
            if (!$this->tableExists($this->newDb, $table)) {
                continue;
            }
            if ($table === 'store_product') {
                $backup[$table] = $this->newDb->name($table)->whereIn('id', $preserveIds)->select()->toArray();
            } else {
                $backup[$table] = $this->newDb->name($table)->whereIn('product_id', $preserveIds)->select()->toArray();
            }
        }

        if (empty($backup['store_product'])) {
            return [];
        }

        return $backup;
    }

    protected function clearProductTableExceptCustomCard($table, array $preserveIds)
    {
        $full = str_replace('`', '', $this->fullTable($this->newDb, $table));
        $preserveIds = array_values(array_filter(array_map('intval', $preserveIds)));

        if (in_array($table, ['store_product_attr', 'store_product_attr_value', 'store_product_cate', 'store_product_relation', 'store_product_description'], true)) {
            if ($preserveIds) {
                $this->newDb->name($table)->whereNotIn('product_id', $preserveIds)->delete();
            } else {
                $this->newDb->execute('DELETE FROM `' . $full . '`');
            }
            return;
        }

        if ($table === 'store_product' && $preserveIds) {
            $this->newDb->name($table)->whereNotIn('id', $preserveIds)->delete();
            return;
        }

        $this->newDb->execute('DELETE FROM `' . $full . '`');
    }

    protected function copyProductTable($table, $batch, array $preserveIds)
    {
        $columns = array_intersect(
            $this->tableColumns($this->oldDb, $table),
            $this->tableColumns($this->newDb, $table)
        );
        if (!$columns) {
            return 0;
        }

        $pk = $this->resolveCopyPrimaryKey($table, $columns);
        if ($pk) {
            return $this->copyProductTableByCursor($table, $columns, $batch, $pk, $preserveIds);
        }

        return $this->copyProductTableByPage($table, $columns, $batch, $preserveIds);
    }

    protected function copyProductTableByCursor($table, array $columns, $batch, $pk, array $preserveIds)
    {
        $total = 0;
        $skipped = 0;
        $lastId = 0;

        while (true) {
            $rows = $this->oldDb->name($table)
                ->where($pk, '>', $lastId)
                ->order($pk, 'asc')
                ->limit($batch)
                ->select()
                ->toArray();
            if (!$rows) {
                break;
            }

            foreach ($rows as $row) {
                if ($this->shouldSkipProductMigrateRow($table, $row, $preserveIds)) {
                    $skipped++;
                    $this->stats['skip']++;
                } else {
                    $this->insertOrUpdateRow($table, $row, $columns, true, $pk);
                    $total++;
                }
                $lastId = (int)$row[$pk];
            }

            if (count($rows) < $batch) {
                break;
            }
        }

        if ($skipped > 0) {
            // 已在 stats 中累计 skip
        }

        return $total;
    }

    protected function copyProductTableByPage($table, array $columns, $batch, array $preserveIds)
    {
        $total = 0;
        $page = 1;

        while (true) {
            $rows = $this->oldDb->name($table)
                ->page($page, $batch)
                ->select()
                ->toArray();
            if (!$rows) {
                break;
            }

            foreach ($rows as $row) {
                if ($this->shouldSkipProductMigrateRow($table, $row, $preserveIds)) {
                    $this->stats['skip']++;
                    continue;
                }
                $row = $this->transformRowForMigrate($table, $row);
                $insertColumns = $this->columnsForInsert($table, $columns);
                $data = $this->sanitizeInsertData(
                    $this->newDb,
                    $table,
                    $this->pickFields($row, $insertColumns, [])
                );
                $this->newDb->name($table)->insert($data);
                $this->stats['insert']++;
                $total++;
            }

            if (count($rows) < $batch) {
                break;
            }
            $page++;
        }

        return $total;
    }

    protected function shouldSkipProductMigrateRow($table, array $row, array $preserveIds)
    {
        if (!$preserveIds) {
            return false;
        }

        if ($table === 'store_product') {
            $id = (int)($row['id'] ?? 0);
            if ($id === self::CUSTOM_CARD_PRODUCT_ID || in_array($id, $preserveIds, true)) {
                return true;
            }
            if ((int)($row['pid'] ?? 0) === self::CUSTOM_CARD_PRODUCT_ID) {
                return true;
            }
            return false;
        }

        if (in_array($table, ['store_product_attr', 'store_product_attr_value', 'store_product_cate', 'store_product_relation', 'store_product_description'], true)) {
            return in_array((int)($row['product_id'] ?? 0), $preserveIds, true);
        }

        return false;
    }

    protected function restoreCustomCardProductData(array $backup, Output $output)
    {
        $preserveIds = $backup['_preserve_ids'] ?? [];
        if (!$preserveIds) {
            return;
        }

        $productTables = [
            'store_product_attr_value',
            'store_product_attr',
            'store_product_cate',
            'store_product_relation',
            'store_product_description',
            'store_product',
        ];

        foreach ($productTables as $table) {
            if (!$this->tableExists($this->newDb, $table)) {
                continue;
            }
            if ($table === 'store_product') {
                $this->newDb->name($table)->whereIn('id', $preserveIds)->delete();
            } else {
                $this->newDb->name($table)->whereIn('product_id', $preserveIds)->delete();
            }
        }

        $restored = 0;
        foreach (array_reverse($productTables) as $table) {
            foreach ($backup[$table] ?? [] as $row) {
                $this->newDb->name($table)->insert(
                    $this->sanitizeInsertData($this->newDb, $table, $row)
                );
                $restored++;
            }
        }

        $output->writeln(sprintf(
            '  已恢复定制卡数据: %d 条（商品 ID: %s）',
            $restored,
            implode(',', $preserveIds)
        ));
    }

    protected function tableExists($db, $table)
    {
        $full = $this->fullTable($db, $table);
        $dbName = $db->getConfig('database');
        $row = $db->query(
            'SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$dbName, $full]
        );
        return !empty($row[0]['c']);
    }

    protected function copyTable($table, $batch, $keepId = false)
    {
        $columns = array_intersect(
            $this->tableColumns($this->oldDb, $table),
            $this->tableColumns($this->newDb, $table)
        );
        if (!$columns) {
            return 0;
        }

        $pk = $this->resolveCopyPrimaryKey($table, $columns);
        if ($pk) {
            return $this->copyTableByCursor($table, $columns, $batch, $keepId, $pk);
        }

        return $this->copyTableByPage($table, $columns, $batch);
    }

    /**
     * 识别可用于游标分批的主键（id / uid / 表 PRIMARY KEY）
     */
    protected function resolveCopyPrimaryKey($table, array $columns)
    {
        foreach (['id', 'uid'] as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }

        $full = str_replace('`', '', $this->fullTable($this->oldDb, $table));
        $rows = $this->oldDb->query("SHOW KEYS FROM `{$full}` WHERE Key_name = 'PRIMARY'");
        if (!empty($rows[0]['Column_name'])) {
            $pk = (string)$rows[0]['Column_name'];
            if (in_array($pk, $columns, true)) {
                return $pk;
            }
        }

        return null;
    }

    /**
     * 按主键游标分批复制
     */
    protected function copyTableByCursor($table, array $columns, $batch, $keepId, $pk)
    {
        $total = 0;
        $lastId = 0;

        while (true) {
            $rows = $this->oldDb->name($table)
                ->where($pk, '>', $lastId)
                ->order($pk, 'asc')
                ->limit($batch)
                ->select()
                ->toArray();
            if (!$rows) {
                break;
            }

            foreach ($rows as $row) {
                $this->insertOrUpdateRow($table, $row, $columns, $keepId, $pk);
                $total++;
                $lastId = (int)$row[$pk];
            }

            if (count($rows) < $batch) {
                break;
            }
        }

        return $total;
    }

    /**
     * 无主键列（如 store_product_description）：按页分批插入
     */
    protected function copyTableByPage($table, array $columns, $batch)
    {
        $total = 0;
        $page = 1;

        while (true) {
            $rows = $this->oldDb->name($table)
                ->page($page, $batch)
                ->select()
                ->toArray();
            if (!$rows) {
                break;
            }

            foreach ($rows as $row) {
                $row = $this->transformRowForMigrate($table, $row);
                $insertColumns = $this->columnsForInsert($table, $columns);
                $data = $this->sanitizeInsertData(
                    $this->newDb,
                    $table,
                    $this->pickFields($row, $insertColumns, [])
                );
                $this->newDb->name($table)->insert($data);
                $this->stats['insert']++;
                $total++;
            }

            if (count($rows) < $batch) {
                break;
            }
            $page++;
        }

        return $total;
    }

    protected function insertOrUpdateRow($table, array $row, array $columns, $keepId, $pk)
    {
        $row = $this->transformRowForMigrate($table, $row);
        $columns = $this->columnsForInsert($table, $columns);

        if ($keepId && isset($row[$pk])) {
            $exists = $this->newDb->name($table)->where($pk, $row[$pk])->count();
            $dataWithId = $this->sanitizeInsertData(
                $this->newDb,
                $table,
                $this->pickFields($row, $columns, [])
            );
            if ($exists) {
                $updateData = $this->sanitizeInsertData(
                    $this->newDb,
                    $table,
                    $this->pickFields($row, $columns, [$pk])
                );
                $this->newDb->name($table)->where($pk, $row[$pk])->update($updateData);
                $this->stats['update']++;
            } else {
                $this->newDb->name($table)->insert($dataWithId);
                $this->stats['insert']++;
            }
        } else {
            $data = $this->sanitizeInsertData(
                $this->newDb,
                $table,
                $this->pickFields($row, $columns, [$pk])
            );
            $this->newDb->name($table)->insert($data);
            $this->stats['insert']++;
        }
    }

    /**
     * 旧库 NULL 写入新库 NOT NULL 字段时补默认值（如 user.work_userid）
     */
    protected function sanitizeInsertData($db, $table, array $data)
    {
        $meta = $this->tableColumnMeta($db, $table);
        foreach ($data as $col => $value) {
            if ($value !== null || !isset($meta[$col])) {
                continue;
            }
            if ($meta[$col]['nullable']) {
                continue;
            }
            $data[$col] = $this->defaultForColumnMeta($meta[$col]);
        }
        return $data;
    }

    protected function tableColumnMeta($db, $table)
    {
        static $cache = [];
        $dbName = $db->getConfig('database');
        $full = str_replace('`', '', $this->fullTable($db, $table));
        $key = $dbName . '.' . $full;
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $rows = $db->query(
            'SELECT COLUMN_NAME, IS_NULLABLE, COLUMN_DEFAULT, DATA_TYPE 
             FROM information_schema.COLUMNS 
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$dbName, $full]
        );
        $meta = [];
        foreach ($rows as $row) {
            $meta[$row['COLUMN_NAME']] = [
                'nullable' => strtoupper((string)$row['IS_NULLABLE']) === 'YES',
                'default' => $row['COLUMN_DEFAULT'],
                'type' => strtolower((string)$row['DATA_TYPE']),
            ];
        }
        $cache[$key] = $meta;
        return $meta;
    }

    protected function defaultForColumnMeta(array $meta)
    {
        if ($meta['default'] !== null) {
            return $meta['default'];
        }
        if (in_array($meta['type'], ['int', 'tinyint', 'smallint', 'mediumint', 'bigint', 'decimal', 'float', 'double'], true)) {
            return 0;
        }
        return '';
    }

    /**
     * 旧系统无本金/赠金拆分：迁移后 ben_money=0，give_money=now_money（余额全部记为赠金）
     */
    protected function transformRowForMigrate($table, array $row)
    {
        if ($table === 'user') {
            $nowMoney = $row['now_money'] ?? 0;
            $row['ben_money'] = 0;
            $row['give_money'] = $nowMoney;
            if (empty($row['belong_store_id']) && !empty($row['store_id'])) {
                $row['belong_store_id'] = (int)$row['store_id'];
            }
            return $row;
        }

        if ($table === 'store_order_cart_info') {
            return $this->transformOrderCartInfoRow($row);
        }

        if ($table === 'system_store') {
            if (empty($row['background_image']) && !empty($row['image'])) {
                $row['background_image'] = (string)$row['image'];
            }
            return $row;
        }

        return $row;
    }

    /**
     * 旧 cart_info 无 pay_price，从 truePrice/sum_true_price 补全
     */
    protected function transformOrderCartInfoRow(array $row)
    {
        $cartNum = max((int)($row['cart_num'] ?? 1), 1);
        $info = $row['cart_info'] ?? '';
        if (is_string($info) && $info !== '') {
            $decoded = json_decode($info, true);
            $info = is_array($decoded) ? $decoded : [];
        } elseif (!is_array($info)) {
            $info = [];
        }

        $payPrice = $info['pay_price'] ?? null;
        if ($payPrice === null || $payPrice === '') {
            if (isset($info['sum_true_price']) && $info['sum_true_price'] !== '') {
                $payPrice = $info['sum_true_price'];
            } elseif (isset($info['truePrice'])) {
                $payPrice = bcmul((string)$info['truePrice'], (string)$cartNum, 2);
            } elseif (isset($info['price'])) {
                $payPrice = $info['price'];
            } elseif (!empty($row['pay_price'])) {
                $payPrice = $row['pay_price'];
            } elseif (!empty($row['total_price'])) {
                $payPrice = $row['total_price'];
            } else {
                $payPrice = '0.00';
            }
            $info['pay_price'] = $payPrice;
        }

        if (empty($info['truePrice']) && $cartNum > 0 && bccomp((string)$payPrice, '0', 2) > 0) {
            $info['truePrice'] = bcdiv((string)$payPrice, (string)$cartNum, 2);
        }

        $info = $this->normalizeOrderCartInfoPayload($row, $info);

        $row['cart_info'] = json_encode($info, JSON_UNESCAPED_UNICODE);

        if (empty($row['pay_price']) || (float)$row['pay_price'] <= 0) {
            $row['pay_price'] = $payPrice;
        }

        if (empty($row['product_type']) && !empty($info['productInfo']['product_type'])) {
            $row['product_type'] = (int)$info['productInfo']['product_type'];
        }

        return $row;
    }

    /**
     * 补全 cart_info 中业务代码依赖的顶层字段（旧库/次卡转换后可能缺失）
     */
    protected function normalizeOrderCartInfoPayload(array $row, array $info)
    {
        $productId = (int)($row['product_id'] ?? 0);
        if (empty($info['product_id'])) {
            $info['product_id'] = $productId > 0 ? $productId : (int)($info['productInfo']['id'] ?? 0);
        }

        $skuUnique = (string)($row['sku_unique'] ?? '');
        if ($skuUnique === '') {
            $skuUnique = (string)($info['productInfo']['attrInfo']['unique'] ?? ($info['attrInfo']['unique'] ?? ''));
        }
        if ($skuUnique !== '' && empty($info['product_attr_unique'])) {
            $info['product_attr_unique'] = $skuUnique;
        }

        $cartId = (string)($row['cart_id'] ?? '');
        if ($cartId !== '' && empty($info['id'])) {
            $info['id'] = $cartId;
        }

        if (!isset($info['cart_num']) && !empty($row['cart_num'])) {
            $info['cart_num'] = (int)$row['cart_num'];
        }

        return $info;
    }

    /**
     * 修复已迁移订单 cart_info 缺失 product_id 等字段（可单独执行）
     */
    protected function repairOrderCartInfoPayload(Output $output)
    {
        if (!$this->tableExists($this->newDb, 'store_order_cart_info')) {
            $output->writeln('<comment>新库无 store_order_cart_info 表，跳过 cart_info 修复</comment>');
            return;
        }

        $output->writeln('<info>--- 修复 store_order_cart_info.cart_info 缺失字段 ---</info>');
        $repaired = 0;
        $skipped = 0;
        $lastId = 0;

        while (true) {
            $rows = $this->newDb->name('store_order_cart_info')
                ->where('id', '>', $lastId)
                ->order('id', 'asc')
                ->limit($this->batch)
                ->select()
                ->toArray();
            if (!$rows) {
                break;
            }

            foreach ($rows as $row) {
                $lastId = (int)$row['id'];
                $info = $this->decodeCartInfoPayload($row);
                $normalized = $this->normalizeOrderCartInfoPayload($row, $info);
                $newJson = json_encode($normalized, JSON_UNESCAPED_UNICODE);
                $oldJson = is_string($row['cart_info'] ?? null) ? $row['cart_info'] : json_encode($info, JSON_UNESCAPED_UNICODE);
                if ($newJson === $oldJson) {
                    $skipped++;
                    continue;
                }
                $this->newDb->name('store_order_cart_info')->where('id', $lastId)->update([
                    'cart_info' => $newJson,
                ]);
                $repaired++;
                $this->stats['update']++;
            }

            if (count($rows) < $this->batch) {
                break;
            }
        }

        $output->writeln(sprintf('  cart_info 修复: %d 条（无需修复 %d）', $repaired, $skipped));
        $this->printStats($output, 'repair_cart_info');
    }

    /**
     * 订单明细 sku_unique / cart_info 与当前商品规格对齐（手机卡包预约校验 product_id+unique）
     */
    protected function repairOrderCartSkuUnique(Output $output): void
    {
        if (!$this->tableExists($this->newDb, 'store_order_cart_info')
            || !$this->tableExists($this->newDb, 'store_product_attr_value')) {
            $output->writeln('<comment>跳过：store_order_cart_info / store_product_attr_value 不存在</comment>');
            return;
        }

        $output->writeln('<info>--- 同步订单明细 SKU unique（手机卡包预约） ---</info>');
        $repaired = 0;
        $skipped = 0;
        $lastId = 0;

        while (true) {
            $rows = $this->newDb->name('store_order_cart_info')
                ->where('id', '>', $lastId)
                ->where('product_id', '>', 0)
                ->order('id', 'asc')
                ->limit($this->batch)
                ->select()
                ->toArray();
            if (!$rows) {
                break;
            }

            foreach ($rows as $row) {
                $lastId = (int)($row['id'] ?? 0);
                $productId = (int)($row['product_id'] ?? 0);
                if ($productId <= 0) {
                    continue;
                }

                $currentUnique = (string)($row['sku_unique'] ?? '');
                if ($this->isSkuUniqueValidForProduct($productId, $currentUnique)) {
                    $info = $this->decodeCartInfoPayload($row);
                    $synced = $this->syncCartInfoSkuUnique($info, $productId, $currentUnique);
                    $newJson = json_encode($synced, JSON_UNESCAPED_UNICODE);
                    $oldJson = is_string($row['cart_info'] ?? null) ? $row['cart_info'] : json_encode($info, JSON_UNESCAPED_UNICODE);
                    if ($newJson !== $oldJson) {
                        $this->newDb->name('store_order_cart_info')->where('id', $lastId)->update(['cart_info' => $newJson]);
                        $repaired++;
                        $this->stats['update']++;
                    } else {
                        $skipped++;
                    }
                    continue;
                }

                $newUnique = $this->resolveValidSkuUniqueForProduct($productId);
                if ($newUnique === '') {
                    $skipped++;
                    continue;
                }

                $info = $this->decodeCartInfoPayload($row);
                $info = $this->syncCartInfoSkuUnique($info, $productId, $newUnique);
                $this->newDb->name('store_order_cart_info')->where('id', $lastId)->update([
                    'sku_unique' => $newUnique,
                    'cart_info' => json_encode($info, JSON_UNESCAPED_UNICODE),
                ]);
                $repaired++;
                $this->stats['update']++;
            }

            if (count($rows) < $this->batch) {
                break;
            }
        }

        $output->writeln(sprintf('  订单 SKU 同步: %d 条（有效/无规格跳过 %d）', $repaired, $skipped));
        $this->printStats($output, 'repair_order_cart_sku');
    }

    protected function isSkuUniqueValidForProduct(int $productId, string $unique): bool
    {
        if ($productId <= 0 || $unique === '' || !$this->tableExists($this->newDb, 'store_product_attr_value')) {
            return false;
        }
        $attr = $this->newDb->name('store_product_attr_value')
            ->where('unique', $unique)
            ->where('type', 0)
            ->find();
        if (!$attr) {
            return false;
        }
        $attr = is_array($attr) ? $attr : $attr->toArray();
        return (int)($attr['product_id'] ?? 0) === $productId;
    }

    protected function resolveValidSkuUniqueForProduct(int $productId): string
    {
        $attr = $this->loadDefaultProductAttrValue($productId);
        if (!$attr) {
            return '';
        }
        $unique = (string)($attr['unique'] ?? '');
        return $this->isSkuUniqueValidForProduct($productId, $unique) ? $unique : '';
    }

    protected function syncCartInfoSkuUnique(array $info, int $productId, string $unique): array
    {
        if ($productId > 0) {
            $info['product_id'] = $productId;
        }
        if ($unique !== '') {
            $info['product_attr_unique'] = $unique;
            if (isset($info['attrInfo']) && is_array($info['attrInfo'])) {
                $info['attrInfo']['unique'] = $unique;
            }
            if (isset($info['productInfo']) && is_array($info['productInfo'])) {
                if (!isset($info['productInfo']['attrInfo']) || !is_array($info['productInfo']['attrInfo'])) {
                    $info['productInfo']['attrInfo'] = [];
                }
                $info['productInfo']['attrInfo']['unique'] = $unique;
            }
        }
        return $info;
    }

    /**
     * 订单明细 product_id 对齐到下单门店的项目副本（跨店预约用 pid 找门店商品）
     */
    protected function repairOrderCartStoreBranchProducts(Output $output): void
    {
        if (!$this->tableExists($this->newDb, 'store_order_cart_info')
            || !$this->tableExists($this->newDb, 'store_order')
            || !$this->tableExists($this->newDb, 'store_product')) {
            $output->writeln('<comment>跳过：订单/商品表不存在</comment>');
            return;
        }

        $output->writeln('<info>--- 订单明细 product_id 对齐下单门店项目副本 ---</info>');
        $repaired = 0;
        $skipped = 0;
        $lastId = 0;

        while (true) {
            $rows = $this->newDb->name('store_order_cart_info')
                ->where('id', '>', $lastId)
                ->where('product_id', '>', 0)
                ->order('id', 'asc')
                ->limit($this->batch)
                ->select()
                ->toArray();
            if (!$rows) {
                break;
            }

            foreach ($rows as $row) {
                $lastId = (int)($row['id'] ?? 0);
                $productId = (int)($row['product_id'] ?? 0);
                $oid = (int)($row['oid'] ?? 0);
                if ($productId <= 0 || $oid <= 0) {
                    continue;
                }

                $storeId = (int)$this->newDb->name('store_order')->where('id', $oid)->value('store_id');
                if ($storeId <= 0) {
                    $skipped++;
                    continue;
                }

                $product = $this->newDb->name('store_product')->where('id', $productId)->find();
                if (!$product) {
                    $skipped++;
                    continue;
                }
                $product = is_array($product) ? $product : $product->toArray();
                $productType = (int)($product['product_type'] ?? 0);
                if (!in_array($productType, [4, 5, 6], true)) {
                    $skipped++;
                    continue;
                }

                if ((int)($product['type'] ?? 0) === 1 && (int)($product['relation_id'] ?? 0) === $storeId) {
                    $skipped++;
                    continue;
                }

                $platformId = $this->resolvePlatformProductId($productId);
                $branchId = $this->findStoreBranchProductId($platformId, $storeId);
                if ($branchId <= 0 || $branchId === $productId) {
                    $skipped++;
                    continue;
                }

                $branch = $this->newDb->name('store_product')->where('id', $branchId)->find();
                if (!$branch) {
                    $skipped++;
                    continue;
                }
                $branch = is_array($branch) ? $branch : $branch->toArray();
                $newUnique = $this->resolveValidSkuUniqueForProduct($branchId);

                $info = $this->decodeCartInfoPayload($row);
                $info = $this->syncCartInfoProductBranch($info, $branch, $newUnique);

                $update = [
                    'product_id' => $branchId,
                    'cart_info' => json_encode($info, JSON_UNESCAPED_UNICODE),
                ];
                if ($newUnique !== '') {
                    $update['sku_unique'] = $newUnique;
                }
                $this->newDb->name('store_order_cart_info')->where('id', $lastId)->update($update);
                $repaired++;
                $this->stats['update']++;
            }

            if (count($rows) < $this->batch) {
                break;
            }
        }

        $output->writeln(sprintf('  订单门店项目副本: 对齐 %d 条（已是副本/无副本跳过 %d）', $repaired, $skipped));
        $this->printStats($output, 'repair_order_cart_branch');
    }

    protected function resolvePlatformProductId(int $productId): int
    {
        if ($productId <= 0) {
            return 0;
        }
        $product = $this->newDb->name('store_product')->where('id', $productId)->find();
        if (!$product) {
            return 0;
        }
        $product = is_array($product) ? $product : $product->toArray();
        $pid = (int)($product['pid'] ?? 0);
        if ((int)($product['type'] ?? 0) === 1 && $pid > 0) {
            return $pid;
        }
        return $productId;
    }

    protected function findStoreBranchProductId(int $platformProductId, int $storeId): int
    {
        if ($platformProductId <= 0 || $storeId <= 0) {
            return 0;
        }
        $branchId = (int)$this->newDb->name('store_product')
            ->where('pid', $platformProductId)
            ->where('type', 1)
            ->where('relation_id', $storeId)
            ->where('is_del', 0)
            ->value('id');
        return $branchId > 0 ? $branchId : 0;
    }

    protected function syncCartInfoProductBranch(array $info, array $branchProduct, string $unique = ''): array
    {
        $productId = (int)($branchProduct['id'] ?? 0);
        if ($productId <= 0) {
            return $info;
        }
        if ($unique !== '') {
            $info = $this->syncCartInfoSkuUnique($info, $productId, $unique);
        } else {
            $info['product_id'] = $productId;
        }
        if (!isset($info['productInfo']) || !is_array($info['productInfo'])) {
            $info['productInfo'] = [];
        }
        $info['productInfo']['id'] = $productId;
        $info['productInfo']['type'] = (int)($branchProduct['type'] ?? 1);
        $info['productInfo']['relation_id'] = (int)($branchProduct['relation_id'] ?? 0);
        $info['productInfo']['pid'] = (int)($branchProduct['pid'] ?? 0);
        $info['productInfo']['product_type'] = (int)($branchProduct['product_type'] ?? 6);
        if (!empty($branchProduct['store_name'])) {
            $info['productInfo']['store_name'] = (string)$branchProduct['store_name'];
        }
        return $info;
    }

    /**
     * 新站独有字段（旧库无列）也需要写入
     */
    protected function columnsForInsert($table, array $columns)
    {
        if ($table === 'user') {
            $newColumns = $this->tableColumns($this->newDb, $table);
            foreach (['ben_money', 'give_money', 'belong_store_id'] as $col) {
                if (in_array($col, $newColumns, true) && !in_array($col, $columns, true)) {
                    $columns[] = $col;
                }
            }
            return $columns;
        }

        if ($table === 'store_order_cart_info') {
            $newColumns = $this->tableColumns($this->newDb, $table);
            foreach (['pay_price', 'total_price', 'settle_price'] as $col) {
                if (in_array($col, $newColumns, true) && !in_array($col, $columns, true)) {
                    $columns[] = $col;
                }
            }
        }

        if ($table === 'system_store') {
            $newColumns = $this->tableColumns($this->newDb, $table);
            foreach (['background_image'] as $col) {
                if (in_array($col, $newColumns, true) && !in_array($col, $columns, true)) {
                    $columns[] = $col;
                }
            }
        }

        return $columns;
    }

    /**
     * 预约迁移：旧 order_work（+ order_work_tmp 组合单）→ store_reservation_order
     */
    protected function migrateReservation(Output $output)
    {
        if (!$this->tableExists($this->oldDb, 'order_work')) {
            $output->writeln('<error>旧库无 order_work 表</error>');
            return;
        }
        if (!$this->tableExists($this->newDb, 'store_reservation_order')) {
            $output->writeln('<error>新库无 store_reservation_order 表</error>');
            return;
        }

        $output->writeln('<info>--- 迁移预约: order_work → store_reservation_order ---</info>');

        $this->ensureReservationColumns($output);

        $this->newDb->execute('DELETE FROM `' . $this->fullTable($this->newDb, 'store_reservation_order') . '`');
        $this->stats['delete']++;
        $output->writeln('  清空: store_reservation_order');

        $output->writeln('  加载 order_work_tmp 索引...');
        $tmpIndex = $this->buildOrderWorkTmpIndex();
        $this->ensureTechnicianStaffFromOrderWork($output);
        $staffMap = $this->buildStaffUidMap();
        $newColumns = $this->tableColumns($this->newDb, 'store_reservation_order');

        $total = 0;
        $lastId = 0;
        $skipped = 0;

        while (true) {
            $works = $this->oldDb->name('order_work')
                ->where('id', '>', $lastId)
                ->order('id', 'asc')
                ->limit($this->batch)
                ->select()
                ->toArray();
            if (!$works) {
                break;
            }

            foreach ($works as $work) {
                $mapped = $this->mapOrderWorkToReservation($work, $tmpIndex, $staffMap);
                if (!$mapped) {
                    $skipped++;
                    $this->stats['skip']++;
                    $lastId = (int)$work['id'];
                    continue;
                }

                $data = $this->sanitizeInsertData(
                    $this->newDb,
                    'store_reservation_order',
                    array_merge($this->pickFields($mapped, $newColumns, []), ['id' => (int)$work['id']])
                );

                $exists = $this->newDb->name('store_reservation_order')->where('id', $data['id'])->count();
                if ($exists) {
                    $updateData = $this->sanitizeInsertData(
                        $this->newDb,
                        'store_reservation_order',
                        $this->pickFields($mapped, $newColumns, ['id'])
                    );
                    $this->newDb->name('store_reservation_order')->where('id', $data['id'])->update($updateData);
                    $this->stats['update']++;
                } else {
                    $this->newDb->name('store_reservation_order')->insert($data);
                    $this->stats['insert']++;
                }

                $total++;
                $lastId = (int)$work['id'];
            }

            if (count($works) < $this->batch) {
                break;
            }
        }

        $output->writeln(sprintf('  迁移 order_work: %d 条（跳过 %d）', $total, $skipped));
        $this->resetStats();
        $this->migrateReservationWriteoffYeji($output, $tmpIndex, $staffMap);
        $this->printStats($output, 'reservation');
    }

    /**
     * 预约表扩展字段（lin12 基线 schema 可能缺失）
     */
    protected function ensureReservationColumns(Output $output)
    {
        $columns = $this->tableColumns($this->newDb, 'store_reservation_order');
        if (!in_array('staff_choose', $columns, true)) {
            $table = str_replace('`', '', $this->fullTable($this->newDb, 'store_reservation_order'));
            $this->newDb->execute(
                'ALTER TABLE `' . $table . '` ADD COLUMN `staff_choose` text NULL COMMENT \'手艺人JSON\' AFTER `service_staff_id`'
            );
            $output->writeln('  已添加 store_reservation_order.staff_choose 列');
        }
    }

    /**
     * order_work.technician_id 为旧 user.uid，补全对应门店员工（不受用户分组排除规则限制）
     */
    protected function ensureTechnicianStaffFromOrderWork(Output $output)
    {
        if (!$this->tableExists($this->oldDb, 'order_work') || !$this->tableExists($this->newDb, 'system_store_staff')) {
            return;
        }

        $output->writeln('  补全预约手艺人门店员工...');
        $pairs = [];
        $lastId = 0;
        while (true) {
            $rows = $this->oldDb->name('order_work')
                ->where('id', '>', $lastId)
                ->field('id,store_id,technician_id,master_id')
                ->order('id', 'asc')
                ->limit($this->batch)
                ->select()
                ->toArray();
            if (!$rows) {
                break;
            }
            foreach ($rows as $row) {
                $storeId = (int)($row['store_id'] ?? 0);
                foreach (['technician_id', 'master_id'] as $field) {
                    $uid = (int)($row[$field] ?? 0);
                    if ($uid > 0 && $storeId > 0) {
                        $pairs[$uid . ':' . $storeId] = [$uid, $storeId];
                    }
                }
                $lastId = (int)$row['id'];
            }
            if (count($rows) < $this->batch) {
                break;
            }
        }

        $created = 0;
        $staffMap = [];
        foreach ($pairs as $pair) {
            [$uid, $storeId] = $pair;
            $hadStaff = $this->resolveStaffId($uid, $storeId, $staffMap) > 0
                || $this->newDb->name('system_store_staff')
                    ->where('uid', $uid)->where('store_id', $storeId)->where('is_del', 0)->count();
            $staffId = $this->ensureStaffForUserAtStore($uid, $storeId, $staffMap);
            if (!$hadStaff && $staffId > 0) {
                $created++;
            }
        }
        $output->writeln(sprintf('  补全手艺人员工: %d 条', $created));
    }

    /**
     * 指定 uid + 门店生成 system_store_staff，返回 staff_id
     */
    protected function ensureStaffForUserAtStore(int $uid, int $storeId, array &$staffMap = [])
    {
        if ($uid <= 0 || $storeId <= 0) {
            return 0;
        }
        $existing = $this->resolveStaffId($uid, $storeId, $staffMap);
        if ($existing > 0) {
            return $existing;
        }
        $staffId = (int)$this->newDb->name('system_store_staff')
            ->where('uid', $uid)
            ->where('store_id', $storeId)
            ->where('is_del', 0)
            ->value('id');
        if ($staffId > 0) {
            $staffMap[$uid][$storeId] = $staffId;
            if (!isset($staffMap[$uid][0])) {
                $staffMap[$uid][0] = $staffId;
            }
            return $staffId;
        }

        $storeExists = $this->newDb->name('system_store')
            ->where('id', $storeId)
            ->where('is_del', 0)
            ->count();
        if (!$storeExists) {
            return 0;
        }

        $user = $this->oldDb->name('user')->where('uid', $uid)->find();
        if (!$user) {
            return 0;
        }
        $user = is_array($user) ? $user : $user->toArray();
        $user['store_id'] = $storeId;

        if (!$this->createStaffFromUser($user)) {
            $staffId = (int)$this->newDb->name('system_store_staff')
                ->where('uid', $uid)
                ->where('store_id', $storeId)
                ->where('is_del', 0)
                ->value('id');
        } else {
            $staffId = (int)$this->newDb->name('system_store_staff')
                ->where('uid', $uid)
                ->where('store_id', $storeId)
                ->where('is_del', 0)
                ->value('id');
        }
        if ($staffId > 0) {
            $staffMap[$uid][$storeId] = $staffId;
            if (!isset($staffMap[$uid][0])) {
                $staffMap[$uid][0] = $staffId;
            }
        }
        return $staffId;
    }

    /**
     * 已核销/待评价/已完成（旧 status 3/4/6）→ 核销记录（不生成 staff_yeji）
     */
    protected function migrateReservationWriteoffYeji(Output $output, array $tmpIndex, array $staffMap)
    {
        if (!$this->tableExists($this->newDb, 'store_order_writeoff')) {
            $output->writeln('<comment>  跳过预约核销：缺少 store_order_writeoff 表</comment>');
            return;
        }

        $output->writeln('<info>--- 迁移预约核销: order_work(3/4/6) → store_order_writeoff ---</info>');

        $legacyIds = $this->oldDb->name('order_work')->column('id');
        if ($legacyIds) {
            $writeoffIds = $this->newDb->name('store_order_writeoff')
                ->whereIn('reservation_oid', $legacyIds)
                ->column('id');
            if ($writeoffIds) {
                $this->newDb->name('store_order_writeoff')->whereIn('id', $writeoffIds)->delete();
                $this->stats['delete'] += count($writeoffIds);
            }
        }

        $total = 0;
        $skipped = 0;
        $lastId = 0;
        while (true) {
            $works = $this->oldDb->name('order_work')
                ->where('id', '>', $lastId)
                ->whereIn('status', [3, 4, 6])
                ->order('id', 'asc')
                ->limit($this->batch)
                ->select()
                ->toArray();
            if (!$works) {
                break;
            }

            foreach ($works as $work) {
                if ($this->insertLegacyReservationWriteoffYeji($work, $tmpIndex, $staffMap)) {
                    $total++;
                } else {
                    $skipped++;
                    $this->stats['skip']++;
                }
                $lastId = (int)$work['id'];
            }

            if (count($works) < $this->batch) {
                break;
            }
        }

        $output->writeln(sprintf('  预约核销: %d 条（跳过 %d）', $total, $skipped));
    }

    protected function insertLegacyReservationWriteoffYeji(array $work, array $tmpIndex, array $staffMap)
    {
        $reservationId = (int)($work['id'] ?? 0);
        $technicianUid = (int)($work['technician_id'] ?? 0);
        $storeId = (int)($work['store_id'] ?? 0);
        $oid = (int)($work['order_id'] ?? 0);
        $tmpSn = trim((string)($work['tmp_sn'] ?? ''));
        $tmpRows = ($tmpSn !== '' && isset($tmpIndex[$tmpSn])) ? $tmpIndex[$tmpSn] : [];
        if ($oid <= 0 && $tmpRows) {
            $oid = (int)$tmpRows[0]['order_id'];
        }
        if ($reservationId <= 0 || $technicianUid <= 0 || $oid <= 0) {
            return false;
        }

        $staffId = $this->ensureStaffForUserAtStore($technicianUid, $storeId, $staffMap);
        if ($staffId <= 0) {
            return false;
        }

        $cartCtx = $this->resolveReservationCartContext($oid, (int)($tmpRows[0]['product_id'] ?? 0), $tmpRows);
        if (!$cartCtx || (int)($cartCtx['cart_info_id'] ?? 0) <= 0) {
            return false;
        }

        $cart = $this->newDb->name('store_order_cart_info')
            ->where('id', (int)$cartCtx['cart_info_id'])
            ->find();
        if (!$cart) {
            return false;
        }
        $cart = is_array($cart) ? $cart : $cart->toArray();

        $writeoffNum = $this->resolveLegacyWriteoffCount($work, $tmpRows);
        $unitPrice = $this->resolveLegacyConsumptionUnitPrice($cart, $oid);
        $writeoffPrice = (float)bcmul((string)$unitPrice, (string)$writeoffNum, 2);
        $hxTime = (int)($work['hx_time'] ?? 0);
        if ($hxTime <= 0) {
            $hxTime = $this->parseLegacyTime($work['created_at'] ?? '');
        }
        if ($hxTime <= 0) {
            $hxTime = time();
        }

        $order = $this->newDb->name('store_order')->where('id', $oid)->find();
        if (!$order) {
            return false;
        }
        $order = is_array($order) ? $order : $order->toArray();

        $writeoffData = [
            'uid' => (int)($order['uid'] ?? $work['uid'] ?? 0),
            'oid' => $oid,
            'reservation_oid' => $reservationId,
            'order_cart_id' => (int)$cart['id'],
            'type' => (int)($cart['type'] ?? 0),
            'relation_id' => $storeId,
            'staff_id' => $staffId,
            'service_type' => 0,
            'product_id' => (int)$cart['product_id'],
            'product_type' => (int)($cart['product_type'] ?? 0),
            'writeoff_num' => $writeoffNum,
            'writeoff_price' => $writeoffPrice,
            'writeoff_code' => (string)($work['verify_code'] ?? $order['verify_code'] ?? ''),
            'add_time' => $hxTime,
            'status' => 0,
        ];
        $writeoffData = $this->sanitizeInsertData($this->newDb, 'store_order_writeoff', $writeoffData);
        $this->newDb->name('store_order_writeoff')->insert($writeoffData);
        $writeoffId = (int)$this->newDb->name('store_order_writeoff')->getLastInsID();
        if ($writeoffId <= 0) {
            return false;
        }
        $this->stats['insert']++;
        return true;
    }

    protected function resolveLegacyWriteoffCount(array $work, array $tmpRows)
    {
        if (!$tmpRows) {
            return 1;
        }
        $count = 0;
        foreach ($tmpRows as $row) {
            if ((int)($row['is_hx'] ?? 0) === 1) {
                $count++;
            }
        }
        return max($count, 1);
    }

    protected function resolveLegacyConsumptionUnitPrice(array $cart, int $oid)
    {
        $writeTimes = max((int)($cart['write_times'] ?? 0), 1);
        $unitPrice = bcdiv((string)($cart['pay_price'] ?? '0'), (string)$writeTimes, 2);
        if (bccomp($unitPrice, '0', 2) > 0) {
            return $unitPrice;
        }
        $productId = (int)($cart['product_id'] ?? 0);
        if ($productId > 0 && $this->tableExists($this->newDb, 'store_product')) {
            $product = $this->newDb->name('store_product')->where('id', $productId)->field('card_num,card_num_type')->find();
            if ($product && (int)($product['card_num'] ?? 0) > 0 && (int)($product['card_num_type'] ?? 0) === 1) {
                $payPrice = (string)$this->newDb->name('store_order')->where('id', $oid)->value('pay_price');
                if ($payPrice !== '' && bccomp($payPrice, '0', 2) > 0) {
                    return bcdiv($payPrice, (string)$product['card_num'], 2);
                }
            }
        }
        return '0';
    }

    protected function loadStaffChooseMeta(int $staffId)
    {
        static $cache = [];
        if (isset($cache[$staffId])) {
            return $cache[$staffId];
        }
        $staff = $this->newDb->name('system_store_staff')
            ->where('id', $staffId)
            ->where('is_del', 0)
            ->find();
        if (!$staff) {
            return $cache[$staffId] = [];
        }
        $staff = is_array($staff) ? $staff : $staff->toArray();
        $positionLabel = '';
        if ((int)($staff['position'] ?? 0) > 0 && $this->tableExists($this->newDb, 'position')) {
            $positionLabel = (string)$this->newDb->name('position')->where('id', (int)$staff['position'])->value('name');
        }
        return $cache[$staffId] = [
            'staff_name' => (string)($staff['staff_name'] ?? ''),
            'position' => (int)($staff['position'] ?? 0),
            'position_label' => $positionLabel,
            'position_level' => (int)($staff['position_level'] ?? 0),
            'position_level_label' => '',
        ];
    }

    /**
     * 门店/收银台用户列表依赖 store_user；旧系统用 user.store_id，需补全关联
     */
    protected function migrateStoreUserRelations(Output $output)
    {
        if (!$this->tableExists($this->newDb, 'store_user')) {
            $output->writeln('<comment>新库无 store_user 表，跳过门店用户关联</comment>');
            return;
        }

        $output->writeln('<info>--- 生成门店用户关联 store_user ---</info>');

        $existing = [];
        $rows = $this->newDb->name('store_user')->field('uid,store_id')->select()->toArray();
        foreach ($rows as $row) {
            $existing[$this->storeUserKey((int)$row['uid'], (int)$row['store_id'])] = true;
        }
        $output->writeln('  已有 store_user: ' . count($existing));

        $total = 0;
        $skipped = 0;

        // 1. 旧库 user.store_id（旧系统门店客户归属字段）
        $lastUid = 0;
        while (true) {
            $users = $this->oldDb->name('user')
                ->where('uid', '>', $lastUid)
                ->where('is_del', 0)
                ->where('store_id', '>', 0)
                ->order('uid', 'asc')
                ->limit($this->batch)
                ->field('uid,store_id,add_time,status')
                ->select()
                ->toArray();
            if (!$users) {
                break;
            }
            foreach ($users as $user) {
                if ($this->insertStoreUserIfMissing(
                    $existing,
                    (int)$user['uid'],
                    (int)$user['store_id'],
                    (int)($user['add_time'] ?? time()),
                    (int)($user['status'] ?? 1)
                )) {
                    $total++;
                } else {
                    $skipped++;
                }
                $lastUid = (int)$user['uid'];
            }
            if (count($users) < $this->batch) {
                break;
            }
        }

        // 2. 新库 user.belong_store_id（store_id 映射后可能在此）
        $lastUid = 0;
        while (true) {
            $users = $this->newDb->name('user')
                ->where('uid', '>', $lastUid)
                ->where('is_del', 0)
                ->where('belong_store_id', '>', 0)
                ->order('uid', 'asc')
                ->limit($this->batch)
                ->field('uid,belong_store_id,add_time,status')
                ->select()
                ->toArray();
            if (!$users) {
                break;
            }
            foreach ($users as $user) {
                if ($this->insertStoreUserIfMissing(
                    $existing,
                    (int)$user['uid'],
                    (int)$user['belong_store_id'],
                    (int)($user['add_time'] ?? time()),
                    (int)($user['status'] ?? 1)
                )) {
                    $total++;
                } else {
                    $skipped++;
                }
                $lastUid = (int)$user['uid'];
            }
            if (count($users) < $this->batch) {
                break;
            }
        }

        // 3. 归属门店记录 user_belong_store
        if ($this->tableExists($this->newDb, 'user_belong_store')) {
            $lastId = 0;
            while (true) {
                $records = $this->newDb->name('user_belong_store')
                    ->where('id', '>', $lastId)
                    ->where('uid', '>', 0)
                    ->where('store_id', '>', 0)
                    ->order('id', 'asc')
                    ->limit($this->batch)
                    ->field('id,uid,store_id,add_time')
                    ->select()
                    ->toArray();
                if (!$records) {
                    break;
                }
                foreach ($records as $record) {
                    if ($this->insertStoreUserIfMissing(
                        $existing,
                        (int)$record['uid'],
                        (int)$record['store_id'],
                        (int)($record['add_time'] ?? time())
                    )) {
                        $total++;
                    } else {
                        $skipped++;
                    }
                    $lastId = (int)$record['id'];
                }
                if (count($records) < $this->batch) {
                    break;
                }
            }
        }

        // 4. 旧库订单：在该门店消费过的用户
        if ($this->tableExists($this->oldDb, 'store_order')) {
            $lastId = 0;
            while (true) {
                $orders = $this->oldDb->name('store_order')
                    ->where('id', '>', $lastId)
                    ->where('uid', '>', 0)
                    ->where('store_id', '>', 0)
                    ->where('paid', 1)
                    ->where('is_del', 0)
                    ->order('id', 'asc')
                    ->limit($this->batch)
                    ->field('id,uid,store_id,add_time')
                    ->select()
                    ->toArray();
                if (!$orders) {
                    break;
                }
                foreach ($orders as $order) {
                    if ($this->insertStoreUserIfMissing(
                        $existing,
                        (int)$order['uid'],
                        (int)$order['store_id'],
                        (int)($order['add_time'] ?? time())
                    )) {
                        $total++;
                    } else {
                        $skipped++;
                    }
                    $lastId = (int)$order['id'];
                }
                if (count($orders) < $this->batch) {
                    break;
                }
            }
        }

        $output->writeln(sprintf('  补充 store_user: %d 条（跳过 %d）', $total, $skipped));
        $this->printStats($output, 'store_user');
    }

    protected function storeUserKey(int $uid, int $storeId)
    {
        return $uid . '_' . $storeId;
    }

    protected function insertStoreUserIfMissing(array &$existing, int $uid, int $storeId, int $addTime = 0, int $status = 1)
    {
        if ($uid <= 0 || $storeId <= 0) {
            $this->stats['skip']++;
            return false;
        }

        $key = $this->storeUserKey($uid, $storeId);
        if (isset($existing[$key])) {
            $this->stats['skip']++;
            return false;
        }

        if (!$this->newDb->name('user')->where('uid', $uid)->where('is_del', 0)->count()) {
            $this->stats['skip']++;
            return false;
        }
        if (!$this->newDb->name('system_store')->where('id', $storeId)->where('is_del', 0)->count()) {
            $this->stats['skip']++;
            return false;
        }

        $this->newDb->name('store_user')->insert(
            $this->sanitizeInsertData($this->newDb, 'store_user', [
                'store_id' => $storeId,
                'uid' => $uid,
                'status' => $status > 0 ? 1 : 0,
                'add_time' => $addTime > 0 ? $addTime : time(),
            ])
        );
        $existing[$key] = true;
        $this->stats['insert']++;

        $this->newDb->name('user')
            ->where('uid', $uid)
            ->where('belong_store_id', 0)
            ->update(['belong_store_id' => $storeId]);

        return true;
    }

    /**
     * 旧系统员工分组用户：按 store_id 在对应门店生成 system_store_staff
     * 排除「顾客」「离职人员」分组
     */
    protected function migrateStaffFromUserGroups(Output $output)
    {
        if (!$this->tableExists($this->newDb, 'system_store_staff')) {
            $output->writeln('<comment>新库无 system_store_staff 表，跳过员工生成</comment>');
            return;
        }
        if (!$this->tableExists($this->oldDb, 'user')) {
            $output->writeln('<comment>旧库无 user 表，跳过员工生成</comment>');
            return;
        }

        $excludeGroupIds = $this->loadExcludedStaffUserGroupIds();
        $output->writeln('<info>--- 按用户分组生成门店员工（排除顾客/离职人员）---</info>');
        $output->writeln('  排除分组 ID: ' . implode(',', $excludeGroupIds));

        $total = 0;
        $skipped = 0;
        $lastUid = 0;

        while (true) {
            $users = $this->oldDb->name('user')
                ->where('uid', '>', $lastUid)
                ->where('is_del', 0)
                ->where('group_id', '>', 0)
                ->whereNotIn('group_id', $excludeGroupIds)
                ->order('uid', 'asc')
                ->limit($this->batch)
                ->select()
                ->toArray();

            if (!$users) {
                break;
            }

            foreach ($users as $user) {
                if ($this->createStaffFromUser($user)) {
                    $total++;
                } else {
                    $skipped++;
                }
                $lastUid = (int)$user['uid'];
            }

            if (count($users) < $this->batch) {
                break;
            }
        }

        $output->writeln(sprintf('  生成门店员工: %d 条（跳过 %d）', $total, $skipped));
        $output->writeln('<comment>  员工默认登录密码: 123456（旧用户密码为 MD5，无法直接用于门店 bcrypt 登录）</comment>');
        $this->printStats($output, 'staff_from_user');
    }

    /**
     * 不参与员工迁移的分组：顾客、离职人员
     */
    protected function loadExcludedStaffUserGroupIds()
    {
        $fallback = [6, 11];
        if (!$this->tableExists($this->oldDb, 'user_group')) {
            return $fallback;
        }
        $ids = $this->oldDb->name('user_group')
            ->whereIn('group_name', ['顾客', '离职人员'])
            ->column('id');
        $ids = array_values(array_unique(array_map('intval', $ids ?: [])));
        return $ids ?: $fallback;
    }

    /**
     * 旧库「管家」分组 ID（职位映射为店长）
     */
    protected function loadButlerUserGroupIds()
    {
        if ($this->butlerUserGroupIds !== null) {
            return $this->butlerUserGroupIds;
        }
        if (!$this->tableExists($this->oldDb, 'user_group')) {
            $this->butlerUserGroupIds = [8];
            return $this->butlerUserGroupIds;
        }
        $ids = $this->oldDb->name('user_group')
            ->where('group_name', '管家')
            ->column('id');
        $this->butlerUserGroupIds = array_values(array_unique(array_map('intval', $ids ?: [8])));
        return $this->butlerUserGroupIds;
    }

    /**
     * 新库职位 ID：管家→店长，其余→护理师
     */
    protected function resolveStaffPositionId(int $groupId)
    {
        $map = $this->loadPositionIdByName();
        if (in_array($groupId, $this->loadButlerUserGroupIds(), true)) {
            return $map['店长'] ?? 1;
        }
        return $map['护理师'] ?? 4;
    }

    protected function loadPositionIdByName()
    {
        if ($this->positionIdByName !== null) {
            return $this->positionIdByName;
        }
        $this->positionIdByName = [];
        if ($this->tableExists($this->newDb, 'position')) {
            $rows = $this->newDb->name('position')->column('name', 'id');
            foreach ($rows as $id => $name) {
                $name = trim((string)$name);
                if ($name !== '') {
                    $this->positionIdByName[$name] = (int)$id;
                }
            }
        }
        if (!$this->positionIdByName) {
            $this->positionIdByName = ['店长' => 1, '护理师' => 4];
        }
        return $this->positionIdByName;
    }

    protected function createStaffFromUser(array $user)
    {
        $uid = (int)($user['uid'] ?? 0);
        if ($uid <= 0) {
            $this->stats['skip']++;
            return false;
        }

        $storeId = $this->resolveUserStoreId($user);
        if ($storeId <= 0) {
            $this->stats['skip']++;
            return false;
        }

        $storeExists = $this->newDb->name('system_store')
            ->where('id', $storeId)
            ->where('is_del', 0)
            ->count();
        if (!$storeExists) {
            $this->stats['skip']++;
            return false;
        }

        $exists = $this->newDb->name('system_store_staff')
            ->where('uid', $uid)
            ->where('store_id', $storeId)
            ->where('is_del', 0)
            ->find();
        if ($exists) {
            $this->stats['skip']++;
            return false;
        }

        $account = $this->resolveStaffAccount($user);
        if ($this->newDb->name('system_store_staff')->where('account', $account)->where('is_del', 0)->count()) {
            $account = $account . '_' . $uid;
        }

        $groupId = (int)($user['group_id'] ?? 0);
        $isButler = in_array($groupId, $this->loadButlerUserGroupIds(), true);
        $positionId = $this->resolveStaffPositionId($groupId);
        $staff = [
            'store_id' => $storeId,
            'uid' => $uid,
            'account' => $account,
            'pwd' => $this->resolveStaffPassword($user['pwd'] ?? ''),
            'avatar' => (string)($user['avatar'] ?? ''),
            'staff_name' => (string)($user['real_name'] ?: ($user['nickname'] ?? '') ?: $account),
            'phone' => (string)($user['phone'] ?? ''),
            'roles' => '',
            'level' => 1,
            'position' => $positionId,
            'position_level' => 0,
            'verify_status' => in_array($groupId, [7, 10], true) ? 1 : 0,
            'order_status' => 1,
            'is_admin' => 0,
            'is_store' => 1,
            'is_manager' => $isButler ? 1 : 0,
            'is_cashier' => $groupId === 15 ? 1 : 0,
            'is_customer' => 0,
            'status' => (int)($user['status'] ?? 1),
            'is_del' => 0,
            'add_time' => (int)($user['add_time'] ?? time()),
            'customer_phone' => (string)($user['phone'] ?? ''),
        ];

        $this->newDb->name('system_store_staff')->insert(
            $this->sanitizeInsertData($this->newDb, 'system_store_staff', $staff)
        );
        $this->stats['insert']++;
        return true;
    }

    protected function resolveUserStoreId(array $user)
    {
        $storeId = (int)($user['store_id'] ?? 0);
        if ($storeId <= 0 && !empty($user['manage_store'])) {
            foreach (explode(',', (string)$user['manage_store']) as $part) {
                $id = (int)trim($part);
                if ($id > 0) {
                    return $id;
                }
            }
        }
        if ($storeId <= 0) {
            $storeId = (int)($user['belong_store_id'] ?? 0);
        }
        return $storeId;
    }

    protected function resolveStaffAccount(array $user)
    {
        $account = trim((string)($user['account'] ?? ''));
        if ($account !== '') {
            return $account;
        }
        $phone = trim((string)($user['phone'] ?? ''));
        if ($phone !== '') {
            return $phone;
        }
        return 'staff_' . (int)($user['uid'] ?? 0);
    }

    /**
     * 门店员工登录使用 bcrypt；旧用户密码为 MD5 无法复用，默认 123456
     */
    protected function resolveStaffPassword($userPwd)
    {
        $userPwd = (string)$userPwd;
        if ($userPwd !== '' && strncmp($userPwd, '$2y$', 4) === 0) {
            return $userPwd;
        }
        return password_hash('123456', PASSWORD_BCRYPT);
    }

    /**
     * lin12 基线 schema 可能缺少卡项订单字段
     */
    protected function ensureOrderCartInfoColumns(Output $output)
    {
        if (!$this->tableExists($this->newDb, 'store_order_cart_info')) {
            return;
        }
        $columns = $this->tableColumns($this->newDb, 'store_order_cart_info');
        $table = str_replace('`', '', $this->fullTable($this->newDb, 'store_order_cart_info'));
        $added = [];
        if (!in_array('cart_type', $columns, true)) {
            $this->newDb->execute(
                'ALTER TABLE `' . $table . '` ADD COLUMN `cart_type` tinyint(1) NOT NULL DEFAULT 0 '
                . 'COMMENT \'订单商品类型0:普通1:赠品2:卡项关联商品3：收银台无码商品\' AFTER `cart_id`'
            );
            $added[] = 'cart_type';
        }
        if (!in_array('is_card', $columns, true)) {
            $this->newDb->execute(
                'ALTER TABLE `' . $table . '` ADD COLUMN `is_card` tinyint(1) NOT NULL DEFAULT 0 '
                . 'COMMENT \'是否卡项关联商品1:是0:否\' AFTER `is_gift`'
            );
            $added[] = 'is_card';
        }
        if ($added) {
            $output->writeln('  已补全 store_order_cart_info 字段: ' . implode(', ', $added));
        }
    }

    /**
     * lin12 基线 schema 可能缺少收银台业绩 JSON 字段
     */
    protected function ensureStoreOrderYejiColumns(Output $output)
    {
        if (!$this->tableExists($this->newDb, 'store_order')) {
            return;
        }
        $columns = $this->tableColumns($this->newDb, 'store_order');
        $table = str_replace('`', '', $this->fullTable($this->newDb, 'store_order'));
        $added = [];
        if (!in_array('yeji', $columns, true)) {
            $this->newDb->execute(
                'ALTER TABLE `' . $table . '` ADD COLUMN `yeji` text NULL COMMENT \'销售业绩JSON\''
            );
            $added[] = 'yeji';
        }
        if (!in_array('service_yeji', $columns, true)) {
            $this->newDb->execute(
                'ALTER TABLE `' . $table . '` ADD COLUMN `service_yeji` text NULL COMMENT \'劳动业绩/手艺人JSON\''
            );
            $added[] = 'service_yeji';
        }
        if ($added) {
            $output->writeln('  已补全 store_order 字段: ' . implode(', ', $added));
        }
    }

    protected function orderCartInfoHasColumn(string $column): bool
    {
        if (!$this->tableExists($this->newDb, 'store_order_cart_info')) {
            return false;
        }
        return in_array($column, $this->tableColumns($this->newDb, 'store_order_cart_info'), true);
    }

    /**
     * 旧次卡(product_type=4) → 原 ID 保留为卡项(5) + 新建关联项目(6)
     * 收银台仍用原商品 ID 售卖卡项；项目可卡内关联，也可在收银台「项目」Tab 单独下单
     */
    protected function migrateLegacyTimesCardProducts(Output $output)
    {
        if (!$this->tableExists($this->newDb, 'store_product')) {
            return;
        }

        $output->writeln('<info>--- 旧次卡(product_type=4) → 原ID卡项(5)+新建项目(6) ---</info>');
        $this->timesCardProductMap = [];
        $preserveIds = $this->loadCustomCardPreserveProductIds();

        $converted = 0;
        $skipped = 0;
        $lastId = 0;

        while (true) {
            $products = $this->newDb->name('store_product')
                ->where('id', '>', $lastId)
                ->where('product_type', 4)
                ->where('is_del', 0)
                ->order('id', 'asc')
                ->limit($this->batch)
                ->select()
                ->toArray();
            if (!$products) {
                break;
            }

            foreach ($products as $product) {
                $lastId = (int)$product['id'];
                if (in_array($lastId, $preserveIds, true)) {
                    $skipped++;
                    $this->stats['skip']++;
                    continue;
                }
                if ($this->convertLegacyTimesCardProduct($product)) {
                    $converted++;
                } else {
                    $skipped++;
                    $this->stats['skip']++;
                }
            }

            if (count($products) < $this->batch) {
                break;
            }
        }

        $output->writeln(sprintf('  次卡转卡项商品: %d 个（跳过 %d）', $converted, $skipped));
        $this->printStats($output, 'times_card_product');
    }

    protected function convertLegacyTimesCardProduct(array $product)
    {
        $cardId = (int)($product['id'] ?? 0);
        if ($cardId <= 0) {
            return false;
        }

        $attr = $this->loadDefaultProductAttrValue($cardId);
        if (!$attr) {
            return false;
        }

        // 原次卡 ID 保留为卡项（收银台加购 ID 与迁移前一致）
        $cardUpdate = [
            'product_type' => 5,
            'delivery_type' => '[2]',
        ];
        if (empty($product['card_cover'])) {
            $cardUpdate['card_cover'] = 1;
        }
        if (empty($product['card_cover_image']) && !empty($product['image'])) {
            $cardUpdate['card_cover_image'] = (string)$product['image'];
        }
        $this->newDb->name('store_product')->where('id', $cardId)->update($cardUpdate);
        $this->newDb->name('store_product_attr_value')
            ->where('product_id', $cardId)
            ->where('type', 0)
            ->update(['product_type' => 5]);

        $serviceProduct = $product;
        unset($serviceProduct['id']);
        $serviceProduct['product_type'] = 6;
        $serviceProduct['spu'] = 'MS' . str_pad((string)$cardId, 11, '0', STR_PAD_LEFT);
        $serviceProduct['reservation_type'] = max(2, (int)($product['reservation_type'] ?? 2));
        $serviceProduct['add_time'] = time();

        $serviceColumns = $this->tableColumns($this->newDb, 'store_product');
        $serviceData = $this->sanitizeInsertData(
            $this->newDb,
            'store_product',
            $this->pickFields($serviceProduct, $serviceColumns, [])
        );
        $this->newDb->name('store_product')->insert($serviceData);
        $serviceId = (int)$this->newDb->name('store_product')->getLastInsID();
        if ($serviceId <= 0) {
            return false;
        }
        $this->stats['insert']++;

        $this->cloneProductChildRows($cardId, $serviceId, ['store_product_cate', 'store_product_description']);
        $this->cloneProductAttrDefinition($cardId, $serviceId);

        $serviceAttrUnique = substr(md5('svc_attr_' . $cardId . '_' . $serviceId), 0, 8);
        $serviceAttr = $attr;
        unset($serviceAttr['id']);
        $serviceAttr['product_id'] = $serviceId;
        $serviceAttr['product_type'] = 6;
        $serviceAttr['unique'] = $serviceAttrUnique;
        $this->newDb->name('store_product_attr_value')->insert(
            $this->sanitizeInsertData(
                $this->newDb,
                'store_product_attr_value',
                $this->pickFields($serviceAttr, $this->tableColumns($this->newDb, 'store_product_attr_value'), [])
            )
        );
        $this->stats['insert']++;
        $this->cloneProductAttrResult($cardId, $serviceId);
        $this->ensureDefaultProductSpec($serviceId, 6, $cardId, $serviceAttrUnique);

        if ($this->tableExists($this->newDb, 'store_card_related')) {
            $this->newDb->name('store_card_related')->where('card_product_id', $cardId)->delete();
            $related = [
                'card_product_id' => $cardId,
                'product_id' => $serviceId,
                'product_type' => 6,
                'product_attr_unique' => $serviceAttrUnique,
                'cost' => (float)($attr['cost'] ?? 0),
                'price' => (float)($attr['price'] ?? 0),
                'write_times' => (int)($attr['write_times'] ?? 1),
                'status' => 1,
                'add_time' => time(),
            ];
            $this->newDb->name('store_card_related')->insert(
                $this->sanitizeInsertData($this->newDb, 'store_card_related', $related)
            );
            $this->stats['insert']++;
        }

        $this->syncTimesCardStoreBranchProducts($cardId, $serviceId, $serviceAttrUnique);

        $this->timesCardProductMap[$cardId] = [
            'service_id' => $serviceId,
            'card_id' => $cardId,
            'service_attr_unique' => $serviceAttrUnique,
            'card_attr_unique' => (string)($attr['unique'] ?? ''),
            'write_times' => (int)($attr['write_times'] ?? 1),
            'price' => (string)($attr['price'] ?? '0'),
            'cost' => (string)($attr['cost'] ?? '0'),
        ];
        return true;
    }

    /**
     * 次卡转卡项后：门店子商品与平台主商品对齐
     * - 项目副本(pid 仍指向旧次卡)改挂到新建的平台项目主商品
     * - 卡项副本 product_type 与平台卡项主商品一致
     * - 各门店有卡项副本时，同步补全项目副本（收银台「项目」Tab 可单独下单）
     */
    protected function syncTimesCardStoreBranchProducts(int $cardId, int $serviceId, string $serviceAttrUnique = ''): void
    {
        if (!$this->tableExists($this->newDb, 'store_product') || $cardId <= 0) {
            return;
        }

        if ($serviceId > 0) {
            $serviceBranchIds = $this->newDb->name('store_product')
                ->where('pid', $cardId)
                ->where('product_type', 6)
                ->column('id');
            if ($serviceBranchIds) {
                $this->newDb->name('store_product')
                    ->where('pid', $cardId)
                    ->where('product_type', 6)
                    ->update(['pid' => $serviceId]);
                foreach ($serviceBranchIds as $branchId) {
                    $this->syncProductAttrValueProductType((int)$branchId, 6);
                }
                $this->stats['update'] += count($serviceBranchIds);
            }
        }

        $cardBranchIds = $this->newDb->name('store_product')
            ->where('pid', $cardId)
            ->whereIn('product_type', [4, 5])
            ->column('id');
        if ($cardBranchIds) {
            $this->newDb->name('store_product')
                ->where('pid', $cardId)
                ->whereIn('product_type', [4, 5])
                ->update(['product_type' => 5]);
            foreach ($cardBranchIds as $branchId) {
                $this->syncProductAttrValueProductType((int)$branchId, 5);
            }
            $this->stats['update'] += count($cardBranchIds);
        }

        if ($serviceId > 0) {
            $this->ensureServiceStoreBranchProductsFromCardBranches($cardId, $serviceId, $serviceAttrUnique);
        }
    }

    /**
     * 已迁移环境：按 store_card_related 补全各门店项目副本
     */
    protected function repairServiceStoreBranchProducts(Output $output): void
    {
        if (!$this->tableExists($this->newDb, 'store_card_related')) {
            $output->writeln('<comment>跳过：store_card_related 不存在</comment>');
            return;
        }

        $output->writeln('<info>--- 补全次卡关联项目的门店副本（收银台「项目」Tab） ---</info>');
        $this->ensureTimesCardProductMapLoaded();
        $pairs = count($this->timesCardProductMap);
        foreach ($this->timesCardProductMap as $cardId => $map) {
            $serviceId = (int)($map['service_id'] ?? 0);
            $serviceAttrUnique = (string)($map['service_attr_unique'] ?? '');
            if ($cardId <= 0 || $serviceId <= 0) {
                continue;
            }
            $this->ensureServiceStoreBranchProductsFromCardBranches((int)$cardId, $serviceId, $serviceAttrUnique);
        }
        $output->writeln(sprintf('  处理卡项/项目对: %d', $pairs));
        $this->printStats($output, 'service_store_branch');
    }

    /**
     * 平台卡项 store_card_related → 各门店副本（收银台用门店商品 ID 查关联项目）
     */
    protected function repairCardRelatedForStoreBranches(Output $output): void
    {
        if (!$this->tableExists($this->newDb, 'store_card_related') || !$this->tableExists($this->newDb, 'store_product')) {
            $output->writeln('<comment>跳过：store_card_related / store_product 不存在</comment>');
            return;
        }

        $output->writeln('<info>--- 卡项关联同步到门店副本（收银台卡项选项目） ---</info>');
        $inserted = 0;
        $skippedBranch = 0;
        $lastId = 0;

        while (true) {
            $branches = $this->newDb->name('store_product')
                ->where('id', '>', $lastId)
                ->where('type', 1)
                ->where('product_type', 5)
                ->where('is_del', 0)
                ->order('id', 'asc')
                ->limit($this->batch)
                ->select()
                ->toArray();
            if (!$branches) {
                break;
            }

            foreach ($branches as $branch) {
                $branchCardId = (int)($branch['id'] ?? 0);
                $lastId = $branchCardId;
                if ($branchCardId <= 0) {
                    continue;
                }

                $storeId = (int)($branch['relation_id'] ?? 0);
                $platformCardId = (int)($branch['pid'] ?? 0);
                if ($platformCardId <= 0 || $storeId <= 0) {
                    continue;
                }

                $exists = (int)$this->newDb->name('store_card_related')
                    ->where('card_product_id', $branchCardId)
                    ->where('status', 1)
                    ->count();
                if ($exists > 0) {
                    $skippedBranch++;
                    continue;
                }

                $platformRelations = $this->newDb->name('store_card_related')
                    ->where('card_product_id', $platformCardId)
                    ->where('status', 1)
                    ->select()
                    ->toArray();
                if (!$platformRelations) {
                    $skippedBranch++;
                    continue;
                }

                foreach ($platformRelations as $rel) {
                    $platformServiceId = (int)($rel['product_id'] ?? 0);
                    if ($platformServiceId <= 0) {
                        continue;
                    }

                    $serviceBranch = $this->newDb->name('store_product')
                        ->where('pid', $platformServiceId)
                        ->where('type', 1)
                        ->where('relation_id', $storeId)
                        ->where('is_del', 0)
                        ->find();
                    if (!$serviceBranch) {
                        continue;
                    }
                    $serviceBranch = is_array($serviceBranch) ? $serviceBranch : $serviceBranch->toArray();
                    $serviceBranchId = (int)($serviceBranch['id'] ?? 0);
                    if ($serviceBranchId <= 0) {
                        continue;
                    }

                    $branchAttrUnique = (string)($rel['product_attr_unique'] ?? '');
                    if ($branchAttrUnique !== '') {
                        $platformAttr = $this->newDb->name('store_product_attr_value')
                            ->where('product_id', $platformServiceId)
                            ->where('unique', $branchAttrUnique)
                            ->find();
                        if ($platformAttr) {
                            $platformAttr = is_array($platformAttr) ? $platformAttr : $platformAttr->toArray();
                            $branchAttr = $this->newDb->name('store_product_attr_value')
                                ->where('product_id', $serviceBranchId)
                                ->where('suk', (string)($platformAttr['suk'] ?? ''))
                                ->find();
                            if ($branchAttr) {
                                $branchAttr = is_array($branchAttr) ? $branchAttr : $branchAttr->toArray();
                                $branchAttrUnique = (string)($branchAttr['unique'] ?? $branchAttrUnique);
                            }
                        }
                    }

                    $this->newDb->name('store_card_related')->insert(
                        $this->sanitizeInsertData($this->newDb, 'store_card_related', [
                            'card_product_id' => $branchCardId,
                            'product_id' => $serviceBranchId,
                            'product_type' => (int)($rel['product_type'] ?? 6),
                            'product_attr_unique' => $branchAttrUnique,
                            'cost' => (float)($rel['cost'] ?? 0),
                            'price' => (float)($rel['price'] ?? 0),
                            'write_times' => (int)($rel['write_times'] ?? 1),
                            'status' => 1,
                            'add_time' => time(),
                        ])
                    );
                    $inserted++;
                    $this->stats['insert']++;
                }
            }

            if (count($branches) < $this->batch) {
                break;
            }
        }

        $output->writeln(sprintf('  门店卡项关联: 新增 %d 条（已有/无平台关联跳过 %d 个门店卡项）', $inserted, $skippedBranch));
        $this->printStats($output, 'card_related_store_branch');
    }

    /**
     * 各门店已有卡项副本时，补全对应的项目副本（type=1, product_type=6, pid=平台项目ID）
     */
    protected function ensureServiceStoreBranchProductsFromCardBranches(int $cardId, int $serviceId, string $platformServiceAttrUnique = ''): void
    {
        if ($cardId <= 0 || $serviceId <= 0 || !$this->tableExists($this->newDb, 'store_product')) {
            return;
        }

        $cardBranches = $this->newDb->name('store_product')
            ->where('pid', $cardId)
            ->where('type', 1)
            ->whereIn('product_type', [4, 5])
            ->where('is_del', 0)
            ->select()
            ->toArray();
        if (!$cardBranches) {
            return;
        }

        foreach ($cardBranches as $cardBranch) {
            $storeId = (int)($cardBranch['relation_id'] ?? 0);
            if ($storeId <= 0) {
                continue;
            }

            $existing = $this->newDb->name('store_product')
                ->where('pid', $serviceId)
                ->where('type', 1)
                ->where('relation_id', $storeId)
                ->where('is_del', 0)
                ->find();
            if ($existing) {
                $existing = is_array($existing) ? $existing : $existing->toArray();
                $existingId = (int)($existing['id'] ?? 0);
                if ($existingId > 0 && (int)($existing['product_type'] ?? 0) !== 6) {
                    $this->newDb->name('store_product')->where('id', $existingId)->update(['product_type' => 6]);
                    $this->syncProductAttrValueProductType($existingId, 6);
                    $this->stats['update']++;
                }
                $this->ensureDefaultProductSpec($existingId, 6, $serviceId, $platformServiceAttrUnique);
                continue;
            }

            $this->cloneServiceStoreBranchFromCardBranch($cardBranch, $serviceId, $platformServiceAttrUnique);
        }
    }

    protected function cloneServiceStoreBranchFromCardBranch(array $cardBranch, int $serviceId, string $platformServiceAttrUnique = ''): int
    {
        $cardBranchId = (int)($cardBranch['id'] ?? 0);
        if ($cardBranchId <= 0) {
            return 0;
        }

        $branchData = $cardBranch;
        unset($branchData['id']);
        $branchData['pid'] = $serviceId;
        $branchData['product_type'] = 6;

        $columns = $this->tableColumns($this->newDb, 'store_product');
        $this->newDb->name('store_product')->insert(
            $this->sanitizeInsertData(
                $this->newDb,
                'store_product',
                $this->pickFields($branchData, $columns, [])
            )
        );
        $serviceBranchId = (int)$this->newDb->name('store_product')->getLastInsID();
        if ($serviceBranchId <= 0) {
            return 0;
        }
        $this->stats['insert']++;

        $this->cloneBranchProductAttrRows($cardBranchId, $serviceBranchId, 6, $platformServiceAttrUnique);
        $this->ensureDefaultProductSpec($serviceBranchId, 6, $serviceId, $platformServiceAttrUnique);
        return $serviceBranchId;
    }

    protected function cloneBranchProductAttrRows(int $fromBranchId, int $toBranchId, int $productType, string $preferredUnique = ''): void
    {
        if ($fromBranchId <= 0 || $toBranchId <= 0) {
            return;
        }

        if ($this->tableExists($this->newDb, 'store_product_attr')) {
            $attrRows = $this->newDb->name('store_product_attr')
                ->where('product_id', $fromBranchId)
                ->where('type', 0)
                ->select()
                ->toArray();
            foreach ($attrRows as $row) {
                unset($row['id']);
                $row['product_id'] = $toBranchId;
                $this->newDb->name('store_product_attr')->insert(
                    $this->sanitizeInsertData(
                        $this->newDb,
                        'store_product_attr',
                        $this->pickFields($row, $this->tableColumns($this->newDb, 'store_product_attr'), [])
                    )
                );
                $this->stats['insert']++;
            }
        }

        if ($this->tableExists($this->newDb, 'store_product_attr_result')) {
            $resultRow = $this->newDb->name('store_product_attr_result')
                ->where('product_id', $fromBranchId)
                ->where('type', 0)
                ->find();
            if ($resultRow) {
                $resultRow = is_array($resultRow) ? $resultRow : $resultRow->toArray();
                unset($resultRow['id']);
                $resultRow['product_id'] = $toBranchId;
                $this->newDb->name('store_product_attr_result')->insert(
                    $this->sanitizeInsertData(
                        $this->newDb,
                        'store_product_attr_result',
                        $this->pickFields($resultRow, $this->tableColumns($this->newDb, 'store_product_attr_result'), [])
                    )
                );
                $this->stats['insert']++;
            }
        }

        if (!$this->tableExists($this->newDb, 'store_product_attr_value')) {
            return;
        }

        $valueRows = $this->newDb->name('store_product_attr_value')
            ->where('product_id', $fromBranchId)
            ->where('type', 0)
            ->select()
            ->toArray();
        foreach ($valueRows as $row) {
            unset($row['id']);
            $row['product_id'] = $toBranchId;
            $row['product_type'] = $productType;
            // 每个 product_id 必须有独立 unique，禁止多门店复用平台 SKU unique
            $row['unique'] = $this->generateProductAttrUnique($toBranchId, (string)($row['suk'] ?? '默认'));
            $this->newDb->name('store_product_attr_value')->insert(
                $this->sanitizeInsertData(
                    $this->newDb,
                    'store_product_attr_value',
                    $this->pickFields($row, $this->tableColumns($this->newDb, 'store_product_attr_value'), [])
                )
            );
            $this->stats['insert']++;
        }
    }

    /**
     * 全量对齐：pid>0 的门店商品 product_type 与主商品(pid)一致，并同步规格表
     */
    protected function syncStoreBranchProductTypesFromParent(Output $output): void
    {
        if (!$this->tableExists($this->newDb, 'store_product')) {
            return;
        }

        $output->writeln('<info>--- 门店商品 product_type 与主商品对齐 ---</info>');
        $updated = 0;
        $lastId = 0;

        while (true) {
            $children = $this->newDb->name('store_product')
                ->where('id', '>', $lastId)
                ->where('pid', '>', 0)
                ->order('id', 'asc')
                ->limit($this->batch)
                ->select()
                ->toArray();
            if (!$children) {
                break;
            }

            foreach ($children as $child) {
                $childId = (int)($child['id'] ?? 0);
                $lastId = $childId;
                if ($childId <= 0) {
                    continue;
                }

                $pid = (int)($child['pid'] ?? 0);
                if ($pid <= 0) {
                    continue;
                }

                $parentType = (int)$this->newDb->name('store_product')->where('id', $pid)->value('product_type');
                if ($parentType <= 0) {
                    continue;
                }

                $childType = (int)($child['product_type'] ?? 0);
                if ($childType === $parentType) {
                    continue;
                }

                $this->newDb->name('store_product')->where('id', $childId)->update(['product_type' => $parentType]);
                $this->syncProductAttrValueProductType($childId, $parentType);
                $updated++;
                $this->stats['update']++;
            }

            if (count($children) < $this->batch) {
                break;
            }
        }

        $output->writeln(sprintf('  对齐门店商品 product_type: %d 条', $updated));
        $this->printStats($output, 'store_branch_product_type');
    }

    protected function syncProductAttrValueProductType(int $productId, int $productType): void
    {
        if ($productId <= 0 || !$this->tableExists($this->newDb, 'store_product_attr_value')) {
            return;
        }
        $this->newDb->name('store_product_attr_value')
            ->where('product_id', $productId)
            ->update(['product_type' => $productType]);
    }

    protected function loadDefaultProductAttrValue(int $productId)
    {
        $attr = $this->newDb->name('store_product_attr_value')
            ->where('product_id', $productId)
            ->where('type', 0)
            ->order('is_default_select', 'desc')
            ->order('id', 'asc')
            ->find();
        if (!$attr) {
            $attr = $this->newDb->name('store_product_attr_value')
                ->where('product_id', $productId)
                ->where('type', 0)
                ->order('id', 'asc')
                ->find();
        }
        if (!$attr) {
            return null;
        }
        return is_array($attr) ? $attr : $attr->toArray();
    }

    protected function productHasAttrValue(int $productId): bool
    {
        return $this->productHasValidAttrValue($productId);
    }

    protected function productHasValidAttrValue(int $productId): bool
    {
        if ($productId <= 0 || !$this->tableExists($this->newDb, 'store_product_attr_value')) {
            return false;
        }
        $row = $this->newDb->name('store_product_attr_value')
            ->where('product_id', $productId)
            ->where('type', 0)
            ->where('unique', '<>', '')
            ->where('is_show', 1)
            ->order('is_default_select', 'desc')
            ->order('id', 'asc')
            ->find();
        if (!$row) {
            return false;
        }
        $row = is_array($row) ? $row : $row->toArray();
        $unique = (string)($row['unique'] ?? '');
        if ($unique === '') {
            return false;
        }
        // unique 被多个 product_id 共用会导致 getOne(unique) 命中别的商品，收银台报「请选择有效的商品属性」
        $ownerId = (int)$this->newDb->name('store_product_attr_value')
            ->where('unique', $unique)
            ->where('type', 0)
            ->order('id', 'asc')
            ->value('product_id');
        return $ownerId === $productId;
    }

    protected function generateProductAttrUnique(int $productId, string $suk = '默认', int $salt = 0): string
    {
        $unique = substr(md5('attr_' . $productId . '_' . $suk . ($salt > 0 ? '_' . $salt : '')), 0, 8);
        if (!$this->tableExists($this->newDb, 'store_product_attr_value')) {
            return $unique;
        }
        $conflict = (int)$this->newDb->name('store_product_attr_value')
            ->where('unique', $unique)
            ->where('type', 0)
            ->where('product_id', '<>', $productId)
            ->count();
        if ($conflict > 0 && $salt < 8) {
            return $this->generateProductAttrUnique($productId, $suk, $salt + 1);
        }
        return $unique;
    }

    /**
     * 修复多个 product_id 共用同一 unique 的 SKU（迁移时复用了平台 unique 导致）
     */
    protected function repairDuplicateProductAttrUniques(Output $output): void
    {
        if (!$this->tableExists($this->newDb, 'store_product_attr_value')) {
            return;
        }

        $table = str_replace('`', '', $this->fullTable($this->newDb, 'store_product_attr_value'));
        $dupUniques = $this->newDb->query(
            'SELECT `unique` FROM `' . $table . '` WHERE `type` = 0 AND `unique` <> \'\' GROUP BY `unique` HAVING COUNT(DISTINCT `product_id`) > 1'
        );
        if (!$dupUniques) {
            $output->writeln('  无重复 SKU unique');
            return;
        }

        $output->writeln('<info>--- 修复重复 SKU unique ---</info>');
        $fixed = 0;

        foreach ($dupUniques as $dup) {
            $oldUnique = (string)($dup['unique'] ?? '');
            if ($oldUnique === '') {
                continue;
            }
            $rows = $this->newDb->name('store_product_attr_value')
                ->where('unique', $oldUnique)
                ->where('type', 0)
                ->order('id', 'asc')
                ->select()
                ->toArray();
            foreach ($rows as $row) {
                $attrId = (int)($row['id'] ?? 0);
                $productId = (int)($row['product_id'] ?? 0);
                if ($attrId <= 0 || $productId <= 0) {
                    continue;
                }
                $newUnique = $this->generateProductAttrUnique($productId, (string)($row['suk'] ?? '默认'));
                if ($newUnique === $oldUnique) {
                    continue;
                }
                $this->newDb->name('store_product_attr_value')->where('id', $attrId)->update(['unique' => $newUnique]);
                if ($this->tableExists($this->newDb, 'store_card_related')) {
                    $this->newDb->name('store_card_related')
                        ->where('product_id', $productId)
                        ->where('product_attr_unique', $oldUnique)
                        ->update(['product_attr_unique' => $newUnique]);
                }
                $fixed++;
                $this->stats['update']++;
            }
        }

        $output->writeln(sprintf('  重复 unique 修复: %d 条 SKU', $fixed));
        $this->printStats($output, 'duplicate_attr_unique');
    }

    protected function clearInvalidProductAttrValues(int $productId): void
    {
        if ($productId <= 0) {
            return;
        }
        if ($this->productHasValidAttrValue($productId)) {
            return;
        }
        if ($this->tableExists($this->newDb, 'store_product_attr_value')) {
            $this->newDb->name('store_product_attr_value')
                ->where('product_id', $productId)
                ->where('type', 0)
                ->delete();
        }
        if ($this->tableExists($this->newDb, 'store_product_attr')) {
            $this->newDb->name('store_product_attr')
                ->where('product_id', $productId)
                ->where('type', 0)
                ->delete();
        }
        if ($this->tableExists($this->newDb, 'store_product_attr_result')) {
            $this->newDb->name('store_product_attr_result')
                ->where('product_id', $productId)
                ->where('type', 0)
                ->delete();
        }
    }

    /**
     * 补全默认规格：优先从 copyFromProductId 复制，否则按商品信息生成「默认」单规格
     */
    protected function ensureDefaultProductSpec(int $productId, int $productType = 6, int $copyFromProductId = 0, string $preferredUnique = ''): bool
    {
        if ($productId <= 0 || $this->productHasValidAttrValue($productId)) {
            return false;
        }
        $this->clearInvalidProductAttrValues($productId);

        if ($copyFromProductId > 0 && $copyFromProductId !== $productId) {
            $this->cloneProductAttrDefinition($copyFromProductId, $productId);
            $this->cloneProductAttrResult($copyFromProductId, $productId);
            $this->cloneBranchProductAttrRows($copyFromProductId, $productId, $productType, $preferredUnique);
            if ($this->productHasValidAttrValue($productId)) {
                return true;
            }
            $this->clearInvalidProductAttrValues($productId);
        }

        return $this->synthesizeDefaultProductSpec($productId, $productType, $preferredUnique);
    }

    protected function synthesizeDefaultProductSpec(int $productId, int $productType = 6, string $preferredUnique = ''): bool
    {
        if ($productId <= 0 || $this->productHasValidAttrValue($productId)) {
            return false;
        }

        $product = $this->newDb->name('store_product')->where('id', $productId)->find();
        if (!$product) {
            return false;
        }
        $product = is_array($product) ? $product : $product->toArray();

        if ($this->tableExists($this->newDb, 'store_product_attr')) {
            $attrCount = (int)$this->newDb->name('store_product_attr')
                ->where('product_id', $productId)
                ->where('type', 0)
                ->count();
            if ($attrCount === 0) {
                $this->newDb->name('store_product_attr')->insert(
                    $this->sanitizeInsertData($this->newDb, 'store_product_attr', [
                        'product_id' => $productId,
                        'attr_name' => '规格',
                        'attr_values' => json_encode(['默认'], JSON_UNESCAPED_UNICODE),
                        'type' => 0,
                    ])
                );
                $this->stats['insert']++;
            }
        }

        if ($this->tableExists($this->newDb, 'store_product_attr_result')) {
            $resultCount = (int)$this->newDb->name('store_product_attr_result')
                ->where('product_id', $productId)
                ->where('type', 0)
                ->count();
            if ($resultCount === 0) {
                $price = (string)($product['price'] ?? '0');
                $image = (string)($product['image'] ?? '');
                $stock = max((int)($product['stock'] ?? 0), 999);
                $result = [
                    'attr' => [[
                        'value' => '规格',
                        'detailValue' => '',
                        'attrHidden' => '',
                        'detail' => ['默认'],
                    ]],
                    'value' => [[
                        'value1' => '默认',
                        'detail' => ['规格' => '默认'],
                        'pic' => $image,
                        'price' => $price,
                        'settle_price' => (string)($product['settle_price'] ?? '0'),
                        'cost' => (string)($product['cost'] ?? '0'),
                        'ot_price' => (string)($product['ot_price'] ?? '0'),
                        'stock' => $stock,
                        'bar_code' => '',
                        'weight' => 0,
                        'volume' => 0,
                        'brokerage' => 0,
                        'brokerage_two' => 0,
                        'code' => 0,
                        'write_times' => 1,
                        'write_valid' => 1,
                        'days' => 0,
                        'section_time' => [],
                    ]],
                ];
                $this->newDb->name('store_product_attr_result')->insert(
                    $this->sanitizeInsertData($this->newDb, 'store_product_attr_result', [
                        'product_id' => $productId,
                        'result' => json_encode($result, JSON_UNESCAPED_UNICODE),
                        'change_time' => time(),
                        'type' => 0,
                    ])
                );
                $this->stats['insert']++;
            }
        }

        $unique = $this->generateProductAttrUnique($productId, '默认');
        $stock = max((int)($product['stock'] ?? 0), 999);
        $attrValue = [
            'type' => 0,
            'product_id' => $productId,
            'product_type' => $productType,
            'suk' => '默认',
            'stock' => $stock,
            'sum_stock' => $stock,
            'sales' => 0,
            'price' => (float)($product['price'] ?? 0),
            'settle_price' => (float)($product['settle_price'] ?? 0),
            'cost' => (float)($product['cost'] ?? 0),
            'ot_price' => (float)($product['ot_price'] ?? 0),
            'vip_price' => (float)($product['vip_price'] ?? 0),
            'image' => (string)($product['image'] ?? ''),
            'unique' => $unique,
            'write_times' => 1,
            'write_valid' => 1,
            'write_days' => 0,
            'write_start' => 0,
            'write_end' => 0,
            'is_default_select' => 1,
            'is_show' => 1,
        ];
        $this->newDb->name('store_product_attr_value')->insert(
            $this->sanitizeInsertData(
                $this->newDb,
                'store_product_attr_value',
                $this->pickFields($attrValue, $this->tableColumns($this->newDb, 'store_product_attr_value'), [])
            )
        );
        $this->stats['insert']++;

        if (array_key_exists('spec_type', $product) && (int)$product['spec_type'] !== 0) {
            $this->newDb->name('store_product')->where('id', $productId)->update(['spec_type' => 0]);
            $this->stats['update']++;
        }

        return true;
    }

    protected function cloneProductAttrResult(int $fromProductId, int $toProductId): void
    {
        if ($fromProductId <= 0 || $toProductId <= 0 || !$this->tableExists($this->newDb, 'store_product_attr_result')) {
            return;
        }
        $exists = (int)$this->newDb->name('store_product_attr_result')
            ->where('product_id', $toProductId)
            ->where('type', 0)
            ->count();
        if ($exists > 0) {
            return;
        }
        $resultRow = $this->newDb->name('store_product_attr_result')
            ->where('product_id', $fromProductId)
            ->where('type', 0)
            ->find();
        if (!$resultRow) {
            return;
        }
        $resultRow = is_array($resultRow) ? $resultRow : $resultRow->toArray();
        unset($resultRow['id']);
        $resultRow['product_id'] = $toProductId;
        $resultRow['change_time'] = time();
        $this->newDb->name('store_product_attr_result')->insert(
            $this->sanitizeInsertData(
                $this->newDb,
                'store_product_attr_result',
                $this->pickFields($resultRow, $this->tableColumns($this->newDb, 'store_product_attr_result'), [])
            )
        );
        $this->stats['insert']++;
    }

    /**
     * 补全 product_type=6 项目缺失/无效的默认规格（收银台用门店商品 ID + unique 加购）
     */
    protected function repairProductDefaultAttrs(Output $output): void
    {
        if (!$this->tableExists($this->newDb, 'store_product') || !$this->tableExists($this->newDb, 'store_product_attr_value')) {
            $output->writeln('<comment>跳过：store_product / store_product_attr_value 不存在</comment>');
            return;
        }

        $output->writeln('<info>--- 补全项目(product_type=6)默认规格（优先门店副本 type=1） ---</info>');
        $this->repairDuplicateProductAttrUniques($output);
        $this->resetStats();
        $repaired = 0;
        $skipped = 0;

        foreach ([1, 0] as $storeType) {
            $typeLabel = $storeType === 1 ? '门店副本' : '平台主商品';
            $lastId = 0;
            $typeRepaired = 0;

            while (true) {
                $query = $this->newDb->name('store_product')
                    ->where('id', '>', $lastId)
                    ->where('product_type', 6)
                    ->where('is_del', 0)
                    ->order('id', 'asc')
                    ->limit($this->batch);
                if ($storeType === 1) {
                    $query->where('type', 1);
                } else {
                    $query->where('type', '<>', 1);
                }
                $products = $query->select()->toArray();
                if (!$products) {
                    break;
                }

                foreach ($products as $product) {
                    $productId = (int)($product['id'] ?? 0);
                    $lastId = $productId;
                    if ($productId <= 0) {
                        continue;
                    }
                    if ($this->productHasValidAttrValue($productId)) {
                        $skipped++;
                        continue;
                    }

                    $copyFrom = (int)($product['pid'] ?? 0);
                    if ($this->ensureDefaultProductSpec($productId, 6, $copyFrom)) {
                        $repaired++;
                        $typeRepaired++;
                    } else {
                        $skipped++;
                    }
                }

                if (count($products) < $this->batch) {
                    break;
                }
            }

            $output->writeln(sprintf('  %s: 补全 %d 个', $typeLabel, $typeRepaired));
        }

        $output->writeln(sprintf('  默认规格合计: 补全 %d 个（有效规格已存在/失败跳过 %d）', $repaired, $skipped));
        $this->printStats($output, 'product_default_attr');
    }

    protected function cloneProductChildRows(int $fromProductId, int $toProductId, array $tables)
    {
        foreach ($tables as $table) {
            if (!$this->tableExists($this->newDb, $table)) {
                continue;
            }
            $rows = $this->newDb->name($table)->where('product_id', $fromProductId)->select()->toArray();
            foreach ($rows as $row) {
                unset($row['id']);
                $row['product_id'] = $toProductId;
                $this->newDb->name($table)->insert(
                    $this->sanitizeInsertData($this->newDb, $table, $this->pickFields($row, $this->tableColumns($this->newDb, $table), []))
                );
                $this->stats['insert']++;
            }
        }
    }

    protected function cloneProductAttrDefinition(int $fromProductId, int $toProductId)
    {
        if (!$this->tableExists($this->newDb, 'store_product_attr')) {
            return;
        }
        $rows = $this->newDb->name('store_product_attr')
            ->where('product_id', $fromProductId)
            ->where('type', 0)
            ->select()
            ->toArray();
        foreach ($rows as $row) {
            unset($row['id']);
            $row['product_id'] = $toProductId;
            $this->newDb->name('store_product_attr')->insert(
                $this->sanitizeInsertData($this->newDb, 'store_product_attr', $this->pickFields($row, $this->tableColumns($this->newDb, 'store_product_attr'), []))
            );
            $this->stats['insert']++;
        }
    }

    protected function ensureTimesCardProductMapLoaded()
    {
        if ($this->timesCardProductMap) {
            return;
        }
        if (!$this->tableExists($this->newDb, 'store_card_related')) {
            return;
        }
        $rows = $this->newDb->name('store_card_related')->where('product_type', 6)->select()->toArray();
        foreach ($rows as $row) {
            $cardId = (int)($row['card_product_id'] ?? 0);
            $serviceId = (int)($row['product_id'] ?? 0);
            if ($cardId <= 0 || $serviceId <= 0) {
                continue;
            }
            $this->timesCardProductMap[$cardId] = [
                'service_id' => $serviceId,
                'card_id' => $cardId,
                'service_attr_unique' => (string)($row['product_attr_unique'] ?? ''),
                'card_attr_unique' => '',
                'write_times' => (int)($row['write_times'] ?? 1),
                'price' => (string)($row['price'] ?? '0'),
                'cost' => (string)($row['cost'] ?? '0'),
            ];
        }
    }

    /**
     * 仅旧次卡订单(product_type=4) → 卡项订单结构；迁移后新下单不走此逻辑
     */
    protected function transformLegacyTimesCardOrders(Output $output)
    {
        if (!$this->tableExists($this->newDb, 'store_order_cart_info') || !$this->tableExists($this->newDb, 'store_order')) {
            return;
        }

        $this->ensureTimesCardProductMapLoaded();
        if (!$this->timesCardProductMap) {
            $output->writeln('<comment>  无次卡映射，跳过订单卡项结构转换</comment>');
            return;
        }

        $output->writeln('<info>--- 旧次卡订单 → 卡项订单(cart_type 0+2) ---</info>');

        $converted = 0;
        $skipped = 0;
        $lastId = 0;

        while (true) {
            $shellRows = $this->newDb->name('store_order_cart_info')
                ->where('id', '>', $lastId)
                ->where('cart_type', 0)
                ->where('product_type', 4)
                ->order('id', 'asc')
                ->limit($this->batch)
                ->select()
                ->toArray();
            if (!$shellRows) {
                break;
            }

            foreach ($shellRows as $shell) {
                $lastId = (int)$shell['id'];
                if ($this->resolveCartProductType($shell) !== 4) {
                    continue;
                }
                $cardId = (int)($shell['product_id'] ?? 0);
                if (!isset($this->timesCardProductMap[$cardId])) {
                    $skipped++;
                    $this->stats['skip']++;
                    continue;
                }
                if ($this->transformTimesCardOrderShell($shell)) {
                    $converted++;
                } else {
                    $skipped++;
                    $this->stats['skip']++;
                }
            }

            if (count($shellRows) < $this->batch) {
                break;
            }
        }

        $output->writeln(sprintf('  次卡订单转卡项结构: %d 单（跳过 %d）', $converted, $skipped));
        $this->printStats($output, 'times_card_order');
    }

    protected function transformTimesCardOrderShell(array $shell)
    {
        $oid = (int)($shell['oid'] ?? 0);
        $cardId = (int)($shell['product_id'] ?? 0);
        $map = $this->timesCardProductMap[$cardId] ?? null;
        $serviceId = (int)($map['service_id'] ?? 0);
        if ($oid <= 0 || !$map || $serviceId <= 0) {
            return false;
        }

        $order = $this->newDb->name('store_order')->where('id', $oid)->find();
        if (!$order || !(int)($order['paid'] ?? 0)) {
            return false;
        }
        $order = is_array($order) ? $order : $order->toArray();

        if ((int)($shell['product_type'] ?? 0) === 5
            && (int)$this->newDb->name('store_order_cart_info')->where('oid', $oid)->where('cart_type', 2)->count() > 0) {
            return false;
        }

        $writeTimes = (int)($shell['write_times'] ?? 0);
        $writeSurplus = (int)($shell['write_surplus_times'] ?? 0);
        if ($writeTimes <= 0) {
            $writeTimes = (int)($map['write_times'] ?? 1);
        }
        if ($writeSurplus <= 0) {
            $writeSurplus = $writeTimes;
        }

        $serviceProduct = $this->newDb->name('store_product')->where('id', $serviceId)->find();
        $cardProduct = $this->newDb->name('store_product')->where('id', $cardId)->find();
        if (!$serviceProduct || !$cardProduct) {
            return false;
        }
        $serviceProduct = is_array($serviceProduct) ? $serviceProduct : $serviceProduct->toArray();
        $cardProduct = is_array($cardProduct) ? $cardProduct : $cardProduct->toArray();

        $serviceAttr = $this->newDb->name('store_product_attr_value')
            ->where('product_id', $serviceId)
            ->where('unique', (string)$map['service_attr_unique'])
            ->find();
        $cardAttr = $this->newDb->name('store_product_attr_value')
            ->where('product_id', $cardId)
            ->order('id', 'asc')
            ->find();
        $serviceAttr = $serviceAttr ? (is_array($serviceAttr) ? $serviceAttr : $serviceAttr->toArray()) : [];
        $cardAttr = $cardAttr ? (is_array($cardAttr) ? $cardAttr : $cardAttr->toArray()) : [];

        $payPrice = (string)($shell['pay_price'] ?? '0');
        $serviceCartPayload = $this->buildMigrationOrderCartPayload(
            $serviceProduct,
            $serviceAttr,
            6,
            $writeTimes,
            $payPrice,
            (int)($map['card_id'] ?? $cardId),
            $this->generateMigrationCartId($oid, $serviceId, 2),
            $writeTimes > 0 ? $writeTimes : 1
        );

        $childExists = (int)$this->newDb->name('store_order_cart_info')
            ->where('oid', $oid)
            ->where('cart_type', 2)
            ->where('product_id', $serviceId)
            ->count();
        if (!$childExists) {
            $childRow = [
                'uid' => (int)($shell['uid'] ?? $order['uid'] ?? 0),
                'oid' => $oid,
                'cart_id' => $this->generateMigrationCartId($oid, $serviceId, 2),
                'cart_type' => 2,
                'type' => (int)($shell['type'] ?? 0),
                'relation_id' => (int)($shell['relation_id'] ?? $order['store_id'] ?? 0),
                'staff_id' => (int)($shell['staff_id'] ?? 0),
                'product_id' => $serviceId,
                'product_type' => 6,
                'sku_unique' => (string)($serviceAttr['unique'] ?? ($shell['sku_unique'] ?? '')),
                'is_gift' => 0,
                'is_card' => 1,
                'is_support_refund' => 0,
                'cart_num' => $writeTimes > 0 ? $writeTimes : 1,
                'total_price' => (float)bcmul((string)($map['price'] ?? '0'), (string)max($writeTimes, 1), 2),
                'pay_price' => (float)$payPrice,
                'surplus_num' => $writeSurplus,
                'split_surplus_num' => $writeSurplus,
                'write_times' => $writeTimes,
                'write_surplus_times' => $writeSurplus,
                'write_start' => (int)($shell['write_start'] ?? 0),
                'write_end' => (int)($shell['write_end'] ?? 0),
                'is_writeoff' => (int)($shell['is_writeoff'] ?? 0),
                'writeoff_time' => (int)($shell['writeoff_time'] ?? 0),
                'cart_info' => json_encode($serviceCartPayload, JSON_UNESCAPED_UNICODE),
                'unique' => md5('mig_child_' . $oid . '_' . $serviceId . '_' . (int)$shell['id']),
                'add_time' => (int)($shell['add_time'] ?? $order['add_time'] ?? time()),
            ];
            $this->newDb->name('store_order_cart_info')->insert(
                $this->sanitizeInsertData($this->newDb, 'store_order_cart_info', $childRow)
            );
            $this->stats['insert']++;
        }

        $shellPayload = $this->buildMigrationOrderCartPayload(
            $cardProduct,
            $cardAttr,
            5,
            $writeTimes,
            $payPrice,
            0,
            (string)($shell['cart_id'] ?? ''),
            1
        );
        $this->newDb->name('store_order_cart_info')->where('id', (int)$shell['id'])->update([
            'product_id' => $cardId,
            'product_type' => 5,
            'sku_unique' => (string)($cardAttr['unique'] ?? ($shell['sku_unique'] ?? '')),
            'write_times' => $writeTimes,
            'write_surplus_times' => $writeSurplus,
            'cart_info' => json_encode($shellPayload, JSON_UNESCAPED_UNICODE),
        ]);
        $this->stats['update']++;

        $orderUpdate = [
            'product_type' => 5,
            'type' => 11,
            'shipping_type' => 2,
        ];
        $this->newDb->name('store_order')->where('id', $oid)->update($orderUpdate);
        $this->stats['update']++;
        return true;
    }

    protected function buildMigrationOrderCartPayload(
        array $product,
        array $attr,
        int $productType,
        int $writeTimes,
        string $payPrice,
        int $cardProductId = 0,
        string $cartId = '',
        int $cartNum = 1
    ) {
        $attrInfo = [
            'unique' => (string)($attr['unique'] ?? ''),
            'suk' => (string)($attr['suk'] ?? '默认'),
            'price' => (string)($attr['price'] ?? $product['price'] ?? '0'),
            'image' => (string)($attr['image'] ?? $product['image'] ?? ''),
            'write_times' => $writeTimes > 0 ? $writeTimes : (int)($attr['write_times'] ?? 1),
            'write_valid' => (int)($attr['write_valid'] ?? 1),
            'write_days' => (int)($attr['write_days'] ?? 0),
            'write_start' => (int)($attr['write_start'] ?? 0),
            'write_end' => (int)($attr['write_end'] ?? 0),
        ];
        $productInfo = [
            'id' => (int)$product['id'],
            'store_name' => (string)($product['store_name'] ?? ''),
            'image' => (string)($product['image'] ?? ''),
            'product_type' => $productType,
            'type' => (int)($product['type'] ?? 0),
            'relation_id' => (int)($product['relation_id'] ?? 0),
            'attrInfo' => $attrInfo,
        ];
        $payload = [
            'id' => $cartId,
            'product_id' => (int)$product['id'],
            'product_attr_unique' => (string)($attr['unique'] ?? ''),
            'productInfo' => $productInfo,
            'attrInfo' => $attrInfo,
            'truePrice' => (string)($attr['price'] ?? $product['price'] ?? '0'),
            'pay_price' => $payPrice,
            'cart_num' => max($cartNum, 1),
            'write_times' => $attrInfo['write_times'],
            'cart_type' => $productType === 5 ? 0 : 2,
            'is_card' => $productType === 6 ? 1 : 0,
        ];
        if ($cardProductId > 0) {
            $payload['card_product_id'] = $cardProductId;
        }
        return $payload;
    }

    protected function generateMigrationCartId(int $oid, int $productId, int $cartType)
    {
        return 'm' . $oid . 'x' . $productId . 't' . $cartType;
    }

    /**
     * 旧库无 user_card_holder：卡项订单(product_type=5) 从 cart_type=0/2 生成卡包
     */
    protected function migrateUserCardHolder(Output $output)
    {
        if (!$this->tableExists($this->newDb, 'user_card_holder')) {
            $output->writeln('<error>新库无 user_card_holder 表</error>');
            return;
        }
        if (!$this->tableExists($this->newDb, 'store_order') || !$this->tableExists($this->newDb, 'store_order_cart_info')) {
            $output->writeln('<comment>新库无订单表，跳过 user_card_holder 生成</comment>');
            return;
        }

        $output->writeln('<info>--- 生成 user_card_holder（卡项 product_type=5）---</info>');
        $this->newDb->execute('DELETE FROM `' . $this->fullTable($this->newDb, 'user_card_holder') . '`');
        $this->stats['delete']++;
        $output->writeln('  清空: user_card_holder');

        $total = 0;
        $skipped = 0;
        $lastId = 0;
        $seenOid = [];

        while (true) {
            $query = $this->newDb->name('store_order_cart_info')
                ->where('id', '>', $lastId)
                ->where('product_type', 5);
            if ($this->orderCartInfoHasColumn('cart_type')) {
                $query->where('cart_type', 0);
            }
            $carts = $query->order('id', 'asc')
                ->limit($this->batch)
                ->select()
                ->toArray();

            if (!$carts) {
                break;
            }

            foreach ($carts as $cart) {
                $lastId = (int)$cart['id'];
                $oid = (int)($cart['oid'] ?? 0);
                if ($oid <= 0 || isset($seenOid[$oid])) {
                    continue;
                }

                $order = $this->newDb->name('store_order')
                    ->where('id', $oid)
                    ->where('paid', 1)
                    ->where('is_del', 0)
                    ->field('uid,store_id,verify_code,source,add_time,is_debt_repay')
                    ->find();
                if (!$order) {
                    continue;
                }
                $order = is_array($order) ? $order : $order->toArray();
                $row = array_merge($cart, $order);

                $holder = $this->buildUserCardHolderFromCartInfo($row);
                if (!$holder) {
                    $skipped++;
                    $this->stats['skip']++;
                    continue;
                }
                $this->newDb->name('user_card_holder')->insert(
                    $this->sanitizeInsertData($this->newDb, 'user_card_holder', $holder)
                );
                $this->stats['insert']++;
                $seenOid[$oid] = true;
                $total++;
            }

            if (count($carts) < $this->batch) {
                break;
            }
        }

        $output->writeln(sprintf('  生成 user_card_holder: %d 条（跳过 %d）', $total, $skipped));
        $this->printStats($output, 'user_card_holder');
    }

    protected function resolveCartProductType(array $cart)
    {
        $type = (int)($cart['product_type'] ?? 0);
        if ($type > 0) {
            return $type;
        }
        $info = $this->decodeCartInfoPayload($cart);
        return (int)($info['productInfo']['product_type'] ?? 0);
    }

    protected function decodeCartInfoPayload(array $cart)
    {
        $info = $cart['cart_info'] ?? '';
        if (is_string($info) && $info !== '') {
            return json_decode($info, true) ?: [];
        }
        return is_array($info) ? $info : [];
    }

    /**
     * 按新系统 setCardHolder 规则，从卡项订单组装卡包（次数取 cart_type=2 汇总）
     */
    protected function buildUserCardHolderFromCartInfo(array $cart)
    {
        $oid = (int)($cart['oid'] ?? 0);
        $uid = (int)($cart['uid'] ?? 0);
        if ($oid <= 0 || $uid <= 0) {
            return null;
        }
        if (!empty($cart['is_debt_repay'])) {
            return null;
        }

        $productType = $this->resolveCartProductType($cart);
        if ($productType !== 5) {
            return null;
        }

        $info = $this->decodeCartInfoPayload($cart);
        $productInfo = $info['productInfo'] ?? [];
        $attrInfo = $productInfo['attrInfo'] ?? [];

        $productId = (int)($cart['product_id'] ?? ($productInfo['id'] ?? 0));
        $isDel = 0;
        $source = (int)($cart['source'] ?? 0);
        if (in_array($source, [8, 9, 10], true)) {
            $productId = (int)($productInfo['product_id'] ?? $productId);
            if ($this->tableExists($this->newDb, 'store_pink')) {
                $pkStatus = $this->newDb->name('store_pink')->where('order_id_key', $oid)->value('status');
                if ($pkStatus !== null && (int)$pkStatus !== 2) {
                    $isDel = 1;
                }
            }
        }

        $writeTimesQuery = $this->newDb->name('store_order_cart_info')->where('oid', $oid);
        $writeSurplusQuery = $this->newDb->name('store_order_cart_info')->where('oid', $oid);
        if ($this->orderCartInfoHasColumn('cart_type')) {
            $writeTimesQuery->where('cart_type', 2);
            $writeSurplusQuery->where('cart_type', 2);
        } else {
            $writeTimesQuery->where('product_type', 6);
            $writeSurplusQuery->where('product_type', 6);
        }
        $writeTimes = (int)$writeTimesQuery->sum('write_times');
        $writeSurplus = (int)$writeSurplusQuery->sum('write_surplus_times');
        if ($writeTimes <= 0) {
            $writeTimes = (int)($cart['write_times'] ?? ($attrInfo['write_times'] ?? 0));
        }
        if ($writeSurplus <= 0) {
            $writeSurplus = (int)($cart['write_surplus_times'] ?? $writeTimes);
        }

        $cardName = (string)($productInfo['store_name'] ?? '');
        if ($cardName === '' && $productId > 0 && $this->tableExists($this->newDb, 'store_product')) {
            $cardName = (string)$this->newDb->name('store_product')->where('id', $productId)->value('store_name');
        }

        return [
            'uid' => $uid,
            'oid' => $oid,
            'card_name' => $cardName,
            'store_id' => (int)($cart['store_id'] ?? 0),
            'product_id' => $productId,
            'product_type' => 5,
            'verify_code' => (string)($cart['verify_code'] ?? ''),
            'write_valid' => (int)($attrInfo['write_valid'] ?? 1),
            'write_days' => (int)($attrInfo['write_days'] ?? 0),
            'write_start' => (int)($cart['write_start'] ?? 0),
            'write_end' => (int)($cart['write_end'] ?? 0),
            'write_times' => $writeTimes,
            'write_surplus_times' => $writeSurplus,
            'is_del' => $isDel,
            'add_time' => (int)($cart['add_time'] ?? time()),
        ];
    }

    /**
     * order_work_tmp 按 tmp_sn 分组（仅已提交/已处理的组合项）
     */
    protected function buildOrderWorkTmpIndex()
    {
        $index = [];
        $lastId = 0;
        $batch = 2000;

        while (true) {
            $rows = $this->oldDb->name('order_work_tmp')
                ->where('id', '>', $lastId)
                ->whereIn('status', [1, 2])
                ->order('id', 'asc')
                ->limit($batch)
                ->select()
                ->toArray();
            if (!$rows) {
                break;
            }
            foreach ($rows as $row) {
                $sn = trim((string)($row['tmp_sn'] ?? ''));
                if ($sn === '') {
                    continue;
                }
                if (!isset($index[$sn])) {
                    $index[$sn] = [];
                }
                $index[$sn][] = $row;
                $lastId = (int)$row['id'];
            }
            if (count($rows) < $batch) {
                break;
            }
        }

        return $index;
    }

    /**
     * 新库店员 uid → staff_id（优先同门店）
     */
    protected function buildStaffUidMap()
    {
        $map = [];
        if (!$this->tableExists($this->newDb, 'system_store_staff')) {
            return $map;
        }
        $rows = $this->newDb->name('system_store_staff')->field('id,uid,store_id')->select()->toArray();
        foreach ($rows as $row) {
            $uid = (int)$row['uid'];
            if ($uid <= 0) {
                continue;
            }
            $storeId = (int)$row['store_id'];
            $map[$uid][$storeId] = (int)$row['id'];
            if (!isset($map[$uid][0])) {
                $map[$uid][0] = (int)$row['id'];
            }
        }
        return $map;
    }

    protected function resolveStaffId(int $userUid, int $storeId, array $staffMap)
    {
        if ($userUid <= 0 || !$staffMap) {
            return 0;
        }
        if (isset($staffMap[$userUid][$storeId])) {
            return $staffMap[$userUid][$storeId];
        }
        return $staffMap[$userUid][0] ?? 0;
    }

    /**
     * 旧预约状态 → 新预约状态
     * 旧: 1已预约 2待服务 3已核销 4已完成 5已退回 6待评价
     * 新: -1已取消 0待服务 1服务中 2已完成 3待确认 4已退回
     */
    protected function mapOrderWorkStatus(array $work)
    {
        if ((int)($work['refund_status'] ?? 0) === 2) {
            return -1;
        }
        $oldStatus = (int)($work['status'] ?? 0);
        if ($oldStatus === 5) {
            return 4;
        }
        if ($oldStatus === 1) {
            return 3;
        }
        if ($oldStatus === 2) {
            return (int)($work['do_time'] ?? 0) > 0 ? 1 : 0;
        }
        if (in_array($oldStatus, [3, 4, 6], true)) {
            return 2;
        }
        return 0;
    }

    protected function mapOrderWorkToReservation(array $work, array $tmpIndex, array $staffMap)
    {
        $storeId = (int)($work['store_id'] ?? 0);
        $technicianUid = (int)($work['technician_id'] ?? 0);
        $masterUid = (int)($work['master_id'] ?? 0);
        $serviceStaffId = $this->ensureStaffForUserAtStore($technicianUid, $storeId, $staffMap);
        $confirmStaffId = $this->ensureStaffForUserAtStore($masterUid, $storeId, $staffMap);

        $tmpSn = trim((string)($work['tmp_sn'] ?? ''));
        $tmpRows = ($tmpSn !== '' && isset($tmpIndex[$tmpSn])) ? $tmpIndex[$tmpSn] : [];

        $oid = (int)($work['order_id'] ?? 0);
        $productId = 0;
        if ($oid <= 0 && $tmpRows) {
            $oid = (int)$tmpRows[0]['order_id'];
            $productId = (int)$tmpRows[0]['product_id'];
        }

        $cartCtx = $this->resolveReservationCartContext($oid, $productId, $tmpRows);
        if ($oid > 0 && !$cartCtx && !$tmpRows) {
            // 订单或购物车尚未迁入新库时仍写入主表字段，商品字段留空
            $cartCtx = [];
        }

        $appointmentTime = (int)($work['appointment_time'] ?? 0);
        if ($appointmentTime <= 0) {
            $appointmentTime = $this->parseLegacyTime($work['created_at'] ?? '');
        }

        $workMinutes = (int)($work['work_time'] ?? 0);
        if ($workMinutes <= 0) {
            $workMinutes = 120;
        }

        $doTime = (int)($work['do_time'] ?? 0);
        $serviceEndTime = 0;
        if ($doTime > 0) {
            $serviceEndTime = $doTime + $workMinutes * 60;
        }

        $reservationStart = $appointmentTime > 0 ? date('H:i', $appointmentTime) : '';
        $reservationEnd = $appointmentTime > 0 ? date('H:i', $appointmentTime + $workMinutes * 60) : '';

        $createdTs = $this->parseLegacyTime($work['created_at'] ?? '');
        if ($createdTs <= 0) {
            $createdTs = $appointmentTime > 0 ? $appointmentTime : time();
        }

        $hxTime = (int)($work['hx_time'] ?? 0);
        $confirmTime = 0;
        $oldStatus = (int)($work['status'] ?? 0);
        if ($confirmStaffId > 0 && in_array($oldStatus, [2, 3, 4, 6], true)) {
            $confirmTime = $hxTime > 0 ? $hxTime : $createdTs;
        }

        $reservationInfo = $this->buildLegacyReservationInfo($work, $tmpRows);

        return [
            'order_id' => $this->buildLegacyReservationSn((int)$work['id']),
            'uid' => (int)($work['uid'] ?? 0),
            'oid' => $oid,
            'cart_info_id' => (int)($cartCtx['cart_info_id'] ?? 0),
            'product_id' => (int)($cartCtx['product_id'] ?? $productId),
            'sku' => (string)($cartCtx['sku'] ?? ''),
            'sku_unique' => (string)($cartCtx['sku_unique'] ?? ''),
            'store_id' => $storeId,
            'staff_id' => 0,
            'service_staff_id' => $serviceStaffId,
            'staff_choose' => $this->buildLegacyStaffChoose($serviceStaffId),
            'confirm_staff_id' => $confirmStaffId,
            'confirm_time' => $confirmTime,
            'table_id' => (int)($work['table_id'] ?? 0),
            'table_name' => (string)($work['table_name'] ?? ''),
            'service_price' => (float)($work['pay_money'] ?? 0),
            'verify_code' => (string)($work['verify_code'] ?? ''),
            'reservation_type' => 2,
            'reservation_time' => $appointmentTime,
            'reservation_time_id' => 0,
            'reservation_start' => $reservationStart,
            'reservation_end' => $reservationEnd,
            'service_duration_minutes' => $workMinutes,
            'addon_items' => '',
            'reservation_name' => (string)($work['real_name'] ?? ''),
            'reservation_phone' => (string)($work['user_phone'] ?? ''),
            'reservation_address' => '',
            'custom_form_title' => '',
            'reservation_info' => $reservationInfo,
            'reservation_create_time' => $createdTs,
            'service_time' => $doTime,
            'service_end_time' => $serviceEndTime,
            'service_describe' => '',
            'service_images' => '',
            'service_tags' => (string)($work['tags'] ?? ''),
            'service_other_tag' => (string)($work['other_tag'] ?? ''),
            'mark' => (string)($work['remark'] ?? ''),
            'remark' => '',
            'refuse_reason' => (string)($work['refuse_reason'] ?? ''),
            'status' => $this->mapOrderWorkStatus($work),
            'is_del' => 0,
            'is_system_del' => 0,
            'add_time' => $createdTs,
        ];
    }

    protected function buildLegacyReservationSn(int $legacyId)
    {
        return 'YY' . str_pad((string)$legacyId, 10, '0', STR_PAD_LEFT);
    }

    protected function parseLegacyTime($value)
    {
        if ($value === '' || $value === null) {
            return 0;
        }
        if (is_numeric($value)) {
            return (int)$value;
        }
        $ts = strtotime((string)$value);
        return $ts ?: 0;
    }

    protected function buildLegacyStaffChoose(int $staffId)
    {
        if ($staffId <= 0) {
            return '';
        }
        $meta = $this->loadStaffChooseMeta($staffId);
        if (!$meta) {
            return json_encode([['staff_id' => $staffId, 'yeji' => 0, 'is_dian' => 1]], JSON_UNESCAPED_UNICODE);
        }
        return json_encode([[
            'staff_id' => $staffId,
            'staff_name' => (string)($meta['staff_name'] ?? ''),
            'position' => (int)($meta['position'] ?? 0),
            'position_label' => (string)($meta['position_label'] ?? ''),
            'position_level' => (int)($meta['position_level'] ?? 0),
            'position_level_label' => (string)($meta['position_level_label'] ?? ''),
            'yeji' => 0,
            'is_dian' => 1,
        ]], JSON_UNESCAPED_UNICODE);
    }

    protected function buildLegacyReservationInfo(array $work, array $tmpRows)
    {
        $info = ['legacy_source' => 'order_work'];
        $remarkTag = trim((string)($work['remark_tag'] ?? ''));
        if ($remarkTag !== '' && $remarkTag !== '[]') {
            $decoded = json_decode($remarkTag, true);
            if (is_array($decoded)) {
                $info['remark_tag'] = $decoded;
            }
        }
        $tmpSn = trim((string)($work['tmp_sn'] ?? ''));
        if ($tmpSn !== '') {
            $info['legacy_tmp_sn'] = $tmpSn;
        }
        if ($tmpRows) {
            $bundle = [];
            foreach ($tmpRows as $row) {
                $bundle[] = [
                    'order_id' => (int)($row['order_id'] ?? 0),
                    'product_id' => (int)($row['product_id'] ?? 0),
                    'buy_num' => (int)($row['buy_num'] ?? 0),
                    'price' => (float)($row['price'] ?? 0),
                    'is_hx' => (int)($row['is_hx'] ?? 0),
                ];
            }
            $info['legacy_bundle'] = $bundle;
        }
        if ((float)($work['money'] ?? 0) > 0) {
            $info['legacy_service_money'] = (float)$work['money'];
        }
        return json_encode($info, JSON_UNESCAPED_UNICODE);
    }

    /**
     * 从新库订单购物车解析商品字段；组合单优先匹配 product_id
     */
    protected function resolveReservationCartContext(int $oid, int $productId, array $tmpRows)
    {
        if ($oid <= 0) {
            return [];
        }
        if (!$this->tableExists($this->newDb, 'store_order_cart_info')) {
            return [];
        }

        $query = $this->newDb->name('store_order_cart_info')->where('oid', $oid);
        if ($productId > 0) {
            $cart = $this->newDb->name('store_order_cart_info')
                ->where('oid', $oid)
                ->where('product_id', $productId)
                ->where('cart_type', 2)
                ->field('id,product_id,sku_unique,cart_info')
                ->find();
            if (!$cart) {
                $cart = $query->where('product_id', $productId)->field('id,product_id,sku_unique,cart_info')->find();
            }
        } else {
            $cart = $query->field('id,product_id,sku_unique,cart_info')->find();
        }
        if (!$cart && $productId > 0) {
            $cart = $this->newDb->name('store_order_cart_info')
                ->where('oid', $oid)
                ->field('id,product_id,sku_unique,cart_info')
                ->find();
        }
        if (!$cart) {
            return [];
        }
        $cart = is_array($cart) ? $cart : $cart->toArray();

        $sku = '';
        $cartInfo = $cart['cart_info'] ?? '';
        if (is_string($cartInfo) && $cartInfo !== '') {
            $decoded = json_decode($cartInfo, true);
            if (is_array($decoded)) {
                $sku = (string)($decoded['productInfo']['attrInfo']['suk'] ?? ($decoded['productInfo']['store_name'] ?? ''));
            }
        }

        return [
            'cart_info_id' => (int)$cart['id'],
            'product_id' => (int)$cart['product_id'],
            'sku_unique' => (string)($cart['sku_unique'] ?? ''),
            'sku' => $sku,
        ];
    }
}
