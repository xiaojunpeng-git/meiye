<?php

namespace C1A\CashierV3\Test;

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\bootstrap\CashierV3Bootstrap;
use app\services\user\UserServices;
use app\services\user\label\UserLabelRelationServices;
use app\services\user\level\UserLevelServices;
use app\services\organization\EmployeeDataScopeServices;
use think\facade\Db;

/**
 * C5 会员集成测试只替换旧 UserServices 的外围写适配，会员 V3 模块、事务、
 * 幂等、锁、编号、专属服务人和统一业务事件均运行生产代码。
 */
final class MemberTestUserServices extends UserServices
{
    public function __construct()
    {
    }

    public function handelExtendInfo(array $inputExtendInfo, bool $isAll = false)
    {
        return $inputExtendInfo;
    }

    public function save(array $data)
    {
        $signal = trim((string)getenv('C5_MEMBER_LOCK_SIGNAL'));
        if ($signal !== '') {
            file_put_contents($signal, 'phone-lock-held');
        }
        $holdUs = max(0, (int)getenv('C5_MEMBER_HOLD_US'));
        if ($holdUs > 0) {
            usleep($holdUs);
        }

        $row = [];
        foreach ([
            'nickname', 'real_name', 'phone', 'bar_code', 'avatar', 'user_type',
            'belong_store_id', 'status', 'add_time', 'extend_info', 'sex',
            'birthday', 'card_id', 'addres', 'mark',
        ] as $field) {
            if (array_key_exists($field, $data)) {
                $row[$field] = $data[$field];
            }
        }
        $row['adminid'] = (int)($data['adminId'] ?? $data['adminid'] ?? 0);
        $row['is_del'] = 0;
        $row['delete_time'] = null;
        if (isset($row['extend_info']) && is_array($row['extend_info'])) {
            $row['extend_info'] = json_encode($row['extend_info'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $uid = (int)Db::name('user')->insertGetId($row);
        return new MemberTestSavedUser($uid);
    }
}

final class MemberTestSavedUser
{
    /** @var int */
    public $uid;

    public function __construct(int $uid)
    {
        $this->uid = $uid;
    }
}

final class MemberTestUserLevelServices extends UserLevelServices
{
    public function __construct()
    {
    }

    public function setUserLevel(int $uid, int $levelId, $vipinfo = [])
    {
        Db::name('user_level')->where('uid', $uid)->update(['status' => 0, 'is_del' => 1]);
        if ($levelId > 0) {
            Db::name('user_level')->insert([
                'uid' => $uid,
                'level_id' => $levelId,
                'status' => 1,
                'is_del' => 0,
                'add_time' => time(),
            ]);
        }
        Db::name('user')->where('uid', $uid)->update([
            'level' => $levelId,
            'level_status' => $levelId > 0 ? 1 : 0,
        ]);
        return true;
    }
}

final class MemberTestUserLabelRelationServices extends UserLabelRelationServices
{
    public function __construct()
    {
    }

    public function setUserLable($uids, array $labels, int $type = 0, int $relationId = 0, bool $group = false)
    {
        $uids = is_array($uids) ? $uids : [$uids];
        foreach ($uids as $uid) {
            $uid = (int)$uid;
            if ($group) {
                Db::name('user_label_relation')->where([
                    'uid' => $uid,
                    'type' => $type,
                    'relation_id' => $relationId,
                ])->delete();
            }
            foreach (array_values(array_unique(array_map('intval', $labels))) as $labelId) {
                Db::name('user_label_relation')->insert([
                    'uid' => $uid,
                    'label_id' => $labelId,
                    'type' => $type,
                    'relation_id' => $relationId,
                ]);
            }
        }
        return true;
    }
}

final class MemberTestSystemConfig
{
    public function get(string $name)
    {
        return $name === 'h5_avatar' ? '/test/member-avatar.png' : '';
    }
}

final class MemberIntegrationFixture
{
    public const STORE_ID = 8;
    public const ORGANIZATION_ID = '3';

    /** @var string[] */
    public const REQUIRED_C5_TABLES = [
        'eb_cashier_v3_member_phone_lock',
        'eb_cashier_v3_member_number_sequence',
        'eb_member_exclusive_service',
        'eb_member_exclusive_service_change',
    ];

    /** @var string[] */
    public const REQUIRED_EVENT_TABLES = [
        'eb_cashier_v3_business_event',
        'eb_cashier_v3_outbox',
        'eb_cashier_v3_outbox_attempt',
        'eb_cashier_v3_consumer_once',
    ];

    public static function bindTestAdapters(): void
    {
        $container = app();
        $employeeScope = TestGraphFactory::mockEmployeeDataScope();
        foreach ([
            EmployeeDataScopeServices::class => $employeeScope,
            UserServices::class => new MemberTestUserServices(),
            UserLevelServices::class => new MemberTestUserLevelServices(),
            UserLabelRelationServices::class => new MemberTestUserLabelRelationServices(),
            'sysConfig' => new MemberTestSystemConfig(),
        ] as $abstract => $instance) {
            if (method_exists($container, 'instance')) {
                $container->instance($abstract, $instance);
            } elseif (method_exists($container, 'bindTo')) {
                $container->bindTo($abstract, $instance);
            } else {
                throw new \RuntimeException('TEST_CONTAINER_BIND_UNAVAILABLE');
            }
        }
        CashierV3Bootstrap::resetForTests();
    }

    public static function ensureLegacySchema(): void
    {
        $statements = [
            "CREATE TABLE IF NOT EXISTS `eb_cash_source` (
              `id` int unsigned NOT NULL AUTO_INCREMENT,
              `name` varchar(64) NOT NULL DEFAULT '',
              `status` tinyint NOT NULL DEFAULT 1,
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
            "CREATE TABLE IF NOT EXISTS `eb_system_menus` (
              `id` int unsigned NOT NULL AUTO_INCREMENT,
              `pid` int unsigned NOT NULL DEFAULT 0,
              `type` tinyint NOT NULL DEFAULT 0,
              `icon` varchar(128) NOT NULL DEFAULT '',
              `menu_name` varchar(128) NOT NULL DEFAULT '',
              `module` varchar(64) NOT NULL DEFAULT '',
              `controller` varchar(64) NOT NULL DEFAULT '',
              `action` varchar(64) NOT NULL DEFAULT '',
              `api_url` varchar(255) NOT NULL DEFAULT '',
              `methods` varchar(32) NOT NULL DEFAULT '',
              `params` text,
              `sort` int NOT NULL DEFAULT 0,
              `is_show` tinyint NOT NULL DEFAULT 1,
              `is_show_path` tinyint NOT NULL DEFAULT 1,
              `access` tinyint NOT NULL DEFAULT 1,
              `menu_path` varchar(255) NOT NULL DEFAULT '',
              `path` varchar(255) NOT NULL DEFAULT '',
              `auth_type` tinyint NOT NULL DEFAULT 0,
              `header` varchar(255) NOT NULL DEFAULT '',
              `is_header` tinyint NOT NULL DEFAULT 0,
              `unique_auth` varchar(128) NOT NULL DEFAULT '',
              `is_del` tinyint NOT NULL DEFAULT 0,
              PRIMARY KEY (`id`), KEY `idx_unique_auth` (`unique_auth`,`is_del`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
            "CREATE TABLE IF NOT EXISTS `eb_system_role` (
              `id` int unsigned NOT NULL AUTO_INCREMENT,
              `rules` text,
              `status` tinyint NOT NULL DEFAULT 1,
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
            "CREATE TABLE IF NOT EXISTS `eb_system_store` (
              `id` int unsigned NOT NULL,
              `name` varchar(64) NOT NULL DEFAULT '',
              `is_show` tinyint NOT NULL DEFAULT 1,
              `is_del` tinyint NOT NULL DEFAULT 0,
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
            "CREATE TABLE IF NOT EXISTS `eb_system_store_staff` (
              `id` int unsigned NOT NULL,
              `store_id` int unsigned NOT NULL DEFAULT 0,
              `employee_id` int unsigned NOT NULL DEFAULT 0,
              `account` varchar(64) NOT NULL DEFAULT '',
              `staff_name` varchar(64) NOT NULL DEFAULT '',
              `roles` varchar(255) NOT NULL DEFAULT '',
              `level` int NOT NULL DEFAULT 1,
              `can_choose` tinyint NOT NULL DEFAULT 0,
              `status` tinyint NOT NULL DEFAULT 1,
              `is_del` tinyint NOT NULL DEFAULT 0,
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
            "CREATE TABLE IF NOT EXISTS `eb_employee` (
              `id` int unsigned NOT NULL,
              `name` varchar(64) NOT NULL DEFAULT '',
              `status` tinyint NOT NULL DEFAULT 1,
              `employment_type_code` varchar(16) DEFAULT NULL,
              `employment_type_version` bigint unsigned NOT NULL DEFAULT 0,
              `is_del` tinyint NOT NULL DEFAULT 0,
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
            "CREATE TABLE IF NOT EXISTS `eb_employee_internal_account` (
              `id` int unsigned NOT NULL AUTO_INCREMENT,
              `employee_id` int unsigned NOT NULL,
              `account` varchar(64) NOT NULL DEFAULT '',
              `pwd` varchar(255) NOT NULL DEFAULT '',
              `status` tinyint NOT NULL DEFAULT 1,
              `credential_version` bigint unsigned NOT NULL DEFAULT 1,
              `last_ip` varchar(64) NOT NULL DEFAULT '',
              `last_time` int unsigned NOT NULL DEFAULT 0,
              `login_count` int unsigned NOT NULL DEFAULT 0,
              `is_del` tinyint NOT NULL DEFAULT 0,
              `add_time` int unsigned NOT NULL DEFAULT 0,
              `update_time` int unsigned NOT NULL DEFAULT 0,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uk_employee_id` (`employee_id`),
              UNIQUE KEY `uk_account` (`account`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
            "CREATE TABLE IF NOT EXISTS `eb_employee_data_scope` (
              `id` int unsigned NOT NULL AUTO_INCREMENT,
              `employee_id` int unsigned NOT NULL,
              `scope_mode` varchar(32) NOT NULL DEFAULT 'personal',
              `source_store_id` int unsigned NOT NULL DEFAULT 0,
              `org_ids` text,
              `store_ids` text,
              `status` tinyint NOT NULL DEFAULT 1,
              `is_del` tinyint NOT NULL DEFAULT 0,
              PRIMARY KEY (`id`), KEY `idx_emp` (`employee_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
            "CREATE TABLE IF NOT EXISTS `eb_employee_store_isolation` (
              `id` int unsigned NOT NULL AUTO_INCREMENT,
              `employee_id` int unsigned NOT NULL,
              `store_id` int unsigned NOT NULL,
              `status` tinyint NOT NULL DEFAULT 1,
              `is_del` tinyint NOT NULL DEFAULT 0,
              PRIMARY KEY (`id`), KEY `idx_emp` (`employee_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
            "CREATE TABLE IF NOT EXISTS `eb_organization` (
              `id` int unsigned NOT NULL,
              `pid` int unsigned NOT NULL DEFAULT 0,
              `name` varchar(64) NOT NULL DEFAULT '',
              `is_del` tinyint NOT NULL DEFAULT 0,
              PRIMARY KEY (`id`), KEY `idx_pid` (`pid`,`is_del`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
            "CREATE TABLE IF NOT EXISTS `eb_organization_store` (
              `id` int unsigned NOT NULL AUTO_INCREMENT,
              `org_id` int unsigned NOT NULL,
              `store_id` int unsigned NOT NULL,
              PRIMARY KEY (`id`), UNIQUE KEY `uk_store_id` (`store_id`), KEY `idx_org` (`org_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
            "CREATE TABLE IF NOT EXISTS `eb_user` (
              `uid` int unsigned NOT NULL AUTO_INCREMENT,
              `nickname` varchar(60) NOT NULL DEFAULT '',
              `real_name` varchar(25) NOT NULL DEFAULT '',
              `phone` char(15) NOT NULL DEFAULT '',
              `bar_code` varchar(32) NOT NULL DEFAULT '',
              `avatar` varchar(256) NOT NULL DEFAULT '',
              `user_type` varchar(32) NOT NULL DEFAULT '',
              `belong_store_id` int NOT NULL DEFAULT 0,
              `status` tinyint NOT NULL DEFAULT 1,
              `is_del` tinyint NOT NULL DEFAULT 0,
              `delete_time` timestamp NULL DEFAULT NULL,
              `add_time` int unsigned NOT NULL DEFAULT 0,
              `extend_info` longtext,
              `sex` tinyint NOT NULL DEFAULT 0,
              `birthday` int NOT NULL DEFAULT 0,
              `card_id` varchar(20) NOT NULL DEFAULT '',
              `addres` varchar(255) NOT NULL DEFAULT '',
              `mark` varchar(255) NOT NULL DEFAULT '',
              `adminid` int unsigned NOT NULL DEFAULT 0,
              `now_money` decimal(12,2) NOT NULL DEFAULT 0,
              `level` int NOT NULL DEFAULT 0,
              `exp` decimal(12,2) NOT NULL DEFAULT 0,
              `level_status` tinyint NOT NULL DEFAULT 0,
              PRIMARY KEY (`uid`), KEY `idx_phone` (`phone`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
            "CREATE TABLE IF NOT EXISTS `eb_user_recharge` (
              `id` bigint unsigned NOT NULL AUTO_INCREMENT,
              `store_id` int unsigned NOT NULL DEFAULT 0,
              `uid` int unsigned NOT NULL DEFAULT 0,
              `staff_id` int unsigned NOT NULL DEFAULT 0,
              `order_id` varchar(64) NOT NULL DEFAULT '',
              `price` decimal(12,2) NOT NULL DEFAULT 0,
              `give_price` decimal(12,2) NOT NULL DEFAULT 0,
              `debt_amount` decimal(12,2) NOT NULL DEFAULT 0,
              `repaid_debt_amount` decimal(12,2) NOT NULL DEFAULT 0,
              `recharge_type` varchar(32) NOT NULL DEFAULT '',
              `paid` tinyint NOT NULL DEFAULT 0,
              `pay_time` int unsigned NOT NULL DEFAULT 0,
              `add_time` int unsigned NOT NULL DEFAULT 0,
              `refund_price` decimal(12,2) NOT NULL DEFAULT 0,
              `cash_choose` tinyint NOT NULL DEFAULT 0,
              `refund_ben` decimal(12,2) NOT NULL DEFAULT 0,
              `refund_give` decimal(12,2) NOT NULL DEFAULT 0,
              `send_all` text,
              `staff_choose` text,
              `is_budan` tinyint NOT NULL DEFAULT 0,
              `is_gendan` tinyint NOT NULL DEFAULT 0,
              `gendan_staff_id` int unsigned NOT NULL DEFAULT 0,
              `budan_time` int unsigned NOT NULL DEFAULT 0,
              `combination_info` text,
              PRIMARY KEY (`id`), UNIQUE KEY `uk_order_id` (`order_id`),
              KEY `idx_uid` (`uid`), KEY `idx_paid` (`paid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
            "CREATE TABLE IF NOT EXISTS `eb_user_card_holder` (
              `id` bigint unsigned NOT NULL AUTO_INCREMENT,
              `uid` int unsigned NOT NULL DEFAULT 0,
              `oid` bigint unsigned NOT NULL DEFAULT 0,
              `card_name` varchar(128) NOT NULL DEFAULT '',
              `card_no` varchar(32) NOT NULL DEFAULT '',
              `store_id` int unsigned NOT NULL DEFAULT 0,
              `product_type` tinyint NOT NULL DEFAULT 0,
              `write_times` int unsigned NOT NULL DEFAULT 0,
              `write_surplus_times` int unsigned NOT NULL DEFAULT 0,
              `write_start` int unsigned NOT NULL DEFAULT 0,
              `write_end` int unsigned NOT NULL DEFAULT 0,
              `is_del` tinyint NOT NULL DEFAULT 0,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uk_uid_oid` (`uid`,`oid`),
              KEY `idx_oid` (`oid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
            "CREATE TABLE IF NOT EXISTS `eb_store_order` (
              `id` bigint unsigned NOT NULL AUTO_INCREMENT,
              `uid` int unsigned NOT NULL DEFAULT 0,
              `store_id` int unsigned NOT NULL DEFAULT 0,
              `paid` tinyint NOT NULL DEFAULT 0,
              `is_del` tinyint NOT NULL DEFAULT 0,
              `is_system_del` tinyint NOT NULL DEFAULT 0,
              `is_user_del` tinyint NOT NULL DEFAULT 0,
              `refund_status` tinyint NOT NULL DEFAULT 0,
              `terminal_action` tinyint NOT NULL DEFAULT 0,
              `card_upgrade_use_oid` bigint unsigned NOT NULL DEFAULT 0,
              `pid` int NOT NULL DEFAULT 0,
              `order_type` tinyint NOT NULL DEFAULT 0,
              `is_debt_repay` tinyint NOT NULL DEFAULT 0,
              `order_id` varchar(64) NOT NULL DEFAULT '',
              `mark` varchar(512) NOT NULL DEFAULT '',
              `pay_price` decimal(12,2) NOT NULL DEFAULT 0,
              `cash_pay_price` decimal(12,2) NOT NULL DEFAULT 0,
              `yue_pay_price` decimal(12,2) NOT NULL DEFAULT 0,
              `debt_amount` decimal(12,2) NOT NULL DEFAULT 0,
              `repaid_debt_amount` decimal(12,2) NOT NULL DEFAULT 0,
              `add_time` int unsigned NOT NULL DEFAULT 0,
              `pay_time` int unsigned NOT NULL DEFAULT 0,
              PRIMARY KEY (`id`),
              KEY `idx_uid_state` (`uid`,`paid`,`is_del`),
              KEY `idx_store` (`store_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
            "CREATE TABLE IF NOT EXISTS `eb_store_order_cart_info` (
              `id` bigint unsigned NOT NULL AUTO_INCREMENT,
              `uid` int unsigned NOT NULL DEFAULT 0,
              `oid` bigint unsigned NOT NULL DEFAULT 0,
              `cart_id` varchar(64) NOT NULL DEFAULT '',
              `product_id` int unsigned NOT NULL DEFAULT 0,
              `cart_type` tinyint NOT NULL DEFAULT 0,
              `product_type` tinyint NOT NULL DEFAULT 0,
              `cart_info` mediumtext,
              `write_times` int unsigned NOT NULL DEFAULT 0,
              `write_surplus_times` int unsigned NOT NULL DEFAULT 0,
              `is_writeoff` tinyint NOT NULL DEFAULT 0,
              `write_start` int unsigned NOT NULL DEFAULT 0,
              `write_end` int unsigned NOT NULL DEFAULT 0,
              `pay_price` decimal(12,2) NOT NULL DEFAULT 0,
              `debt_amount` decimal(12,2) NOT NULL DEFAULT 0,
              `repaid_debt_amount` decimal(12,2) NOT NULL DEFAULT 0,
              `is_gift` tinyint NOT NULL DEFAULT 0,
              PRIMARY KEY (`id`),
              KEY `idx_oid_role` (`oid`,`cart_type`,`product_type`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
            "CREATE TABLE IF NOT EXISTS `eb_store_reservation_order` (
              `id` bigint unsigned NOT NULL AUTO_INCREMENT,
              `cart_info_id` bigint unsigned NOT NULL DEFAULT 0,
              `status` tinyint NOT NULL DEFAULT 0,
              `is_del` tinyint NOT NULL DEFAULT 0,
              `is_system_del` tinyint NOT NULL DEFAULT 0,
              PRIMARY KEY (`id`),
              KEY `idx_cart_status` (`cart_info_id`,`status`,`is_del`,`is_system_del`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
            "CREATE TABLE IF NOT EXISTS `eb_store_debt` (
              `id` bigint unsigned NOT NULL AUTO_INCREMENT,
              `debt_no` varchar(32) NOT NULL DEFAULT '',
              `order_id` bigint unsigned NOT NULL DEFAULT 0,
              `order_sn` varchar(32) NOT NULL DEFAULT '',
              `uid` int unsigned NOT NULL DEFAULT 0,
              `store_id` int unsigned NOT NULL DEFAULT 0,
              `staff_id` int unsigned NOT NULL DEFAULT 0,
              `status` tinyint NOT NULL DEFAULT 0,
              `total_debt` decimal(12,2) NOT NULL DEFAULT 0,
              `repaid_debt` decimal(12,2) NOT NULL DEFAULT 0,
              `remark` varchar(500) NOT NULL DEFAULT '',
              `add_time` int unsigned NOT NULL DEFAULT 0,
              `update_time` int unsigned NOT NULL DEFAULT 0,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uniq_debt_no` (`debt_no`),
              KEY `idx_order_id` (`order_id`),
              KEY `idx_uid_status_store` (`uid`,`status`,`store_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
            "CREATE TABLE IF NOT EXISTS `eb_store_debt_item` (
              `id` bigint unsigned NOT NULL AUTO_INCREMENT,
              `debt_id` bigint unsigned NOT NULL DEFAULT 0,
              `order_id` bigint unsigned NOT NULL DEFAULT 0,
              `cart_info_id` bigint unsigned NOT NULL DEFAULT 0,
              `product_id` bigint unsigned NOT NULL DEFAULT 0,
              `product_type` tinyint NOT NULL DEFAULT 0,
              `product_name` varchar(255) NOT NULL DEFAULT '',
              `cart_num` int NOT NULL DEFAULT 1,
              `debt_amount` decimal(12,2) NOT NULL DEFAULT 0,
              `repaid_debt` decimal(12,2) NOT NULL DEFAULT 0,
              `add_time` int unsigned NOT NULL DEFAULT 0,
              `update_time` int unsigned NOT NULL DEFAULT 0,
              PRIMARY KEY (`id`),
              KEY `idx_debt_id` (`debt_id`),
              KEY `idx_order_cart` (`order_id`,`cart_info_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
            "CREATE TABLE IF NOT EXISTS `eb_store_product` (
              `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
              `pid` bigint(20) unsigned NOT NULL DEFAULT 0,
              `type` tinyint NOT NULL DEFAULT 1,
              `relation_id` bigint(20) unsigned NOT NULL DEFAULT 0,
              `product_type` tinyint NOT NULL DEFAULT 0,
              `store_name` varchar(128) NOT NULL DEFAULT '',
              `cate_id` varchar(255) NOT NULL DEFAULT '',
              `keyword` varchar(255) NOT NULL DEFAULT '',
              `unit_name` varchar(32) NOT NULL DEFAULT '',
              `sort` int NOT NULL DEFAULT 0,
              `is_show` tinyint NOT NULL DEFAULT 1,
              `is_del` tinyint NOT NULL DEFAULT 0,
              `is_verify` tinyint NOT NULL DEFAULT 1,
              `is_inventory` tinyint NOT NULL DEFAULT 0,
              `allow_negative_stock` tinyint NOT NULL DEFAULT 1,
              `card_num` int NOT NULL DEFAULT 0,
              `card_num_type` tinyint NOT NULL DEFAULT 0,
              `card_rule_type` varchar(24) NOT NULL DEFAULT '',
              `card_rule_version` int unsigned NOT NULL DEFAULT 0,
              `card_choice_limit` int unsigned NOT NULL DEFAULT 0,
              `card_shared_times` int unsigned NOT NULL DEFAULT 0,
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
            "CREATE TABLE IF NOT EXISTS `eb_store_product_attr_value` (
              `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
              `product_id` bigint(20) unsigned NOT NULL DEFAULT 0,
              `product_type` tinyint NOT NULL DEFAULT 0,
              `unique` varchar(20) NOT NULL DEFAULT '',
              `suk` varchar(128) NOT NULL DEFAULT '',
              `price` decimal(12,2) unsigned NOT NULL DEFAULT 0,
              `ot_price` decimal(12,2) unsigned NOT NULL DEFAULT 0,
              `cost` decimal(12,2) unsigned NOT NULL DEFAULT 0,
              `stock` decimal(18,4) NOT NULL DEFAULT 0,
              `code` varchar(50) NOT NULL DEFAULT '',
              `bar_code` varchar(50) NOT NULL DEFAULT '',
              `is_show` tinyint NOT NULL DEFAULT 1,
              `type` tinyint NOT NULL DEFAULT 0,
              `write_times` int NOT NULL DEFAULT 0,
              `write_valid` tinyint NOT NULL DEFAULT 1,
              `write_days` int NOT NULL DEFAULT 0,
              `write_start` int NOT NULL DEFAULT 0,
              `write_end` int NOT NULL DEFAULT 0,
              PRIMARY KEY (`id`), KEY `idx_unique_suk` (`unique`,`suk`), KEY `idx_product_suk` (`product_id`,`suk`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
            "CREATE TABLE IF NOT EXISTS `eb_store_product_category` (
              `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
              `cate_name` varchar(128) NOT NULL DEFAULT '',
              `type` tinyint NOT NULL DEFAULT 0,
              `relation_id` bigint(20) unsigned NOT NULL DEFAULT 0,
              `is_show` tinyint NOT NULL DEFAULT 1,
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
            "CREATE TABLE IF NOT EXISTS `eb_store_card_related` (
              `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
              `card_product_id` bigint(20) unsigned NOT NULL DEFAULT 0,
              `product_id` bigint(20) unsigned NOT NULL DEFAULT 0,
              `product_type` tinyint NOT NULL DEFAULT 0,
              `product_attr_unique` varchar(20) NOT NULL DEFAULT '',
              `cost` decimal(12,2) unsigned NOT NULL DEFAULT 0,
              `price` decimal(12,2) unsigned NOT NULL DEFAULT 0,
              `write_times` int NOT NULL DEFAULT 0,
              `writeoff_amount` decimal(12,2) unsigned NOT NULL DEFAULT 0,
              `status` tinyint NOT NULL DEFAULT 1,
              PRIMARY KEY (`id`), KEY `idx_card_product_product` (`card_product_id`,`product_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
            "CREATE TABLE IF NOT EXISTS `eb_store_order_writeoff` (
              `id` bigint unsigned NOT NULL AUTO_INCREMENT,
              `uid` int unsigned NOT NULL DEFAULT 0,
              `relation_id` int unsigned NOT NULL DEFAULT 0,
              `add_time` int unsigned NOT NULL DEFAULT 0,
              `status` tinyint NOT NULL DEFAULT 0,
              `staff_id` int unsigned NOT NULL DEFAULT 0,
              PRIMARY KEY (`id`),
              KEY `idx_uid_status_store_time` (`uid`,`status`,`relation_id`,`add_time`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
            "CREATE TABLE IF NOT EXISTS `eb_staff_yeji` (
              `id` bigint unsigned NOT NULL AUTO_INCREMENT,
              `staff_id` int unsigned NOT NULL DEFAULT 0,
              `staff_name` varchar(64) NOT NULL DEFAULT '',
              `type` tinyint NOT NULL DEFAULT 0,
              `status` tinyint NOT NULL DEFAULT 0,
              `link_id` bigint unsigned NOT NULL DEFAULT 0,
              `store_id` int unsigned NOT NULL DEFAULT 0,
              PRIMARY KEY (`id`),
              KEY `idx_link_snapshot` (`link_id`,`type`,`status`,`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
            "CREATE TABLE IF NOT EXISTS `eb_store_user` (
              `id` int unsigned NOT NULL AUTO_INCREMENT,
              `store_id` int unsigned NOT NULL,
              `uid` int unsigned NOT NULL,
              `label_id` text,
              `status` tinyint NOT NULL DEFAULT 1,
              `add_time` int unsigned NOT NULL DEFAULT 0,
              PRIMARY KEY (`id`), UNIQUE KEY `uk_store_uid` (`store_id`,`uid`), KEY `idx_uid` (`uid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
            "CREATE TABLE IF NOT EXISTS `eb_system_user_level` (
              `id` int unsigned NOT NULL,
              `is_show` tinyint NOT NULL DEFAULT 1,
              `is_del` tinyint NOT NULL DEFAULT 0,
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
            "CREATE TABLE IF NOT EXISTS `eb_user_label` (
              `id` int unsigned NOT NULL,
              `label_name` varchar(64) NOT NULL DEFAULT '',
              `type` tinyint NOT NULL DEFAULT 0,
              `relation_id` int unsigned NOT NULL DEFAULT 0,
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
            "CREATE TABLE IF NOT EXISTS `eb_user_label_relation` (
              `id` int unsigned NOT NULL AUTO_INCREMENT,
              `uid` int unsigned NOT NULL,
              `label_id` int unsigned NOT NULL,
              `type` tinyint NOT NULL DEFAULT 0,
              `relation_id` int unsigned NOT NULL DEFAULT 0,
              PRIMARY KEY (`id`), UNIQUE KEY `uk_member_label` (`uid`,`label_id`,`type`,`relation_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
            "CREATE TABLE IF NOT EXISTS `eb_user_level` (
              `id` int unsigned NOT NULL AUTO_INCREMENT,
              `uid` int unsigned NOT NULL,
              `level_id` int unsigned NOT NULL,
              `status` tinyint NOT NULL DEFAULT 1,
              `is_del` tinyint NOT NULL DEFAULT 0,
              `add_time` int unsigned NOT NULL DEFAULT 0,
              PRIMARY KEY (`id`), KEY `idx_uid` (`uid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        ];
        foreach ($statements as $sql) {
            Db::execute($sql);
        }
        self::ensureColumn('eb_system_store', 'is_show', "tinyint NOT NULL DEFAULT 1");
        self::ensureColumn('eb_system_store', 'is_del', "tinyint NOT NULL DEFAULT 0");
        self::ensureColumn('eb_employee', 'name', "varchar(64) NOT NULL DEFAULT ''");
        self::ensureColumn('eb_user_card_holder', 'product_type', "tinyint NOT NULL DEFAULT 0");
        self::ensureColumn('eb_user_card_holder', 'write_times', "int unsigned NOT NULL DEFAULT 0");
        self::ensureColumn('eb_store_product', 'card_rule_type', "varchar(24) NOT NULL DEFAULT ''");
        self::ensureColumn('eb_store_product', 'card_rule_version', "int unsigned NOT NULL DEFAULT 0");
        self::ensureColumn('eb_store_product', 'card_choice_limit', "int unsigned NOT NULL DEFAULT 0");
        self::ensureColumn('eb_store_product', 'card_shared_times', "int unsigned NOT NULL DEFAULT 0");
        self::ensureColumn('eb_store_card_related', 'writeoff_amount', "decimal(12,2) unsigned NOT NULL DEFAULT 0");
        self::ensureColumn('eb_store_order', 'pid', "int NOT NULL DEFAULT 0");
        self::ensureColumn('eb_store_order', 'order_type', "tinyint NOT NULL DEFAULT 0");
        self::ensureColumn('eb_store_order', 'is_debt_repay', "tinyint NOT NULL DEFAULT 0");
        self::ensureColumn('eb_store_order', 'mark', "varchar(512) NOT NULL DEFAULT ''");
        self::ensureColumn('eb_store_order', 'add_time', "int unsigned NOT NULL DEFAULT 0");
        self::ensureColumn('eb_store_order', 'pay_time', "int unsigned NOT NULL DEFAULT 0");
        self::ensureColumn('eb_store_order_cart_info', 'uid', "int unsigned NOT NULL DEFAULT 0");
        self::ensureColumn('eb_store_debt', 'uid', "int unsigned NOT NULL DEFAULT 0");
        self::ensureColumn('eb_store_debt', 'store_id', "int unsigned NOT NULL DEFAULT 0");
        self::ensureColumn('eb_store_debt', 'debt_no', "varchar(32) NOT NULL DEFAULT ''");
        self::ensureColumn('eb_store_debt', 'order_sn', "varchar(32) NOT NULL DEFAULT ''");
        self::ensureColumn('eb_store_debt', 'staff_id', "int unsigned NOT NULL DEFAULT 0");
        self::ensureColumn('eb_store_debt', 'remark', "varchar(500) NOT NULL DEFAULT ''");
        self::ensureColumn('eb_store_debt', 'add_time', "int unsigned NOT NULL DEFAULT 0");
        self::ensureColumn('eb_store_debt', 'update_time', "int unsigned NOT NULL DEFAULT 0");
        self::ensureColumn('eb_employee', 'employment_type_code', "varchar(16) DEFAULT NULL");
        self::ensureColumn('eb_employee', 'employment_type_version', "bigint unsigned NOT NULL DEFAULT 0");
    }

    public static function resetAndSeed(): void
    {
        foreach ([
            'user_label_relation', 'user_level', 'store_user', 'user',
            'organization_store', 'organization',
        ] as $table) {
            Db::execute('TRUNCATE TABLE `eb_' . $table . '`');
        }
        foreach ([
            'cashier_v3_workspace_line',
            'cashier_v3_workspace_draft',
            'cashier_v3_entitlement_resource_version',
            'staff_yeji',
            'store_order_writeoff',
            'store_reservation_order',
            'store_debt',
            'store_order_cart_info',
            'user_card_holder',
            'store_order',
            'cashier_v3_consumer_once',
            'cashier_v3_outbox_attempt',
            'cashier_v3_outbox',
            'cashier_v3_business_event',
            'cashier_v3_member_phone_lock',
            'member_exclusive_service_change',
            'member_exclusive_service',
        ] as $table) {
            if (self::tableExists('eb_' . $table)) {
                Db::execute('DELETE FROM `eb_' . $table . '`');
            }
        }

        Db::execute("REPLACE INTO `eb_system_store` (`id`,`name`,`is_show`,`is_del`) VALUES
          (8,'本店',1,0),(9,'同组织店',1,0),(10,'上级组织店',1,0),
          (11,'集团店',1,0),(12,'其他事业部店',1,0)");
        Db::execute("REPLACE INTO `eb_system_store_staff`
          (`id`,`store_id`,`employee_id`,`account`,`staff_name`,`roles`,`level`,`status`,`is_del`) VALUES
          (1,8,1,'operator1','操作员一','1',0,1,0),
          (2,8,2,'operator2','操作员二','1',0,1,0),
          (20,8,20,'artisan20','专属服务人甲','1',1,1,0),
          (30,8,30,'selector-sales-30','人员查询销售内部','1',1,1,0),
          (31,8,31,'selector-sales-31','人员查询销售未分类','1',1,1,0),
          (32,9,32,'selector-sales-32','人员查询销售跨店','1',1,1,0),
          (33,8,33,'selector-sales-33','人员查询销售停用','1',1,0,0)");
        Db::execute("REPLACE INTO `eb_employee` (`id`,`name`,`status`,`employment_type_code`,`employment_type_version`,`is_del`) VALUES
          (1,'操作员一',1,'internal',1,0),(2,'操作员二',1,'internal',1,0),(20,'专属服务人甲',1,NULL,0,0),
          (30,'人员查询销售内部',1,'internal',1,0),(31,'人员查询销售未分类',1,NULL,0,0),
          (32,'人员查询销售跨店',1,'partner',1,0),(33,'人员查询销售停用',1,'internal',1,0)");
        Db::execute('DELETE FROM `eb_employee_data_scope`');
        Db::execute('DELETE FROM `eb_employee_store_isolation`');
        Db::execute('DELETE FROM `eb_employee_internal_account`');

        Db::execute("INSERT INTO `eb_organization` (`id`,`pid`,`name`,`is_del`) VALUES
          (1,0,'集团',0),(2,1,'事业部',0),(3,2,'当前组织',0),(4,1,'其他事业部',0)");
        Db::execute("INSERT INTO `eb_organization_store` (`org_id`,`store_id`) VALUES
          (3,8),(3,9),(2,10),(1,11),(4,12)");
        Db::execute("REPLACE INTO `eb_system_user_level` (`id`,`is_show`,`is_del`) VALUES (1,1,0)");
        Db::execute("REPLACE INTO `eb_user_label` (`id`,`label_name`,`type`,`relation_id`) VALUES
          (11,'高价值',0,0),(12,'重点跟进',0,0)");
    }

    public static function seedMember(
        int $uid,
        string $name,
        string $phone,
        int $storeId,
        int $status = 1,
        int $isDel = 0,
        $deleteTime = null
    ): void {
        Db::name('user')->insert([
            'uid' => $uid,
            'nickname' => $name,
            'real_name' => $name,
            'phone' => $phone,
            'bar_code' => sprintf('%09d', $uid),
            'belong_store_id' => $storeId,
            'status' => $status,
            'is_del' => $isDel,
            'delete_time' => $deleteTime,
            'add_time' => time(),
        ]);
        Db::name('store_user')->insert([
            'uid' => $uid,
            'store_id' => $storeId,
            'status' => 1,
            'add_time' => time(),
        ]);
    }

    public static function dispatcher()
    {
        return CashierV3Bootstrap::dispatcher();
    }

    public static function operatorProfile(int $operatorId): array
    {
        return [
            'id' => $operatorId,
            'store_id' => self::STORE_ID,
            'level' => 0,
            'roles' => [1],
            'employee_id' => $operatorId,
            'account' => 'operator' . $operatorId,
        ];
    }

    public static function operatorScope(int $operatorId): CashierV3OperatorScope
    {
        return new CashierV3OperatorScope(
            self::STORE_ID,
            $operatorId,
            self::ORGANIZATION_ID,
            '0'
        );
    }

    public static function dataScope($dispatcher, int $operatorId): CashierV3DataScopeContext
    {
        return $dispatcher->dataScopeFactory()->build(
            self::STORE_ID,
            $operatorId,
            self::operatorProfile($operatorId),
            '0',
            self::ORGANIZATION_ID
        );
    }

    public static function directCommand(
        $dispatcher,
        string $action,
        array $payload,
        int $operatorId = 1,
        string $idempotencyKey = ''
    ): array {
        $handler = $dispatcher->handlers()->requireCommand($action);
        $op = self::operatorScope($operatorId);
        $scope = self::dataScope($dispatcher, $operatorId);
        return Db::transaction(function () use ($handler, $action, $payload, $operatorId, $idempotencyKey, $op, $scope) {
            return $handler([
                'action' => $action,
                'payload' => $payload,
                'operator_scope' => $op,
                'data_scope' => $scope,
                'operator' => self::operatorProfile($operatorId),
                'idempotency_key' => $idempotencyKey !== '' ? $idempotencyKey : 'CMD-' . self::uuid(),
            ]);
        });
    }

    public static function projectionSession(int $operatorId = 1): array
    {
        return [
            'store_id' => self::STORE_ID,
            'operator_id' => $operatorId,
            'operator_profile' => self::operatorProfile($operatorId),
            'client_session_id' => 'SESSION-' . self::uuid(),
            'operator_ip' => '127.0.0.1',
        ];
    }

    /**
     * @return array{body:array,session:array,workspace_id:string,version:int}
     */
    public static function commandRequest($dispatcher, string $action, array $payload, int $operatorId = 1, string $idempotencyKey = ''): array
    {
        $session = self::projectionSession($operatorId);
        $keyServices = app()->make(\app\services\cashier\v3\CashierV3IdempotencyKeyServices::class);
        $stateContexts = new \app\services\cashier\v3\CashierV3StateContextServices($keyServices);
        $state = $stateContexts->resolve(
            self::STORE_ID,
            $operatorId,
            $session['client_session_id'],
            ''
        );
        $session['state_context_id'] = $state['state_context_id'];
        $workspaceId = sprintf('ws:%d:%d:%s', self::STORE_ID, $operatorId, $state['state_context_id']);
        Db::transaction(function () use ($dispatcher, $workspaceId) {
            $scope = \app\services\cashier\v3\CashierV3ResourceScope::of('store', (string)self::STORE_ID);
            $dispatcher->versionServices()->ensureRegistered($scope, 'cashier_workspace', $workspaceId);
        });
        $version = (int)Db::name('cashier_v3_resource_version')
            ->where('resource_kind', 'cashier_workspace')
            ->where('resource_id', $workspaceId)
            ->value('current_version');
        $command = [
            'action' => $action,
            'idempotencyKey' => $idempotencyKey !== '' ? $idempotencyKey : 'CMD-' . self::uuid(),
            'contexts' => [[
                'kind' => 'cashier_workspace',
                'id' => $workspaceId,
                'expectedVersion' => $version,
            ]],
        ];
        return [
            'body' => array_merge($payload, [
                'action' => $action,
                'clientSessionId' => $session['client_session_id'],
                'stateContextId' => $state['state_context_id'],
                'correlationId' => 'CORR-' . self::uuid(),
                'command' => $command,
            ]),
            'session' => $session,
            'workspace_id' => $workspaceId,
            'version' => $version,
        ];
    }

    public static function tableExists(string $table): bool
    {
        $rows = Db::query(
            'SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',
            [$table]
        );
        return (int)($rows[0]['c'] ?? 0) === 1;
    }

    private static function ensureColumn(string $table, string $column, string $definition): void
    {
        $rows = Db::query(
            'SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',
            [$table, $column]
        );
        if ((int)($rows[0]['c'] ?? 0) === 0) {
            Db::execute(sprintf(
                'ALTER TABLE `%s` ADD COLUMN `%s` %s',
                str_replace('`', '``', $table),
                str_replace('`', '``', $column),
                $definition
            ));
        }
    }

    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
