<?php
declare(strict_types=1);
/** Add a future-gift capability, default off. Never changes issued claim amounts.
 * Named database guard and explicit CLI mode keep each customer's migration isolated.
 */
$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';
$app = new think\App($root . '/'); $app->env->load($root . '/.env'); $app->initialize();
set_exception_handler(static function (Throwable $e): void { file_put_contents('php://stderr', $e->getMessage() . "\n"); exit(1); });
$args = getopt('', ['database:', 'apply', 'rollback']);
$db = think\facade\Db::query('SELECT DATABASE() db')[0]['db'];
if (($args['database'] ?? '') !== $db || isset($args['apply']) === isset($args['rollback'])) throw new RuntimeException('请指定匹配的数据库及唯一迁移方向');
$prefix = (string)think\facade\Db::connect()->getConfig('prefix');
if (!preg_match('/^[A-Za-z0-9_]*$/D', $prefix)) throw new RuntimeException('Invalid prefix');
$table = $prefix . 'store_coupon_issue';
$exists = think\facade\Db::query('SHOW COLUMNS FROM `' . $table . '` LIKE \'allow_gift_face_edit\'');
$changed = false;
if (isset($args['apply']) && !$exists) {
    think\facade\Db::execute('ALTER TABLE `' . $table . '` ADD COLUMN `allow_gift_face_edit` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'允许赠送时修改多次抵用券面值\'');
    $changed = true;
}
if (isset($args['rollback']) && $exists) {
    think\facade\Db::execute('ALTER TABLE `' . $table . '` DROP COLUMN `allow_gift_face_edit`');
    $changed = true;
}
echo json_encode(['database' => $db, 'changed' => $changed, 'mode' => isset($args['apply']) ? 'apply' : 'rollback']), "\n";
