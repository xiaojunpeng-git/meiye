<?php

namespace app\services\query;

use app\services\metric\MetricDictionaryServices;

/**
 * 与具体页面路由无关的统一查询对象图。
 */
final class UnifiedQueryRuntime
{
    /** @var array<string,object>|null */
    private static $runtime;

    public static function resetForTests(): void
    {
        self::$runtime = null;
    }

    /** @return array<string,object> */
    public static function runtime(): array
    {
        if (self::$runtime !== null) {
            return self::$runtime;
        }

        /** @var MetricDictionaryServices $metricDictionary */
        $metricDictionary = app()->make(MetricDictionaryServices::class);
        $definitions = $metricDictionary->getDefinitions();
        if (!$definitions) {
            throw new \LogicException('统一查询启动失败：系统指标字典为空');
        }

        $registrars = [];
        foreach (self::configuredClasses('registrars', []) as $className) {
            $registrar = app()->make($className);
            if (!$registrar instanceof UnifiedQueryPageRegistrar) {
                throw new \LogicException(
                    '统一查询 registrar 未实现 UnifiedQueryPageRegistrar：' . $className
                );
            }
            $registrars[] = $registrar;
        }
        $registry = UnifiedQueryPageRegistry::fromRegistrars($definitions, $registrars);
        self::bindServices(['registry' => $registry]);
        $permissionResolvers = new UnifiedQueryPagePermissionResolverRegistry($registry);
        foreach (self::configuredClasses('permission_resolvers', []) as $className) {
            $permissionResolver = app()->make($className);
            if (!$permissionResolver instanceof UnifiedQueryPagePermissionResolver) {
                throw new \LogicException(
                    '统一查询权限 resolver 未实现 UnifiedQueryPagePermissionResolver：'
                    . $className
                );
            }
            $permissionResolvers->register($permissionResolver);
        }
        $permissionResolvers->freeze();
        $access = new UnifiedQueryAccessPolicy();
        $validator = new StructuredExpressionValidator($registry);
        $evaluator = new StructuredExpressionEvaluator();
        $references = new UnifiedQueryFieldReferenceServices();
        $customFields = new UnifiedQueryCustomFieldServices(
            $registry,
            $validator,
            $access,
            $references
        );
        $execution = new UnifiedQueryExecutionServices($registry, $validator, $evaluator);
        $preferences = new UnifiedQueryPreferenceServices(
            $registry,
            $customFields,
            $execution,
            $access,
            $references
        );
        $aliases = new UnifiedQueryFieldAliasServices($registry, $customFields, $access);
        $exports = new UnifiedQueryExportTaskServices(
            $registry,
            $customFields,
            $aliases,
            $references,
            $execution,
            $preferences,
            $access
        );
        $capabilities = new UnifiedQueryCapabilityServices(
            $registry,
            $customFields,
            $aliases,
            $exports,
            $preferences,
            $access
        );
        $commandCoordinator = new UnifiedQueryCommandCoordinator(
            $registry,
            $preferences,
            $aliases,
            $customFields,
            $exports
        );
        $contextFactory = new UnifiedQueryContextFactory($registry, $permissionResolvers);

        $core = compact(
            'registry',
            'permissionResolvers',
            'access',
            'validator',
            'evaluator',
            'references',
            'customFields',
            'execution',
            'preferences',
            'aliases',
            'exports',
            'capabilities',
            'commandCoordinator',
            'contextFactory'
        );
        self::bindServices($core);

        $providers = new UnifiedQueryProviderRegistry($registry);
        foreach (self::configuredClasses('providers', []) as $className) {
            $provider = app()->make($className);
            if (!$provider instanceof UnifiedQueryProvider) {
                throw new \LogicException(
                    '统一查询 provider 未实现 UnifiedQueryProvider：' . $className
                );
            }
            $providers->register($provider);
        }
        $providers->freeze();

        $workerContextResolvers = new UnifiedQueryWorkerContextResolverRegistry($registry);
        foreach (self::configuredClasses('worker_context_resolvers', []) as $className) {
            $resolver = app()->make($className);
            if (!$resolver instanceof UnifiedQueryWorkerContextResolver) {
                throw new \LogicException(
                    '统一查询 Worker context resolver 未实现 UnifiedQueryWorkerContextResolver：'
                    . $className
                );
            }
            $workerContextResolvers->register($resolver);
        }
        $workerContextResolvers->freeze();
        $exportStorage = new UnifiedQueryExportStorage();

        self::$runtime = array_merge($core, compact(
            'providers',
            'workerContextResolvers',
            'exportStorage'
        ));
        self::bindServices(self::$runtime);
        return self::$runtime;
    }

    public static function service(string $key)
    {
        $runtime = self::runtime();
        if (!isset($runtime[$key])) {
            throw new \InvalidArgumentException('未知统一查询服务：' . $key);
        }
        return $runtime[$key];
    }

    protected static function configuredClasses(string $key, array $default): array
    {
        $configured = config('unified_query.' . $key);
        if ($configured === null) {
            return $default;
        }
        if (!is_array($configured)) {
            throw new \LogicException('统一查询配置必须是类名数组：' . $key);
        }
        $classes = [];
        foreach ($configured as $className) {
            $className = trim((string)$className);
            if ($className === '' || !class_exists($className)) {
                throw new \LogicException('统一查询配置类不存在：' . $className);
            }
            if (isset($classes[$className])) {
                throw new \LogicException('统一查询配置类重复：' . $className);
            }
            $classes[$className] = true;
        }
        return array_keys($classes);
    }

    protected static function bindServices(array $services): void
    {
        $app = app();
        if (!is_object($app) || !method_exists($app, 'instance')) {
            return;
        }
        foreach ($services as $service) {
            if (is_object($service)) {
                $app->instance(get_class($service), $service);
            }
        }
    }
}
