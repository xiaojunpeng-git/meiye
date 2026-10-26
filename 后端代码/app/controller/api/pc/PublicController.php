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

namespace app\controller\api\pc;


use app\Request;
use app\services\other\CityAreaServices;
use app\services\pc\PublicServices;
use mohe\services\SystemConfigService;
use mohe\basic\BaseController;

class PublicController extends BaseController
{
    protected $services;

    public function __construct(PublicServices $services)
    {
        $this->services = $services;
    }

    /**
     * 获取城市数据
     * @param Request $request
     * @return mixed
     */
    public function getCity(Request $request, CityAreaServices $services)
    {
        [$pid] = $request->getMore([
            [['pid', 'd'], 0],
        ], true);
        return app('json')->success($services->getCityTreeList((int)$pid));
    }

    /**
     * 获取公司信息
     * @return mixed
     */
    public function getCompanyInfo()
    {
        $data = SystemConfigService::more(['login_logo', 'contact_number', 'links_open', 'links_list', 'company_address', 'copyright', 'record_No', 'site_name', 'site_keywords', 'site_description', 'pc_logo', 'filing_list', 'store_user_agreement']);
        $logoUrl = $data['pc_logo'] ?? '';
        if (strstr($logoUrl, 'http') === false && $logoUrl) {
            $logoUrl = sys_config('site_url') . $logoUrl;
        }
		try {
			$copyright = $this->__z6uxyJQ4xYa5ee1mx5();
		} catch (\Throwable $e) {
			$copyright = ['copyrightContext' => '', 'copyrightImage' => ''];
		}
		$data['copyrightImage'] = $copyright['copyrightImage'];
		$data['copyright'] = $copyright['copyrightContext'] ?: $data['copyright'];

        $logoUrl = str_replace('\\', '/', $logoUrl);
        $data['logoUrl'] = $logoUrl;
        if ($data['links_open']) {
            $linksList = $data['links_list'];
            $linksList = is_array($linksList) ? $linksList : [];
            $edition = $volume = [];
            foreach ($linksList as $key => $row) {
                $volume[$key] = $row['sort'];
                $edition[$key] = $row['url'];
            }
            array_multisort($volume, SORT_DESC, $edition, SORT_ASC, $linksList);
            $data['links_list'] = $linksList;
        } else {
            $data['links_list'] = [];
        }
		if ($data['filing_list']) {
			$filingList = $data['filing_list'];
			$filingList = is_array($filingList) ? $filingList : [];
			$edition = $volume = [];
			foreach ($filingList as $key => $row) {
				$volume[$key] = $row['sort'];
				$edition[$key] = $row['url'];
			}
			array_multisort($volume, SORT_DESC, $edition, SORT_ASC, $filingList);
			$data['filing_list'] = $filingList;
		}  else {
			$data['filing_list'] = [];
		}
        return app('json')->successful($data);
    }

    /**
     * 获取关注微信二维码
     * @return mixed
     */
    public function getWechatQrcode()
    {
        $data['wechat_qrcode'] = sys_config('wechat_qrcode');
        return app('json')->successful($data);
    }
}
