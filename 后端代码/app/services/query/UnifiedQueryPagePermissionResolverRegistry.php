<?php

namespace app\services\query;

/**
 * 可选的页面权限解析器；无专属解析器时使用 ContextFactory 的 feature 默认合同。
 */
class UnifiedQueryPagePermissionResolverRegistry
{
    /** @var UnifiedQueryPageRegistry */
    protected $pages;

    /** @var array<string,UnifiedQueryPagePermissionResolver> */
    protected $resolvers = [];

    /** @var bool */
    protected $frozen = false;

    public function __construct(UnifiedQueryPageRegistry $pages)
    {
        $this->pages = $pages;
    }

    public function register(UnifiedQueryPagePermissionResolver $resolver): void
    {
        if ($this->frozen) {
            throw new \LogicException('统一查询权限 resolver registry 已冻结');
        }
        $pageCode = trim($resolver->pageCode());
        $this->pages->page($pageCode);
        if (isset($this->resolvers[$pageCode])) {
            throw new \LogicException('统一查询权限 resolver 重复登记：' . $pageCode);
        }
        $this->resolvers[$pageCode] = $resolver;
    }

    public function freeze(): void
    {
        $this->frozen = true;
    }

    /** @return UnifiedQueryPagePermissionResolver|null */
    public function resolverFor(string $pageCode)
    {
        $this->pages->page($pageCode);
        return $this->resolvers[$pageCode] ?? null;
    }

    public function pageCodes(): array
    {
        return array_keys($this->resolvers);
    }

    public function isFrozen(): bool
    {
        return $this->frozen;
    }
}
