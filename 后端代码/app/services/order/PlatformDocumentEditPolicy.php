<?php
declare(strict_types=1);
namespace app\services\order;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\manifest\CashierV3ActionManifest;
use think\facade\Db;

/** 平台单据总开关只限制平台订单入口，不改变门店权限、历史事实或查询数据范围。 */
final class PlatformDocumentEditPolicy
{
    public const CONFIG_KEY = 'platform_document_edit_enabled';
    public const DENIED_CODE = 'PLATFORM_DOCUMENT_EDIT_DISABLED';
    public const MESSAGE = '平台端已关闭修改单据，仅可查询、查看详情、打印和导出。';
    /** 打印/导出虽使用命令回执，但不修改业务单据，关闭时继续允许。 */
    private const READ_COMMANDS = ['print-sales-order-receipt', 'create-unified-query-export'];

    /** 每次读取数据库，避免长驻进程或旧浏览器缓存继续沿用已关闭状态。 */
    public static function enabled(bool $lock = false): bool
    {
        $q = Db::name('system_config')->where('menu_name', self::CONFIG_KEY)->where('is_store', 0);
        // 事务共享锁允许并行改单，并与配置保存串行化；返回关闭后新写入不能穿越开关。
        if ($lock) $q->lock('LOCK IN SHARE MODE');
        $raw = $q->value('value');
        if ($raw === null) return true; // 未安装定义时兼容原行为，新增定义默认开启。
        $value = json_decode((string)$raw, true);
        return $value === 1 || $value === '1' || $value === true;
    }

    /** 按权威 action 清单判定，不能信任客户端的 command/type/readOnly 声明或别名。 */
    public static function changesDocument(string $action): bool
    {
        $definition = CashierV3ActionManifest::requireAction($action);
        return $definition['type'] === CashierV3ActionManifest::TYPE_COMMAND
            && !in_array($definition['canonical'], self::READ_COMMANDS, true);
    }

    public static function assertAllowed(string $action, bool $lock = false): void
    {
        if (self::changesDocument($action) && !self::enabled($lock)) {
            throw new CashierV3CommandException(self::DENIED_CODE, self::MESSAGE);
        }
    }
}
