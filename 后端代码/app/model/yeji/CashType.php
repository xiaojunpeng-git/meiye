<?php
// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2020 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------
namespace app\model\yeji;

use mohe\traits\ModelTrait;
use mohe\basic\BaseModel;
use think\Model;

/**
 *  文章Model
 * Class Article
 * @package app\model\article
 */
class CashType extends BaseModel
{
    use ModelTrait;

    /** 旧卡录入 */
    const OLD_CARD_ENTRY = 9;

    /** 组合支付欠款（不计业绩，同旧卡录入） */
    const DEBT_ENTRY = 10;

    /**
     * 组合支付校验：禁止旧卡录入；combination_info 至少两条（仅余额除外，会自动按余额支付落单）
     * @param array $combinationInfo
     * @return void
     */
    public static function validateCombinationInfo(array $combinationInfo): void
    {
        if (count($combinationInfo) < 2 && !self::isOnlyBalanceCombination($combinationInfo) && !self::isOnlyDebtCombination($combinationInfo)) {
            throw new \think\exception\ValidateException('组合支付不能只有一种支付方式');
        }
        foreach ($combinationInfo as $row) {
            $price = $row['price'] ?? '';
            if ($price === '' || $price === null || bccomp((string)$price, '0', 2) <= 0) {
                throw new \think\exception\ValidateException('组合支付里不能有0金额的情况出现');
            }
            if ((int)($row['activePay'] ?? 0) === 2 && (int)($row['type'] ?? 0) === self::OLD_CARD_ENTRY) {
                throw new \think\exception\ValidateException('组合支付不支持旧卡录入');
            }
        }
    }

    /**
     * 组合支付明细是否全部为余额（不含卡升级），此类会自动转为余额支付
     */
    public static function isOnlyBalanceCombination(array $combinationInfo): bool
    {
        if ($combinationInfo === []) {
            return false;
        }
        foreach ($combinationInfo as $row) {
            if ((int)($row['activePay'] ?? 0) !== 3) {
                return false;
            }
            if (($row['pay_sub_type'] ?? 'balance') === 'card_upgrade') {
                return false;
            }
        }
        return true;
    }

    /**
     * 组合支付明细是否全部为欠款
     */
    public static function isOnlyDebtCombination(array $combinationInfo): bool
    {
        if ($combinationInfo === []) {
            return false;
        }
        foreach ($combinationInfo as $row) {
            if ((int)($row['activePay'] ?? 0) !== 3) {
                return false;
            }
            if (($row['pay_sub_type'] ?? 'balance') !== 'debt') {
                return false;
            }
        }
        return true;
    }

    /**
     * 数据表主键
     * @var string
     */
    protected $pk = 'id';

    /**
     * 模型名称
     * @var string
     */
    protected $name = 'cash_type';


}
