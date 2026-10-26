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

namespace app\services\supplier\finance;

use app\dao\supplier\finance\SupplierExtractDao;
use app\dao\store\StoreUserDao;
use app\services\BaseServices;
use app\services\supplier\SystemSupplierServices;
use app\services\system\admin\SystemAdminServices;
use mohe\services\FormBuilder as Form;
use think\exception\ValidateException;
use think\facade\Route as Url;

/**
 * 门店提现
 * Class StoreExtractServices
 * @package app\services\store\finance
 * @mixin SupplierExtractDao
 */
class SupplierExtractServices extends BaseServices
{
    /**
     * 构造方法
     * StoreUser constructor.
     * @param StoreUserDao $dao
     */
    public function __construct(SupplierExtractDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 获取一条提现记录
     * @param int $id
     * @param array $field
     * @return array|\think\Model|null
     */
    public function getExtract(int $id, array $field = [])
    {
        return $this->dao->get($id, $field);
    }

    /**
     * 显示资源列表
     * @param array $where
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function index(array $where, array $whereData = [])
    {
        $list = $this->getStoreExtractList($where);
        //待审核金额
        $where['status'] = 0;
        $extract_statistics['price'] = $this->dao->getExtractMoneyByWhere($where, 'extract_price');

        //待转账金额
        $where['status'] = 1;
        $where['pay_status'] = 0;
        $extract_statistics['unPayPrice'] = $this->dao->getExtractMoneyByWhere($where, 'extract_price');
        //累计提现
        $where['status'] = 1;
        $where['pay_status'] = 1;
        $extract_statistics['paidPrice'] = $this->dao->getExtractMoneyByWhere($where, 'extract_price');
        $extract_statistics['price_count'] = 0;
        //未提现金额
        /** @var SupplierFlowingWaterServices $financeFlowServices */
        $financeFlowServices = app()->make(SupplierFlowingWaterServices::class);
        $whereData['is_take'] = 1;
		$price_not = $financeFlowServices->getSumFinance(['supplier_id' => isset($where['supplier_id']) && $where['supplier_id'] ? $where['supplier_id'] : 0], $whereData);
        $extract_statistics['price_not'] = max($price_not, 0);
        //冻结中金额
        $whereData['is_take'] = 0;
        $freeze_price = $financeFlowServices->getFreezeSumFinance($whereData);
        $extract_statistics['freeze_price'] = max($freeze_price, 0);

		$extract_statistics['extract_min_price'] = sys_config('supplier_extract_min_price');
		$extract_statistics['extract_max_price'] = sys_config('supplier_extract_max_price');
        return compact('extract_statistics', 'list');
    }


    /**
     * 获取提现列表
     * @param array $where
     * @param string $field
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getStoreExtractList(array $where, string $field = '*')
    {
        [$page, $limit] = $this->getPageValue();
        $list = $this->dao->getExtractList($where, $field, ['supplier'], $page, $limit);
        if ($list) {
            /** @var SystemAdminServices $adminServices */
            $adminServices = app()->make(SystemAdminServices::class);
            $adminIds = array_unique(array_column($list, 'admin_id'));
            $adminInfos = [];

            if ($adminIds) $adminInfos = $adminServices->getColumn([['id', 'in', $adminIds]], 'id,real_name', 'id');
            foreach ($list as &$item) {
                $item['add_time'] = $item['add_time'] ? date("Y-m-d H:i:s", $item['add_time']) : '';
                $item['admin_name'] = $item['admin_id'] ? ($adminInfos[$item['admin_id']]['real_name'] ?? '') : '';
            }
        }

        $count = $this->dao->count($where);
        return compact('list', 'count');
    }

    /**
     * 提现申请
     * @param int $supplierId
     * @param array $data
     * @return bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function cash(int $supplierId, array $data)
    {
        /** @var SystemSupplierServices $systemSupplier */
        $systemSupplier = app()->make(SystemSupplierServices::class);
        $supplierInfo = $systemSupplier->getSupplierInfo($supplierId);

        $insertData = [];
        switch ($data['extract_type']) {
            case 'bank':
                if (!$supplierInfo['bank_code'] || !$supplierInfo['bank_address']) {
                    throw new ValidateException('请先设置提现银行与开户行信息');
                }
                $insertData['bank_code'] = $supplierInfo['bank_code'];
                $insertData['bank_address'] = $supplierInfo['bank_address'];
                break;
            case 'alipay':
                if (!$supplierInfo['alipay_account'] || !$supplierInfo['alipay_qrcode_url']) {
                    throw new ValidateException('请先设置提现支付宝信息');
                }
                $insertData['alipay_account'] = $supplierInfo['alipay_account'];
                $insertData['qrcode_url'] = $supplierInfo['alipay_qrcode_url'];
                break;
            case 'weixin':
                if (!$supplierInfo['wechat'] || !$supplierInfo['wechat_qrcode_url']) {
                    throw new ValidateException('请先设置提现微信信息');
                }
                $insertData['wechat'] = $supplierInfo['wechat'];
                $insertData['qrcode_url'] = $supplierInfo['wechat_qrcode_url'];
                break;
            default:
                throw new ValidateException('暂不支持该类型提现');
                break;
        }
        $insertData['supplier_id'] = $supplierInfo['id'];
        $insertData['extract_type'] = $data['extract_type'];
        $insertData['extract_price'] = $data['money'];
        $insertData['add_time'] = time();
        $insertData['supplier_mark'] = $data['mark'];
        $insertData['status'] = 0;
        if (!$this->dao->save($insertData)) {
            return false;
        }
        return true;
    }


    /**
     * 拒绝
     * @param $id
     * @return mixed
     */
    public function refuse(int $id, string $message, int $adminId)
    {
        $extract = $this->getExtract($id);
        if (!$extract) {
            throw new ValidateException('操作记录不存在!');
        }
        if ($extract->status == 1) {
            throw new ValidateException('已经提现,错误操作');
        }
        if ($extract->status == -1) {
            throw new ValidateException('您的提现申请已被拒绝,请勿重复操作!');
        }
        if ($this->dao->update($id, ['fail_time' => time(), 'fail_msg' => $message, 'status' => -1, 'admin_id' => $adminId])) {
            return true;
        } else {
            throw new ValidateException('操作失败!');
        }
    }

    /**
     * 通过
     * @param $id
     * @return mixed
     */
    public function adopt(int $id, int $adminId)
    {
        $extract = $this->getExtract($id);
        if (!$extract) {
            throw new ValidateException('操作记录不存!');
        }
        if ($extract->status == 1) {
            throw new ValidateException('您已提现,请勿重复提现!');
        }
        if ($extract->status == -1) {
            throw new ValidateException('您的提现申请已被拒绝!');
        }
        if ($this->dao->update($id, ['status' => 1, 'admin_id' => $adminId])) {
            return true;
        } else {
            throw new ValidateException('操作失败!');
        }
    }

    /**
     * 转账页面
     * @param int $id
     * @return string
     */
    public function add_transfer(int $id)
    {
        $field = array();
        $title = '转账信息';
        $field[] = Form::input('voucher_title', '转账说明：', '')->maxlength(30)->required();
        $field[] = Form::frameImage('voucher_image', '转账凭证：', Url::buildUrl(config('admin.admin_prefix') .  '/widget.images/index', array('fodder' => 'voucher_image')), '')->icon('ios-add')->width('960px')->height('505px')->modal(['footer-hide' => true]);
        return create_form($title, $field, Url::buildUrl('/supplier/extract/save_transfer/' . $id), 'POST');
    }
}
