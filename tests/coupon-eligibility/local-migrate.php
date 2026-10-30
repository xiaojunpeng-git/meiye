<?php
// Local schema preparation only; customer deployment requires separate authorization.
require '/var/www/html/vendor/autoload.php';
$app = new think\App('/var/www/html/'); $app->initialize();
$config = think\facade\Db::connect()->getConfig();
if (($config['hostname'] ?? '') !== 'mysql' || ($config['database'] ?? '') !== 'ruihao' || getenv('R23_LOCAL_ONLY') !== '1') throw new RuntimeException('local-only guard');
foreach (explode(';', file_get_contents('/var/www/html/database/upgrades/20261029_r23_coupon_eligibility.sql')) as $sql) {
    if (trim($sql)) think\facade\Db::execute($sql);
}
echo "Local migration OK\n";
