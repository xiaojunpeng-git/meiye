<?php

namespace app\services\query;

/**
 * page_code 到领域 provider 的唯一、冻结映射。
 */
class UnifiedQueryProviderRegistry
{
    /** @var UnifiedQueryPageRegistry|null */
    protected $pages;

    /** @var array<string,UnifiedQueryProvider> */
    protected $providers = [];

    /** @var bool */
    protected $frozen = false;

    public function __construct(UnifiedQueryPageRegistry $pages = null)
    {
        $this->pages = $pages;
    }

    public function register(UnifiedQueryProvider $provider): void
    {
        if ($this->frozen) {
            throw new \LogicException('统一查询 provider registry 已冻结');
        }
        $pageCode = trim($provider->pageCode());
        if (!preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $pageCode)) {
            throw new \InvalidArgumentException('统一查询 provider page_code 不合法');
        }
        if ($this->pages !== null) {
            $this->pages->page($pageCode);
        }
        if (isset($this->providers[$pageCode])) {
            throw new \LogicException('统一查询 provider 重复登记：' . $pageCode);
        }
        $this->providers[$pageCode] = $provider;
    }

    public function freeze(): void
    {
        if ($this->pages !== null) {
            foreach ($this->pages->pageCodes() as $pageCode) {
                if (!isset($this->providers[$pageCode])) {
                    throw new \LogicException('统一查询页面缺少 provider：' . $pageCode);
                }
            }
        }
        $this->frozen = true;
    }

    public function resolve(string $pageCode): UnifiedQueryProvider
    {
        $pageCode = trim($pageCode);
        if ($this->pages !== null) {
            $this->pages->page($pageCode);
        }
        if (!isset($this->providers[$pageCode])) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_PROVIDER_NOT_AVAILABLE',
                '当前页面的查询服务尚未就绪，请稍后重试。',
                ['page_code' => $pageCode]
            );
        }
        return $this->providers[$pageCode];
    }

    public function pageCodes(): array
    {
        return array_keys($this->providers);
    }

    public function isFrozen(): bool
    {
        return $this->frozen;
    }
}
