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

namespace app\services\system\attachment;

use app\services\BaseServices;
use app\dao\system\attachment\SystemAttachmentDao;
use mohe\exceptions\AdminException;
use mohe\exceptions\UploadException;
use mohe\services\DownloadImageService;
use mohe\services\UploadService;
use mohe\traits\ServicesTrait;
use think\exception\ValidateException;

/**
 *
 * Class SystemAttachmentServices
 * @package app\services\attachment
 * @mixin SystemAttachmentDao
 */
class SystemAttachmentServices extends BaseServices
{
    use ServicesTrait;

	/**
	 * @var string[]
	 */
	protected $type = [
		0 => '平台',
		1 => '门店',
		2 => '供应商',
		3 => '代理商'
	];

    /**
     * SystemAttachmentServices constructor.
     * @param SystemAttachmentDao $dao
     */
    public function __construct(SystemAttachmentDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 获取单个资源
     * @param array $where
     * @param string $field
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getInfo(array $where, string $field = '*')
    {
        return $this->dao->getOne($where, $field);
    }

    /**
     * 获取图片列表
     * @param array $where
     * @return array
     */
    public function getImageList(array $where)
    {
        [$page, $limit] = $this->getPageValue();
        $list = $this->dao->getList($where, $page, $limit);
        if ($list) {
            $site_url = sys_config('site_url');
            foreach ($list as &$item) {
                if ($item['file_type'] == 1) {
                    if ($site_url) {
                        $item['satt_dir'] = (strpos($item['satt_dir'], $site_url) !== false || strstr($item['satt_dir'], 'http') !== false) ? $item['satt_dir'] : $site_url . $item['satt_dir'];
                        $item['att_dir'] = (strpos($item['att_dir'], $site_url) !== false || strstr($item['att_dir'], 'http') !== false) ? $item['satt_dir'] : $site_url . $item['att_dir'];
                    }
                }
                $item['att_size'] = formatFileSize($item['att_size']);
                $item['time'] = date('Y-m-d H:i:s', $item['time']);
            }
            $list = get_thumb_water($list, 'mid', ['satt_dir']);
        }
        $where['module_type'] = 1;
        $count = $this->dao->count($where);
        return compact('list', 'count');
    }

    /**
     * 删除图片
     * @param string $ids
     */
    public function del(string $ids)
    {
        $ids = explode(',', $ids);
        if (empty($ids)) throw new AdminException('请选择要删除的图片');
        foreach ($ids as $v) {
            $attinfo = $this->dao->get((int)$v);
            if ($attinfo) {
                try {
                    $upload = UploadService::init($attinfo['image_type']);
                    if ($attinfo['image_type'] == 1) {
                        $fileArr = parse_url($attinfo['att_dir']);
                        $filePath = $fileArr['path'] ?? $attinfo['att_dir'];
                        if (strpos($filePath, '/') == 0) {
                            $filePath = substr($filePath, 1);
                        }
                        if ($filePath) $upload->delete($filePath);
                    } else {
                        if ($attinfo['name']) $upload->delete($attinfo['name']);
                    }
                } catch (\Throwable $e) {
                }
                $this->dao->delete((int)$v);
            }
        }
    }

    /**
     * 图片上传
     * @param int $pid
     * @param string $file
     * @param int $upload_type
     * @param int $type
     * @param int $relation_id
     * @param string $uploadToken
     * @return mixed
     */
    public function upload(int $pid, string $file, int $upload_type, int $type, int $relation_id = 0)
    {
        if ($upload_type == 0) {
            $upload_type = (int)sys_config('upload_type', 1);
        }
        try {
            $path = make_path('attach', 2, true);
            $upload = UploadService::init($upload_type);
            $res = $upload->to($path)->validate()->setAuthThumb((bool)sys_config('image_thumb_status'))->move($file);
            if ($res === false) {
                throw new UploadException($upload->getError());
            } else {
                $fileInfo = $upload->getUploadInfo();
                //保存附件记录
                $this->saveAttachment($fileInfo, $pid, $type, $relation_id, 1, $upload_type);
                return $res->filePath;
            }
        } catch (\Exception $e) {
            throw new UploadException($e->getMessage());
        }
    }

    /**
     * @param array $data
     * @return \mohe\basic\BaseModel
     */
    public function move(array $data)
    {
        $res = $this->dao->move($data);
        if (!$res) throw new AdminException('移动失败或不能重复移动到同一分类下');
    }

    /**
     * 添加信息
     * @param array $data
     */
    public function save(array $data)
    {
        $this->dao->save($data);
    }

    /**添加附件记录
     * @param $name
     * @param $att_size
     * @param $att_type
     * @param $att_dir
     * @param $satt_dir
     * @param $pid
     * @param $imageType
     * @param $time
     * @param $module_type
     * @return bool
     */
    public function attachmentAdd($name, $att_size, $att_type, $att_dir, $satt_dir = '', $pid = 0, $imageType = 1, $time = 0, $module_type = 1)
    {
        $data['name'] = $name;
        $data['att_dir'] = $att_dir;
        $data['satt_dir'] = $satt_dir;
        $data['att_size'] = $att_size;
        $data['att_type'] = $att_type;
        $data['image_type'] = $imageType;
        $data['module_type'] = $module_type;
        $data['time'] = $time ? $time : time();
        $data['pid'] = $pid;
        if (!$this->dao->save($data)) {
            throw new ValidateException('添加附件失败');
        }
        return true;
    }

    /**
     * 清除昨日海报
     * @return bool
     * @throws \Exception
     */
    public function emptyYesterdayAttachment()
    {
        try {
            $list = $this->dao->getYesterday();
            foreach ($list as $key => $item) {
                $upload = UploadService::init((int)$item['image_type']);
                if ($item['image_type'] == 1) {
                    $att_dir = $item['att_dir'];
                    if ($att_dir && strstr($att_dir, 'uploads') !== false) {
                        if (strstr($att_dir, 'http') === false)
                            $upload->delete($att_dir);
                        else {
                            $filedir = substr($att_dir, strpos($att_dir, 'uploads'));
                            if ($filedir) $upload->delete($filedir);
                        }
                    }
                } else {
                    if ($item['name']) $upload->delete($item['name']);
                }
            }
            $this->dao->delYesterday();
            return true;
        } catch (\Exception $e) {
            $this->dao->delYesterday();
            return true;
        }
    }


    /**
     * 门店图片上传
     * @param int $pid
     * @param string $file
     * @param int $relationId
     * @param int $type
     * @return mixed
     */
    public function storeUpload(int $pid, string $file, int $relationId, int $type = 1, int $upload_type = 0, string $uploadToken = '')
    {
        try {
            if ($upload_type == 0) {
                $upload_type = (int)sys_config('upload_type', 1);
            }
            $path = make_path('attach/' . ($type == 2 ? 'supplier' : ($type == 1 ? 'store' : '')), 2, true);
            $upload = UploadService::init($upload_type);
            $res = $upload->to($path)->validate()->setAuthThumb(true)->move($file);
            if ($res === false) {
                throw new UploadException($upload->getError());
            } else {
                $fileInfo = $upload->getUploadInfo();
                //保存附件记录
                $this->saveAttachment($fileInfo, $pid, $type, $relationId, 1, $upload_type, $uploadToken);
                return $res->filePath;
            }
        } catch (\Exception $e) {
            throw new UploadException($e->getMessage());
        }
    }

    /**
     * 视频分片上传
     * @param $data
     * @param $file
     * @param int $type
     * @param int $relation_id
     * @return mixed
     */
    public function videoUpload($data, $file, int $type = 0, int $relation_id = 0)
    {
        $data['chunkNumber'] = (int)$data['chunkNumber'];
        $store_dir = '';
        switch ($type) {
            case 1://门店
                $store_dir = 'store' . DIRECTORY_SEPARATOR;
                break;
            case 2://供应商
                $store_dir = 'supplier' . DIRECTORY_SEPARATOR;
                break;
        }
        $dir = '/uploads/video/' . $store_dir;
		$tmp_dir = '/uploads/video_tmp';
        if (!is_dir(public_path() . $dir)) mkdir(public_path() . $dir, 0777, true);
		if (!is_dir(public_path() . $tmp_dir)) mkdir(public_path() . $tmp_dir, 0777, true);
        $tmp_file = public_path() . $tmp_dir . '/' . $data['filename'] . '__' . $data['chunkNumber'];
        move_uploaded_file($file->getPathName(), $tmp_file);
        $res['code'] = 0;
        $res['msg'] = 'error';
        $res['file_path'] = '';
        if ($data['chunkNumber'] == $data['totalChunks']) {
            $blob = '';
            for ($i = 1; $i <= $data['totalChunks']; $i++) {
                $blob .= file_get_contents(public_path() . $tmp_dir . '/' . $data['filename'] . '__' . $i);
            }
            file_put_contents(public_path() . $dir. $data['filename'], $blob);
            for ($i = 1; $i <= $data['totalChunks']; $i++) {
                @unlink(public_path() . $tmp_dir . '/' . $data['filename'] . '__' . $i);
            }
            if (file_exists(public_path() . $dir . $data['filename'])) {
                $res['code'] = 2;
                $res['msg'] = 'success';
                $res['file_path'] = $dir . $data['filename'];
            }
        } else {
            if (file_exists($tmp_file)) {
                $res['code'] = 1;
                $res['msg'] = 'waiting';
                $res['file_path'] = '';
            }
        }
        $res['name'] = $res['dir'] = $res['file_path'];
        if (strpos($res['file_path'], 'http') === false) {
            $res['dir'] = $res['file_path'] = sys_config('site_url') . $res['file_path'];
        }
        if ($res['code'] == 2) {
            $res['size'] = getFileHeaders($res['file_path'])['size'] ?? 0;
            $this->saveAttachment($res, (int)($data['pid'] ?? 0), $type, $relation_id, 2);
        }
        return $res;
    }

    /**
     * 云端上传的视频保存记录
     * @param array $data
     * @param int $type
     * @param int $relation_id
     * @param int $upload_type
     * @return bool
     */
    public function saveOssVideoAttachment(array $data, int $type = 0, int $relation_id = 0, int $upload_type = 1)
    {
        $fileInfo = [];
        $fileInfo['name'] = $fileInfo['real_name'] = $data['path'];
        $fileInfo['cover_image'] = $data['cover_image'];
        if (!$fileInfo['cover_image'] && $upload_type != 1) {//云端视频
            $res = UploadService::init($upload_type)->videoCoverImage($data['path'], 'mid');
            $fileInfo['cover_image'] = $res['mid'] ?? '';
        }
        $fileInfo['size'] = getFileHeaders($data['path'])['size'] ?? 0;
        $this->saveAttachment($fileInfo, (int)$data['pid'], $type, $relation_id, 2, $upload_type);
        return true;
    }

    /**
     * 保存附件信息
     * @param $fileInfo
     * @param int $pid
     * @param int $type
     * @param int $relation_id
     * @param int $file_type
     * @param int $upload_type
     * @return bool
     */
    public function saveAttachment($fileInfo, int $pid = 0, int $type = 1, int $relation_id = 0, int $file_type = 1, int $upload_type = 1, string $uploadToken = '')
    {
        $fileType = pathinfo($fileInfo['name'], PATHINFO_EXTENSION);
        if ($fileInfo && !in_array($fileType, ['xlsx', 'xls', 'ico'])) {
            $data['file_type'] = $file_type;
            $data['type'] = $type;
            $data['relation_id'] = $relation_id;
            $data['name'] = $fileInfo['name'];
            $data['real_name'] = $fileInfo['real_name'] ?? $fileInfo['name'] ?? '';
            $data['att_dir'] = $fileInfo['dir'] ?? $fileInfo['name'] ?? '';
            if ($data['att_dir'] && strpos($data['att_dir'], 'http') === false) {
                $data['att_dir'] = sys_config('site_url') . $data['att_dir'];
            }
            $data['satt_dir'] = $fileInfo['thumb_path'] ?? $fileInfo['cover_image'] ?? '';
            if ($file_type == 2) {
                if (!$data['satt_dir']) {//视频 默认封面
                    $data['satt_dir'] = sys_config('site_url') . '/statics/images/video_default_cover.png';
                }
                if ($data['real_name'] && strpos($data['real_name'], '/') !== false) {
                    $nameArr = explode('/', $data['real_name']);
                    $data['real_name'] = end($nameArr) ?? $data['real_name'];
                }
            }

            $data['att_size'] = $fileInfo['size'] ?? '';
            $data['att_type'] = $fileInfo['type'] ?? '';
            $data['image_type'] = $upload_type;
            $data['module_type'] = 1;
            $data['time'] = $fileInfo['time'] ?? time();
            $data['pid'] = $pid;
            $data['scan_token'] = $uploadToken;
            $this->dao->save($data);
        }
        return true;
    }

	/**
	 * 网络图片上传
	 * @param array $data
	 * @param int $type
	 * @param int $relation_id
	 * @return bool
	 * @throws \Exception
	 */
    public function onlineUpload(array $data, int $type = 0, int $relation_id = 0)
    {
		if (!isset($data['images']) || !$data['images']) {
			throw new AdminException('请复制网络图片地址并点击提取图片，在确认上传');
		}
        //生成附件目录
        if (make_path('attach', 3, true) === '') {
            throw new AdminException('无法创建文件夹，请检查您的上传目录权限');
        }

        //上传图片
        /** @var SystemAttachmentServices $systemAttachmentService */
        $systemAttachmentService = app()->make(SystemAttachmentServices::class);
        $siteUrl = sys_config('site_url');

        foreach ($data['images'] as $image) {
            try {
                $uploadValue = app()->make(DownloadImageService::class)->thumb(true)->downloadOnlineImage($image);
            } catch (\Throwable $e) {
                throw new AdminException('文件下载失败，请更换链接');
            }

            if (is_array($uploadValue)) {
                //TODO 拼接图片地址
                if ($uploadValue['image_type'] == 1) {
                    $imagePath = $siteUrl . $uploadValue['path'];
                } else {
                    $imagePath = $uploadValue['path'];
                }
                //写入数据库
                if (!$uploadValue['is_exists']) {
                    $systemAttachmentService->save([
                        'type' => $type,
                        'relation_id' => $relation_id,
                        'name' => $uploadValue['name'],
                        'real_name' => $uploadValue['name'],
                        'att_dir' => $imagePath,
                        'satt_dir' => $imagePath,
                        'att_size' => $uploadValue['size'],
                        'att_type' => $uploadValue['mime'],
                        'image_type' => $uploadValue['image_type'],
                        'module_type' => 1,
                        'time' => time(),
                        'pid' => $data['pid']
                    ]);
                }
            }
        }
        return true;
    }

    /**
     * 删除门店、供应商附件
     * @param array $where
     * @param int $type
     * @param int $relation_id
     * @return bool
     */
    public function delAttachment(array $where = [], int $type = 0, int $relation_id = 0)
    {
        $where['type'] = $type;
        $where['relation_id'] = $relation_id;
        //删除附件
        $attachments = $this->dao->getColumn($where, 'att_id');
        if ($attachments) {
            $idsArr = array_chunk($attachments, 100);
            foreach ($idsArr as $ids) {
                $this->del(implode(',', $ids));
            }
        }
        return true;
    }
}
