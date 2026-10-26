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
namespace app\controller\store\file;

use app\controller\store\AuthController;
use app\services\other\CacheServices;
use app\services\system\attachment\SystemAttachmentServices;
use app\services\store\SystemStoreStaffServices;
use think\facade\App;

/**
 * 图片管理类
 * Class SystemAttachment
 * @package app\controller\store\file
 */
class SystemAttachment extends AuthController
{
    /**
    * @var SystemAttachmentServices
    */
    protected $service;

    /**
     * SystemAttachment constructor.
     * @param App $app
     * @param SystemAttachmentServices $service
     */
    public function __construct(App $app, SystemAttachmentServices $service)
    {
        parent::__construct($app);
        $this->service = $service;
    }

    /**
     * 显示列表
     * @return mixed
     */
    public function index()
    {
        $where = $this->request->getMore([
			['name', ''],
            ['pid', 0],
            ['file_type', 1]
        ]);
        $where['type'] = 1;
        $where['relation_id'] = $this->storeId;
        return app('json')->success($this->service->getImageList($where));
    }

    /**
     * 删除指定资源
     *
     * @param string $ids
     * @return \think\Response
     */
    public function delete()
    {
        [$ids] = $this->request->postMore([
            ['ids', '']
        ], true);
        $this->service->del($ids);
        return app('json')->success('删除成功');
    }

    /**
     * 图片上传
     * @param int $upload_type
     * @param int $type
     * @return mixed
     */
    public function upload($upload_type = 0, $type = 0)
    {
        [$pid, $file] = $this->request->postMore([
            ['pid', 0],
            ['file', 'file'],
        ], true);
        $res = $this->service->storeUpload((int)$pid, $file, (int)$this->storeId);
        return app('json')->success('上传成功', ['src' => $res]);
    }

    /**
     * 移动图片
     * @return mixed
     */
    public function moveImageCate()
    {
        $data = $this->request->postMore([
            ['pid', 0],
            ['images', '']
        ]);
        $this->service->move($data);
        return app('json')->success('移动成功');
    }

    /**
     * 修改文件名
     * @param $id
     * @return mixed
     */
    public function update($id)
    {
        $realName = $this->request->post('real_name', '');
        if (!$realName) {
            return app('json')->fail('文件名称不能为空');
        }
        $this->service->update($id, ['real_name' => $realName]);
        return app('json')->success('修改成功');
    }

	/**
     * 获取上传类型
     * @return mixed
     */
    public function uploadType()
    {
        $data['upload_type'] = (string)sys_config('upload_type', 1);
        return app('json')->success($data);
    }

    /**
     * 视频分片上传
     * @return mixed
     */
    public function videoUpload()
    {
        $data = $this->request->postMore([
            ['chunkNumber', 0],//第几分片
            ['currentChunkSize', 0],//分片大小
            ['chunkSize', 0],//总大小
            ['totalChunks', 0],//分片总数
            ['file', 'file'],//文件
            ['md5', ''],//MD5
            ['filename', ''],//文件名称
            ['pid', 0],//分类ID
        ]);
        $fileHandle = $this->request->file($data['file']);
        if (!$fileHandle) return $this->fail('上传信息为空');
        $res = $this->service->videoUpload($data, $fileHandle, 1, (int)$this->storeId);
        return app('json')->success($res);
    }

	/**
 	* 保存云端视频记录
	* @return mixed
	*/
	public function saveVideoAttachment()
	{
		$data = $this->request->postMore([
            ['path', ''],//视频地址
            ['cover_image', ''],//封面地址
			['pid', 0],//分类ID
			['upload_type', 1],//上传类型
        ]);
		 $res = $this->service->saveOssVideoAttachment($data, 1, (int)$this->storeId, (int)$data['upload_type']);
        return app('json')->success($res);
	}

    /**
	 * 获取扫码上传页面链接以及参数
     * @return \think\Response
     */
    public function scanUploadQrcode(CacheServices $services)
    {
        [$pid] = $this->request->getMore([
            ['pid', 0]
        ], true);
        $uploadToken = md5(time());
        $upload_file_size_max = config('upload.filesize');//文件上传大小kb
        $services->setDbCache($this->storeId .'_store_scan_upload', $uploadToken, 68400);
        $url = sys_config('site_url') . '/app/upload?pid=' . $pid . '&cache='. $this->storeId .'_store_scan_upload&type=1&relation_id='. $this->storeId . '&upload_file_size_max=' . $upload_file_size_max . '&token=' . $uploadToken;
        return app('json')->success(['url' => $url]);
    }

    /**
	 * 删除二维码
     * @return \think\Response
     */
    public function removeUploadQrcode(CacheServices $services)
    {
        $services->deleteDbCache($this->storeId .'_store_scan_upload');
        return app('json')->success();
    }

    /**
	 * 获取扫码上传的图片数据
     * @param $scan_token
     * @return \think\Response
     */
    public function scanUploadImage($scan_token)
    {
        return app('json')->success($this->service->scanUploadImage($scan_token));
    }

    /**
	 * 网络图片上传
     * @return \think\Response
     */
    public function onlineUpload()
    {
        $data = $this->request->postMore([
            ['pid', 0],
            ['images', []]
        ]);
        $this->service->onlineUpload($data,1, (int)$this->storeId);
        return app('json')->success('上传完成');
    }

    /**
	 * 获取上传信息
     * @return \think\Response
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getAdminsData()
    {
        /** @var SystemStoreStaffServices $staffServices */
        $staffServices = app()->make(SystemStoreStaffServices::class);
        $data['is_way'] = $staffServices->value(['id'=>$this->storeId], 'is_way');
        $data['upload_file_size_max'] = config('upload.filesize');//文件上传大小kb
        return app('json')->success($data);
    }

    /**
	 * 保存上传信息
     * @param int $is_way
     * @return \think\Response
     */
    public function setAdminsData($is_way = 0)
    {
        /** @var SystemStoreStaffServices $staffServices */
        $staffServices = app()->make(SystemStoreStaffServices::class);
        if(!in_array($is_way,[0,1,2])) return app('json')->fail('参数有误！');
        $res = $staffServices->update($this->storeId, ['is_way' => $is_way]);
        if($res) return app('json')->success('ok');
        else return app('json')->fail('保存失败');
    }
}
