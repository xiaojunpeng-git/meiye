<?php

declare(strict_types=1);

namespace app\services\mobile\merchant;

use InvalidArgumentException;

/**
 * Authoritative capability catalogue for the Vue 3 mobile merchant application.
 *
 * Rule ids intentionally live outside legacy system_menus.  A job stores these
 * stable ids in its mobile channel rule field; the same catalogue translates the
 * granted features into server-side merchant actions.  Adding a merchant page
 * requires adding its feature here before that page can be granted to a job.
 */
final class MobileMerchantCapabilityCatalog
{
    public const CONTRACT_VERSION = 'mobile-merchant-capability-v1';

    public const RULE_WORKBENCH = 401001;
    public const RULE_RESERVATIONS = 401002;
    public const RULE_CUSTOMER_CARE = 401003;
    public const RULE_CUSTOMERS = 401004;
    public const RULE_CUSTOMER_SETTINGS = 401005;
    public const RULE_GOALS = 401006;
    public const RULE_WAREHOUSE = 401007;
    public const RULE_PROFILE = 401008;
    /** 经营首页入口；与历史目标权限分开，避免复用旧功能编号。 */
    public const RULE_HOME = 401009;

    // The platform tree also submits checked group nodes. They are accepted as
    // catalogue metadata only and deliberately grant no server-side action.
    public const GROUP_STORE_OPERATIONS = 401100;
    public const GROUP_CUSTOMER_OPERATIONS = 401200;
    public const GROUP_PERSONAL_OPERATIONS = 401300;

    /**
     * 岗位策略当前只展示商家端四个底部入口。
     * 入口下的预约、客情和客户设置能力由所属顶层入口统一承载，
     * 不再让岗位策略按内部页面拆分配置。
     *
     * @return array<int, array{id:int,title:string,feature_code:string,actions:array<int,string>}>
     */
    public function features(): array
    {
        return [
            ['id' => self::RULE_HOME, 'title' => '经营', 'feature_code' => 'mobile.merchant.home', 'actions' => ['MERCHANT_HOME_VIEW']],
            ['id' => self::RULE_WAREHOUSE, 'title' => '数据', 'feature_code' => 'mobile.merchant.warehouse', 'actions' => ['MERCHANT_WAREHOUSE_VIEW']],
            ['id' => self::RULE_CUSTOMERS, 'title' => '客户', 'feature_code' => 'mobile.merchant.customers', 'actions' => ['CUSTOMER_VIEW', 'CUSTOMER_CREATE', 'CUSTOMER_AUDIENCE_VIEW', 'CUSTOMER_AUDIENCE_MANAGE', 'CUSTOMER_CARE_VIEW', 'CUSTOMER_CARE_WRITE']],
            ['id' => self::RULE_WORKBENCH, 'title' => '工作台', 'feature_code' => 'mobile.merchant.workbench', 'actions' => ['RESERVATION_VIEW', 'RESERVATION_CREATE', 'RESERVATION_MANAGE', 'ENGINEERING_LEDGER_VIEW']],
        ];
    }

    /**
     * Tree returned to the platform job editor. The four business entrances are
     * flat leaves; internal merchant pages are intentionally not exposed here.
     *
     * @return array<int, array{id:int,title:string,feature_code:string,actions:array<int,string>}>
     */
    public function menuTree(): array
    {
        // 顶层入口直接作为叶子权限，岗位策略不再配置内部页面。
        return $this->features();
    }

    /** @return int[] */
    public function allRuleIds(): array
    {
        $featureIds = array_map(static function (array $feature): int {
            return (int)$feature['id'];
        }, array_merge($this->features(), $this->legacyFeatures()));
        return array_merge([
            self::GROUP_STORE_OPERATIONS,
            self::GROUP_CUSTOMER_OPERATIONS,
            self::GROUP_PERSONAL_OPERATIONS,
        ], $featureIds);
    }

    /** @param array<int, mixed> $ruleIds @return int[] */
    public function normalizeRuleIds(array $ruleIds, bool $strict = true): array
    {
        $known = array_flip($this->allRuleIds());
        $normalized = [];
        foreach ($ruleIds as $value) {
            $id = (int)$value;
            if ($id <= 0) {
                continue;
            }
            if (!isset($known[$id])) {
                if ($strict) {
                    throw new InvalidArgumentException('商家端权限代码不属于当前 Vue 3 商家端功能目录。');
                }
                continue;
            }
            $normalized[$id] = $id;
        }
        return array_values($normalized);
    }

    /** @param array<int, mixed> $ruleIds @return string[] */
    public function availableActions(array $ruleIds, string $scopeMode): array
    {
        $selected = array_flip($this->normalizeRuleIds($ruleIds, false));
        $actions = [
            'merchant.context.bootstrap',
            'merchant.context.switch',
            'merchant.session.logout',
            'merchant.self.participation.read',
            'merchant.self.performance.read',
        ];
        $goalsSelected = false;
        // New configurations select one of the four entrances. Keep legacy
        // feature ids actionable so already-saved岗位 continue to work until
        // they are migrated to the entrance-level model.
        foreach (array_merge($this->features(), $this->legacyFeatures()) as $feature) {
            if (!isset($selected[(int)$feature['id']])) {
                continue;
            }
            $actions = array_merge($actions, $feature['actions']);
            if ((int)$feature['id'] === self::RULE_GOALS) {
                $goalsSelected = true;
            }
        }
        if ($goalsSelected && $scopeMode === 'STORES') {
            $actions[] = 'TARGET_TEAM_VIEW';
        }
        return array_values(array_unique($actions));
    }

    /**
     * 历史规则只用于兼容已经保存的岗位，不再返回给岗位策略菜单树。
     *
     * @return array<int, array{id:int,title:string,feature_code:string,actions:array<int,string>}>
     */
    private function legacyFeatures(): array
    {
        return [
            ['id' => self::RULE_RESERVATIONS, 'title' => '预约管理', 'feature_code' => 'mobile.merchant.reservations', 'actions' => ['RESERVATION_VIEW', 'RESERVATION_CREATE', 'RESERVATION_MANAGE']],
            ['id' => self::RULE_CUSTOMER_CARE, 'title' => '客情管理', 'feature_code' => 'mobile.merchant.customer_care', 'actions' => ['CUSTOMER_CARE_VIEW', 'CUSTOMER_CARE_WRITE']],
            ['id' => self::RULE_CUSTOMER_SETTINGS, 'title' => '客户数据设置', 'feature_code' => 'mobile.merchant.customer_settings', 'actions' => []],
            ['id' => self::RULE_GOALS, 'title' => '目标', 'feature_code' => 'mobile.merchant.goals', 'actions' => ['TARGET_PERSONAL_VIEW', 'TARGET_PERSONAL_MANAGE']],
            ['id' => self::RULE_PROFILE, 'title' => '我的', 'feature_code' => 'mobile.merchant.profile', 'actions' => []],
        ];
    }

    private function featuresById(): array
    {
        $byId = [];
        foreach (array_merge($this->features(), $this->legacyFeatures()) as $feature) {
            $byId[(int)$feature['id']] = $feature;
        }
        return $byId;
    }
}
