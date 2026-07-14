<?php
/**
 *  +----------------------------------------------------------------------
 *  | MOHE [ MOHE赋能开发者，助力企业发展 ]
 *  +----------------------------------------------------------------------
 *  | Copyright (c) 2016~2022 https://www.mohe.com All rights reserved.
 *  +----------------------------------------------------------------------
 *  | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
 *  +----------------------------------------------------------------------
 *  | Author: MOHE Team <admin@mohe.com>
 *  +----------------------------------------------------------------------
 */

namespace mohe\services\upload\extend\jdoss;


use AsyncAws\Core\Request;
use mohe\exceptions\UploadException;
use mohe\services\upload\BaseClient;
use GuzzleHttp\Psr7\Utils;


/**
 * 京东云上传
 * Class Client
 * @package mohe\services\upload\extend\jdoss
 */
class Client extends BaseClient
{

	const ALGORITHM_REQUEST = 'AWS4-HMAC-SHA256';

	const BLACKLIST_HEADERS = [
		'cache-control' => true,
		'content-type' => true,
		'content-length' => true,
		'expect' => true,
		'max-forwards' => true,
		'pragma' => true,
		'range' => true,
		'te' => true,
		'if-match' => true,
		'if-none-match' => true,
		'if-modified-since' => true,
		'if-unmodified-since' => true,
		'if-range' => true,
		'accept' => true,
		'authorization' => true,
		'proxy-authorization' => true,
		'from' => true,
		'referer' => true,
		'user-agent' => true,
		'x-amzn-trace-id' => true,
		'aws-sdk-invocation-id' => true,
		'aws-sdk-retry' => true,
	];
    /**
     * AK
     * @var
     */
    protected $accessKeyId;

    /**
     * SK
     * @var
     */
    protected $secretKey;

    /**
     * 桶名
     * @var string
     */
    protected $bucketName;

    /**
     * 地区
     * @var string
     */
    protected $region;

    /**
     * @var mixed|string
     */
    protected $uploadUrl;

    /**
     * @var string
     */
    protected $baseUrl = 's3.<REGION>.jdcloud-oss.com';

    //默认地域
    const DEFAULT_REGION = 'cn-north-1';

    /**
     * Client constructor.
     * @param array $config
     */
    public function __construct(array $config = [])
    {
        $this->accessKeyId = $config['accessKey'] ?? '';
        $this->secretKey = $config['secretKey'] ?? '';
        $this->bucketName = $config['bucket'] ?? '';
        $this->region = $config['region'] ?? self::DEFAULT_REGION;
        $this->uploadUrl = $config['uploadUrl'] ?? '';
    }

	/**
	 * 上传
	 * @param string $bucket
	 * @param string $region
	 * @param string $key
	 * @param array $data
	 * @return array|\mohe\services\upload\extend\cos\SimpleXMLElement
	 */
	public function putObject(string $bucket, string $region, string $key, array $data)
	{
		$url = $this->getRequestUrl($bucket, $region);

		$header = [
			'Host' => $url,
		];
		if (isset($data['body'])) {
			$header['Content-Length'] = strlen($data['body']);
		}

		return $this->request('https://' . $url . '/'. $key, 'PUT', $data, $header);
	}

	/**
	 * 删除文件
	 * @param string $bucket
	 * @param string $region
	 * @param string $key
	 * @return array|\mohe\services\upload\extend\cos\SimpleXMLElement
	 */
	public function deleteObject(string $bucket, string $region, string $key)
	{
		$url = $this->getRequestUrl($bucket, $region);

		$header = [
			'Host' => $url,
		];

		return $this->request('https://' . $url . '/'. $key, 'DELETE', [], $header);
	}

	/**
	 * 获取桶列表
	 * @return array|bool|\mohe\services\upload\SimpleXMLElement|mixed
	 */
    public function listBuckets()
    {
        $url = $this->getRequestUrl();
        $header = [
            'Host' => $url,
        ];

        $res = $this->request('https://' . $url . '/', 'GET', [], $header);

        return $res;
    }

	/**
	 * 检测桶，不存在返回true
	 * @param string $bucket
	 * @param string $region
	 * @return array|bool|\mohe\services\upload\SimpleXMLElement|mixed
	 */
	public function headBucket(string $bucket, string $region = '')
	{
		$url = $this->getRequestUrl($bucket, $region);

		$header = [
			'Host' => $url
		];

		return $this->request('https://' . $url, 'head', [], $header);
	}

	/**
	 * 创建桶
	 * @param $name
	 * @param $region
	 * @param $acl
	 * @return array|\mohe\services\upload\extend\cos\SimpleXMLElement
	 */
    public function createBucket($name, $region, $acl)
    {
        $url = $this->getRequestUrl($name, $region);
        $header = [
            'Host' => $url,
            'x-amz-acl' => $acl
        ];
        $res = $this->request('https://' . $url . '/', 'PUT', [], $header);

        return $res;
    }

	/**
	 * 删除桶
	 * @param string $bucket
	 * @param string $region
	 * @return array|\mohe\services\upload\extend\cos\SimpleXMLElement
	 */
	public function deleteBucket(string $bucket, string $region = '')
	{
		$url = $this->getRequestUrl($bucket, $region);

		$header = [
			'Host' => $url
		];

		return $this->request('https://' . $url . '/', 'DELETE', [], $header);
	}

	/**
	 * 设置桶跨域规则
	 * @param string $bucket
	 * @param string $region
	 * @param $data
	 * @return array|\mohe\services\upload\extend\cos\SimpleXMLElement
	 */
	public function putBucketCors(string $bucket, string $region = '', $data = [])
	{
		$url = $this->getRequestUrl($bucket, $region);

		$header = [
			'Host' => $url,
			'content-type' => 'application/xml'
		];

		return $this->request('https://' . $url . '/?cors', 'PUT', $data, $header);
	}

	/**
	 * 获取host
	 * @param string $bucket
	 * @param string $region
	 * @return string
	 */
    public function getRequestUrl(string $bucket = '', string $region = self::DEFAULT_REGION)
    {
        if (!$this->accessKeyId) {
            throw new UploadException('请传入SecretId');
        }
        if (!$this->secretKey) {
            throw new UploadException('请传入SecretKey');
        }

        return ($bucket ? $bucket . '.' : '') . 's3.' . $region . '.jdcloud-oss.com';
    }

	/**
	 * 发起请求
	 * @param string $url
	 * @param string $method
	 * @param array $data
	 * @param array $clientHeader
	 * @param int $timeout
	 * @return array|bool|\mohe\services\upload\SimpleXMLElement|mixed
	 */
    protected function request(string $url, string $method, array $data = [], array $clientHeader = [], int $timeout = 10)
    {
		if (!isset($clientHeader['Content-Length'])) {
			$clientHeader['Content-Length'] = 0;
		}
		$clientHeader['x-amz-date'] = gmdate('Ymd\THis\Z');
		$clientHeader['x-amz-content-sha256'] = hash('sha256', $data['body'] ?? '', false);

		$authorization = $this->generateAwsSignatureV4($data['region'] ?? self::DEFAULT_REGION, $url, $method, $clientHeader, $data);
		$clientHeader['Authorization'] = $authorization;

        return $this->requestClient($url, $method, $data, $clientHeader, $timeout);
    }

	/**
	 * 生成签名
	 * @param string $region
	 * @param string $url
	 * @param string $httpMethod
	 * @param array $header
	 * @param array $data
	 * @param string $service
	 * @return string
	 */
    protected function generateAwsSignatureV4(string $region, string $url, string $httpMethod, array $header, array $data = [], string $service = 's3')
    {
        $algorithm = self::ALGORITHM_REQUEST;
        $t = new \DateTime('UTC');
        $amzDate = $t->format('Ymd\THis\Z');
        $dateStamp = $t->format('Ymd');
		[$canonicalRequest, $signedHeaders] = $this->createCanonicalRequest($url, $httpMethod, $header, $data);

        $credentialScope = $dateStamp . '/' . $region . '/' . $service . '/aws4_request';
        $stringToSign = $algorithm . "\n" . $amzDate . "\n" . $credentialScope . "\n" . hash('sha256', $canonicalRequest);
        $signingKey = hash_hmac('sha256', 'aws4_request',
            hash_hmac('sha256', $service,
                hash_hmac('sha256', $region,
                    hash_hmac('sha256', $dateStamp, 'AWS4' . $this->secretKey, true),
                    true),
                true),
            true);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        return $algorithm . ' Credential=' . $this->accessKeyId . '/' . $credentialScope . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature;
    }

	/**
	 * @param string $url
	 * @param string $httpMethod
	 * @param array $header
	 * @param array $data
	 * @return array
	 */
	public function createCanonicalRequest(string $url, string $httpMethod, array $header, array $data = [])
	{
		$canonicalQueryString = '';
		$payload = '';
		if (!empty($data['query'])) {
			$query = $payload = $data['query'];
			ksort($query);
			$queryAttr = [];
			foreach ($query as $key => $item) {
				$queryAttr[urlencode($key)] = urlencode($item);
			}
			if ($queryAttr) {
				$canonicalQueryString = implode('&', $queryAttr);
			}
			$payload = json_encode($payload);
		} elseif (!empty($data['body'])) {
			$payload = $data['body'];
		} elseif (!empty($data['json'])) {
			$payload = json_encode($data['json']);
		}
		$canonicalHeaders = '';
		$signedHeaders = '';
		if ($header) {
			$canonicalHeadersAtrr = $signedHeadersAttr = [];
			ksort($header);
			foreach ($header as $key => $item) {
				$key = strtolower($key);
				if (isset(self::BLACKLIST_HEADERS[$key])) {
					continue;
				}
				$canonicalHeadersAtrr[] = $key . ':' . preg_replace('/\s+/', ' ', trim($item));
				$signedHeadersAttr[] = strtolower($key);
			}
			ksort($canonicalHeadersAtrr);
			ksort($signedHeadersAttr);
			if ($canonicalHeadersAtrr) {
				$canonicalHeaders = implode("\n", $canonicalHeadersAtrr);
				$signedHeaders = implode(';', $signedHeadersAttr);
			}
		}
		$canonicalUri = $this->createCanonicalizedPath($url);
		$bodyDigest = $this->buildBodyDigest($header, (string)Utils::streamFor($payload));
		$canonicalRequest = $httpMethod . "\n" . $canonicalUri . "\n" . $canonicalQueryString . "\n" . $canonicalHeaders . "\n" . "\n" . $signedHeaders . "\n" . $bodyDigest;
		return [$canonicalRequest, $signedHeaders];
	}


	/**
	 *
	 * @param string $url
	 * @return string
	 */
	public function createCanonicalizedPath(string $url)
	{
		$canonicalUri = '/';

		$urlAttr = pathinfo($url);
		if (isset($urlAttr['dirname']) && $urlAttr['dirname'] !== 'https:') {
			$urlParse = parse_url($urlAttr['dirname'] ?? '');
			if (isset($urlParse['path'])) {
				$canonicalUri .= substr($urlParse['path'], 1) . '/';
			}
			if (isset($urlAttr['basename'])) {
				$canonicalUri .= $urlAttr['basename'];
			}
			//|| strlen($canonicalUri) - 1 !== $pos
			if (!($pos = strripos($canonicalUri, '/')) ) {
				$canonicalUri .= '/';
			}
		}
		return $canonicalUri;
	}

	/**
	 * 处理dody hash
	 * @param array $header
	 * @param string $body
	 * @return string
	 */
	protected function buildBodyDigest(array $header, string $body = ''): string
	{
		if (isset($header['x-amz-content-sha256'])) {
			$hash = $header['x-amz-content-sha256'];
		} else {
			$hash = hash('sha256', $body);
		}

		return $hash;
	}

	/**
	 * 处理bogy
	 * @param string $body
	 * @return string
	 */
	public function dechunk(string $body): string
	{
		$h = fopen('php://temp', 'w+');
		stream_filter_append($h, 'dechunk', \STREAM_FILTER_WRITE);
		fwrite($h, $body);
		$body = stream_get_contents($h, -1, 0);
		rewind($h);
		ftruncate($h, 0);

		return $body;
	}

	/**
	 * 获取区域
	 * @return \string[][]
	 */
    public function getRegion()
    {
        return [
            [
                'value' => 'cn-north-1',
                'label' => '华北-北京',
            ],
            [
                'value' => 'cn-south-1',
                'label' => '华南-广州',
            ],
            [
                'value' => 'cn-east-2',
                'label' => '华东-上海',
            ],
            [
                'value' => 'cn-east-1',
                'label' => '华东-宿迁',
            ]
        ];
    }
}
