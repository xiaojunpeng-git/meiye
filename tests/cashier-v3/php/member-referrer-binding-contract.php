<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$module = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/member/CashierV3MemberModule.php');
$panel = (string)file_get_contents($root . '/前端代码/cashier-v3/src/components/member/MemberCreatorPanel.vue');
$memberList = (string)file_get_contents($root . '/前端代码/cashier-v3/src/views/MemberListView.vue');
$shell = (string)file_get_contents($root . '/前端代码/cashier-v3/src/layouts/CashierShell.vue');
$platformService = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/member/CustomerReferrerProfileServices.php');
$platformController = (string)file_get_contents($root . '/后端代码/app/controller/admin/v1/user/CustomerReferrerProfile.php');
$platformView = (string)file_get_contents($root . '/前端代码/admin/src/pages/user/list/handle/userInfo.vue');
$legacyForm = (string)file_get_contents($root . '/前端代码/admin/src/pages/user/list/handle/userForm.vue');

$checks = [
    'create resolves scoped referrer and persists spread uid' => strpos($module, 'resolveReferrerMemberId($payload, 0') !== false && strpos($module, "'spread_uid' => \$referrerMemberId") !== false,
    'update only accepts explicit referrer field and rejects self referral' => strpos($module, "array_key_exists('referrerMemberId', \$payload)") !== false && strpos($module, '推荐人不能是顾客本人') !== false,
    'store visibility is locked before referrer write' => strpos($module, 'findManageableMember((string)$referrerId, $operatorScope, $dataScope, true)') !== false,
    'first course completion locks referrer mutation' => strpos($module, "where('first_course_completed_at', '>', 0)") !== false,
    'member rows and editor read referrer projection' => strpos($module, "'referrerMemberId' =>") !== false && strpos($module, "'referrerLocked' =>") !== false && strpos($module, 'referrerProjection') !== false,
    'member created and updated events retain referrer audit fields' => strpos($module, "'referrer_member_name_snapshot'") !== false && strpos($module, "'referrer_locked'") !== false,
    'store creator has a referrer selector and locked display' => strpos($panel, 'onSelectReferrer') !== false && strpos($panel, '推荐人 / 老客转介绍') !== false && strpos($panel, '首次疗程卡已成交，推荐人不可再修改') !== false,
    'store list opens a scoped referrer selector' => strpos($memberList, "context: 'member-referrer'") !== false && strpos($shell, "'member-referrer'") !== false,
    'platform uses a controlled V3 service and business event' => strpos($platformController, 'CustomerReferrerProfileServices') !== false && strpos($platformService, 'CashierV3BusinessEventRecorder') !== false && strpos($platformService, "'platform-v3-member-referrer'") !== false,
    'platform event scope uses the V3 store-organization resolver' => strpos($platformService, 'CashierV3ScopeResolver') !== false && strpos($platformService, '$scopeResolver->operatorScope($storeId, $adminId)') !== false,
    'platform profile displays controlled binding and lock' => strpos($platformView, 'updateCustomerV3ReferrerApi') !== false && strpos($platformView, '首次疗程卡已成交，推荐人已锁定') !== false,
    'legacy profile write excludes referrer fields' => strpos($legacyForm, 'const { spread_uid, spread_uid_nickname, ...legacyProfilePayload }') !== false,
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$passed) $failed[] = $name;
}
exit($failed ? 1 : 0);
