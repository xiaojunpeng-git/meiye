<?php
declare(strict_types=1);

/**
 * 会员导入的纯业务口径测试：旧 UID 保留映射、同手机号汇总、页面整数赠金、
 * 未映射旧门店不猜归属。数据库事务另在隔离 MySQL 中验证。
 */
require dirname(__DIR__, 3) . '/scripts/import/customer_008_morestore_member_points.php';

function contractMember(int $uid, string $phone, int $store, string $money, int $points): array
{
    return [
        'uid' => $uid, 'account' => '', 'pwd' => '', 'real_name' => '',
        'nickname' => '', 'avatar' => '', 'phone' => $phone, 'birthday' => 0,
        'card_id' => '', 'mark' => '', 'add_time' => 1, 'sex' => 0,
        'status' => 1, 'store_id' => $store, 'now_money' => $money,
        'integral' => $points, 'addres' => '', 'provincials' => '',
        'province' => 0, 'city' => 0, 'area' => 0, 'street' => 0,
        'user_type' => 'h5', 'login_type' => '',
    ];
}

function contractCheck(bool $value, string $message): void
{
    if (!$value) throw new RuntimeException($message);
}

$members = [
    contractMember(10, '13800000001', 0, '10.75', 4),
    contractMember(15, '13800000001', 25, '2.99', 8),
    contractMember(20, '', 69, '1.40', 2),
    contractMember(21, '', 69, '3.80', 3),
    contractMember(30, 'not-a-phone', 17, '0.00', 0),
    contractMember(31, 'not-a-phone', 17, '0.00', 0),
    contractMember(421, '13900000001', 17, '13692.00', 4950),
];
$storeMap = [17 => 118, 25 => 120];
$storeUsers = [
    ['id' => 1, 'uid' => 21, 'store_id' => 25, 'status' => 1, 'add_time' => 1],
];
[$map, $groups] = m008Resolution($members, $storeUsers, $storeMap);
$routineUsers = [
    ['id' => 1, 'uid' => 15, 'openid' => 'routine-openid-15',
        'unionid' => 'routine-union-15', 'is_del' => 0],
    ['id' => 2, 'uid' => 21, 'openid' => 'deleted-openid-21',
        'unionid' => '', 'is_del' => 1],
];
$summary = m008Summary($members, [], $routineUsers, $map, $groups, str_repeat('a', 64));
contractCheck(count($map) === 7 && count($groups) === 6, 'source UID coverage');
contractCheck($map[10]['target_uid'] === 10 && $map[15]['target_uid'] === 10,
    'same valid phone must merge into deterministic master');
contractCheck($groups[10]['balance_yuan'] === 12 && $groups[10]['integral'] === 12,
    'each source balance must be truncated before merge; points must add');
contractCheck($groups[10]['target_store_id'] === 120,
    'a sibling operating store is the group store when master has none');
contractCheck($map[20]['target_uid'] === 20 && $map[21]['target_uid'] === 21,
    'blank phones are never merged');
contractCheck($groups[20]['target_store_id'] === 0 && $groups[21]['target_store_id'] === 120,
    'closed store is not copied; explicit active membership may resolve store');
contractCheck($map[30]['target_uid'] === 30 && $map[31]['target_uid'] === 31,
    'invalid phone strings are not identity keys');
contractCheck($summary['gift_balance_yuan'] === 13708 && $summary['current_integral'] === 4967,
    'current asset totals must use member master values');
contractCheck($summary['sample_421']['target_uid'] === 421
    && $summary['sample_421']['source_balance_yuan'] === 13692
    && $summary['sample_421']['source_integral'] === 4950,
    'browser sample values must remain individually checkable');
contractCheck($summary['imported_routine_identities'] === 1
    && $summary['inactive_routine_identities'] === 1,
    'only active routine identities may be imported');
echo "008 member and points import contract passed\n";
