<?php
namespace app\services\report;

use app\services\query\UnifiedQueryRuntime;
use app\services\report\query\BasicRegistrationQueryProvider;
use app\services\report\query\BasicRegistrationQueryRegistrar;

/** 两端认证控制器的统一查询桥接；这里不从浏览器接收身份、授权或任意门店范围。 */
final class BasicReportHostServices
{
    public static function context(array $stores, string $mode, int $operatorId, string $actorKind, bool $write, int $staffId = 0, string $pageCode = BasicRegistrationQueryProvider::PAGE_CODE): array
    {
        if ($operatorId < 1 || !in_array($actorKind, ['platform', 'store'], true)) throw new \InvalidArgumentException('登录身份无效');
        if (in_array($mode, ['none', 'self_participant'], true)) throw new \InvalidArgumentException('当前数据权限不能查看无本人归属的人工登记');
        if (!in_array($mode, ['all', 'stores', 'platform_admin', 'agent_limited'], true)) throw new \InvalidArgumentException('当前报表数据权限无效');
        $stores = array_values(array_unique(array_filter(array_map('intval', $stores), static function ($id) { return $id > 0; })));
        if (!$stores) throw new \InvalidArgumentException('当前账号没有可查看的门店');
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
        $context = UnifiedQueryRuntime::service('contextFactory')->make([
            'tenant_id' => '0', 'account_id' => $operatorId, 'operator_id' => $operatorId,
            'store_id' => $actorKind === 'store' ? $stores[0] : 0, 'organization_id' => '',
            'visible_store_ids' => $stores, 'ancestor_organization_ids' => [],
            'permission_version' => hash('sha256', json_encode([$actorKind, $operatorId, $stores, $mode, $write])),
            // 统一查询提供器仍用旧 data 内部能力码；门店/平台宿主先校验真实菜单权限，
            // 此上下文只供已通过的基础报表查询使用，不能作为岗位授权来源。
            'granted_features' => ['cashier.v3.data'], 'scope_dimensions' => [],
            'query_cutoff_date' => $today, 'data_as_of' => time(),
        ], ['pageCode' => $pageCode]);
        // 平台管理员与代理商范围已由控制器裁剪为明确门店，不让领域Reader猜测平台身份语义。
        return $context + ['basic_scope_mode' => $mode === 'all' ? 'all' : 'stores', 'basic_registration_write' => $write,
            'basic_actor_key' => $actorKind . ':' . $operatorId, 'basic_staff_id' => $staffId];
    }

    /** 目录与各表静态字段共用一份定义；数据缺口由查询明确报告，不伪造零数据。 */
    public static function catalog(): array
    {
        return array_map(static function ($entry) {
            $business = $entry['code'] !== BasicRegistrationQueryProvider::PAGE_CODE;
            // 门店对账的授权后动态门店/逐日列由独立Provider声明，不冒充固定粒度Reader。
            $fields = $entry['code'] === StoreReconciliationReportServices::CODE
                ? (new StoreReconciliationReportServices())->definitions($entry['code'])
                : ($business ? BasicBusinessReportServices::fields($entry['code']) : []);
            return $entry + ['available' => true, 'queryFields' => $fields];
        }, BasicReportCatalogServices::entries());
    }

    /** 门店岗位逐表权限只裁剪已登记目录，不让总入口或客户端 pageCode 推导额外授权。 */
    public static function storeCatalogForFeatures(array $features): array
    {
        return array_values(array_filter(self::catalog(), static function (array $entry) use ($features): bool {
            return self::canReadStoreReport((string)$entry['code'], $features);
        }));
    }

    public static function canReadStoreReport(string $code, array $features): bool
    {
        BasicReportCatalogServices::definition($code);
        return in_array(\app\services\organization\StorePermissionCatalogServices::basicReportFeatureCode($code), $features, true);
    }

    public static function query(array $context, array $payload): array
    {
        $code = (string)($payload['pageCode'] ?? BasicRegistrationQueryProvider::PAGE_CODE);
        BasicReportCatalogServices::definition($code);
        // 宿主已完成认证与范围裁剪，重新绑定页面身份，不改变授权集合或信任浏览器身份。
        $context['page_code'] = $code;
        $result = UnifiedQueryRuntime::service('providers')->resolve($code)->query($context, $payload);
        if ($code !== BasicRegistrationQueryProvider::PAGE_CODE) return $result;
        $result['queryFields'] = array_map(static function ($field) {
            $widths = ['registration_id' => 100, 'platform' => 90, 'store_name' => 150, 'registered_date' => 130, 'coupon_code' => 180, 'amount' => 120, 'registration_type' => 100];
            return ['key' => $field[0], 'label' => $field[1], 'type' => $field[2], 'defaultVisible' => $field[3], 'defaultQuick' => $field[4],
                'width' => $widths[$field[0]], 'fixed' => in_array($field[0], ['platform', 'store_name'], true) ? 'left' : '',
                'money' => $field[0] === 'amount', 'summable' => $field[0] === 'amount'];
        }, BasicRegistrationQueryRegistrar::fields());
        $result['table_layout'] = ['fixed' => true, 'header_height' => 42, 'summary_position' => 'first_row'];
        return $result;
    }
}
