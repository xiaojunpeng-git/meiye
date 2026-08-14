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

    // The platform tree also submits checked group nodes. They are accepted as
    // catalogue metadata only and deliberately grant no server-side action.
    public const GROUP_STORE_OPERATIONS = 401100;
    public const GROUP_CUSTOMER_OPERATIONS = 401200;
    public const GROUP_PERSONAL_OPERATIONS = 401300;

    /** @return array<int, array{id:int,title:string,feature_code:string,actions:array<int,string>}> */
    public function features(): array
    {
        return [
            ['id' => self::RULE_WORKBENCH, 'title' => '工作台', 'feature_code' => 'mobile.merchant.workbench', 'actions' => []],
            ['id' => self::RULE_RESERVATIONS, 'title' => '预约管理', 'feature_code' => 'mobile.merchant.reservations', 'actions' => ['RESERVATION_VIEW', 'RESERVATION_CREATE', 'RESERVATION_MANAGE']],
            ['id' => self::RULE_CUSTOMER_CARE, 'title' => '客情管理', 'feature_code' => 'mobile.merchant.customer_care', 'actions' => ['CUSTOMER_CARE_VIEW', 'CUSTOMER_CARE_WRITE']],
            ['id' => self::RULE_CUSTOMERS, 'title' => '客户管理', 'feature_code' => 'mobile.merchant.customers', 'actions' => ['CUSTOMER_VIEW', 'CUSTOMER_CREATE', 'CUSTOMER_AUDIENCE_VIEW', 'CUSTOMER_AUDIENCE_MANAGE']],
            ['id' => self::RULE_CUSTOMER_SETTINGS, 'title' => '客户数据设置', 'feature_code' => 'mobile.merchant.customer_settings', 'actions' => []],
            ['id' => self::RULE_GOALS, 'title' => '目标', 'feature_code' => 'mobile.merchant.goals', 'actions' => ['TARGET_PERSONAL_VIEW', 'TARGET_PERSONAL_MANAGE']],
            ['id' => self::RULE_WAREHOUSE, 'title' => '数仓', 'feature_code' => 'mobile.merchant.warehouse', 'actions' => []],
            ['id' => self::RULE_PROFILE, 'title' => '我的', 'feature_code' => 'mobile.merchant.profile', 'actions' => []],
        ];
    }

    /**
     * Tree returned to the platform job editor. Group nodes are saved only to
     * preserve Tree select-all state; only feature leaves grant actions.
     *
     * @return array<int, array{id:int,title:string,children:array}>
     */
    public function menuTree(): array
    {
        $features = $this->featuresById();
        return [
            ['id' => self::GROUP_STORE_OPERATIONS, 'title' => '店务协同', 'children' => [$features[self::RULE_WORKBENCH], $features[self::RULE_RESERVATIONS]]],
            ['id' => self::GROUP_CUSTOMER_OPERATIONS, 'title' => '客户运营', 'children' => [$features[self::RULE_CUSTOMER_CARE], $features[self::RULE_CUSTOMERS], $features[self::RULE_CUSTOMER_SETTINGS]]],
            ['id' => self::GROUP_PERSONAL_OPERATIONS, 'title' => '经营与个人', 'children' => [$features[self::RULE_GOALS], $features[self::RULE_WAREHOUSE], $features[self::RULE_PROFILE]]],
        ];
    }

    /** @return int[] */
    public function allRuleIds(): array
    {
        $featureIds = array_map(static function (array $feature): int {
            return (int)$feature['id'];
        }, $this->features());
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
                    throw new InvalidArgumentException('手机端权限代码不属于当前 Vue 3 商家端功能目录。');
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
        foreach ($this->features() as $feature) {
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

    private function featuresById(): array
    {
        $byId = [];
        foreach ($this->features() as $feature) {
            $byId[(int)$feature['id']] = $feature;
        }
        return $byId;
    }
}
