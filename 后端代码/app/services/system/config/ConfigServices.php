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

namespace app\services\system\config;


use app\dao\store\StoreConfigDao;
use app\services\BaseServices;
use mohe\services\SystemConfigService;

/**
 * Class ConfigServices
 * @package app\services\system\config
 * @mixin StoreConfigDao
 */
class ConfigServices extends BaseServices
{
	//打印机类型
	const PRINTER_NAME = [
		1 => 'yi_lian_yun',
		2 => 'fei_e_yun',
 	];

	//平台打印机配置
	const PRINTER_KEY = [
		'switch' => 'pay_success_printing_switch',
		'print_type' => 'print_type',
		'printing_client_id', 'printing_api_key', 'develop_id', 'terminal_number',
		'fey_user', 'fey_ukey', 'fey_sn',
	];
	//配置字段对应
	const PRINTER_CONFIG_KEY = [
		'yi_lian_yun' => ['printing_client_id' => 'clientId', 'printing_api_key' => 'apiKey', 'develop_id' => 'partner', 'terminal_number' => 'terminal'],
		'fei_e_yun' => ['fey_user' => 'feyUser', 'fey_ukey' => 'feyUkey', 'fey_sn' => 'feySn'],
	];

    //门店打印机配置
    const STORE_PRINTER_KEY = [
		'switch' => 'store_pay_success_printing_switch',
		'print_type' => 'store_print_type',
		'store_printing_client_id', 'store_printing_api_key', 'store_develop_id',  'store_terminal_number',
		'store_fey_user', 'store_fey_ukey', 'store_fey_sn',
    ];

	//门店配置字段对应
	const STORE_PRINTER_CONFIG_KEY = [
		'yi_lian_yun' => ['store_printing_client_id' => 'clientId', 'store_printing_api_key' => 'apiKey', 'store_develop_id' => 'partner', 'store_terminal_number' => 'terminal'],
		'fei_e_yun' => ['store_fey_user' => 'feyUser', 'store_fey_ukey' => 'feyUkey', 'store_fey_sn' => 'feySn'],
	];

	//供应商打印机配置
	const SUPPLIER_PRINTER_KEY = [
		'switch' => 'store_pay_success_printing_switch',
		'print_type' => 'store_print_type',
		'store_printing_client_id', 'store_printing_api_key', 'store_develop_id',  'store_terminal_number',
		'store_fey_user', 'store_fey_ukey', 'store_fey_sn',
	];

	//供应商配置字段对应
	const SUPPLIER_PRINTER_CONFIG_KEY = [
		'yi_lian_yun' => ['store_printing_client_id' => 'clientId', 'store_printing_api_key' => 'apiKey', 'store_develop_id' => 'partner', 'store_terminal_number' => 'terminal'],
		'fei_e_yun' => ['store_fey_user' => 'feyUser', 'store_fey_ukey' => 'feyUkey', 'store_fey_sn' => 'feySn'],
	];

    const CONFIG_TYPE = [
        'printing_deploy' => self::PRINTER_KEY,
		'store_printing_deploy' => self::STORE_PRINTER_KEY,
		'supplier_printing_deploy' => self::SUPPLIER_PRINTER_KEY
    ];

    /**
     * StoreConfigServices constructor.
     * @param StoreConfigDao $dao
     */
    public function __construct(StoreConfigDao $dao)
    {
        $this->dao = $dao;
    }

	public function getPrinterType(int $printType = 1)
	{
		return self::PRINTER_NAME[$printType] ?? 'yi_lian_yun';
	}

	/**
	 * 获取小票打印配置
	 * @param int $type
	 * @param int $relation_id
	 * @return array
	 */
	public function getPrintingConfig(int $type = 0, int $relation_id = 0, int $printId = 0, int $plat_type = -1) : array
	{
		/** @var SystemConfigService $configService */
		$configService = app('sysConfig');
		switch ($type) {
			case 0://平台
				$keys = self::PRINTER_KEY;
				$configKey = self::PRINTER_CONFIG_KEY;
				break;
			case 1://门店
				$keys = self::STORE_PRINTER_KEY;
				$configKey = self::STORE_PRINTER_CONFIG_KEY;
				$configService->setStore($relation_id, $printId);
				break;
			case 2://供应商
				$keys = self::SUPPLIER_PRINTER_KEY;
				$configKey = self::SUPPLIER_PRINTER_CONFIG_KEY;
				$configService->setSupplier($relation_id, $printId);
				break;
			default:
				$keys = self::PRINTER_KEY;
				$configKey = self::PRINTER_CONFIG_KEY;
				break;
		}
		$key = array_values($keys);
		$config = $configService->more($key);
		$switch = $config[$keys['switch']] ?? 1;
		$name = $this->getPrinterType($plat_type != -1 ? $plat_type : (int)($config[$keys['print_type']] ?? 1));
		$configKey = $configKey[$name];
		$configData = [];
		foreach ($config as $key => $value) {
			if (isset($configKey[$key])) {
				$configData[$configKey[$key]] = $value;
			}
		}
		return [$switch, $name, $configData];
	}



}
