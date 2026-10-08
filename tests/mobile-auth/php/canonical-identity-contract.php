<?php
declare(strict_types=1);

namespace app\services { abstract class BaseServices {} }
namespace mohe\exceptions { class AdminException extends \RuntimeException {} }
namespace think\facade { class Db {} }

namespace {
    $root = dirname(__DIR__, 3);
    require $root . '/后端代码/app/services/user/CanonicalUserIdentityServices.php';

    use app\services\user\CanonicalUserIdentityServices;
    use mohe\exceptions\AdminException;

    $failed = 0;
    $executed = [];
    $matrix = json_decode((string)file_get_contents(__DIR__ . '/../requirements-matrix.json'), true);
    if (!is_array($matrix) || !is_array($matrix['requirements'] ?? null)) {
        fwrite(STDERR, "FAIL MATRIX_INVALID\n");
        exit(1);
    }
    foreach ($matrix['requirements'] as $requirement) {
        if (($requirement['evidence'] ?? '') !== 'php/canonical-identity-contract.php') {
            fwrite(STDERR, "FAIL MATRIX_EVIDENCE_INVALID\n");
            exit(1);
        }
        $executed[] = $requirement['id'];
    }

    $ok = static function (string $id, bool $condition, string $detail = '') use (&$failed): void {
        if ($condition) {
            echo "PASS {$id}\n";
            return;
        }
        $failed++;
        fwrite(STDERR, "FAIL {$id}" . ($detail === '' ? '' : " {$detail}") . "\n");
    };

    $service = new CanonicalUserIdentityServices();
    $ok('MA-CI-01', $service->phoneDigest('13800138000') === hash('sha256', '13800138000'));
    $rejectedEmpty = false;
    $rejectedMalformed = false;
    try { $service->phoneDigest(''); } catch (AdminException $e) { $rejectedEmpty = true; }
    try { $service->phoneDigest('1380013800x'); } catch (AdminException $e) { $rejectedMalformed = true; }
    $ok('MA-CI-01', $rejectedEmpty && $rejectedMalformed, 'empty_or_malformed_phone_was_accepted');

    $upgrade = $root . '/后端代码/database/upgrades/2026-07-29-手机认证Canonical手机号身份';
    $apply = (string)file_get_contents($upgrade . '/02-正式升级.sql');
    $precheck = (string)file_get_contents($upgrade . '/01-升级前检查.sql');
    $postcheck = (string)file_get_contents($upgrade . '/03-升级后验证.sql');
    $ok('MA-CI-02',
        strpos($apply, 'CREATE TABLE IF NOT EXISTS `eb_user_phone_identity`') !== false
        && strpos($apply, "'LEGACY_CONFLICT'") !== false
        && strpos($apply, 'GROUP BY phone HAVING COUNT(*)>1') !== false
        && strpos($apply, 'UPDATE `eb_user`') === false
        && strpos($apply, 'DELETE FROM `eb_user`') === false
    );
    $ok('MA-CI-03',
        strpos($apply, 'UNIQUE KEY `uk_phone_digest` (`phone_digest`)') !== false
        && strpos($apply, 'UNIQUE KEY `uk_bound_uid` (`bound_uid`)') !== false
        && strpos($apply, 'CREATE TABLE IF NOT EXISTS `eb_user_phone_identity_audit`') !== false
        && strpos($apply, "'REASSIGN_AFTER_MEMBER_CANCEL'") === false
        && strpos($postcheck, "'REASSIGN_AFTER_MEMBER_CANCEL'") !== false
        && strpos($postcheck, 'POSTCHECK_OK') !== false
    );
    $ok('MA-CI-03',
        strpos($precheck, '20260728-002-cashier-v3-member-consistency') !== false
        && strpos($precheck, 'STOP_MOBILE_AUTH_CANONICAL_IDENTITY_PRECHECK_FAILED') !== false
    );
    $identitySource = (string)file_get_contents($root . '/后端代码/app/services/user/CanonicalUserIdentityServices.php');
    $ok('MA-CI-03',
        strpos($identitySource, 'lockPhoneOwners($phone)') !== false
        && strpos($identitySource, 'releaseIdentityForCancelledMember') === false
        && strpos($identitySource, 'clearPhoneIdentity(int $uid') !== false
        && strpos($identitySource, '会员注销不释放手机号身份') !== false,
        'cancelled_phone_reassignment_must_stay_blocked'
    );
    $merchantSource = (string)file_get_contents($root . '/后端代码/app/services/merchant/MerchantCustomerServices.php');
    $h5Source = (string)file_get_contents($root . '/后端代码/app/services/user/LoginServices.php');
    $legacyCashierSource = (string)file_get_contents($root . '/后端代码/app/controller/cashier/User.php');
    $importSource = (string)file_get_contents($root . '/后端代码/app/services/other/Import/ImportRecordServices.php');
    $memberEditSource = (string)file_get_contents($root . '/后端代码/app/services/user/UserServices.php');
    $ok('MA-CI-03',
        strpos($merchantSource, 'ensureUserIdentity($phone') !== false
        && strpos($h5Source, 'ensureUserIdentity((string)$phone') !== false
        && substr_count($h5Source, 'replacePhoneIdentity($uid, $phone') === 2
        && strpos($legacyCashierSource, 'ensureUserIdentity($phone') !== false
        && strpos($legacyCashierSource, 'replacePhoneIdentity($uid, (string)$data[\'phone\']') !== false
        && strpos($importSource, 'createMemberWithUniquePhone($userServices, $save)') !== false
        && strpos($memberEditSource, 'replacePhoneIdentity($id, trim((string)$data[\'phone\']') !== false
        && strpos($memberEditSource, 'clearPhoneIdentity($id, $audit)') !== false,
        'legacy_member_writers_must_use_canonical_phone_identity'
    );

    $expected = ['MA-CI-01', 'MA-CI-02', 'MA-CI-03'];
    $ok('MATRIX', $executed === $expected, json_encode($executed));
    echo 'TEST_COUNT=' . (count($expected) + 5) . "\n";
    exit($failed === 0 ? 0 : 1);
}
