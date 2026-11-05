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
namespace app\controller\admin\v1\system\config;

use app\controller\admin\AuthController;
use app\Request;
use app\services\diy\DiyServices;
use app\services\store\SystemStoreServices;
use app\services\system\config\SystemConfigServices;
use app\services\system\config\SystemConfigTabServices;
use think\facade\App;

/**
 * 系统配置
 * Class SystemConfig
 * @package app\controller\admin\v1\setting
 */
class SystemConfig extends AuthController
{

    /**
     * SystemConfig constructor.
     * @param App $app
     * @param SystemConfigServices $services
     */
    public function __construct(App $app, SystemConfigServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    /**
     * 显示资源列表
     *
     * @return \think\Response
     */
    public function index()
    {
        $where = $this->request->getMore([
            ['tab_id', 0],
            ['status', -1]
        ]);
        if (!$where['tab_id']) {
            return $this->fail('参数错误');
        }
        if ($where['status'] == -1) {
            unset($where['status']);
        }
        $where['is_store'] = 0;
        return $this->success($this->services->getConfigList($where));
    }

    /**
     * 显示创建资源表单页.
     * @param $type
     * @return \think\Response
     */
    public function create()
    {
        [$type, $tabId] = $this->request->getMore([
            [['type', 'd'], ''],
            [['tab_id', 'd'], 1]
        ], true);
        return $this->success($this->services->createFormRule($type, $tabId));
    }

    /**
     * 保存新建的资源
     *
     * @return \think\Response
     */
    public function save()
    {
        $data = $this->request->postMore([
            ['menu_name', ''],
            ['type', ''],
            ['input_type', ''],
            ['config_tab_id', []],
            ['parameter', ''],
            ['upload_type', 1],
            ['required', ''],
            ['width', 0],
            ['high', 0],
            ['value', ''],
            ['info', ''],
            ['desc', ''],
            ['sort', 0],
            ['status', 0],
            ['is_store', 0],
        ]);
        if ($data['config_tab_id']) $data['config_tab_id'] = end($data['config_tab_id']);
        if (!$data['info']) return $this->fail('请输入配置名称');
        if (!$data['menu_name']) return $this->fail('请输入字段名称');
        if (!$data['desc']) return $this->fail('请输入配置简介');
        if ($data['sort'] < 0) {
            $data['sort'] = 0;
        }
        if ($data['type'] == 'text') {
            if (!$data['width']) return $this->fail('请输入文本框的宽度');
            if ($data['width'] <= 0) return $this->fail('请输入正确的文本框的宽度');
        }
        if ($data['type'] == 'textarea') {
            if (!$data['width']) return $this->fail('请输入多行文本框的宽度');
            if (!$data['high']) return $this->fail('请输入多行文本框的高度');
            if ($data['width'] < 0) return $this->fail('请输入正确的多行文本框的宽度');
            if ($data['high'] < 0) return $this->fail('请输入正确的多行文本框的宽度');
        }
        if ($data['type'] == 'radio' || $data['type'] == 'checkbox') {
            if (!$data['parameter']) return $this->fail('请输入配置参数');
            $this->services->valiDateRadioAndCheckbox($data);
        }
        $data['value'] = json_encode($data['value']);
        $config = $this->services->getOne(['menu_name' => $data['menu_name']]);
        if ($config) {
            $this->services->update($config['id'], $data, 'id');
        } else {
            $this->services->save($data);
        }
        event('config.create', [$data]);
        return $this->success('添加配置成功!');
    }

    /**
     * 显示指定的资源
     *
     * @param int $id
     * @return \think\Response
     */
    public function read($id)
    {
        if (!$id) {
            return $this->fail('参数错误，请重新打开');
        }
        $info = $this->services->getReadList((int)$id);
        return $this->success(compact('info'));
    }

    /**
     * 显示编辑资源表单页.
     *
     * @param int $id
     * @return \think\Response
     */
    public function edit($id)
    {
        $config = $this->services->get((int)$id);
        if ($config && in_array($config['menu_name'], ['merchant_mini_appid', 'merchant_mini_appsecret'], true)) {
            return $this->fail('请在小程序配置页成对修改商家小程序凭据');
        }
        return $this->success($this->services->editConfigForm((int)$id));
    }

    /**
     * 保存更新的资源
     *
     * @param int $id
     * @return \think\Response
     */
    public function update($id)
    {
        $type = request()->post('type');
        if ($type == 'text' || $type == 'textarea' || $type == 'radio' || ($type == 'upload' && (request()->post('upload_type') == 1 || request()->post('upload_type') == 3))) {
            $value = request()->post('value');
        } else {
            $value = request()->post('value/a');
        }
        if (!$value) $value = request()->post(request()->post('menu_name'));
        $data = $this->request->postMore([
			['is_store', 0],
			['status', 1],
			['info', ''],
			['desc', ''],
			['sort', 0],
			['config_tab_id', 0],
			['required', ''],
			['parameter', ''],
			['value', $value],
			['upload_type', 1],
			['input_type', '']
		]);
        $data['config_tab_id'] = end($data['config_tab_id']);
        $config = $this->services->get($id);
        if (!$config) {
            return $this->fail('编辑的记录不存在!');
        }
        if (in_array($config['menu_name'], ['merchant_mini_appid', 'merchant_mini_appsecret'], true)) {
            return $this->fail('请在小程序配置页成对修改商家小程序凭据');
        }
        $data['value'] = json_encode($data['value']);
        $this->services->update($id, $data);
        \mohe\services\SystemConfigService::clear();
        return $this->success('修改成功!');
    }

    /**
     * 删除指定资源
     *
     * @param int $id
     * @return \think\Response
     */
    public function delete($id)
    {
        if (!$this->services->delete($id))
            return $this->fail('删除失败,请稍候再试!');
        else {
            event('config.delete', [$id]);
            return $this->success('删除成功!');
        }
    }

    /**
     * 修改状态
     * @param $id
     * @param $status
     * @return mixed
     */
    public function set_status($id, $status)
    {
        if ($status == '' || $id == 0) {
            return $this->fail('参数错误');
        }
        $this->services->update($id, ['status' => $status]);
        event('config.status', [$id, $status]);
        return $this->success($status == 0 ? '隐藏成功' : '显示成功');
    }

    /**
     * 基础配置
     * */
    public function edit_basics(Request $request)
    {
        $tabId = $this->request->param('tab_id', 1);
        if (!$tabId) {
            return $this->fail('参数错误');
        }
        $url = $request->baseUrl();
        return $this->success($this->services->getConfigForm($url, $tabId));
    }

    /**
     * @param string $type
     * @return mixed
     */
    public function getNewFormBuild(string $type)
    {
        return $this->success($this->services->getNewFormBuildRule($type));
    }

	/**
     * 获取用户配置
     * @return mixed
     */
    public function getUserConfig($type)
    {
        return $this->success($this->services->getUserConfig($type));
    }

	/**
 	* 保存用户配置
	* @param $type
	* @return mixed
	 */
    public function saveUserConfig($type)
    {
		$post = $this->request->post();
		$this->validate($post, \app\validate\admin\setting\SystemConfigValidate::class);
		$this->services->saveUserConfig($type, $post);
        return $this->success('保存成功');
    }

    /**
     * 获取缩略图配置
     * @return mixed
     */
    public function getImageConfig()
    {
        return $this->success($this->services->getImageConfig());
    }

    /**
     * 保存数据    true
     * */
    public function save_basics(Request $request)
    {
        $post = $this->request->post();
        // 排班已迁至独立版本化保存；旧标签页不能绕过版本保护覆盖该开关。
        if (array_key_exists('reservation_schedule_manage', $post)) {
            if ((int)$post['reservation_schedule_manage'] !== (\app\services\store\roster\StaffRosterSwitch::enabled()?1:0)) return $this->fail('启用排班已更新，请在该参数处单独保存。');
            unset($post['reservation_schedule_manage']);
        }
        // 新开关缺少配置定义时拒绝整张表单，避免界面显示保存成功但语音范围未落库。
        if (array_key_exists('reservation_service_voice_mode', $post)) {
            if (!in_array((string)$post['reservation_service_voice_mode'], ['1', '2', '3'], true)) {
                return $this->fail('服务语音播报选项无效');
            }
            if (!$this->services->getOne(['menu_name' => 'reservation_service_voice_mode', 'is_store' => 0])) {
                return $this->fail('服务语音播报配置缺失，请先完成配置升级');
            }
        }
        // The member selector switch is rendered with an off fallback before its
        // config definition exists. Reject that incomplete installation before
        // saving any form fields, instead of reporting success for a lost choice.
        if (array_key_exists('cashier_member_default_all_switch', $post)
            && !$this->services->getOne(['menu_name' => 'cashier_member_default_all_switch', 'is_store' => 0])) {
            return $this->fail('选择会员默认全部配置缺失，请先完成配置升级');
        }

        foreach ($post as $k => $v) {
            if (is_array($v)) {
                $res = $this->services->getUploadTypeList($k);
                foreach ($res as $kk => $vv) {
                    if ($kk == 'upload') {
                        if ($vv == 1 || $vv == 3) {
                            $post[$k] = $v[0];
                        }
                    }
                }
            }
        }
		//打开友情链接 && 友情链接有配置内容
		if (isset($post['links_list']) && !empty($post['links_list']) && isset($post['links_open']) && $post['links_open']) {
			foreach ($post['links_list'] as $item) {
				foreach ($item as $v => $k) {
					if ('' === $item[$v]) {
						return $this->fail('友情链接内容不能为空');
					}
				}
			}
		}
		if (isset($post['filing_list']) && !empty($post['filing_list'])) {
			foreach ($post['filing_list'] as $item) {
				foreach ($item as $v => $k) {
					if ('' === $item[$v]) {
						return $this->fail('PC底部自定义展示内容不能为空');
					}
				}
			}
		}
        $this->validate($post, \app\validate\admin\setting\SystemConfigValidate::class);
        if (isset($post['upload_type'])) {
            $this->services->checkThumbParam($post);
        }
        if (isset($post['store_brokerage_binding_status'])) {
            $this->services->checkBrokerageBinding($post);
        }
        if (isset($post['store_brokerage_ratio']) && isset($post['store_brokerage_two'])) {
            $num = $post['store_brokerage_ratio'] + $post['store_brokerage_two'];
            if ($num > 100) {
                return $this->fail('一二级返佣比例不能大于100%');
            }
        }
        if (isset($post['spread_banner'])) {
            $num = count($post['spread_banner']);
            if ($num > 5) {
                return $this->fail('分销海报不能多于5张');
            }
        }
        if (isset($post['user_extract_min_price'])) {
            if (!preg_match('/[0-9]$/', $post['user_extract_min_price'])) {
                return $this->fail('提现最低金额只能为数字!');
            }
        }
		if (isset($post['user_extract_max_price'])) {
			if (!preg_match('/[0-9]$/', $post['user_extract_max_price'])) {
				return $this->fail('提现最高金额只能为数字!');
			}
		}
        if (isset($post['store_extract_max_price']) && isset($post['store_extract_min_price'])) {
            if ($post['store_extract_max_price'] < $post['store_extract_min_price']) {
                return $this->fail('门店提现最低金额不能大于最高金额');
            }
        }
        //小程序支付
        if(isset($post['pay_routine_open']) && $post['pay_routine_open']) {
            if (empty($post['pay_routine_mchid'])) {
                return $this->fail('小程序商户号不能为空');
            }
        }
		if (isset($post['routine_auth_type'])) {
			if (empty($post['routine_auth_type']) || count($post['routine_auth_type']) == 0) {
				return app('json')->fail('手机号获取方式至少选择一个');
			}
		}
        if(isset($post['integral_ratio_status']) && $post['integral_ratio_status'] && isset($post['integral_ratio']) && $post['integral_ratio'] <= 0) return app('json')->fail('积分抵用比例必须大于0');
        //全场包邮开关
//        if (isset($post['whole_free_shipping'])) {
//            $wholeFreeShipping = (int)$post['whole_free_shipping'];
//            if (!$wholeFreeShipping) {
//                $post['store_free_postage'] = 0;
//            }
//            unset($post['whole_free_shipping']);
//        }
		//分销提现
		if (isset($post['user_extract_alipay_status'])) {
			if (!$post['user_extract_bank_status'] && !$post['user_extract_alipay_status'] && !$post['user_extract_wechat_status'] && !$post['user_extract_balance_status']) {
				return app('json')->fail('分销提现方式至少请开启一个');
			}
		}
		//系统参数过滤
		if (isset($post['param_filter_data'])) {
			$post['param_filter_data'] = base64_encode($post['param_filter_data']);
		}
		//进店规则
		if (isset($post['user_entry_rules']) || isset($post['shop_operation_type'])) {
			app()->make(SystemStoreServices::class)->clearEntryStoreCache();
			app()->make(DiyServices::class)->updateCacheDiyVersion();
		}
		if (isset($post['ico_path'])) {
			$from = public_path() . $post['ico_path'];
			$toHome = public_path('home') . 'favicon.ico';
			$toPublic = public_path() . 'favicon.ico';
			@copy($from, $toHome);
			@copy($from, $toPublic);
		}

        // Resolve both switches from submitted values or existing instance
        // config. A partial form post may not leave two live collectors.
        $paymentKeys = ['fuyou_pay_status', 'fuyou_id', 'fuyou_key', 'fuyou_sub_appid', 'fuyou_name',
            'suixingpay_pay_status', 'suixingpay_org_id', 'suixingpay_mno', 'suixingpay_gateway_url',
            'suixingpay_platform_public_key', 'suixingpay_sub_appid'];
        $touchesPayment = (bool)array_intersect($paymentKeys, array_keys($post));
        $fuyouOn = (int)($post['fuyou_pay_status'] ?? sys_config('fuyou_pay_status', 0)) === 1;
        $suixingOn = (int)($post['suixingpay_pay_status'] ?? sys_config('suixingpay_pay_status', 0)) === 1;
        if ($touchesPayment && $fuyouOn && $suixingOn) return $this->fail('富友与随行付只能开启一种，请先关闭另一种。');
        if ($touchesPayment && $suixingOn) {
            $required = ['suixingpay_org_id' => '随行付机构号', 'suixingpay_mno' => '随行付商户号',
                'suixingpay_gateway_url' => '随行付接口地址',
                'suixingpay_platform_public_key' => '随行付平台公钥',
                'suixingpay_sub_appid' => '随行付小程序 AppID'];
            foreach ($required as $key => $label) {
                $value = trim((string)($post[$key] ?? sys_config($key, '')));
                if ($value === '') return $this->fail($label . '不能为空');
            }
            $gateway = trim((string)($post['suixingpay_gateway_url'] ?? sys_config('suixingpay_gateway_url', '')));
            $host = (string)parse_url($gateway, PHP_URL_HOST);
            if (parse_url($gateway, PHP_URL_SCHEME) !== 'https'
                || !preg_match('/(^|\.)(tianquetech\.com|suixingpay\.com)$/D', $host))
                return $this->fail('随行付接口地址必须是官方 HTTPS 域名');
            $appid = trim((string)($post['suixingpay_sub_appid'] ?? sys_config('suixingpay_sub_appid', '')));
            if ($appid !== (string)sys_config('routine_appId'))
                return $this->fail('随行付小程序 AppID 与当前会员端不一致');
            $callbackBase = trim((string)sys_config('site_url', ''));
            $callbackParts = parse_url($callbackBase);
            if (!$callbackParts || strtolower((string)($callbackParts['scheme'] ?? '')) !== 'https'
                || empty($callbackParts['host']))
                return $this->fail('请先配置本站 HTTPS 回调域名');
            $keyFile = trim((string)env('SUIXINGPAY_PRIVATE_KEY_FILE', ''));
            if ($keyFile === '' || !is_file($keyFile) || !is_readable($keyFile)
                || (fileperms($keyFile) & 0007) !== 0)
                return $this->fail('请先在本实例服务器安全配置随行付私钥文件');
        }

        if ($touchesPayment && $fuyouOn) {
            $required = [
                'fuyou_id' => '富友商家ID',
                'fuyou_key' => '富友支付Key',
                'fuyou_sub_appid' => '富友sub_appid',
                'fuyou_name' => '支付名称',
            ];
            foreach ($required as $key => $label) {
                $value = array_key_exists($key, $post) && trim((string)$post[$key]) !== ''
                    ? trim((string)$post[$key])
                    : trim((string)sys_config($key, ''));
                if ($value === '') {
                    return $this->fail($label . '不能为空');
                }
            }
        }

        // 富友支付 Key 留空表示不修改，避免密钥回显与误覆盖
        if (array_key_exists('fuyou_key', $post) && trim((string)$post['fuyou_key']) === '') {
            unset($post['fuyou_key']);
        }

        if (array_key_exists('merchant_mini_appid', $post) || array_key_exists('merchant_mini_appsecret', $post)) {
            // Merchant and member mini-programs can have different AppIDs.
            // Save the pair only after the instance's config rows exist, and
            // never erase a stored secret when the password field is blank.
            if (!$this->services->getOne(['menu_name' => 'merchant_mini_appid', 'is_store' => 0])
                || !$this->services->getOne(['menu_name' => 'merchant_mini_appsecret', 'is_store' => 0])) {
                return $this->fail('请先完成商家小程序配置升级');
            }
            $merchantAppId = trim((string)($post['merchant_mini_appid'] ?? sys_config('merchant_mini_appid', '')));
            $merchantSecret = trim((string)($post['merchant_mini_appsecret'] ?? ''));
            $storedAppId = trim((string)sys_config('merchant_mini_appid', ''));
            $storedSecret = trim((string)sys_config('merchant_mini_appsecret', ''));
            if ($merchantAppId !== '' && !preg_match('/^wx[A-Za-z0-9]{16}$/', $merchantAppId)) {
                return $this->fail('商家小程序 AppID 格式无效');
            }
            if ($merchantSecret !== '' && !preg_match('/^[A-Za-z0-9]{16,128}$/', $merchantSecret)) {
                return $this->fail('商家小程序 AppSecret 格式无效');
            }
            if (($merchantAppId === '') !== ($merchantSecret === '' && $storedSecret === '')) {
                return $this->fail('商家小程序 AppID 和 AppSecret 必须配套填写');
            }
            if ($merchantAppId !== $storedAppId && $merchantSecret === '' && $storedSecret !== '') {
                return $this->fail('更换商家小程序 AppID 时必须填写对应的 AppSecret');
            }
            if ($merchantSecret === '') {
                unset($post['merchant_mini_appsecret']);
            }
        }

        foreach ($post as $k => $v) {
            $config_one = $this->services->getOne(['menu_name' => $k]);
            if ($config_one) {
                $config_one['value'] = $v;
                $this->services->valiDateValue($config_one);
                $this->services->update($k, ['value' => json_encode($v)], 'menu_name');
            }
        }
        if (isset($post['wss_open'])) {
            $this->services->saveSslFilePath((int)$post['wss_open'], $post['wss_local_pk'] ?? '', $post['wss_local_cert'] ?? '');
        }
        \mohe\services\SystemConfigService::clear();
        return $this->success('修改成功');
    }

    /**
     * 获取系统设置头部分类
     * @param SystemConfigTabServices $services
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function header_basics(SystemConfigTabServices $services)
    {
        [$type, $pid] = $this->request->getMore([
            [['type', 'd'], 0],
            [['pid', 'd'], 0]
        ], true);
        if ($type == 3) {//其它分类
            $config_tab = [];
        } else {
            $config_tab = $services->getConfigTab($pid);
        }
        return $this->success(compact('config_tab'));
    }

    /**
     * 获取单个配置的值
     * @param $name
     * @return mixed
     */
    public function get_system($name)
    {
        if ($name === 'merchant_mini_appsecret') {
            // The generic single-value endpoint must not bypass the
            // credential form's non-echo behavior.
            return $this->success(['value' => '', 'configured' => (string)sys_config($name, '') !== '']);
        }
        $value = sys_config($name);
        return $this->success(compact('value'));
    }

    /**
     * @return mixed
     */
    public function getVersion()
    {
        $version = get_mohe_version();

        return $this->success([
            'version' => $version,
            'label' => strripos($version, 'min') === false ? 3 : 2
        ]);
    }

}
