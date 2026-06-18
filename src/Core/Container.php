<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Core;

use Framework\Core\LazyLoadingProxy;

/**
 * 高性能 IoC 容器（兼容 PHP 7.2 – 8.3）
 *
 * 关键优化
 * 1.  O(1) 循环依赖检测（$buildingMap）
 * 2.  反射 + 参数元数据缓存（$reflectionCache）
 * 3.  运行期工厂闭包缓存（$factoryCache）——彻底摆脱二次反射
 * 4.  避免不必要的 array_merge
 * 5.  单例只保存已初始化对象
 * 6.  OPcache 预加载友好
 *
 * 实时加载：核心基础设施 -> 路由、数据库连接、配置
 * 延迟加载​:高开销服务 -> 第三方 API 客户端、报表生成
 * 延迟加载​:按需使用的业务服务 -> Session、身份验证、邮件发送
 * 用法保持与原版一致：setParams() / bind() / set() / get()
 */
class Container
{
    /** @var array 用于当次请求内的静态缓存 */
    protected $instances = [];
    /** @var array 全局默认参数 */
    protected $params = [];
    /** @var array [abstract => ['concrete'=>…, 'singleton'=>…]] */
    protected $bindings = [];
    /** @var array 别名 => 真实类名 */
    protected $aliases = [];

    /** @var array O(1) 检测循环依赖 */
    protected $buildingMap = [];

    /** @var array [class => [ReflectionClass, paramMeta]] */
    protected static $reflectionCache = [];
    /** @var array [class => Closure] */
    protected static $factoryCache = [];

    /** @var array 编译后的定义缓存（生产环境使用） */
    protected $compiledDefinitions = [];

    /* ---------- 配置 ---------- */
    // 设置全局默认参数，供构造函数标量参数使用。
    public function setParams(array $params): void{
        $this->params = $params;
    }

    public function alias(string $alias, string $concrete): void{
        $this->aliases[$alias] = $concrete;
    }

    /**
     * 支持绑定类名、闭包工厂，控制单例/多例和延迟加载。
     * @param string $abstract 抽象类名或接口名
     * @param bool $singleton 单例true ，多例false(每次获取都是新实例)
     * @param bool $defer defer=true服务延迟加载($concrete 具体类名，$singleton 必须是true)
     * @return void
     */
    public function bind(string $abstract, $concrete, bool $singleton = true, bool $defer = false)
    {
        $abstract = $this->resolveAlias($abstract);

        if (true === $defer && is_string($concrete) && class_exists($concrete)) {
            $deferredId = 'deferred:' . $abstract;
            $classToBuild = $concrete;

            // 1. 绑定真实实现到隐藏 ID。
            // 将其也标记为 defer => true，确保 preResolve() 会跳过它，避免在 bootstrap 阶段触发未就绪的依赖（如 Request）
            $this->bindings[$deferredId] = [
                'concrete' => function ($container, $params) use ($classToBuild) {
                    return $container->make($classToBuild, $params);
                },
                'singleton' => $singleton,
                'defer' => true
            ];

            // 2. 原始 ID 绑定代理
            $concrete = function ($container, $params) use ($deferredId) {
                return new LazyLoadingProxy($container, $deferredId);
            };
            $singleton = true; // 代理对象必须是单例
        }

        $this->bindings[$abstract] = [
            'concrete' => $concrete,
            'singleton' => $singleton,
            'defer' => $defer
        ];

        if (true !== $singleton) unset($this->instances[$abstract]);
    }

    // 直接注册实例到容器（如配置对象），只支持实例，不支持闭包。无依赖或单一依赖直接实例化，实例化前需要提前注册依赖
    public function set(string $class, $object): void{
        $class = $this->resolveAlias($class);
        $this->instances[$class] = $object;
    }

    /**
     * 加载预编译的类定义（生产环境优化）
     */
    public function loadDefinitions(array $definitions): void{
        $this->compiledDefinitions = $definitions;
    }

    /**
     * 判断服务是否可用（已绑定、已实例化 或 类存在可自动解析）
     * 符合 PSR-11 语义
     * @param string $id
     * @return bool
     */
    public function has(string $id): bool
    {
        if ($id === self::class || $id === Container::class) {
            return true;
        }

        $id = $this->resolveAlias($id);

        return isset($this->instances[$id])
            || isset($this->bindings[$id])
            || class_exists($id);
    }

    public function getInstances(): array
    {
        return $this->instances;
    }

    /**
     * 自动解析类的构造函数依赖，支持递归依赖解析。
     * @param string $class 要解析的类名或别名
     * @param array $params 临时参数（覆盖全局参数）
     * @return mixed
     */
    public function get($class, array $params = [])
    {
        if ($class === self::class || $class === Container::class) return $this;

        // 单例直接返回
        if (isset($this->instances[$class])) return $this->instances[$class];

        // 处理绑定
        if (isset($this->bindings[$class])) {
            $binding   = $this->bindings[$class];
            $concrete  = $binding['concrete'];
            $singleton = $binding['singleton'];

            // 修复递归死循环：只有当 concrete 不同于 class 时才进行转向解析
            if ($concrete !== $class) {
                $object = \is_string($concrete)
                    ? $this->get($concrete, $params) // 转向具体实现
                    : $concrete($this, $params + $this->params); // 工厂闭包

                if ($singleton) $this->instances[$class] = $object;
                return $object;
            }
        }

        return $this->make($class, $params);
    }

    /**
     * 该方法跳过 bindings 和 instances 缓存，直接根据类名进行实例化并处理依赖。
     * 它是避免 LazyLoadingProxy 递归死循环的核心。
     */
    public function make(string $class, array $params = [])
    {
        // 解析别名
        $class = $this->resolveAlias($class);

        // 循环依赖检测（O(1)）
        if (isset($this->buildingMap[$class])) {
            throw new \RuntimeException("Circular dependency: $class");
        }

        $this->buildingMap[$class] = true;

        try {
            // 优先使用编译缓存
            if (isset($this->compiledDefinitions[$class])) {
                $object = $this->buildFromDefinition($class, $this->compiledDefinitions[$class], $params);
            } else {
                // 回退到反射构建（开发环境或未缓存类）
                if (!isset(self::$factoryCache[$class])) {
                    self::$factoryCache[$class] = $this->buildFactory($class);
                }
                $object = self::$factoryCache[$class]($this, $params);
            }
        } finally {
            unset($this->buildingMap[$class]);
        }

        // 生成 / 调用工厂闭包（无反射热路径）
        /* if (!isset(self::$factoryCache[$class])) {
            self::$factoryCache[$class] = $this->buildFactory($class);
        }

        $object = self::$factoryCache[$class]($this, $params);

        unset($this->buildingMap[$class]); */

        // 是否存入单例缓存
        $isSingleton = isset($this->bindings[$class]) ? (bool)$this->bindings[$class]['singleton'] : true;
        if ($isSingleton) {
            $this->instances[$class] = $object;
        }

        return $object;
    }

    public function clear(): void{
        $this->instances   = [];
        $this->buildingMap = [];
    }

    // 预解析所有非延迟绑定的服务，提前暴露依赖问题。
    public function preResolve(): void
    {
        foreach ($this->bindings as $abstract => $binding) {
            if ($binding['defer']) continue;
            if (!$binding['defer']) {
                if (!is_string($binding['concrete']) && !is_callable($binding['concrete'])) continue;
                try {
                    $this->get($abstract);
                } catch (\Throwable $e) {
                    throw new \RuntimeException(
                        'Failed to preparse service `{' . $abstract . '}`: ' . $e->getMessage()
                    );
                }
            }
        }
    }

    // 从编译定义构建对象（无反射，极速）
    protected function buildFromDefinition(string $class, array $def, array $callParams)
    {
        // $def 结构: ['ctor' => [['dep'=>'ClassA'], ['name'=>'id','def'=>0]]]
        $args = [];
        $mergedParams = $this->params + $callParams;

        foreach ($def['ctor'] as $meta) {
            if (isset($meta['dep'])) {
                $args[] = $this->get($meta['dep']);
            } else {
                $key = $meta['name'];
                if (array_key_exists($key, $mergedParams)) {
                    $args[] = $mergedParams[$key];
                } elseif (array_key_exists('def', $meta)) {
                    $args[] = $meta['def'];
                } else {
                    $args[] = null; // or throw
                }
            }
        }

        return new $class(...$args);
    }

    // 导出类定义用于缓存 (供 Compile.php 使用)
    public function getReflectionDefinition(string $class): ?array
    {
        if (!class_exists($class)) return null;
        $rc = new \ReflectionClass($class);
        $ctor = $rc->getConstructor();
        $params = [];
        if ($ctor) {
            foreach ($ctor->getParameters() as $p) {
                $type = $p->getType();
                if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                    $params[] = ['dep' => $type->getName()];
                } else {
                    $params[] = [
                        'name' => $p->getName(),
                        'def' => $p->isDefaultValueAvailable() ? $p->getDefaultValue() : null
                    ];
                }
            }
        }
        return ['ctor' => $params];
    }

    /* ---------- 内部：工厂闭包生成 ---------- */

    protected function resolveAlias(string $id): string
    {
        return $this->aliases[$id] ?? $id;
    }

    protected function buildFactory(string $class): \Closure
    {
        $class = $this->resolveAlias($class);

        /* A) 反射缓存 */
        if (!isset(self::$reflectionCache[$class])) {
            if (!\class_exists($class)) throw new \InvalidArgumentException("Class not found: $class");

            $rc   = new \ReflectionClass($class);
            $ctor = $rc->getConstructor();

            $paramMeta = [];
            if ($ctor) {
                foreach ($ctor->getParameters() as $p) {
                    $type = $p->getType();
                    if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                        $paramMeta[] = ['dep' => $type->getName()]; // 依赖注入
                    } else {
                        $paramMeta[] = [
                            'name'       => $p->getName(),
                            'hasDefault' => $p->isDefaultValueAvailable(),
                            'def'        => $p->isDefaultValueAvailable() ? $p->getDefaultValue() : null,
                            'nullable'   => $type && $type->allowsNull(),
                        ];
                    }
                }
            }
            self::$reflectionCache[$class] = [$rc, $paramMeta];
        }

        [$reflector, $paramMeta] = self::$reflectionCache[$class];

        /* B) 返回闭包：执行期只做纯 PHP 运算，不再触碰反射对象 */
        return static function (Container $container, array $callParams) use ($reflector, $paramMeta, $class): object {
            /* 合并参数：callParams 优先 */
            $merged = $container->params;
            foreach ($callParams as $k => $v) {
                $merged[$k] = $v;
            }

            // 无参数
            if (empty($paramMeta)) return $reflector->newInstance();

            $args = [];
            foreach ($paramMeta as $meta) {
                if (isset($meta['dep'])) {
                    // 依赖注入
                    $args[] = $container->get($meta['dep']);
                } else {
                    // 标量参数
                    $key = $meta['name'];
                    if (\array_key_exists($key, $merged)) {
                        $args[] = $merged[$key];
                    } elseif ($meta['hasDefault']) {
                        $args[] = $meta['def'];
                    } elseif ($meta['nullable']) {
                        $args[] = null;
                    } else {
                        throw new \RuntimeException(
                            "Cannot resolve \${$key} for {$reflector->getName()}. " .
                                "You can pass it via get('$class', ['{$key}' => ...]) or setParams(['{$key}' => ...])"
                        );
                    }
                }
            }

            return $reflector->newInstanceArgs($args);
        };
    }
}

/*
【bind】:
$container = new Container();

// 绑定接口到实现类（单例）
$container->bind(DatabaseInterface::class, MySQLDatabase::class);

// 绑定闭包工厂（多例）
$container->bind(Logger::class, function ($c) {
    return new FileLogger($c->get('logPath'));
}, false);

// 绑定 Database 为延迟加载
$container->bind(Database::class, Database::class, true, true);

// 预解析非延迟服务，如果依赖缺失，此处抛出异常。
$container->preResolve();

// 首次调用返回代理对象（未实际创建 Database）
$databaseProxy = $container->get(Database::class);

// 调用方法时触发真实对象创建
$databaseProxy->query('SELECT * FROM users');

【代理模式验证】​:
// 绑定接口到实现并延迟加载
$container->bind(UserRepositoryInterface::class, UserRepository::class, true, true);

// 获取代理对象
$userRepo = $container->get(UserRepositoryInterface::class);

// 实际调用时创建 UserRepository 实例
$users = $userRepo->getAllUsers();

// ----------全局参数配置-----------
// 全局参数配置
$container->setParams([
    'dbHost' => 'localhost',
    'dbUser' => 'root'
]);

// 绑定带参数的类
$container->bind(Database::class, function ($c, $params) {
    return new MySQLDatabase(
        $params['dbHost'] ?? 'default_host',
        $params['dbUser'] ?? 'default_user'
    );
});

【get】
$db = $container->get(DatabaseInterface::class);
$logger = $container->get(Logger::class, ['logPath' => '/tmp/logs']);

*/

/*
直接给你**极简且实战的用法说明**，带典型场景举例：

---

## 1. `setParams(array $params)`

**作用：**
设置全局默认参数，供后续实例化有“标量参数”的类用。
**场景：**
有些类的构造方法不是全靠依赖注入，还要传业务参数。

**用法：**

```php
$container->setParams([
    'db_host' => '127.0.0.1',
    'db_user' => 'root',
]);
```

> 一般用于**全局配置/常量注入**。

---

## 2. `bind($abstract, $concrete, $singleton = true)`

**作用：**
把接口或抽象（或逻辑名）绑定到具体实现。
**场景：**

* 依赖接口而不是实现
* 需要随时替换实现（比如mock、切换不同服务）
* 支持工厂闭包绑定

**用法：**

```php
// 绑定接口到类（单例模式，默认）
$container->bind(LoggerInterface::class, FileLogger::class);

// 绑定字符串别名
$container->bind('cache', RedisCache::class);

// 原型绑定（每次get都新new出来）
$container->bind('notifier', function($c, $params) { return new Notifier($params['type']); }, false);
```

> 一般用于**依赖倒置、工厂、mock切换**。

---

## 3. `set($class, $object)`

**作用：**
直接设置单例实例（对象已经创建好），之后get直接返回，不再new。
**场景：**

* 单例模式（如日志、数据库连接）
* 需提前配置好的实例

**用法：**

```php
$logger = new Logger('/tmp/a.log');
$container->set(Logger::class, $logger);
```

> 用于**全局单例实例注入**。

---

## 4. `get($class, $params = [])`

**作用：**
获取实例（自动注入），支持临时参数传递。
**场景：**

* 正常依赖注入
* 需要对某次实例化临时传参（覆盖全局）

**用法：**

```php
// 获取带递归依赖注入的对象
$db = $container->get(MyDb::class);

// 临时传参，覆盖setParams
$mailer = $container->get(Mailer::class, ['smtp_user' => 'xxx']);
```

> 用于**获取所有对象、实现所有自动注入逻辑**。

---

## 5. `clear()`

**作用：**
清除所有单例实例和构建栈。
**场景：**

* 测试环境（重置容器）
* 应用热重载/worker模式

**用法：**

```php
$container->clear();
```

> 用于**测试/重启/资源释放**。

---

### 场景梳理总结表（中英双语版）

| 方法        | 何时用/举例            | 用法片段                                | 备注           |
| --------- | ----------------- | ----------------------------------- | ------------ |
| setParams | 有“基础参数”全局注入       | `$c->setParams(['x'=>1])`           | 业务常量、配置项     |
| bind      | 接口->实现、工厂、mock、原型 | `$c->bind('id', Foo::class, false)` | 依赖倒置、每次new实例 |
| set       | 单例对象、已构造实例        | `$c->set(Foo::class, $fooObj)`      | 全局单例         |
| get       | 获取对象、自动依赖注入、临时传参  | `$c->get(Foo::class, ['x'=>2])`     | 推荐主要入口       |
| clear     | 重置容器（测试/重启）       | `$c->clear()`                       | 单测/worker等场景 |

---

一句话：
**一般业务只用`get`和`bind`，单例用`set`，参数全局用`setParams`，需要清理用`clear`，全都够用！**

---

实时加载：核心基础设施 -> 路由、数据库连接、配置
延迟加载​:高开销服务 -> 第三方 API 客户端、报表生成
延迟加载​:按需使用的业务服务 -> Session、身份验证、邮件发送

*/
