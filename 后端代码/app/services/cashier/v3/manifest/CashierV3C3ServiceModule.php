<?php
// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------

namespace app\services\cashier\v3\manifest;

/**
 * C3｜服务单、预约、房间、挂单、核销。
 *
 * 三个「去结账」动作在这里登记的是**规范写命令名**（prepare-*-checkout）。
 * 前端页面上仍叫 open-reservation-checkout / open-room-service-checkout /
 * open-service-checkout，由桥接层按别名映射成规范名后再发命令。
 * 它们会创建或复用结账请求，不是纯展示，因此不能按 projection 剥离 command。
 *
 * 四个纯准备动作（prepare-*-service-completion、prepare-room-assignment）
 * 只准备抽屉与确认界面，不改变业务状态，因此是受控 projection：
 * 不落回执、不推进业务资源版本、不写事实事件与 Outbox，
 * 只从权威源返回准备数据与最新资源版本，由随后的真正提交动作携带这些版本。
 */
class CashierV3C3ServiceModule implements CashierV3ActionModule
{
    public const OWNER = 'C3';

    private const FEATURE_ROOM = 'cashier.v3.room';
    private const FEATURE_RESERVATION = 'cashier.v3.reservation';
    private const FEATURE_HANG = 'cashier.v3.hang';
    private const FEATURE_WRITEOFF = 'cashier.v3.writeoff';
    private const FEATURE_CASHIER = 'cashier.v3.cashier';
    // 预约在本期不再按操作人角色/功能入口二次拦截。登录态和强制数据
    // 范围仍由统一网关注入；这里仅取消操作级授权门禁。
    private const POLICY_RESERVATION_OPERATION = 'policy:reservation_operation';

    /**
     * 页面 action => 规范写命令。桥接层按此表映射后再发送。
     * 前后端共用同一张表：客户端 manifest 里必须出现同样的三条。
     */
    public const CHECKOUT_ALIASES = [
        'open-reservation-checkout' => 'prepare-reservation-checkout',
        'open-room-service-checkout' => 'prepare-room-service-checkout',
        'open-service-checkout' => 'prepare-service-checkout',
    ];

    /** 受控 projection：只准备界面，不改业务状态 */
    public const PREPARATION_PROJECTIONS = [
        'prepare-service-completion',
        'prepare-reservation-service-completion',
        'prepare-room-service-completion',
        'prepare-room-assignment',
    ];

    public function owner(): string
    {
        return self::OWNER;
    }

    public function actions(): array
    {
        $command = [];
        $projection = [];
        $aliases = [];

        // ---- 三个去结账：规范写命令 + 页面别名 ----
        $aliasFeature = [
            'prepare-reservation-checkout' => self::FEATURE_RESERVATION,
            'prepare-room-service-checkout' => self::FEATURE_ROOM,
            'prepare-service-checkout' => self::FEATURE_CASHIER,
        ];
        foreach (self::CHECKOUT_ALIASES as $pageAction => $canonical) {
            $command[$canonical] = $aliasFeature[$canonical];
            $aliases[$pageAction] = $canonical;
        }

        // ---- 服务确认与房间安排：受控 projection ----
        $projection['prepare-service-completion'] = self::FEATURE_CASHIER;
        $projection['prepare-reservation-service-completion'] = self::FEATURE_RESERVATION;
        $projection['prepare-room-service-completion'] = self::FEATURE_ROOM;
        $projection['prepare-room-assignment'] = self::FEATURE_ROOM;

        // ---- 服务确认提交与后续流转（写）----
        foreach ([
            'confirm-service-completion' => self::FEATURE_CASHIER,
            'save-service-line-completion' => self::FEATURE_CASHIER,
            'save-service-line-craftsmen' => self::FEATURE_CASHIER,
            'finish-service-completion' => self::FEATURE_CASHIER,
            'retry-service-completion' => self::FEATURE_CASHIER,
            'return-to-service-edit' => self::FEATURE_CASHIER,
            'continue-service-checkout' => self::FEATURE_CASHIER,
            'save-service-room-assignment' => self::FEATURE_ROOM,
        ] as $action => $feature) {
            $command[$action] = $feature;
        }
        $projection['open-service-line-completion'] = self::FEATURE_CASHIER;
        $projection['open-service-line-staff-allocation'] = self::FEATURE_CASHIER;
        $projection['query-service-completion-result'] = self::FEATURE_CASHIER;

        // ---- 预约 ----
        foreach ([
            'confirm-reservation',
            'reject-reservation',
            'mark-reservation-no-show',
        ] as $action) {
            $command[$action] = self::FEATURE_RESERVATION;
        }
        foreach ([
            'create-reservation',
            'update-reservation',
            'cancel-reservation',
            'start-reservation-service',
            'end-reservation-service',
            'select-reservation-member',
        ] as $action) {
            $command[$action] = self::POLICY_RESERVATION_OPERATION;
        }
        foreach ([
            'query-reservations',
            'open-reservation-detail',
            'open-reservation-editor',
            'open-reservation-more-actions',
            'open-reservation-member-selector',
            'query-reservation-project-catalog',
            'change-reservation-calendar-date',
            'recalculate-reservation-plan',
            'query-reservation-result',
        ] as $action) {
            $projection[$action] = self::FEATURE_RESERVATION;
        }

        // ---- 房间 ----
        foreach ([
            'refresh-room-status',
            'open-room-detail',
            'open-room-more-actions',
            'open-room-next-reservation',
            'open-room-service-session',
            'open-unassigned-room-list',
            'prepare-empty-room-cashier',
        ] as $action) {
            $projection[$action] = self::FEATURE_ROOM;
        }

        // ---- 挂单 ----
        foreach (['submit-hang-order', 'resume-hang-order', 'void-hang-order'] as $action) {
            $command[$action] = self::FEATURE_HANG;
        }
        foreach ([
            'query-hang-orders',
            'open-hang-order',
            'open-hang-order-void-confirmation',
            'query-hang-order-result',
        ] as $action) {
            $projection[$action] = self::FEATURE_HANG;
        }

        // ---- 核销工作台 ----
        foreach ([
            'submit-writeoff',
            'toggle-writeoff-project',
            'change-writeoff-project-times',
            'change-writeoff-supplement-date',
            'exit-writeoff-supplement',
            'clear-writeoff-selection',
            'return-to-writeoff-edit',
            'start-service-from-writeoff',
        ] as $action) {
            $command[$action] = self::FEATURE_WRITEOFF;
        }
        foreach ([
            'select-writeoff-member',
        ] as $action) {
            $command[$action] = self::FEATURE_WRITEOFF;
        }
        foreach ([
            'prepare-writeoff',
            'focus-writeoff-source',
            'open-writeoff-records',
            'open-writeoff-more-actions',
            'open-writeoff-member-selector',
            'open-writeoff-staff-allocation',
            'query-writeoff-result',
        ] as $action) {
            $projection[$action] = self::FEATURE_WRITEOFF;
        }

        return CashierV3ActionManifest::buildModuleActions(self::OWNER, $command, $projection, $aliases);
    }
}
