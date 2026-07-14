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

namespace app\services\store;


use app\dao\store\StoreConfigDao;
use app\services\BaseServices;
use app\services\system\config\SystemConfigServices;
use mohe\form\Build;
use think\exception\ValidateException;

/**
 * Class StoreConfigServices
 * @package app\services\store
 * @mixin StoreConfigDao
 */
class StoreConfigServices extends BaseServices
{

	/**
	 * 表单数据切割符号
	 * @var string
	 */
	protected $cuttingStr = '=>';

    //打印机配置
    const PRINTER_KEY = [
        'store_terminal_number', 'store_printing_client_id',
        'store_printing_api_key', 'store_develop_id', 'store_pay_success_printing_switch',
		'store_print_type', 'store_fey_user', 'store_fey_ukey', 'store_fey_sn',
    ];
    //快递发货配置
    const EXPRESS_KEY = [
        'store_config_export_id', 'store_config_export_temp_id', 'store_config_export_to_name',
        'store_config_export_to_tel', 'store_config_export_to_address', 'store_config_export_siid', 'store_config_export_open'
    ];

    //桌码配置
    const TABLE_CODE = [
        'store_code_switch', 'store_checkout_method', 'store_number_diners_window'
    ];

    //收银台看板配置
    const NOTICE_BOARD = [
        'is_show_staff_switch', 'chart_time_interval'
    ];

    const CONFIG_TYPE = [
        'store_printing_deploy' => self::PRINTER_KEY,
        'store_electronic_sheet' => self::EXPRESS_KEY,
        'store_table_code' => self::TABLE_CODE,
        'cashier_notice_board' => self::NOTICE_BOARD
    ];

    /**
     * StoreConfigServices constructor.
     * @param StoreConfigDao $dao
     */
    public function __construct(StoreConfigDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 保存或者更新门店配置
     * @param array $data
     * @param int $storeId
     */
    public function saveConfig(array $data, int $type = 1, int $relation_id = 0, int $printId = 0)
    {
        $config = [];
        foreach ($data as $key => $value) {
            if ($this->dao->count(['key_name' => $key, 'type' => $type, 'relation_id' => $relation_id, 'print_id' => $printId])) {
                $this->dao->update(['key_name' => $key, 'type' => $type, 'relation_id' => $relation_id, 'print_id' => $printId], ['value' => json_encode($value)]);
            } else {
                $config[] = [
                    'key_name' => $key,
					'type' => $type,
                    'relation_id' => $relation_id,
					'print_id' => $printId,
                    'value' => json_encode($value)
                ];
            }
        }
        if ($config) {
            $this->dao->saveAll($config);
        }
    }

	/**
	 * 获取配置
	 * @param string $key
	 * @param int $type
	 * @param int $relation_id
	 * @param $default
	 * @return mixed|null
	 */
    public function getConfig(string $key, int $type = 0, int $relation_id = 0, int $printId = 0, $default = null)
    {
        $value = $this->dao->value(['key_name' => $key, 'type' => $type, 'relation_id' => $relation_id, 'print_id' => $printId], 'value');
        return is_null($value) ? $default : json_decode($value, true);
    }

    /**
	 * 获取门店配置
     * @param int $storeId
     * @param array $keys
     */
    public function getConfigAll(array $keys, int $type = 0, int $relation_id = 0, int $printId = 0)
    {
        $confing = $this->dao->searchs($keys, $type, $relation_id, $printId)->column('value', 'key_name');
        return array_map(function ($item) {
            return json_decode($item, true);
        }, $confing);
    }

	public function getOptions(string $parameter)
	{
		$parameter = explode("\n", $parameter);
		$options = [];
		foreach ($parameter as $v) {
			if (strstr($v, $this->cuttingStr) !== false) {
				$pdata = explode($this->cuttingStr, $v);
				$options[] = ['label' => $pdata[1], 'value' => (int)$pdata[0]];
			}
		}
		return $options;
	}

	/**
	 * @param array $configName
	 * @param int $type
	 * @param int $relation_id
	 * @param int $group
	 * @return array
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function getConfigAllField(array $configName = [], int $type = 0, int $relation_id = 0, int $group = 0)
	{
		/** @var SystemConfigServices $systemConfigServices */
		$systemConfigServices = app()->make(SystemConfigServices::class);
		$list = $systemConfigServices->getConfigAllListByWhere(['menu_name' => $configName], $type, $relation_id, ['menu_name', 'info', 'type', 'value', 'desc', 'parameter']);
		if ($list) {
			foreach ($list as &$item) {
				if ($relation_id != 0) {
					$item['value'] = $item['store_value'] ?? '';
				}
				$item['value'] = json_decode($item['value'], true);
			}
			$list = array_combine(array_column($list, 'menu_name'), $list);
		}

		$value = [];
		foreach ($configName as $key) {
			if ($group) {
				$value[$key] = $list[$key]['value'] ?? '';
			} else {
				$value[$key] = $list[$key] ?? ['info' => '', 'type' => 'text', 'value' => '', 'desc' => '', 'parameter' => ''];
			}
		}
		return $value;
	}

	/**
	 * 获取表单
	 * @param string $name
	 * @param int $type
	 * @param int $relation_id
	 * @return array
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function getFormBuildRule(string $name, int $type = 0, int $relation_id = 0)
	{
		switch ($name) {
			case 'third'://第三方
				$data = $this->thirdPartyFormBuild($type, $relation_id);
				break;
			default:
				throw new ValidateException('类型错误');
		}
		return $data;
	}

	/**
	 * 电子面单
	 * @param int $type
	 * @param int $relation_id
	 * @return array
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function exportFormBuild(int $type = 0, int $relation_id = 0)
	{
		$build = new Build();
		$build->url('system/config');
		$data = $this->getConfigAllField([
			'store_config_export_open', 'store_config_export_siid', 'store_config_export_to_name', 'store_config_export_to_tel', 'store_config_export_to_address',
		], $type, $relation_id);

		$build->rule([
			Build::tabs()->option('电子面单', [
				Build::radio('store_config_export_open', $data['store_config_export_open']['info'], (int)($data['store_config_export_open']['value'] ?? 0))->control(1, [
					Build::input('store_config_export_to_name', $data['store_config_export_to_name']['info'], $data['store_config_export_to_name']['value'])->info($data['store_config_export_to_name']['desc']),
					Build::input('store_config_export_to_tel', $data['store_config_export_to_tel']['info'], $data['store_config_export_to_tel']['value'])->info($data['store_config_export_to_tel']['desc']),
					Build::input('store_config_export_to_address', $data['store_config_export_to_address']['info'], $data['store_config_export_to_address']['value'])->info($data['store_config_export_to_address']['desc']),
					Build::input('store_config_export_siid', $data['store_config_export_siid']['info'], $data['store_config_export_siid']['value'])->info($data['store_config_export_siid']['desc']),
				])->options($this->getOptions($data['store_config_export_open']['parameter']))->info($data['store_config_export_open']['desc'])
			])
		]);
		return $build->toArray();
	}

	/**
	 * 第三方配置
	 * @return array
	 */
	public function thirdPartyFormBuild(int $type = 0, int $relation_id = 0)
	{
		$build = new Build();
		$build->url('system/config');
		if ($type == 1) {
			$data = $this->getConfigAllField([
				'store_pay_success_printing_switch', 'store_develop_id', 'store_printing_api_key', 'store_printing_client_id', 'store_terminal_number',
				'store_print_type', 'store_fey_user', 'store_fey_ukey', 'store_fey_sn',
				'store_config_export_open', 'store_config_export_siid', 'store_config_export_to_name', 'store_config_export_to_tel', 'store_config_export_to_address',
			], $type, $relation_id);

			$build->rule([
				Build::tabs()->option('小票打印配置', [
					Build::switch('store_pay_success_printing_switch', $data['store_pay_success_printing_switch']['info'], (int)($data['store_pay_success_printing_switch']['value'] ?? 0))->control(1, [
						Build::radio('store_print_type', $data['store_print_type']['info'], in_array($data['store_print_type']['value'], [1, 2]) ? $data['store_print_type']['value'] : 1)->control(1, [
							Build::input('store_develop_id', $data['store_develop_id']['info'], $data['store_develop_id']['value'])->info($data['store_develop_id']['desc']),
							Build::input('store_printing_api_key', $data['store_printing_api_key']['info'], $data['store_printing_api_key']['value'])->info($data['store_printing_api_key']['desc']),
							Build::input('store_printing_client_id', $data['store_printing_client_id']['info'], $data['store_printing_client_id']['value'])->info($data['store_printing_client_id']['desc']),
							Build::input('store_terminal_number', $data['store_terminal_number']['info'], $data['store_terminal_number']['value'])->info($data['store_terminal_number']['desc']),
						])->control(2, [
							Build::input('store_fey_user', $data['store_fey_user']['info'], $data['store_fey_user']['value'])->info($data['store_fey_user']['desc']),
							Build::input('store_fey_ukey', $data['store_fey_ukey']['info'], $data['store_fey_ukey']['value'])->info($data['store_fey_ukey']['desc']),
							Build::input('store_fey_sn', $data['store_fey_sn']['info'], $data['store_fey_sn']['value'])->info($data['store_fey_sn']['desc'])
						])->options($this->getOptions($data['store_print_type']['parameter']))->info($data['store_print_type']['desc'])
					])->trueValue('打开', 1)->falseValue('关闭', 0),
				])->option('电子面单', [
					Build::radio('store_config_export_open', $data['store_config_export_open']['info'], (int)($data['store_config_export_open']['value'] ?? 0))->control(1, [
						Build::input('store_config_export_to_name', $data['store_config_export_to_name']['info'], $data['store_config_export_to_name']['value'])->info($data['store_config_export_to_name']['desc']),
						Build::input('store_config_export_to_tel', $data['store_config_export_to_tel']['info'], $data['store_config_export_to_tel']['value'])->info($data['store_config_export_to_tel']['desc']),
						Build::input('store_config_export_to_address', $data['store_config_export_to_address']['info'], $data['store_config_export_to_address']['value'])->info($data['store_config_export_to_address']['desc']),
						Build::input('store_config_export_siid', $data['store_config_export_siid']['info'], $data['store_config_export_siid']['value'])->info($data['store_config_export_siid']['desc']),
					])->options($this->getOptions($data['store_config_export_open']['parameter']))->info($data['store_config_export_open']['desc'])
				])
			]);
		} elseif ($type = 2) {
			$data = $this->getConfigAllField([
				'store_pay_success_printing_switch', 'store_develop_id', 'store_printing_api_key', 'store_printing_client_id', 'store_terminal_number',
				'store_print_type', 'store_fey_user', 'store_fey_ukey', 'store_fey_sn',
			], $type, $relation_id);

			$build->rule([
				Build::tabs()->option('小票打印配置', [
					Build::switch('store_pay_success_printing_switch', $data['store_pay_success_printing_switch']['info'], (int)($data['store_pay_success_printing_switch']['value'] ?? 0))->control(1, [
						Build::radio('store_print_type', $data['store_print_type']['info'], in_array($data['store_print_type']['value'], [1, 2]) ? $data['store_print_type']['value'] : 1)->control(1, [
							Build::input('store_develop_id', $data['store_develop_id']['info'], $data['store_develop_id']['value'])->info($data['store_develop_id']['desc']),
							Build::input('store_printing_api_key', $data['store_printing_api_key']['info'], $data['store_printing_api_key']['value'])->info($data['store_printing_api_key']['desc']),
							Build::input('store_printing_client_id', $data['store_printing_client_id']['info'], $data['store_printing_client_id']['value'])->info($data['store_printing_client_id']['desc']),
							Build::input('store_terminal_number', $data['store_terminal_number']['info'], $data['store_terminal_number']['value'])->info($data['store_terminal_number']['desc']),
						])->control(2, [
							Build::input('store_fey_user', $data['store_fey_user']['info'], $data['store_fey_user']['value'])->info($data['store_fey_user']['desc']),
							Build::input('store_fey_ukey', $data['store_fey_ukey']['info'], $data['store_fey_ukey']['value'])->info($data['store_fey_ukey']['desc']),
							Build::input('store_fey_sn', $data['store_fey_sn']['info'], $data['store_fey_sn']['value'])->info($data['store_fey_sn']['desc'])
						])->options($this->getOptions($data['store_print_type']['parameter']))->info($data['store_print_type']['desc'])
					])->trueValue('打开', 1)->falseValue('关闭', 0),
				])
			]);
		}
		return $build->toArray();
	}


}
