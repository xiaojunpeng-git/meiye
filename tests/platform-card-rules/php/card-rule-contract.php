<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/后端代码/vendor/autoload.php';

use app\services\product\product\StoreCardRuleServices;
use mohe\exceptions\AdminException;

$service = new StoreCardRuleServices();
$failures = [];

$controllerSource = file_get_contents(dirname(__DIR__, 3) . '/后端代码/app/controller/admin/v1/product/StoreProduct.php');
$modelSource = file_get_contents(dirname(__DIR__, 3) . '/后端代码/app/model/product/product/StoreProduct.php');
$daoSource = file_get_contents(dirname(__DIR__, 3) . '/后端代码/app/dao/product/product/StoreProductDao.php');
$productServiceSource = file_get_contents(dirname(__DIR__, 3) . '/后端代码/app/services/product/product/StoreProductServices.php');
$syncServiceSource = file_get_contents(dirname(__DIR__, 3) . '/后端代码/app/services/product/branch/StoreBranchProductServices.php');
$relatedServiceSource = file_get_contents(dirname(__DIR__, 3) . '/后端代码/app/services/product/product/StoreCardRelatedServices.php');
$syncJobSource = file_get_contents(dirname(__DIR__, 3) . '/后端代码/app/jobs/product/ProductSyncStoreJob.php');
$createListenerSource = file_get_contents(dirname(__DIR__, 3) . '/后端代码/app/listener/product/CreateSuccess.php');

$base = static function (string $type, int $validity = 2): array {
    return [
        'product_type' => 5,
        'spec_type' => 0,
        'card_rule_type' => $type,
        'card_choice_limit' => 0,
        'card_shared_times' => 0,
        'attr' => ['write_valid' => $validity, 'days' => 90, 'section_time' => []],
        'related' => [[
            'product_id' => 101,
            'unique' => 'sku-101',
            'write_times' => 3,
            'writeoff_amount' => 88,
            'labor_fee' => 42,
        ]],
    ];
};

$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$expectFailure = static function (callable $callback, string $messagePart, string $label) use (&$failures): void {
    try {
        $callback();
        $failures[] = $label . ': expected failure';
    } catch (AdminException $exception) {
        if (strpos($exception->getMessage(), $messagePart) === false) {
            $failures[] = $label . ': unexpected message ' . $exception->getMessage();
        }
    }
};

$normal = $service->normalizeForSave($base(StoreCardRuleServices::NORMAL));
$assert($normal['card_num'] === 0 && $normal['card_num_type'] === 0, 'normal legacy projection');
$assert($normal['related'][0]['write_times'] === 3, 'normal independent times');
$assert($normal['related'][0]['writeoff_amount'] === 0, 'normal amount cleared');
$assert($normal['related'][0]['labor_fee_cents'] === 4200, 'card project labor fee persists in cents');

$choiceKindInput = $base(StoreCardRuleServices::CHOICE_KIND);
$choiceKindInput['card_choice_limit'] = 1;
$choiceKind = $service->normalizeForSave($choiceKindInput);
$assert($choiceKind['card_num'] === 1 && $choiceKind['card_num_type'] === 0, 'choice-kind legacy projection');
$assert($choiceKind['card_choice_limit'] === 1, 'choice-kind limit retained');

$choiceCountInput = $base(StoreCardRuleServices::CHOICE_COUNT);
$choiceCountInput['card_shared_times'] = 10;
$choiceCount = $service->normalizeForSave($choiceCountInput);
$assert($choiceCount['card_num'] === 10 && $choiceCount['card_num_type'] === 1, 'choice-count legacy projection');
$assert($choiceCount['related'][0]['write_times'] === 0, 'choice-count project times cleared');

$combinationInput = $base(StoreCardRuleServices::COMBINATION);
$combinationInput['related'][0] += [
    'combination_group_no' => 1, 'combination_group_name' => '组合1',
    'combination_group_rule' => 'choice_kind', 'combination_choice_limit' => 1,
    'combination_shared_times' => 0,
];
$combinationInput['related'][] = [
    'product_id' => 102, 'unique' => 'sku-102', 'write_times' => 9,
    'writeoff_amount' => 0, 'labor_fee' => 6,
    'combination_group_no' => 2, 'combination_group_name' => '组合2',
    'combination_group_rule' => 'choice_count', 'combination_choice_limit' => 0,
    'combination_shared_times' => 4,
];
$combination = $service->normalizeForSave($combinationInput);
$assert($combination['card_rule_version'] === 2 && $combination['card_num'] === 0,
    'combination has isolated issued-card definition');
$assert($combination['related'][0]['write_times'] === 3
    && $combination['related'][1]['write_times'] === 0,
    'combination kind keeps independent times while count group owns shared pool');
$invalidCombination = $combinationInput;
$invalidCombination['related'][1]['combination_group_no'] = 1;
$expectFailure(static fn() => $service->normalizeForSave($invalidCombination),
    '同一组合的规则配置必须一致', 'combination rejects mixed group definitions');
$invalidCombination = $combinationInput;
$invalidCombination['related'][1]['product_id'] = 101;
$expectFailure(static fn() => $service->normalizeForSave($invalidCombination),
    '同一项目不能放进多个组合', 'combination rejects project across groups');

$time = $service->normalizeForSave($base(StoreCardRuleServices::TIME));
$assert($time['related'][0]['write_times'] === 0, 'time card project times cleared');
$assert($time['related'][0]['writeoff_amount'] === '88', 'time card writeoff amount retained as whole yuan');

$fractionalWriteoff = $base(StoreCardRuleServices::TIME);
$fractionalWriteoff['related'][0]['writeoff_amount'] = '88.50';
$expectFailure(
    static fn() => $service->normalizeForSave($fractionalWriteoff),
    '必须是整数元',
    'time-card writeoff rejects fractional amount'
);

$tampered = $base(StoreCardRuleServices::NORMAL);
$tampered['card_rule_type'] = StoreCardRuleServices::NORMAL;
$tampered['attr']['write_valid'] = 1;
$tampered['related'][0]['write_times'] = 99;
$lockedDefinition = $base(StoreCardRuleServices::CHOICE_COUNT, 2);
$lockedDefinition['card_rule_version'] = 1;
$lockedDefinition['card_shared_times'] = 12;
$lockedDefinition['attr']['days'] = 180;
$lockedDefinition['related'][0]['write_times'] = 0;
$lockedDefinition['related'][0]['writeoff_amount'] = 0;
$lockedDefinition['related'][0]['labor_fee_cents'] = 4200;
$locked = $service->preserveLockedDefinition($tampered, $lockedDefinition);
$assert($locked['card_rule_type'] === StoreCardRuleServices::CHOICE_COUNT, 'store edit preserves synced rule type');
$assert($locked['card_shared_times'] === 12, 'store edit preserves synced shared count');
$assert($locked['attr']['write_valid'] === 2 && $locked['attr']['days'] === 180, 'store edit preserves synced validity');
$assert($locked['related'][0]['write_times'] === 0, 'store edit preserves synced project rule');
$assert($locked['related'][0]['labor_fee_cents'] === 4200, 'store edit preserves synced card labor fee');

$invalidLabor = $base(StoreCardRuleServices::NORMAL);
$invalidLabor['related'][0]['labor_fee'] = '42.50';
$expectFailure(
    static fn() => $service->normalizeForSave($invalidLabor),
    '必须是整数元',
    'card labor rejects fractional amount'
);

$expectFailure(
    static fn() => $service->normalizeForSave($base(StoreCardRuleServices::TIME, 1)),
    '时间卡不允许永久有效',
    'time perpetual validity'
);

$assert(strpos($controllerSource, "['card_rule_type', '']") !== false, 'list controller accepts card-rule filter');
$assert(strpos($modelSource, 'searchCardRuleTypeAttr') !== false, 'model exposes card-rule searcher');
$assert(
    strpos($modelSource, "['normal', 'choice_kind', 'choice_count', 'time', 'combination']") !== false,
    'card-rule searcher restricts values to confirmed rules'
);
$assert(
    substr_count($daoSource, "where('a.card_rule_type', \$where['card_rule_type'])") === 2,
    'category list and count queries both apply card-rule filter'
);
$assert(
    strpos($productServiceSource, 'preserveLockedDefinition($data, $existingDefinition)') !== false,
    'platform-derived store card edits preserve the synced definition'
);
$assert(
    strpos($syncServiceSource, '$productInfo = $productInfo->toArray();') !== false
    && strpos($syncServiceSource, '$sourceProductSnapshot = $productInfo;') !== false,
    'store sync clones the complete platform product row'
);
$assert(
    strpos($syncServiceSource, '平台资料覆盖门店资料') !== false
    && strpos($syncServiceSource, 'product_change_price_status') === false
    && strpos($syncServiceSource, '$productInfo[\'store_cate_id\'] = $branchProductInfo[\'store_cate_id\']') === false,
    'store sync force-overwrites platform product configuration without local price/category exceptions'
);
$assert(
    strpos($syncServiceSource, "unset(\n                    \$productInfo['stock']") !== false
    && strpos($syncServiceSource, "unset(\$productInfo['stock'], \$productInfo['is_show'])") === false,
    'store sync protects inventory facts while allowing platform visibility to propagate'
);
$assert(
    strpos($syncServiceSource, "\$item['price'] = \$price") === false
    && strpos($syncServiceSource, "\$item['price'] = \$oldPrice") === false,
    'store SKU price is no longer replaced by the previous store override'
);
$assert(
    strpos($relatedServiceSource, "'writeoff_amount' => \$item['writeoff_amount']") !== false,
    'store sync relation normalization keeps time-card writeoff amount'
);
$assert(
    strpos($relatedServiceSource, "'card_product_id' => \$card_product_id") !== false
    && strpos($relatedServiceSource, '整张卡的其余关联仍会参与定义校验') !== false,
    'component remapping locks the complete card definition before changing one relation'
);
$lockGuardSource = file_get_contents(dirname(__DIR__, 3) . '/后端代码/app/services/product/product/StoreCatalogWriteLockGuard.php');
$assert(
    strpos($lockGuardSource, 'bool $expandTransitiveCards = true') !== false
    && strpos($lockGuardSource, "(\$seed['expandTransitiveCards'] || \$firstPass)") !== false,
    'default catalog writes retain full closure while sync writes may stop after direct reverse cards'
);
$assert(
    strpos($syncServiceSource, '$isCardDefinitionSync = (int)$productInfo[\'product_type\'] === 5') !== false
    && strpos($syncServiceSource, '}, true, !$isCardDefinitionSync, false);') !== false
    && strpos($relatedServiceSource, "true,\n            false\n        );") !== false,
    'card distribution and relation remap use bounded write plans'
);
$assert(
    strpos($productServiceSource, "->where('type', \$nameScope[0])") !== false
    && strpos($productServiceSource, "->where('relation_id', \$nameScope[1])") !== false
    && strpos($productServiceSource, "->where('is_del', 0)") !== false
    && strpos($productServiceSource, 'SELECT GET_LOCK(?, 15) AS acquired') !== false
    && strpos($productServiceSource, '商品名称已存在，请更换名称') !== false,
    'product save rejects concurrent duplicate names within the same catalog only'
);
$assert(
    strpos($syncJobSource, 'function reconcileProductScope') !== false
    && strpos($syncJobSource, "->where('pid', \$productId)") !== false
    && strpos($syncJobSource, '平台商品同步未覆盖门店') !== false,
    'platform product sync reconciles missing target stores'
);
$assert(
    strpos($createListenerSource, "dispatchDo('reconcileProductScope', [\$id, 0], 70)") !== false,
    'platform product save schedules the sync coverage reconciliation'
);
$assert(
    strpos($controllerSource, '$chooseType === 97') !== false
    && strpos($controllerSource, "\$where['type'] = 0") !== false
    && strpos($controllerSource, "\$where['relation_id'] = 0") !== false
    && strpos($controllerSource, "\$where['is_show'] = 1") !== false
    && strpos($controllerSource, "\$where['is_card'] = 0") !== false,
    'card project selector is restricted to published platform master projects'
);
$assert(
    strpos($daoSource, 'case 97://平台卡项项目选择：只取平台项目主数据') !== false
    && substr_count($daoSource, "\$query->where('product_type', 6);") >= 2,
    'card project selector is server-forced to project type'
);

$duplicate = $base(StoreCardRuleServices::NORMAL);
$duplicate['related'][] = $duplicate['related'][0];
$expectFailure(
    static fn() => $service->normalizeForSave($duplicate),
    '不能重复添加',
    'duplicate project'
);

$tooManyKinds = $base(StoreCardRuleServices::CHOICE_KIND);
$tooManyKinds['card_choice_limit'] = 2;
$expectFailure(
    static fn() => $service->normalizeForSave($tooManyKinds),
    '不能超过卡内项目总数',
    'choice-kind limit'
);

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "platform card rule contract: PASS\n";
