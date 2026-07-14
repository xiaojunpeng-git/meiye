<?php

namespace mohe\services\upload\storage;

use Aws\Acm\Exception\AcmException;
use mohe\exceptions\AdminException;
use mohe\exceptions\UploadException;
use mohe\basic\BaseUpload;
use GuzzleHttp\Psr7\Utils;
use think\exception\ValidateException;


/**
 * 京东云COS文件上传
 * Class Jdoss
 * @package mohe\services\upload\storage
 */
class Jdoss extends BaseUpload
{


    /**
     * 应用id
     * @var string
     */
    protected $appid;

    /**
     * accessKey
     * @var mixed
     */
    protected $accessKey;

    /**
     * secretKey
     * @var mixed
     */
    protected $secretKey;

    /**
     * 句柄
     * @var S3Client
     */
    protected $handle;

    /**
     * 空间域名 Domain
     * @var mixed
     */
    protected $uploadUrl;

    /**
     * 存储空间名称  公开空间
     * @var mixed
     */
    protected $storageName;

    /**
     * COS使用  所属地域
     * @var mixed|null
     */
    protected $storageRegion;

	/**
	 * @var string
	 */
	protected $cdn;

    /**
     * 水印位置
     * @var string[]
     */
    protected $position = [
        '1' => '1',//：左上
        '2' => '2',//：中上
        '3' => '3',//：右上
        '4' => '4',//：左中
        '5' => '5',//：中部
        '6' => '6',//：右中
        '7' => '7',//：左下
        '8' => '8',//：中下
        '9' => '9',//：右下
    ];

    /**
     * 初始化
     * @param array $config
     * @return mixed|void
     */
    public function initialize(array $config)
    {
        parent::initialize($config);
        $this->accessKey = $config['accessKey'] ?? null;
        $this->secretKey = $config['secretKey'] ?? null;
        $this->uploadUrl = $this->checkUploadUrl($config['uploadUrl'] ?? '') ?: sys_config('site_url');
        $this->storageName = $config['storageName'] ?? null;
        $this->storageRegion = $config['storageRegion'] ?? null;
        $this->waterConfig['watermark_text_font'] = 'simfang仿宋.ttf';
    }

	/**
	 * @return \mohe\services\upload\extend\jdoss\Client
	 */
	protected function app()
	{
		if (!$this->accessKey || !$this->secretKey) {
			throw new UploadException('Please configure accessKey and secretKey');
		}
		$this->handle = new \mohe\services\upload\extend\jdoss\Client([
			'accessKey' => $this->accessKey,
			'secretKey' => $this->secretKey,
		]);
		return $this->handle;
	}

	/**
	 * 上传图片
	 * @param string $file
	 * @return array|bool|mixed
	 */
    public function move(string $file = 'file')
    {
        $fileHandle = app()->request->file($file);
        if (!$fileHandle) {
            return $this->setError('上传的文件不存在');
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
        $key = $this->saveFileName($fileHandle->getRealPath(), $fileHandle->getOriginalExtension());
        $key = $this->getUploadPath($key);

		$body = fopen($fileHandle->getRealPath(), 'rb');
		$body = (string)Utils::streamFor($body);
        try {
            $uploadInfo = $this->app()->putObject($this->storageName, $this->storageRegion, $key, [
				'body' => $body
            ]);
			if (!$uploadInfo) {
				return $this->setError('Upload failure');
			}
            $this->fileInfo->uploadInfo = $uploadInfo;
            $this->fileInfo->realName = $fileHandle->getOriginalName();
            $this->fileInfo->filePath = ($this->cdn ?: $this->uploadUrl) . '/' . $key;
            $this->fileInfo->fileName = $key;
            $this->fileInfo->filePathWater = $this->water($this->fileInfo->filePath);
            $this->authThumb && $this->thumb($this->fileInfo->filePath);
            return $this->fileInfo;
        } catch (\Throwable $e) {
            return $this->setError($e->getMessage());
        }
    }

	/**
	 * 文件流上传
	 * @param string $fileContent
	 * @param string|null $key
	 * @return bool|mixed
	 */
    public function stream(string $fileContent, string $key = null)
    {
        try {
            if (!$key) {
                $key = $this->saveFileName();
            }
            $key = $this->getUploadPath($key);
			$fileContent = (string)Utils::streamFor($fileContent);
            $uploadInfo = $this->app()->putObject($this->storageName, $this->storageRegion, $key, [
                'body' => $fileContent
            ]);
			if (!$uploadInfo) {
				return $this->setError('Upload failure');
			}
            $this->fileInfo->uploadInfo = $uploadInfo;
            $this->fileInfo->realName = $key;
            $this->fileInfo->filePath = ($this->cdn ?: $this->uploadUrl) . '/' . $key;
            $this->fileInfo->fileName = $key;
            $this->fileInfo->filePathWater = $this->water($this->fileInfo->filePath);
            $this->thumb($this->fileInfo->filePath);
            return $this->fileInfo;
        } catch (\Throwable $e) {
            return $this->setError($e->getMessage());
        }
    }

	/**
	 * 删除
	 * @param string $key
	 * @return array|bool|\mohe\services\upload\extend\cos\SimpleXMLElement|mixed
	 */
    public function delete(string $key)
    {
        try {
            return $this->app()->deleteObject($this->storageName, $this->storageRegion, $key);
        } catch (\Throwable $e) {
            return $this->setError($e->getMessage());
        }
    }

	/**
	 * @param string $region
	 * @param bool $line
	 * @param bool $shared
	 * @return array|\Aws\Result
	 */
    public function listbuckets(string $region = 'cn-north-1', bool $line = false, bool $shared = false)
    {
        try {
            $res = $this->app()->listBuckets();
            return $res['Buckets']['Bucket'] ?? [];
        } catch (\Throwable $e) {
            return [];
        }
    }

	/**
	 * 创建桶
	 * @param string $name
	 * @param string $region
	 * @param string $acl
	 * @return array|\AsyncAws\S3\Result\CreateBucketOutput|bool|\mohe\services\upload\extend\cos\SimpleXMLElement
	 */
    public function createBucket(string $name, string $region = '', string $acl = 'public-read')
    {
        $regionData = $this->getRegion();
        $regionData = array_column($regionData, 'value');
        if (!in_array($region, $regionData)) {
            return $this->setError('COS:无效的区域!');
        }
        $this->storageRegion = $region;
        $app = $this->app();
        //检测桶
        try {
            $app->headBucket($name, $region);
        } catch (\Throwable $e) {
            //桶不存在返回404
            if (strstr('404', $e->getMessage())) {
                return $this->setError('JDOSS:' . $e->getMessage());
            }
        }
        //创建桶
        try {
            $res = $app->createBucket($name, $region, $acl);
        } catch (\Throwable $e) {
            return $this->setError('JDOSS:' . $e->getMessage());
        }
        return $res;
    }

	/**
	 * 删除桶
	 * @param string $name
	 * @param string $region
	 * @return bool
	 */
	public function deleteBucket(string $name, string $region = '')
	{
		try {
			$this->storageRegion = $region;
			$this->app()->deleteBucket($name, $region);
			return true;
		} catch (AcmException $e) {
			return $this->setError($e->getMessage());
		}
	}

    public function getRegion()
    {
        return [
            [
                'value' => 'cn-north-1',
                'label' => '华北-北京'
            ],
            [
                'value' => 'cn-east-1',
                'label' => '华东-宿迁'
            ],
            [
                'value' => 'cn-east-2',
                'label' => '华东-上海'
            ],
            [
                'value' => 'cn-south-1',
                'label' => '华南-广州'
            ]
        ];
    }


    public function getDomian(string $name, string $region = null)
    {
        try {
            $this->storageRegion = $region;
            $res = $this->app()->getBucketPolicy([
                'Bucket' => $name
            ]);
            return $res['DomainName'] ?? [];
        } catch (\Throwable $e) {
            return $this->setError($e->getMessage());
        }
    }

    public function bindDomian(string $name, string $domain, string $region = null)
    {
        try {
            $this->storageRegion = $region;
            $this->app()->putBucketWebsite([
                'Bucket' => $name,
                'WebsiteConfiguration' => [
                    'RedirectAllRequestsTo' => [
                        'HostName' => $domain,
                        'Protocol' => 'http'
                    ]
                ]
            ]);
            return true;
        } catch (\Throwable $e) {
            return $this->setError($e->getMessage());
        }
    }

    public function setBucketCors(string $name, string $region)
    {
        $this->storageRegion = $region;
        try {
            $this->app()->putBucketCors($name, $region,
                ['CORSConfiguration' => [ // REQUIRED
                    'CORSRules' => [ // REQUIRED
                        [
                            'AllowedHeaders' => ['*'],
                            'AllowedMethods' => ['POST', 'GET', 'PUT', 'DELETE', 'HEAD'], // REQUIRED
                            'AllowedOrigins' => ['*'], // REQUIRED
                            'ExposeHeaders' => ['Etag'],
                            'MaxAgeSeconds' => 0
                        ],
                    ],
                ]
            ]);
            return true;
        } catch (\Throwable $e) {
            return $this->setError($e->getMessage());
        }
    }

    /**
     * 获取OSS上传密钥
     * @return mixed|void
     */
    public function getTempKeys($key = '', $path = '', $contentType = '', $expires = '+10 minutes')
    {
        try {
            $app = $this->app();
			$host = $app->getRequestUrl() . '/' . $this->storageName;
			$url =  'https://' . $host . '/' . $key;
			$params = $this->getTempKeysParam($host, $url);
			$param = [];
			foreach ($params as $k => $value) {
				$param[] = $k . '=' . $value;
			}
			return [
				'upload_url' => $url . '?' . implode('&', $param),
				'type' => 'JDOSS',
				'url' => $this->uploadUrl . '/' . $key
			];
        } catch (\Throwable $e) {
            return $this->setError($e->getMessage());
        }
    }

	/**
	 * 	获取上传需要参数
	 * @param string $host
	 * @return array
	 */
	public function getTempKeysParam(string $host, string $url)
	{
		$amzDate = gmdate('Ymd\THis\Z');
		$sdt = substr($amzDate, 0, 8);

		$credentialScope = $sdt . '/' . $this->storageRegion . '/' . 's3' . '/aws4_request';

		$clientHeader = [
			'Host' => $host,
			'X-Amz-Content-Sha256' => 'UNSIGNED-PAYLOAD',
			'X-Amz-Date' => $amzDate
		];
		$param = [
			'X-Amz-Content-Sha256'    => 'UNSIGNED-PAYLOAD',
			'X-Amz-Algorithm'   => 'AWS4-HMAC-SHA256',
			'X-Amz-Credential' => $this->accessKey . '/' . $credentialScope,
			'X-Amz-Date' => $amzDate,
			'X-Amz-SignedHeaders'     => 'host;X-Amz-Content-Sha25;X-Amz-Date',
			'X-Amz-Expires' => 600
		];
		[$canonicalRequest, $signedHeaders] = $this->app()->createCanonicalRequest($url, 'GET', $clientHeader, ['query' => $param]);

		$stringToSign = "AWS4-HMAC-SHA256\n" . $amzDate . "\n" . $credentialScope . "\n" . hash('sha256', $canonicalRequest);

		$key = $this->getSigningKey($sdt, $this->storageRegion, 's3', $this->accessKey);
		$signature = hash_hmac('sha256', $stringToSign, $key);

		$param['X-Amz-Signature'] = $signature;
		return $param;

	}

	/**
	 * 生成key
	 * @param $shortDate
	 * @param $region
	 * @param $service
	 * @param $secretKey
	 * @return false|string
	 */
	public function getSigningKey($shortDate, $region, $service, $secretKey)
	{
		$kSecret = 'AWS4' . $secretKey;
		$kDate = hash_hmac('sha256', $shortDate, $kSecret, true);
		$kRegion = hash_hmac('sha256', $region, $kDate, true);
		$kService = hash_hmac('sha256', $service, $kRegion, true);
		return hash_hmac('sha256', 'aws4_request', $kService, true);
	}

    /**
     * 缩略图
     * @param string $filePath
     * @param string $fileName
     * @param string $type
     * @return array|mixed
     */
    public function thumb(string $filePath = '', string $fileName = '', string $type = 'all')
    {
        $filePath = $this->getFilePath($filePath);
        $data = ['big' => $filePath, 'mid' => $filePath, 'small' => $filePath];
        $this->fileInfo->filePathBig = $this->fileInfo->filePathMid = $this->fileInfo->filePathSmall = $this->fileInfo->filePathWater = $filePath;
        if ($filePath) {
            $config = $this->thumbConfig;
            foreach ($this->thumb as $v) {
                if ($type == 'all' || $type == $v) {
                    $height = 'thumb_' . $v . '_height';
                    $width = 'thumb_' . $v . '_width';
                    $key = 'filePath' . ucfirst($v);
					//x-oss-process=img/s/200/300
                    if (sys_config('image_thumbnail_status', 1) && isset($config[$height]) && isset($config[$width]) && $config[$height] && $config[$width]) {
                        $this->fileInfo->$key = $filePath . '?x-oss-process=img/s' . $config[$width] . '/' . $config[$height];
                        $this->fileInfo->$key = $this->water($this->fileInfo->$key);
                        $data[$v] = $this->fileInfo->$key;
                    } else {
                        $this->fileInfo->$key = $this->water($this->fileInfo->$key);
                        $data[$v] = $this->fileInfo->$key;
                    }
                }
            }
        }
        return $data;
    }

    /**
     * 水印
     * @param string $filePath
     * @return mixed|string
     */
    public function water(string $filePath = '')
    {
        $filePath = $this->getFilePath($filePath);
        $waterConfig = $this->waterConfig;
        $waterPath = $filePath;
        if ($waterConfig['image_watermark_status'] && $filePath) {
            if (strpos($filePath, '?x-oss-process') === false) {
                $filePath .= '?x-oss-process=img';
            }
            switch ($waterConfig['watermark_type']) {
                case 1://图片
                    if (!$waterConfig['watermark_image']) {
                        throw new AdminException('请先配置水印图片');
                    }
					//x-oss-process=img/wmi/wk/ZG93bmxvYWRzOmxvZ28ucG5n/ws/100
                    $waterPath = $filePath .= '/wmi/wk/' . base64_encode($waterConfig['watermark_image']) . '/wd/' . $waterConfig['watermark_opacity'] . '/wp/' . ($this->position[$waterConfig['watermark_position']] ?? '1') . '/wdx/' . $waterConfig['watermark_x'] . '/wdy/' . $waterConfig['watermark_y'];
                    break;
                case 2://文字
                    if (!$waterConfig['watermark_text']) {
                        throw new AdminException('请先配置水印文字');
                    }
					//?x-oss-process=img/wmt/wt/5Lqs5Lic5LqR
                    $waterConfig['watermark_text_color'] = str_replace('#', '', $waterConfig['watermark_text_color']);
                    $waterPath = $filePath .= '/wmt/wt/' . base64_encode($waterConfig['watermark_text']) . '/wc/' . $waterConfig['watermark_text_color'] . '/ws/' . $waterConfig['watermark_text_size'] . '/wp/' . ($this->position[$waterConfig['watermark_position']] ?? 'nw') . '/wdx/' . $waterConfig['watermark_x'] . '/wdy/' . $waterConfig['watermark_y'] . '/wr/' . $waterConfig['watermark_text_angle'];
                    break;
            }
        }
        return $waterPath;
    }

	/**
	 * 获取视频封面图
	 * @param string $filePath
	 * @param string $type
	 * @param int $time
	 * @return array
	 */
	public function videoCoverImage(string $filePath = '', string $type = 'all', int $time = 1)
	{
		$data = ['big' => $filePath, 'mid' => $filePath, 'small' => $filePath];
		$this->fileInfo->filePathBig = $this->fileInfo->filePathMid = $this->fileInfo->filePathSmall = $this->fileInfo->filePathWater = $filePath;
		if ($filePath) {
			//?x-oss-process=video/snapshot,t_7000,f_jpg,w_800,h_600,m_fast
			foreach ($this->thumb as $v) {
				if ($type == 'all' || $type == $v) {
					$height = 600;
					$width = 400;
					$key = 'filePath' . ucfirst($v);
					$this->fileInfo->$key = $filePath . '?x-oss-process=video/snapshot,t_' . ($time * 1000) . ',f_jpg,w_' . $width . ',h_' . $height . ',m_fast';
					$data[$v] = $this->fileInfo->$key;
				}
			}
		}
		return $data;
	}
}
