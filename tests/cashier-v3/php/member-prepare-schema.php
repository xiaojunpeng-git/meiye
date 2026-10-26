<?php
/** 在正式 C5 增量 SQL 前建立最小旧库基线；不得创建任何 C5 新表。 */
require __DIR__ . '/../lib/boot-env.php';
require '/var/www/html/vendor/autoload.php';
require __DIR__ . '/../lib/_lib.php';
require __DIR__ . '/../lib/TestGraphFactory.php';
require __DIR__ . '/../lib/MemberIntegrationFixture.php';

use C1A\CashierV3\Test\MemberIntegrationFixture;

c1aBootThinkApp('/var/www/html/');
MemberIntegrationFixture::ensureLegacySchema();
echo "C5_LEGACY_SCHEMA_READY=1\n";
