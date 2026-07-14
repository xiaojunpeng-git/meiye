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
namespace mohe\services\upload\storage;

use mohe\basic\BaseUpload;
use mohe\exceptions\UploadException;
use mohe\services\DownloadImageService;
use think\exception\ValidateException;
use think\facade\Config;
use think\facade\Filesystem;
use think\File;
use think\Image;

/**
 * 本地上传
 * Class Local
 * @package mohe\services\upload\storage
 */
class Local extends BaseUpload
{

    /**
     * 默认存放路径
     * @var string
     */
    protected $defaultPath;

    /**
     * 缩略图、水印图存放位置
     * @var string
     */
    public $thumbWaterPath = 'thumb_water';

    public function initialize(array $config)
    {
        parent::initialize($config);
//        $this->defaultPath = Config::get('filesystem.disks.' . Config::get('filesystem.default') . '.url');
        $this->waterConfig['watermark_text_font'] = app()->getRootPath() . 'public' . '/statics/font/simsunb.ttf';
    }

    protected function app()
    {
        //
    }

    public function getTempKeys()
    {
        //
        return $this->setError('请检查您的上传配置，视频默认oss上传');
    }

    /**
     * 生成上传文件目录
     * @param $path
     * @param null $root
     * @return string
     */
    public function uploadDir($path, $root = null)
    {
        if ($root === null) $root = app()->getRootPath() . 'public/';
        return str_replace('\\', '/', $root . 'uploads/' . $path);
    }

    /**
     * 检查上传目录不存在则生成
     * @param $dir
     * @return bool
     */
    protected function validDir($dir)
    {
        return is_dir($dir) == true || mkdir($dir, 0777, true) == true;
    }

    /**
     * 检测filepath是否是远程地址
     * @param string $filePath
     * @return bool
     */
    public function checkFilePathIsRemote(string $filePath)
    {
        return strpos($filePath, 'https:') !== false || strpos($filePath, 'http:') !== false || substr($filePath, 0, 2) === '//';
    }

    /**
     * 生成与配置相关的文件名称以及路径
     * @param string $filePath 原地址
     * @param string $toPath 保存目录
     * @param array $config 配置相关参数
     * @param string $root
     * @return string
     */
    public function createSaveFilePath(string $filePath, string $toPath, array $config = [], $root = '/')
    {
        [$path, $ext] = $this->getFileName($filePath);
        $fileName = md5(json_encode($config) . $filePath);
        return $this->uploadDir($toPath, $root) . '/' . $fileName . '.' . $ext;
    }

    /**
     * 文件上传
     * @param string $file
     * @return array|bool|mixed|\StdClass
     */
    public function move(string $file = 'file', $realName = false)
    {
        $fileHandle = app()->request->file($file);
        if (!$fileHandle) {
            return $this->setError('Upload file does not exist');
        }
        if ($this->validate) {
            try {
                $error = [
                    $file . '.filesize' => 'Upload filesize error',
                    $file . '.fileExt' => 'Upload fileExt error',
                    $file . '.fileMime' => 'Upload fileMine error'
                ];
                validate([$file => $this->validate], $error)->check([$file => $fileHandle]);
            } catch (ValidateException $e) {
                return $this->setError($e->getMessage());
            }
        }
//		$stream = fopen($fileHandle->getPathname(), 'r');
//		$content = (fread($stream, filesize($fileHandle->getPathname())));
//		if (is_resource($stream)) {
//			fclose($stream);
//		}
//		if (preg_match('/think|php|log|phar|Socket|Channel|Flysystem|Psr6Cache|Cached|Request|debug|Psr6Cachepool|eval/i', $content)) {
//			return $this->setError('文件内容不合法');
//		}
        $disk ='public';
        $path = $this->path;
        $rule = null;
        if (in_array($fileHandle->getOriginalMime(), ['application/x-x509-ca-cert', 'application/octet-stream'])) {
            $disk = 'pem';
            $path ='';
            $rule = function() {
                return md5(microtime(true));
            };
        }
		$fileType = pathinfo($fileHandle->getOriginalName(), PATHINFO_EXTENSION);
        $this->defaultPath = Config::get('filesystem.disks.' . $disk . '.url');
//        $fileName = Filesystem::putFileAs($this->path, $fileHandle, in_array($fileType, ['ico']) ? $fileHandle->getOriginalName() : strtolower($fileHandle->hashName()));
        if($realName) {
            $fileName = Filesystem::disk($disk)->putFileAs($path, $fileHandle,$fileHandle->getoriginalName());
        }else{
            $fileName= Filesystem::disk($disk)->putFile($path, $fileHandle,$rule);
        }
        if (!$fileName)
            return $this->setError('Upload failure');
        $filePath = Filesystem::disk($disk)->path($fileName);
        $this->fileInfo->uploadInfo = new File($filePath);
        $this->fileInfo->realName = $fileHandle->getOriginalName();
        $this->fileInfo->fileName = $this->fileInfo->uploadInfo->getFilename();
        $this->fileInfo->filePath = $this->defaultPath . '/' . str_replace('\\', '/', $fileName);
        if ($this->checkImage(pem_path() . $this->fileInfo->filePath) && $this->authThumb && $fileType != 'ico') {
            try {
                $this->thumb($this->fileInfo->filePath);
            } catch (\Throwable $e) {
                return $this->setError($e->getMessage());
            }
        }
        return $this->fileInfo;
    }

    /**
     * 文件流上传
     * @param string $fileContent
     * @param string|null $key
     * @return array|bool|mixed|\StdClass
     */
    public function stream(string $fileContent, string $key = null)
    {
        if (!$key) {
            $key = $this->saveFileName();
        }
        $dir = $this->uploadDir($this->path);
        if (!$this->validDir($dir)) {
            return $this->setError('Failed to generate upload directory, please check the permission!');
        }
        $fileName = $dir . '/' . $key;
        file_put_contents($fileName, $fileContent);
        $this->defaultPath = Config::get('filesystem.disks.' . Config::get('filesystem.default') . '.url');
        $this->fileInfo->uploadInfo = new File($fileName);
        $this->fileInfo->realName = $key;
        $this->fileInfo->fileName = $key;
        $this->fileInfo->filePath = $this->defaultPath . '/' . $this->path . '/' . $key;
        if ($this->checkImage(root_path() . 'public' . $this->fileInfo->filePath) && $this->authThumb) {
            try {
                $this->thumb($this->fileInfo->filePath);
            } catch (\Throwable $e) {
                return $this->setError($e->getMessage());
            }
        }
        return $this->fileInfo;
    }

    /**
     * 文件流下载保存图片
     * @param string $fileContent
     * @param string|null $key
     * @return array|bool|mixed|\StdClass
     */
    public function down(string $fileContent, string $key = null)
    {
        if (!$key) {
            $key = $this->saveFileName();
        }
        $dir = $this->uploadDir($this->path);
        if (!$this->validDir($dir)) {
            return $this->setError('Failed to generate upload directory, please check the permission!');
        }
        $this->defaultPath = Config::get('filesystem.disks.' . Config::get('filesystem.default') . '.url');
        $fileName = $dir . '/' . $key;
        file_put_contents($fileName, $fileContent);
        $this->downFileInfo->downloadInfo = new File($fileName);
        $this->downFileInfo->downloadRealName = $key;
        $this->downFileInfo->downloadFileName = $key;
        $this->downFileInfo->downloadFilePath = $this->defaultPath . '/' . $this->path . '/' . $key;
        return $this->downFileInfo;
    }

    /**
     * 生成缩略图
     * @param string $filePath
     * @param string $type
     * @return array|mixed|string[]
     */
    public function thumb(string $filePath = '', string $type = 'all')
    {
        $filePath = $this->getFilePath($filePath, true);
        $data = ['big' => $filePath, 'mid' => $filePath, 'small' => $filePath];
        $this->fileInfo->filePathBig = $this->fileInfo->filePathMid = $this->fileInfo->filePathSmall = $this->fileInfo->filePathWater = $filePath;
        //地址存在且不是远程地址
        if ($filePath && !$this->checkFilePathIsRemote($filePath) && strpos($filePath, 'uploads/' . $this->thumbWaterPath) === false) {
            $dir = $this->uploadDir($this->thumbWaterPath);
            if (!$this->validDir($dir)) {
                throw new ValidateException('缩略图生成目录生成失败，目录：' . $dir);
            }
            $filePath = $this->water($filePath);
            $this->fileInfo->filePathWater = $filePath;
            $config = $this->thumbConfig;
            try {
                foreach ($this->thumb as $v) {
                    if ($type == 'all' || $type == $v) {
                        $height = 'thumb_' . $v . '_height';
                        $width = 'thumb_' . $v . '_width';
                        if (isset($config[$height]) && isset($config[$width]) && $config[$height] && $config[$width]) {
                            $savePath = $this->createSaveFilePath($filePath, $this->thumbWaterPath, [$height => $config[$height], $width => $config[$width]]);
                            //防止重复生成
                            if (!file_exists(root_path() . 'public' . $savePath)) {
								if ($this->authThumb) {//生成
									$Image = Image::open(app()->getRootPath() . 'public' . $filePath);
                                	$Image->thumb($config[$width], $config[$height])->save(root_path() . 'public' . $savePath);
								} else {
									$savePath = $filePath;
								}
                            }
                            $key = 'filePath' . ucfirst($v);
                            $data[$v] = $this->fileInfo->$key = $savePath;
                        }
                    }
                }
            } catch (\Throwable $e) {
                throw new ValidateException($e->getMessage());
            }
        }
        return $data;
    }

    /**
     * 添加水印
     * @param string $filePath
     * @return mixed|string
     */
    public function water(string $filePath = '')
    {
        $waterPath = $filePath = $this->getFilePath($filePath);
        //地址存在且不是远程地址
        if ($filePath && !$this->checkFilePathIsRemote($filePath)) {
            $waterConfig = $this->waterConfig;
            if ($waterConfig['image_watermark_status'] && $filePath) {
                $waterPath = $this->createSaveFilePath($filePath, $this->thumbWaterPath, $waterConfig);
                switch ($waterConfig['watermark_type']) {
                    case 1:
                        if ($waterConfig['watermark_image']) $waterPath = $this->image($filePath, $waterConfig, $waterPath);
                        break;
                    case 2:
                        $waterPath = $this->text($filePath, $waterConfig, $waterPath);
                        break;
                }
            }
        }
        return $waterPath;
    }

    /**
     * 图片水印
     * @param string $filePath
     * @param array $waterConfig
     * @param string $waterPath
     * @return string
     */
    public function image(string $filePath, array $waterConfig = [], string $waterPath = '')
    {
        if (!$waterConfig) {
            $waterConfig = $this->waterConfig;
        }
        $watermark_image = $waterConfig['watermark_image'];
        //远程图片
        if ($watermark_image && $this->checkFilePathIsRemote($watermark_image)) {
            //看是否在本地
            if (!$this->isLocal($watermark_image)) {//不再本地  继续下载
                [$p, $e] = $this->getFileName($watermark_image);
                $name = 'water_image_' . md5($watermark_image) . '.' . $e;
                $this->defaultPath = Config::get('filesystem.disks.' . Config::get('filesystem.default') . '.url');
                $watermark_image = $this->defaultPath . '/' . $this->thumbWaterPath . '/' . $name;
                if (!file_exists(root_path() . 'public' . $watermark_image)) {
                    try {
                        /** @var DownloadImageService $down */
                        $down = app()->make(DownloadImageService::class);
                        $data = $down->path($this->thumbWaterPath)->downloadImage($waterConfig['watermark_image'], $name);
                        $watermark_image = $data['path'] ?? '';
                    } catch (\Throwable $e) {
                        throw new ValidateException('远程水印图片下载失败，原因：' . $e->getMessage());
                    }
                }
            } else {
                $watermark_image = $this->getFilePath($watermark_image, true);
            }
        }
        if (!$watermark_image) {
            throw new ValidateException('请先配置水印图片');
        }
        if (!$waterPath) {
            [$path, $ext] = $this->getFileName($filePath);
            $waterPath = $path . '_water_image.' . $ext;
        }
        try {
            if (!file_exists(root_path() . 'public' . $waterPath)) {
				if ($this->authThumb) {//生成
					$Image = Image::open(app()->getRootPath() . 'public' . $filePath);
					$Image->water(app()->getRootPath() . 'public' . $watermark_image, $waterConfig['watermark_position'] ?: 1, $waterConfig['watermark_opacity'])->save(root_path() . 'public' . $waterPath);
				} else {
					$waterPath = $filePath;
				}
            }
        } catch (\Throwable $e) {
            throw new ValidateException($e->getMessage());
        }
        return $waterPath;
    }

    /**
     * 文字水印
     * @param string $filePath
     * @param array $waterConfig
     * @param string $waterPath
     * @return string
     */
    public function text(string $filePath, array $waterConfig = [], string $waterPath = '')
    {
        if (!$waterConfig) {
            $waterConfig = $this->waterConfig;
        }
        if (!$waterConfig['watermark_text']) {
            throw new ValidateException('请先配置水印文字');
        }
        if (!$waterPath) {
            [$path, $ext] = $this->getFileName($filePath);
            $waterPath = $path . '_water_text.' . $ext;
        }
        try {
            if (!file_exists(root_path() . 'public' . $waterPath)) {
				if ($this->authThumb) {//生成
					$Image = Image::open(app()->getRootPath() . 'public' . $filePath);
					if (strlen($waterConfig['watermark_text_color']) < 7) {
						$waterConfig['watermark_text_color'] = substr($waterConfig['watermark_text_color'], 1);
						$waterConfig['watermark_text_color'] = '#' . $waterConfig['watermark_text_color'] . $waterConfig['watermark_text_color'];
					}
					if (strlen($waterConfig['watermark_text_color']) > 7) {
						$waterConfig['watermark_text_color'] = substr($waterConfig['watermark_text_color'], 0, 7);
					}
					$Image->text($waterConfig['watermark_text'], $waterConfig['watermark_text_font'], $waterConfig['watermark_text_size'], $waterConfig['watermark_text_color'], $waterConfig['watermark_position'], [$waterConfig['watermark_x'], $waterConfig['watermark_y'], $waterConfig['watermark_text_angle']])->save(root_path() . 'public' . $waterPath);
				} else {
					$waterPath = $filePath;
				}
            }
        } catch (\Throwable $e) {
            throw new ValidateException($e->getMessage() . $e->getLine());
        }
        return $waterPath;
    }

    /**
     * 删除文件
     * @param string $filePath
     * @return bool|mixed
     */
    public function delete(string $filePath)
    {
        if (file_exists(public_path() . $filePath)) {
            try {
                unlink(public_path() . $filePath);
                $waterConfig = $this->waterConfig;
                //水印图片
                $waterPath = $this->createSaveFilePath($filePath, $this->thumbWaterPath, $waterConfig, null);
                if (file_exists($waterPath)) unlink($waterPath);
                $config = $this->thumbConfig;
                //缩略图
                foreach ($this->thumb as $v) {
                    $height = 'thumb_' . $v . '_height';
                    $width = 'thumb_' . $v . '_width';
                    if (isset($config[$height]) && isset($config[$width]) && $config[$height] && $config[$width]) {
                        $thumbPath = $this->createSaveFilePath($waterPath, $this->thumbWaterPath, [$height => $config[$height], $width => $config[$width]], null);
                        if (file_exists($thumbPath)) unlink(public_path() . $thumbPath);
                    }
                }
                return true;
            } catch (UploadException $e) {
                return $this->setError($e->getMessage());
            }
        }
        return false;
    }

    public function listbuckets(string $region, bool $line = false, bool $shared = false)
    {
        return [];
    }

    public function createBucket(string $name, string $region)
    {
        return null;
    }

    public function deleteBucket(string $name)
    {
        return null;
    }

    public function setBucketCors(string $name, string $region)
    {
        return true;
    }

    public function getRegion()
    {
        return [];
    }

    public function bindDomian(string $name, string $domain, string $region = null)
    {
        return true;
    }

    public function getDomian(string $name, string $region = null)
    {
        return [];
    }
}
