<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$migration = $root . '/后端代码/database/upgrades/2026-08-03-客情正式单号与下钻';
$apply = (string)file_get_contents($migration . '/02-正式升级.sql');
$precheck = (string)file_get_contents($migration . '/01-升级前检查.sql');
$postcheck = (string)file_get_contents($migration . '/03-升级后验证.sql');
$service = (string)file_get_contents($root . '/后端代码/app/services/customer/care/CustomerCareCommandService.php');
$repository = (string)file_get_contents($root . '/后端代码/app/services/customer/care/ThinkPhpCustomerCareRepository.php');
$passed = 0;
$failed = 0;

function documentNumberOk(string $name, bool $condition): void
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

documentNumberOk('precheck is gated by care core and clean target shape',
    strpos($precheck, '20260729-001-customer-care-core') !== false
    && strpos($precheck, 'PRECHECK_OK') !== false
    && strpos($precheck, 'STOP_CUSTOMER_CARE_DOCUMENT_NUMBER_PRECHECK_FAILED') !== false);
documentNumberOk('migration adds immutable columns and daily sequence table',
    strpos($apply, '`task_no` varchar(16)') !== false
    && strpos($apply, '`record_no` varchar(16)') !== false
    && strpos($apply, 'CREATE TABLE `eb_customer_care_document_sequence`') !== false
    && strpos($apply, 'PRIMARY KEY (`tenant_id`,`business_date`,`document_type`)') !== false);
documentNumberOk('migration leaves historical data entirely untouched',
    strpos($apply, 'UPDATE eb_customer_care_') === false
    && strpos($apply, 'INSERT INTO `_care_') === false
    && strpos($apply, 'TEMPORARY TABLE') === false
    && strpos($apply, '`task_no` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL') !== false
    && strpos($apply, '`record_no` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL') !== false);
documentNumberOk('nullable unique formal-number fields reserve uniqueness for new rows only',
    strpos($apply, 'UNIQUE KEY `uk_tenant_task_no` (`tenant_id`,`task_no`)') !== false
    && strpos($apply, 'UNIQUE KEY `uk_tenant_record_no` (`tenant_id`,`record_no`)') !== false);
documentNumberOk('new numbers reserve atomic locked sequence inside command transaction',
    strpos($repository, 'reserveDocumentSequence') !== false
    && strpos($repository, '->lock(true)') !== false
    && strpos($service, "'TASK' => 'GJ'") !== false
    && strpos($service, "'RECORD' => 'KQ'") !== false
    && strpos($service, 'nextDocumentNo') !== false);
documentNumberOk('postcheck rejects blank malformed duplicate and invalid sequence rows',
    strpos($postcheck, '@care_bad_task_no') !== false
    && strpos($postcheck, '@care_bad_record_no') !== false
    && strpos($postcheck, '@care_task_duplicate_no') !== false
    && strpos($postcheck, '@care_record_duplicate_no') !== false
    && strpos($postcheck, '@care_sequence_invalid') !== false
    && strpos($postcheck, 'POSTCHECK_OK') !== false);

echo "ASSERT_PASSED={$passed}\n";
echo "ASSERT_FAILED={$failed}\n";
exit($failed === 0 ? 0 : 1);
