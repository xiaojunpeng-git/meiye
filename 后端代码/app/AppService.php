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
namespace app;

use app\listener\wechat\OffcialAccountListener;
use app\listener\wechat\OpenPlatformListener;
use app\listener\wechat\RoutineListener;
use app\listener\wechat\WorkListener;
use mohe\services\SystemConfigService;
use app\services\work\WorkConfigServices;
use mohe\services\GroupDataService;
use mohe\services\wechat\config\HttpCommonConfig;
use mohe\services\wechat\config\LogCommonConfig;
use mohe\services\wechat\config\WorkConfig;
use mohe\services\wechat\MiniProgram;
use mohe\services\wechat\OfficialAccount;
use mohe\services\wechat\OpenPlatform;
use mohe\services\wechat\Work;
use mohe\utils\Json;
use think\Service;
use Yurun\Util\Swoole\Guzzle\SwooleHandler;
use GuzzleHttp\DefaultHandler;
use app\services\customer\care\CustomerCareClock;
use app\services\customer\care\CustomerCareRepository;
use app\services\customer\care\SystemCustomerCareClock;
use app\services\customer\care\ThinkPhpCustomerCareRepository;
use app\services\customer\care\query\CustomerCareCursorCodec;
use app\services\customer\care\query\CustomerCareQueryRepository;
use app\services\customer\care\query\ThinkPhpCustomerCareQueryRepository;

/**
 * Class AppService
 * @package app
 */
class AppService extends Service
{

    public $bind = [
        'json' => Json::class,
        'sysConfig' => SystemConfigService::class,
        'sysGroupData' => GroupDataService::class
    ];

    public function boot()
    {
        defined('DS') || define('DS', DIRECTORY_SEPARATOR);
        DefaultHandler::setDefaultHandler(SwooleHandler::class);
    }

    /**
     * 注册
     */
    public function register()
    {
		// The mobile/PC customer-care adapter shares these implementations; only
		// trusted request contexts may reach the command/query services.
		$this->app->bind(CustomerCareRepository::class, ThinkPhpCustomerCareRepository::class);
		$this->app->bind(CustomerCareQueryRepository::class, ThinkPhpCustomerCareQueryRepository::class);
		$this->app->bind(CustomerCareClock::class, SystemCustomerCareClock::class);
		$this->app->bind(CustomerCareCursorCodec::class, function () {
			return new CustomerCareCursorCodec(hash('sha256', (string)config('mobile_auth.hmac_key')));
		});
        //http配置服务
        $this->app->bind(HttpCommonConfig::class, function () {
            return (new HttpCommonConfig())->setServe(\app\services\system\config\SystemConfigServices::class);
        });
        //公众号
        $this->app->bind(OfficialAccount::class, function () {
            return (new OfficialAccount)->setPushMessageHandler(OffcialAccountListener::class);
        });
		//小程序
		$this->app->bind(MiniProgram::class, function () {
			return (new MiniProgram)->setPushMessageHandler(RoutineListener::class);
		});
        //开放平台
        $this->app->bind(OpenPlatform::class, function () {
            return (new OpenPlatform)->setPushMessageHandler(OpenPlatformListener::class);
        });
        //实例化企业微信配置
        $this->app->bind(WorkConfig::class, function () {
            return (new WorkConfig(new LogCommonConfig(), $this->app->make(HttpCommonConfig::class)))->setHandler(WorkConfigServices::class);
        });
        //企业微信
        $this->app->bind(Work::class, function () {
            return (new Work)->setPushMessageHandler(WorkListener::class)
                ->setConfigHandler(WorkConfigServices::class);
        });
    }

}
