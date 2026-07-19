<?php
declare(strict_types=1);

namespace app\services\order\terminal;

use think\exception\ValidateException;

/**
 * 退款/作废终态人话错误映射（禁止把内部异常原文返回前端）
 */
class OrderTerminalError
{
    public const ORDER_NOT_FOUND = 'ORDER_NOT_FOUND';
    public const ORDER_NOT_PAID = 'ORDER_NOT_PAID';
    public const ORDER_DELETED = 'ORDER_DELETED';
    public const ALREADY_REFUNDED = 'ALREADY_REFUNDED';
    public const ALREADY_VOIDED = 'ALREADY_VOIDED';
    public const TERMINAL_PROCESSING = 'TERMINAL_PROCESSING';
    public const ACTION_CONFLICT = 'ACTION_CONFLICT';
    public const SPLIT_NOT_ALLOWED = 'SPLIT_NOT_ALLOWED';
    public const PARTIAL_NOT_ALLOWED = 'PARTIAL_NOT_ALLOWED';
    public const REFUND_DATE_FUTURE = 'REFUND_DATE_FUTURE';
    public const REFUND_DATE_BEFORE_PAY = 'REFUND_DATE_BEFORE_PAY';
    public const REFUND_DATE_INVALID = 'REFUND_DATE_INVALID';
    public const BALANCE_BEN_NOT_ENOUGH = 'BALANCE_BEN_NOT_ENOUGH';
    public const BALANCE_GIVE_NOT_ENOUGH = 'BALANCE_GIVE_NOT_ENOUGH';
    public const ORIGIN_BEN_EXCEED = 'ORIGIN_BEN_EXCEED';
    public const ORIGIN_GIVE_EXCEED = 'ORIGIN_GIVE_EXCEED';
    public const BALANCE_SOURCE_UNKNOWN = 'BALANCE_SOURCE_UNKNOWN';
    public const BALANCE_INVARIANT = 'BALANCE_INVARIANT';
    public const REOPEN_NOT_ALLOWED = 'REOPEN_NOT_ALLOWED';
    public const REOPEN_RECHARGE_DENIED = 'REOPEN_RECHARGE_DENIED';
    public const REOPEN_DEBT_DENIED = 'REOPEN_DEBT_DENIED';
    public const REOPEN_NOT_VOID = 'REOPEN_NOT_VOID';
    public const REOPEN_DRAFT_EXPIRED = 'REOPEN_DRAFT_EXPIRED';
    public const REOPEN_DRAFT_NOT_FOUND = 'REOPEN_DRAFT_NOT_FOUND';
    public const REOPEN_ALREADY_BOUND = 'REOPEN_ALREADY_BOUND';
    public const REOPEN_ALREADY_DONE = 'REOPEN_ALREADY_DONE';
    public const REOPEN_BIND_FAILED = 'REOPEN_BIND_FAILED';
    public const REOPEN_ATTACH_UID_MISMATCH = 'REOPEN_ATTACH_UID_MISMATCH';
    public const REOPEN_ATTACH_ORDER_PAID = 'REOPEN_ATTACH_ORDER_PAID';
    public const REOPEN_ATTACH_SOURCE_CONFLICT = 'REOPEN_ATTACH_SOURCE_CONFLICT';
    public const REOPEN_ATTACH_DRAFT_STATE = 'REOPEN_ATTACH_DRAFT_STATE';
    public const IDEMPOTENT_REPLAY = 'IDEMPOTENT_REPLAY';
    public const STORE_SCOPE_DENIED = 'STORE_SCOPE_DENIED';
    public const AMOUNT_INVALID = 'AMOUNT_INVALID';
    public const REFUND_AMOUNT_EXCEED = 'REFUND_AMOUNT_EXCEED';
    public const MOBILE_WRITEOFF_DENIED = 'MOBILE_WRITEOFF_DENIED';
    public const RECHARGE_ALREADY_REFUNDED = 'RECHARGE_ALREADY_REFUNDED';
    public const WRITEOFF_SUB_ORDER = 'WRITEOFF_SUB_ORDER';
    public const REFUND_BALANCE_GT_AMOUNT = 'REFUND_BALANCE_GT_AMOUNT';
    public const BALANCE_NOT_ON_ORDER = 'BALANCE_NOT_ON_ORDER';
    public const BOOKKEEPING_CONFIRM_REQUIRED = 'BOOKKEEPING_CONFIRM_REQUIRED';
    public const CHANNEL_DONE_LOCAL_FAIL = 'CHANNEL_DONE_LOCAL_FAIL';
    public const PAYMENT_PLAN_MISMATCH = 'PAYMENT_PLAN_MISMATCH';
    public const LEGACY_REFUND_DISABLED = 'LEGACY_REFUND_DISABLED';
    public const BOOKKEEPING_NEED_MANUAL = 'BOOKKEEPING_NEED_MANUAL';
    public const GENERIC_FAIL = 'GENERIC_FAIL';

    /** @var array<string,string> */
    protected static $messages = [
        self::ORDER_NOT_FOUND => '订单不存在，请核对后再操作。',
        self::ORDER_NOT_PAID => '当前订单未支付，不能办理退款或作废。',
        self::ORDER_DELETED => '订单已删除，不能继续操作。',
        self::ALREADY_REFUNDED => '该订单已退款，不能再次退款或作废。',
        self::ALREADY_VOIDED => '该订单已作废，不能再次作废或退款。',
        self::TERMINAL_PROCESSING => '该订单正在处理退款或作废，请稍后再查看结果，不要重复提交。',
        self::ACTION_CONFLICT => '退款和作废不能同时进行，请刷新订单后重试。',
        self::SPLIT_NOT_ALLOWED => '当前只支持整笔订单退款，不能选择部分商品或分次退款。',
        self::PARTIAL_NOT_ALLOWED => '当前只支持整笔订单退款，不能部分退款。',
        self::REFUND_DATE_FUTURE => '退款日期不能晚于今天。',
        self::REFUND_DATE_BEFORE_PAY => '退款日期不能早于订单支付日期。',
        self::REFUND_DATE_INVALID => '退款日期格式不正确，请重新选择。',
        self::BALANCE_BEN_NOT_ENOUGH => '当前可退本金不足，请调整退款本金后再提交。',
        self::BALANCE_GIVE_NOT_ENOUGH => '当前可退赠金不足，请调整退款赠金后再提交。',
        self::ORIGIN_BEN_EXCEED => '退款本金不能超过本订单实际使用的本金。',
        self::ORIGIN_GIVE_EXCEED => '退款赠金不能超过本订单实际使用的赠金。',
        self::BALANCE_SOURCE_UNKNOWN => '该历史订单的本金/赠金支付明细不完整，请先人工核对',
        self::BALANCE_INVARIANT => '会员余额数据异常，请先核对后再操作。',
        self::REOPEN_NOT_ALLOWED => '该订单不能重新开单。',
        self::REOPEN_RECHARGE_DENIED => '充值订单不能重新开单，请直接办理新的充值。',
        self::REOPEN_DEBT_DENIED => '欠款补交订单不能重新开单。',
        self::REOPEN_NOT_VOID => '只有已作废的订单才能重新开单。',
        self::REOPEN_DRAFT_EXPIRED => '重开草稿已过期，请从原作废订单重新发起。',
        self::REOPEN_DRAFT_NOT_FOUND => '重开草稿不存在或已失效，请重新发起。',
        self::REOPEN_ALREADY_BOUND => '该重开草稿已生成新订单，请到收银台继续支付或查看原草稿，不要重复开单。',
        self::REOPEN_ALREADY_DONE => '该作废订单已经重新开单，不能再次重开。',
        self::REOPEN_BIND_FAILED => '重新开单关联未完成，请联系负责人核对后重试。',
        self::REOPEN_ATTACH_UID_MISMATCH => '重开草稿与当前会员不一致，请重新发起重开。',
        self::REOPEN_ATTACH_ORDER_PAID => '该订单已支付，不能再绑定重开草稿。',
        self::REOPEN_ATTACH_SOURCE_CONFLICT => '该订单已关联其他作废订单，不能重复绑定。',
        self::REOPEN_ATTACH_DRAFT_STATE => '重开草稿状态不正确，请重新发起重开。',
        self::IDEMPOTENT_REPLAY => '相同请求已提交，请勿重复操作。',
        self::STORE_SCOPE_DENIED => '无权操作其它门店的订单。',
        self::AMOUNT_INVALID => '退款金额或本金、赠金填写不正确，请输入不超过两位小数的非负数字。',
        self::REFUND_AMOUNT_EXCEED => '退款金额不能超过本订单实际支付金额，请修改后再提交。',
        self::MOBILE_WRITEOFF_DENIED => '该订单中的项目已核销，请到电脑端办理退款。',
        self::RECHARGE_ALREADY_REFUNDED => '该充值订单已退款，不能再次退款。',
        self::WRITEOFF_SUB_ORDER => '请对原销售订单办理退款或作废，不能直接操作核销单据。',
        self::REFUND_BALANCE_GT_AMOUNT => '退回本金与赠金合计不能大于退款金额，请调整后再提交。',
        self::BALANCE_NOT_ON_ORDER => '该订单未使用余额支付，不能填写退款本金或赠金。',
        self::BOOKKEEPING_CONFIRM_REQUIRED => '本单含记账收款，请确认已线下退回并勾选确认后再提交。',
        self::CHANNEL_DONE_LOCAL_FAIL => '外部退款已完成，但本地收口未完成，请联系负责人核对后继续处理，不要重复发起渠道退款。',
        self::PAYMENT_PLAN_MISMATCH => '退回的本金、赠金与记账、外部渠道合计必须等于退款金额，且不能自动改动您填写的本金或赠金，请调整后再提交。',
        self::LEGACY_REFUND_DISABLED => '旧退款入口已停用，请通过统一退款办理。',
        self::BOOKKEEPING_NEED_MANUAL => '本单含记账收款，自动任务不能直接退款成功，请由门店或后台人员确认已线下退回后再继续办理。',
        self::GENERIC_FAIL => '操作未成功，订单状态未改变，请核对后重试或联系负责人。',
    ];

    public static function message(string $code): string
    {
        return self::$messages[$code] ?? self::$messages[self::GENERIC_FAIL];
    }

    public static function throw(string $code, string $internalDetail = ''): void
    {
        $e = new ValidateException(self::message($code));
        if ($internalDetail !== '') {
            // 供日志使用；ValidateException 对外 getMessage 仍是人话
            $e->errorCode = $code;
            $e->internalDetail = $internalDetail;
        }
        throw $e;
    }

    /** @return array<string,string> */
    public static function all(): array
    {
        return self::$messages;
    }
}
