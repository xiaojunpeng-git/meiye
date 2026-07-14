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
declare (strict_types=1);

namespace app\services\other;

use app\services\BaseServices;
use app\dao\other\QrcodeDao;
use app\services\system\attachment\SystemAttachmentServices;
use mohe\exceptions\AdminException;
use mohe\services\DownloadImageService;
use mohe\services\UploadService;
use mohe\services\UtilService;
use mohe\services\wechat\MiniProgram;
use mohe\services\wechat\OfficialAccount;
use mohe\traits\ServicesTrait;
use GuzzleHttp\Psr7\Utils;
use think\exception\ValidateException;

/**
 *
 * Class QrcodeServices
 * @package app\services\other
 * @mixin QrcodeDao
 */
class QrcodeServices extends BaseServices
{

    use ServicesTrait;

    /**
     * QrcodeServices constructor.
     * @param QrcodeDao $dao
     */
    public function __construct(QrcodeDao $dao)
    {
        $this->dao = $dao;
    }

	/**
	 * 微信公众号二维码下载到本地
	 * @param string $type
	 * @param string $ticket
	 * @param bool $isDown
	 * @return string
	 */
	public function getWechatQrcodeUrlByTicket(string $type, string $ticket = '', bool $isDown = true)
	{
		$url = '';
		if ($type && $ticket) {
			try {
				$url = OfficialAccount::qrcodeService()->url($ticket);
			} catch (\Throwable $e) {

			}
			if ($url && strpos($url, 'weixin.qq.com') && $isDown) {//远程图片
				$name = $type . '.jpg';
				/** @var DownloadImageService $download */
				$download = app()->make(DownloadImageService::class);
				$downloadUrl = $download->path('qrcode')->downloadImage($url, $name)['path'] ?? '';
				if ($downloadUrl) $url = sys_config('site_url') . $downloadUrl;
			}
		}
		return $url;
	}

    /**
     * 获取临时二维码
     * @param $type
     * @param $id
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getTemporaryQrcode($type, $id)
    {
        $where['third_id'] = $id;
        $where['third_type'] = $type;
        $res = $this->dao->getOne($where);
        if (!$res) {
            $this->createTemporaryQrcode($id, $type);
            $res = $this->getTemporaryQrcode($type, $id);
        } else if (empty($res['expire_seconds']) || $res['expire_seconds'] < time()) {
            $this->createTemporaryQrcode($id, $type, $res['id']);
            $res = $this->getTemporaryQrcode($type, $id);
        }
        if (!$res['ticket']) throw new AdminException('临时二维码获取错误');
        return $res;
    }

    /**
     * 临时二维码生成
     * @param $id
     * @param $type
     * @param string $qrcode_id
     */
    public function createTemporaryQrcode($id, $type, $qrcode_id = '')
    {
		$data = OfficialAccount::createTemporaryQrcode($id, 30 * 24 * 3600);
        $data['qrcode_url'] = $data['url'];
        $data['expire_seconds'] = $data['expire_seconds'] + time();
        $data['url'] = $this->getWechatQrcodeUrlByTicket((string)$type, (string)$data['ticket']);
        $data['status'] = 1;
        $data['third_id'] = $id;
        $data['third_type'] = $type;
        if ($qrcode_id) {
            $this->dao->update($qrcode_id, $data);
        } else {
            $data['add_time'] = time();
            $this->dao->save($data);
        }
    }

    /**
     * 获取永久二维码
     * @param $type
     * @param $id
     * @return array|mixed|\think\Model
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getForeverQrcode($type, $id)
    {
        $where['third_id'] = $id;
        $where['third_type'] = $type;
        $res = $this->dao->getOne($where);
        if (!$res) {
            $this->createForeverQrcode($id, $type);
            $res = $this->getForeverQrcode($type, $id);
        }
        if (!$res['ticket']) throw new AdminException('永久二维码获取错误');
        return $res;
    }

    /**
     * 永久二维码生成
     * @param $id
     * @param $type
     */
    public function createForeverQrcode($id, $type)
    {
        $data = OfficialAccount::createForeverQrcode($id);
        $data['qrcode_url'] = $data['url'];
        $data['url'] = $this->getWechatQrcodeUrlByTicket((string)$type, (string)$data['ticket']);
        $data['expire_seconds'] = 0;
        $data['status'] = 1;
        $data['third_id'] = $id;
        $data['third_type'] = $type;
        $data['add_time'] = time();
        $this->dao->save($data);
    }

    /**
     * 获取二维码完整路径，不存在则自动生成
     * @param string $name
     * @param string $link
     * @param bool $force
     * @return bool|mixed|string
     */
    public function getWechatQrcodePath(string $name, string $link, bool $force = false, bool $isSaveAttach = true)
    {
        /** @var SystemAttachmentServices $systemAttachmentService */
        $systemAttachmentService = app()->make(SystemAttachmentServices::class);
        try {
            if (!$isSaveAttach) {
                $imageInfo = "";
            } else {
                $imageInfo = $systemAttachmentService->getOne(['name' => $name]);
            }
            $siteUrl = sys_config('site_url');
            if (!$imageInfo) {
                $codeUrl = UtilService::setHttpType($siteUrl . $link, request()->isSsl() ? 0 : 1);//二维码链接
                $imageInfo = UtilService::getQRCodePath($codeUrl, $name);
                if (is_string($imageInfo) && $force)
                    return false;
                if (is_array($imageInfo)) {
                    if ($isSaveAttach) {
                        $systemAttachmentService->save([
                            'name' => $imageInfo['name'],
                            'att_dir' => $imageInfo['dir'],
                            'satt_dir' => $imageInfo['thumb_path'],
                            'att_size' => $imageInfo['size'],
                            'att_type' => $imageInfo['type'],
                            'image_type' => $imageInfo['image_type'],
                            'module_type' => 2,
                            'time' => time(),
                            'pid' => 1,
                            'type' => 1
                        ]);
                    }
                    $url = $imageInfo['dir'];
                } else {
                    $url = '';
                    $imageInfo = ['image_type' => 0];
                }
            } else $url = $imageInfo['att_dir'];
            if ($imageInfo['image_type'] == 1 && $url) $url = $siteUrl . $url;
            return $url;
        } catch (\Throwable $e) {
            if ($force)
                return false;
            else
                return '';
        }
    }

	/**
	 * 获取小程序分享二维码
	 * @param int $id
	 * @param int $uid
	 * @param int $type
	 * @param string $namePath
	 * @param bool $isSaveAttach
	 * @param array $appendData
	 * @param string $savePath
	 * @return false|mixed|string
	 */
	public function getRoutineQrcodePath(int $id, int $uid, int $type = 0, string $namePath = '', bool $isSaveAttach = true, array $appendData = [], string $savePath = 'qrcode/routine')
	{
		$data = 'id=' . $id . '&spid=' . $uid;
		$page = '';
        $module_type = 2;
		switch ($type) {
			case -1:
				$page = 'pages/index/index';
				break;
			case 0://普通商品
				$page = 'pages/goods_details/index';
				break;
			case 1:
			case 3:
			case 4:
			case 6:
			case 7://1秒杀3拼团4积分7新人专享
				$page = 'pages/activity/goods_details/index';
				break;
			case 2://砍价
				$page = 'pages/activity/goods_bargain_details/index';
				break;
			case 9: //桌码二维码
				$page = 'pages/store/table_code/index';
				$data = 'qrcode_id=' . $id. '&store_id=' . $appendData['store_id'] ?? 0;
                $module_type = 3;
				break;
			case 10: //门店二维码
				$page = 'pages/store/home/index';
				$data = 'id=' . $id . '&staff_id=' . $appendData['staff_id'] ?? 0;
				break;
			case 31://拼团状态
				$page = 'pages/activity/goods_combination_status/index';
				break;
			case 90:
			case 91://核销页面
				$page = 'pages/admin/order_cancellation/index';
				break;
			case 99://首页
				$page = 'pages/index/index';
				break;
			case 103://线下支付
				$page = 'pages/annex/offline_pay/index';
				break;
			case 104://兑换激活svip
				$page = 'pages/annex/vip_active/index';
				break;
			case 105://付费会员中心
				$page = 'pages/annex/vip_paid/index';
				break;
			case 106://专题页面
				$page = 'pages/annex/special/index';
				break;
			case 107:
				$page = 'pages/annex/offline_pay/index';
				$data .= '&store_id=' . $id;
				break;
			default:
				$page = 'pages/index/index';
				break;
		}
		if (in_array($type, [0, 1, 3, 4, 6, 7])) {
			$data .= '&type=' . $type;
		}
		if (in_array($type, [90, 91])) {
			$data = 'auth=3&code=' . $id;
		}
		if (!$page || !$namePath) {
			return false;
		}
		try {
			/** @var SystemAttachmentServices $systemAttachmentService */
			$systemAttachmentService = app()->make(SystemAttachmentServices::class);
			if (!$isSaveAttach) {
				$imageInfo = "";
			} else {
				$imageInfo = $systemAttachmentService->getOne(['name' => $savePath . '/' . $namePath]);
				//检测远程文件是否存在
				if (isset($imageInfo['att_dir']) && strstr($imageInfo['att_dir'], 'http') !== false && curl_file_exist($imageInfo['att_dir']) === false) {
					$imageInfo = '';
					$systemAttachmentService->delete(['name' => $savePath . '/' . $namePath]);
				}
			}
			$siteUrl = sys_config('site_url');
			if (!$imageInfo) {
				$res = MiniProgram::appCodeUnlimit($data, $page, 280);
				if (!$res) return false;
				$uploadType = (int)sys_config('upload_type', 1);
				$upload = UploadService::init($uploadType);
				$res = (string)Utils::streamFor($res);
				$res = $upload->to($savePath)->validate()->stream($res, $namePath);
				if ($res === false) {
					return false;
				}
				$imageInfo = $upload->getUploadInfo();
				$imageInfo['image_type'] = $uploadType;
				if ($imageInfo['image_type'] == 1) $remoteImage = UtilService::remoteImage($siteUrl . $imageInfo['dir']);
				else $remoteImage = UtilService::remoteImage($imageInfo['dir']);
				if (!$remoteImage['status']) return false;
				if ($isSaveAttach) {
					$systemAttachmentService->save([
						'name' => $imageInfo['name'],
						'att_dir' => $imageInfo['dir'],
						'satt_dir' => $imageInfo['thumb_path'],
						'att_size' => $imageInfo['size'],
						'att_type' => $imageInfo['type'],
						'image_type' => $imageInfo['image_type'],
						'module_type' => $module_type,
						'time' => time(),
						'pid' => 1,
						'type' => 2
					]);
				}
				$url = $imageInfo['dir'];
			} else $url = $imageInfo['att_dir'];
			if ($imageInfo['image_type'] == 1) $url = $siteUrl . $url;
			return $url;
		} catch (\Throwable $e) {
            var_dump($e->getMessage());
            die();
			return false;
		}
	}

	/**
	 * 添加二维码  存在直接获取
	 * @param $thirdId
	 * @param $thirdType
	 * @param $page
	 * @param $qrCodeLink
	 * @return array|object|\think\Model
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function qrCodeForever($thirdId = 0, $thirdType = 'spread', $page = '', $qrCodeLink = '')
    {
        $qrcode = $this->dao->getOne(['third_id' => $thirdId, 'third_type' => $thirdType]);
        if ($qrcode) {
            return $qrcode;
        }
        return $this->setQrcodeForever($thirdId, $thirdType, $page, $qrCodeLink);
    }

    /**
     * 检测是否存在
     * @param int $thirdId
     * @param string $thirdType
     * @return int
     */
    public function qrCodeExist($thirdId = 0, $thirdType = 'spread')
    {
        return !!$this->dao->getCount(['third_id' => $thirdId, 'third_type' => $thirdType]);
    }

    /**
     * 添加二维码记录
     * @param int $thirdId
     * @param string $thirdType
     * @param string $page
     * @param string $qrCodeLink
     * @return object
     */
    public function setQrcodeForever($thirdId = 0, $thirdType = 'spread', $page = '', $qrCodeLink = '')
    {
        $data['third_type'] = $thirdType;
        $data['third_id'] = $thirdId;
        $data['status'] = 1;
        $data['add_time'] = time();
        $data['page'] = $page;
        $data['url_time'] = '';
        $data['qrcode_url'] = $qrCodeLink;
        if (!$re = $this->dao->save($data)) {
            throw new ValidateException('生成失败');
        }
        return $re;
    }

    /**
     * 修改二维码地址
     * @param int $id
     * @param array $data
     * @return bool
     */
    public function setQrcodeFind($id = 0, $data = array())
    {
        if (!$id) return false;
        if (!$this->dao->get((int)$id)) {
            throw new ValidateException('数据不存在');
        }
        if (!$re = $this->dao->update($id, $data, 'id')) {
            throw new ValidateException('修改数据失败');
        }
        return $re;
    }
}
