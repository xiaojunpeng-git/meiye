<?php
// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
namespace app\services\cashier\v3;

/**
 * 收银 V3 统一结果码。
 *
 * status 规范主状态：
 * - success：成功（含幂等重放）；
 * - conflict：版本冲突，前端保留输入并提示刷新；
 * - failed：确定的业务／契约失败；
 * - result_unknown：写命令已发送后无法证明服务端是否执行（禁止换新幂等键重试）。
 */
class CashierV3ResultCode
{
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CONFLICT = 'conflict';
    /** 写命令结果未知：全系统唯一规范主状态，禁止与 failed 混用 */
    public const STATUS_RESULT_UNKNOWN = 'result_unknown';

    public const UNKNOWN_COMMAND_ACTION = 'UNKNOWN_COMMAND_ACTION';
    public const ACTION_NOT_IMPLEMENTED = 'ACTION_NOT_IMPLEMENTED';
    public const ACTION_DEPENDENCY_NOT_READY = 'ACTION_DEPENDENCY_NOT_READY';
    public const COMMAND_REQUIRED = 'COMMAND_REQUIRED';
    public const READ_ONLY_ACTION_NOT_COMMANDABLE = 'READ_ONLY_ACTION_NOT_COMMANDABLE';

    public const PERMISSION_DENIED = 'PERMISSION_DENIED';
    public const PERMISSION_POLICY_MISSING = 'PERMISSION_POLICY_MISSING';

    public const INVALID_COMMAND_CONTEXT = 'INVALID_COMMAND_CONTEXT';
    public const RESOURCE_VERSION_CONFLICT = 'RESOURCE_VERSION_CONFLICT';
    public const RESOURCE_NOT_FOUND = 'RESOURCE_NOT_FOUND';
    public const RESOURCE_SCOPE_UNRESOLVED = 'RESOURCE_SCOPE_UNRESOLVED';

    public const INVALID_IDEMPOTENCY_KEY = 'INVALID_IDEMPOTENCY_KEY';
    public const IDEMPOTENCY_KEY_CONFLICT = 'IDEMPOTENCY_KEY_CONFLICT';
    public const ORIGINAL_IDEMPOTENCY_KEY_REQUIRED = 'ORIGINAL_IDEMPOTENCY_KEY_REQUIRED';

    public const COMMAND_RESULT_INCOMPLETE = 'COMMAND_RESULT_INCOMPLETE';
    public const COMMAND_TRANSACTION_REQUIRED = 'COMMAND_TRANSACTION_REQUIRED';
    public const COMMAND_TOUCHED_INVALID = 'COMMAND_TOUCHED_INVALID';
    public const COMMAND_RESULT_UNKNOWN = 'COMMAND_RESULT_UNKNOWN';
    public const COMMAND_EVENT_CONTRACT_MISSING = 'COMMAND_EVENT_CONTRACT_MISSING';
    public const COMMAND_EVENT_PERSISTENCE_INCOMPLETE = 'COMMAND_EVENT_PERSISTENCE_INCOMPLETE';
    public const EVENT_OUTBOX_NOT_READY = 'EVENT_OUTBOX_NOT_READY';
    public const EVENT_CONTRACT_INVALID = 'EVENT_CONTRACT_INVALID';
    public const OUTBOX_STATE_INVALID = 'OUTBOX_STATE_INVALID';
    public const OUTBOX_LEASE_CONFLICT = 'OUTBOX_LEASE_CONFLICT';

    public const CLIENT_SESSION_REQUIRED = 'CLIENT_SESSION_REQUIRED';
    public const COMMAND_TABLE_NOT_READY = 'COMMAND_TABLE_NOT_READY';
    public const SECURE_RANDOM_UNAVAILABLE = 'SECURE_RANDOM_UNAVAILABLE';
    public const PROJECTION_REBUILD_FAILED = 'PROJECTION_REBUILD_FAILED';
}
