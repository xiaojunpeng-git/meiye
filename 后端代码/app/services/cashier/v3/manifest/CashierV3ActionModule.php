<?php
// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------

namespace app\services\cashier\v3\manifest;

/**
 * 一个子任务（C2／C3／C4／C5）拥有的 action 清单模块。
 *
 * 拆成独立模块文件而不是一个巨型常量：四个子任务并行开发时，
 * 各自只改自己的模块，不会在同一个文件上互相覆盖。
 */
interface CashierV3ActionModule
{
    /** 该模块的归属子任务：C2／C3／C4／C5 */
    public function owner(): string;

    /**
     * 该模块登记的 action 清单。
     *
     * 每项结构：
     *   action     => [
     *     'canonical' => 规范 action（写命令别名映射后的名字，默认与 action 相同）
     *     'type'      => 'command' | 'projection'
     *     'permission'=> 功能入口权限码；空串表示「仅需登录」，null 表示未定策略（fail-closed）
     *     'note'      => 备注（可选）
     *   ]
     *
     * @return array<string,array>
     */
    public function actions(): array;
}
