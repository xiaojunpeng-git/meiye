<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$detail = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/member/CashierV3MemberDetailQueryServices.php');
$readProjection = (string)strstr($detail, '    public function read(');
$readProjection = (string)strstr($readProjection, '    /** @return array<int,array<string,mixed>> */', true);
$passed = 0;
$failed = 0;

function memberProfileArchiveOk(string $name, bool $condition): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS {$name}\n";
        return;
    }
    $failed++;
    echo "FAIL {$name}\n";
}

memberProfileArchiveOk('profile projection includes all basic archive fields',
    strpos($detail, 'birthday,card_id,wechat_account,addres,mark,add_time,extend_info') !== false
    && strpos($detail, "'idCard' => (string)(\$member['card_id'] ?? '')") !== false
    && strpos($detail, "'memberLevel' => \$levelName") !== false
    && strpos($detail, ": '普通会员';") !== false
    && strpos($detail, "'remark' => (string)(\$member['mark'] ?? '')") !== false);
memberProfileArchiveOk('profile projection resolves member tag names from the authoritative relation',
    strpos($detail, "Db::name('user_label_relation')") !== false
    && strpos($detail, "->join('user_label label', 'label.id = relation.label_id')") !== false
    && strpos($detail, "'tags' => \$tagNames") !== false);
memberProfileArchiveOk('custom profile fields follow enabled system configuration',
    strpos($detail, "SystemConfigService::get('user_extend_info', [])") !== false
    && strpos($detail, "empty(\$definition['use'])") !== false
    && strpos($detail, 'isBuiltInProfileField') !== false);
memberProfileArchiveOk('custom profile fields support legacy param info value rows',
    strpos($detail, "\$row['param'] ?? \$row['key'] ?? \$row['info']") !== false
    && strpos($detail, "\$row['value'] ?? ''") !== false
    && strpos($detail, "\$stored[\$param] ?? \$stored[\$label] ?? ''") !== false);
memberProfileArchiveOk('profile archive is a read projection with no writes',
    strpos($readProjection, "Db::name('user')") !== false
    && strpos($readProjection, '->update(') === false
    && strpos($readProjection, '->insert(') === false
    && strpos($readProjection, '->delete(') === false);

echo "ASSERT_PASSED={$passed}\n";
echo "ASSERT_FAILED={$failed}\n";
exit($failed === 0 ? 0 : 1);
