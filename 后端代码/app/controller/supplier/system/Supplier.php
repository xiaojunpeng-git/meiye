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

namespace app\controller\supplier\system;

use app\controller\supplier\AuthController;
use app\services\supplier\SystemSupplierServices;
use app\services\system\admin\SystemAdminServices;
use think\facade\App;

/**
 * 供应商控制器
 * Class Supplier
 * @package app\controller\supplier
 */
class Supplier extends AuthController
{

    /**
     * 构造方法
     * Supplier constructor.
     * @param App $app
     * @param SystemSupplierServices $supplierServices
     */
    public function __construct(App $app, SystemSupplierServices $supplierServices)
    {
        parent::__construct($app);
        $this->services = $supplierServices;
    }

    /**
     * 获取供应商信息
     * @return void
     */
    public function read()
    {
        $info = $this->services->getSupplierInfo((int)$this->supplierId, 'id, supplier_name, name, phone, admin_id, email, address, province, city, area, street, detailed_address, sort, is_show, mark', ['admin']);
        $info->hidden(['roles', 'admin_is_del', 'admin_type', 'level','admin_id']);
        $info = $info->toArray();
        $info['pwd'] = '';
        return $this->success($info);
    }

    /**
     * 更新供应商信息
     * @return void
     */
    public function update()
    {
        $data = $this->request->postMore([
            ['supplier_name', ''],
            ['name', ''],
            ['phone', ''],
            ['email', ''],
            ['address', ''],
            ['province', 0],
            ['city', 0],
            ['area', 0],
            ['street', 0],
            ['detailed_address', ''],
            ['account', ''],
            ['conf_pwd', ''],
            ['pwd', ''],
        ]);

        $this->validate($data, \app\validate\supplier\SystemSupplierValidate::class, 'update');
        $data['address'] = str_replace([' ', '/', '\\'], '', $data['address']);
        $data['detailed_address'] = str_replace([' ', '/', '\\'], '', $data['detailed_address']);
        /** @var SystemAdminServices $adminServices */
        $adminServices = app()->make(SystemAdminServices::class);
        if (!$adminInfo = $adminServices->get($this->supplierInfo['admin_id'])) {
			return $this->fail('管理员不存在,无法修改');
        }
        if ($adminInfo->is_del) {
			return $this->fail('管理员已经删除');
        }
        //修改账号
        if (isset($data['account']) && $data['account'] !=$adminInfo->account && $adminServices->isAccountUsable($data['account'], $this->supplierInfo['admin_id'], 2)) {
			return $this->fail('管理员账号已存在');
        }
        if ($data['pwd']) {
            if (!$data['conf_pwd']) {
				return $this->fail('请输入确认密码');
            }

            if ($data['conf_pwd'] != $data['pwd']) {
				return $this->fail('上次输入的密码不相同');
            }

            $adminInfo->pwd = $adminServices->passwordHash($data['pwd']);

        }
        $adminInfo->real_name = $data['name'] ?? $adminInfo->real_name;
        $adminInfo->phone = $data['phone'] ?? $adminInfo->phone;
        $adminInfo->account = $data['account'] ?? $adminInfo->account;

        unset($data['account']);
        unset($data['pwd']);
        unset($data['conf_pwd']);

        // 修改管理员
        $res = $adminInfo->save();
        if (!$res) return $this->fail('管理员修改失败');
        $this->services->update((int)$this->supplierId, $data);
        return $this->success('保存成功');
    }

    /**
     * 获取供应商财务信息
     * @return mixed
     */
    public function getFinanceInfo()
    {
        $Info = $this->services->get((int)$this->supplierId);
        if (!$Info) {
            return app('json')->fail('供应商不存在');
        }
        return app('json')->success($Info->toArray());
    }

    /**
     * 设置供应商财务信息
     * @return mixed
     */
    public function setFinanceInfo()
    {
        $data = $this->request->postMore([
            ['bank_code', ''],
            ['bank_address', ''],
            ['alipay_account', ''],
            ['alipay_qrcode_url', ''],
            ['wechat', ''],
            ['wechat_qrcode_url', '']
        ]);
        $Info = $this->services->get((int)$this->supplierId);
        if (!$Info) {
            return app('json')->fail('供应商不存在');
        }
        if ($this->services->update($Info['id'], $data)) {
            return app('json')->success('设置成功');
        } else {
            return app('json')->fail('设置失败');
        }
    }
}
