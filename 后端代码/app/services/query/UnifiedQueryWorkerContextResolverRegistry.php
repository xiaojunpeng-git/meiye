<?php

namespace app\services\query;

/**
 * page_code 到 Worker 当前权限上下文 resolver 的唯一冻结映射。
 */
class UnifiedQueryWorkerContextResolverRegistry
{
    /** @var UnifiedQueryPageRegistry */
    protected $pages;

    /** @var array<string,UnifiedQueryWorkerContextResolver> */
    protected $resolvers = [];

    /** @var bool */
    protected $frozen = false;

    public function __construct(UnifiedQueryPageRegistry $pages)
    {
        $this->pages = $pages;
    }

    public function register(UnifiedQueryWorkerContextResolver $resolver): void
    {
        if ($this->frozen) {
            throw new \LogicException('统一查询 Worker context resolver registry 已冻结');
        }
        $pageCodes = method_exists($resolver, 'pageCodes')
            ? $resolver->pageCodes()
            : [$resolver->pageCode()];
        if (!is_array($pageCodes) || !$pageCodes) {
            throw new \LogicException('统一查询 Worker context resolver 未声明页面：' . get_class($resolver));
        }
        foreach (array_values(array_unique(array_map('strval', $pageCodes))) as $pageCode) {
            $pageCode = trim($pageCode);
            $this->pages->page($pageCode);
            if (isset($this->resolvers[$pageCode])) {
                throw new \LogicException(
                    '统一查询 Worker context resolver 重复登记：' . $pageCode
                );
            }
            $this->resolvers[$pageCode] = $resolver;
        }
    }

    public function freeze(): void
    {
        foreach ($this->pages->pageCodes() as $pageCode) {
            if (!isset($this->resolvers[$pageCode])) {
                throw new \LogicException(
                    '统一查询页面缺少 Worker context resolver：' . $pageCode
                );
            }
        }
        $this->frozen = true;
    }

    public function resolve(string $pageCode): UnifiedQueryWorkerContextResolver
    {
        $pageCode = trim($pageCode);
        $this->pages->page($pageCode);
        if (!isset($this->resolvers[$pageCode])) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_WORKER_CONTEXT_RESOLVER_NOT_AVAILABLE',
                '当前页面的导出权限服务尚未就绪，请稍后重试。',
                ['page_code' => $pageCode]
            );
        }
        return $this->resolvers[$pageCode];
    }

    public function pageCodes(): array
    {
        return array_keys($this->resolvers);
    }

    public function isFrozen(): bool
    {
        return $this->frozen;
    }

    public function pageRegistry(): UnifiedQueryPageRegistry
    {
        return $this->pages;
    }
}
