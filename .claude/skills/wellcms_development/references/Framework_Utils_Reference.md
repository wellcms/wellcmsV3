# WellCMS 3.0 框架工具类参考手册

**文档用途**: 编写插件时优先查找主程序已实现工具方法，避免重复造轮子  
**最后更新**: 2026-04-15

---

## 📋 目录索引

| 命名空间 | 路径 | 工具类数量 |
|----------|------|------------|
| `Framework\Utils` | `src/Utils/` | 16个 |
| `App\Utils` | `app/Utils/` | 5个 |

---

## 一、Framework\Utils (src/Utils/)

### 1. ArrayHelper - 数组处理
**文件**: `src/Utils/ArrayHelper.php`

| 方法 | 功能描述 | 使用示例 |
|------|----------|----------|
| `findIdByFast()` | 通过值快速查找ID | `ArrayHelper::findIdByFast($users, 'Alice', 'name', 'id')` |
| `multiSortKey()` | 二维数组多列排序（可指定索引键） | `ArrayHelper::multiSortKey($list, 'rank', true, 'id')` |
| `changeKey()` | 更改二维数组键名 | `ArrayHelper::changeKey($list, 'user_id')` |
| `pagination()` | 二维数组分页 | `ArrayHelper::pagination($list, 1, 20)` |
| `rankKey()` | 二维数组整理为一维关联数组 | `ArrayHelper::rankKey($arr, 'rank', 'key', 'value')` |
| `unique()` | 移除二维数组中重复的值 | `ArrayHelper::unique($array2D, true, true)` |
| `merge()` | 合并二维数组（重复以第一个数组为准） | `ArrayHelper::merge($array1, $array2, 'id')` |
| `sortKey()` | 按相同键值对两个数组排序 | `ArrayHelper::sortKey($array1, $array2, 'id')` |
| `value()` | 根据键查找数组中的值 | `ArrayHelper::value($arr, 'key', 'default')` |
| `htmlspecialchars()` | 多维数组中值为预定义字符串编码为HTML实体 | `ArrayHelper::htmlspecialchars($var, 2)` |
| `multiSort()` | 对多维数组按指定键排序 | `ArrayHelper::multiSort($list, 'id', true)` |
| `assocSlice()` | 取一维或二维数组指定数量数据并保持排序 | `ArrayHelper::assocSlice($list, 0, 10)` |
| `arrayListConditionOrderBy()` | 对数组进行查找、排序、筛选 | `ArrayHelper::arrayListConditionOrderBy($list, $cond, $orderby, 1, 20)` |
| `arrayListValues()` | 从二维数组中取 key=>value 格式一维数组 | `ArrayHelper::arrayListValues($list, 'name', 'id')` |
| `sum()` | 从二维数组中对某一列求和 | `ArrayHelper::sum($list, 'amount')` |
| `max()` | 从二维数组中对某一列求最大值 | `ArrayHelper::max($list, 'score')` |
| `min()` | 从二维数组中对某一列求最小值 | `ArrayHelper::min($list, 'score')` |
| `arrayListChangeKey()` | 将 key 更换为某一列的值 | `ArrayHelper::arrayListChangeKey($list, 'id')` |
| `arrayForeach()` | 将二维数组合并为一个数组 | `ArrayHelper::arrayForeach($list, 'name')` |
| `arrayListKeyLower()` | 将数组键值转化为小写 | `ArrayHelper::arrayListKeyLower($arr)` |
| `arrayListKeyUpper()` | 将数组键值转化为大写 | `ArrayHelper::arrayListKeyUpper($arr)` |
| `arraySort()` | 通过数组列进行排序 | `ArrayHelper::arraySort($list, 'age', SORT_ASC, SORT_REGULAR)` |
| `keepKeys()` | 保留二维数组指定的键值 | `ArrayHelper::keepKeys($list, ['id', 'name'])` |
| `chunk()` | 根据某一列的值进行组块 | `ArrayHelper::chunk($list, 'category_id')` |

---

### 2. DirectoryHelper - 目录操作
**文件**: `src/Utils/DirectoryHelper.php`

| 方法 | 功能描述 | 使用示例 |
|------|----------|----------|
| `getOneLevel()` | 获取一级子目录 | `DirectoryHelper::getOneLevel('/tmp')` |
| `globRecursive()` | 递归glob匹配 | `DirectoryHelper::globRecursive('/tmp/*.txt')` |
| `mkdir()` | 创建目录 | `DirectoryHelper::mkdir('/tmp/test', 0755, true)` |
| `rmdir()` | 删除空目录 | `DirectoryHelper::rmdir('/tmp/test')` |
| `rmdirRecursive()` | 递归删除目录 | `DirectoryHelper::rmdirRecursive('/tmp/test', true)` |
| `setDir()` | 根据ID创建分层目录 | `DirectoryHelper::setDir(123, '/upload')` |
| `getDir()` | 获取ID分层路径 | `DirectoryHelper::getDir(123)` → `000/000/123` |
| `copyRecursive()` | 递归复制目录 | `DirectoryHelper::copyRecursive('/src', '/dst')` |
| `isWritable()` | 检查是否可写（兼容Windows） | `DirectoryHelper::isWritable('/tmp/file')` |

**⚠️ 安全特性**: `rmdirRecursive()` 禁止删除系统根目录和当前工作目录

---

### 3. ZipUtility - ZIP 压缩/解压
**文件**: `src/Utils/ZipUtility.php`

| 方法 | 功能描述 | 使用示例 |
|------|----------|----------|
| `zip()` | 创建ZIP压缩包 | `$zip->zip('/output.zip', '/source/dir')` |
| `unzip()` | 解压ZIP文件 | `$zip->unzip('/file.zip', '/output/dir')` |

**特性**:
- 自动检测 `ZipArchive` 扩展，不可用时降级到纯 PHP 实现
- 自动扁平化同名双层目录
- 支持 PHP 7.2+ 无依赖

---

### 4. FileHelper - 文件操作
**文件**: `src/Utils/FileHelper.php`

| 方法 | 功能描述 | 使用示例 |
|------|----------|----------|
| `lock()` | 基于$key创建独占文件锁 | `FileHelper::lock('key', 30)` |
| `unlock()` | 释放与$key关联的锁文件 | `FileHelper::unlock('key')` |
| `isLocked()` | 检查锁是否有效 | `FileHelper::isLocked('key')` |
| `fileLock()` | 通过临时锁文件执行回调函数 | `FileHelper::fileLock($callback, 10)` |
| `fileReplaceVar()` | 动态替换文件中的变量（PHP/JSON/JS） | `FileHelper::fileReplaceVar($file, $replace, true)` |
| `fileBackname()` | 获取文件备份名 | `FileHelper::fileBackname('/file.txt')` |
| `isBackfile()` | 判断是否为备份文件 | `FileHelper::isBackfile('/file.txt')` |
| `fileBackup()` | 创建文件的备份副本 | `FileHelper::fileBackup('/file.txt')` |
| `fileBackupRestore()` | 用备份文件还原原文件 | `FileHelper::fileBackupRestore('/file.txt')` |
| `fileBackupUnlink()` | 删除指定文件的备份 | `FileHelper::fileBackupUnlink('/file.txt')` |
| `fileGetContentsTry()` | 多次尝试安全读取文件 | `FileHelper::fileGetContentsTry($file, 3)` |
| `filePutContentsTry()` | 多次尝试安全写入文件 | `FileHelper::filePutContentsTry($file, $data, 3)` |
| `fileExt()` | 安全获取文件扩展名 | `FileHelper::fileExt($filename, 16)` |
| `fileName()` | 获取无扩展名的文件名 | `FileHelper::fileName('/path/file.txt')` |
| `copy()` | 封装copy函数 | `FileHelper::copy($src, $dest)` |
| `unlink()` | 封装unlink函数 | `FileHelper::unlink($file)` |
| `fileMtime()` | 获取文件修改时间 | `FileHelper::fileMtime($file)` |

---

### 5. IpHelper - IP 地址处理
**文件**: `src/Utils/IpHelper.php`

| 方法 | 功能描述 | 使用示例 |
|------|----------|----------|
| `ip()` | 获取客户端IP | `IpHelper::ip()` |
| `validIp()` | 验证IP地址格式 | `IpHelper::validIp($ip)` |
| `isPrivate()` | 检查是否为私有地址范围 | `IpHelper::isPrivate($ip)` |
| `normalizeIp()` | 规范化IP（支持IPv6） | `IpHelper::normalizeIp($ip)` |
| `bin2ip()` | 二进制转IP | `IpHelper::bin2ip($bin)` |
| `ip2bin()` | IP转二进制 | `IpHelper::ip2bin($ip)` |
| `parseCidrRange()` | 解析CIDR格式网段 | `IpHelper::parseCidrRange('192.168.1.0/24')` |
| `detect()` | 检测IP格式 | `IpHelper::detect($ip)` |
| `longIp()` | IPv4/IPv6转整型 | `IpHelper::longIp('127.0.0.1')` |
| `safeLong2ip()` | 整型转IP | `IpHelper::safeLong2ip($longIp)` |
| `maskIp()` | IP脱敏（IPv4） | `IpHelper::maskIp($ip)` |
| `maskIpv6()` | IPv6脱敏 | `IpHelper::maskIpv6($ipv6)` |
| `getIpVersion()` | 获取IP版本 | `IpHelper::getIpVersion($ip)` |
| `setContextIp()` | 设置上下文IP | `IpHelper::setContextIp($ip)` |
| `setContextUa()` | 设置上下文UA | `IpHelper::setContextUa($ua)` |
| `userAgent()` | 获取User-Agent | `IpHelper::userAgent()` |
| `setContextHost()` | 设置上下文Host | `IpHelper::setContextHost($host)` |
| `host()` | 获取HTTP_HOST | `IpHelper::host()` |
| `setContextLang()` | 设置上下文语言 | `IpHelper::setContextLang($lang)` |
| `acceptLanguage()` | 获取Accept-Language | `IpHelper::acceptLanguage()` |
| `setContextScheme()` | 设置上下文Scheme | `IpHelper::setContextScheme($scheme)` |
| `scheme()` | 获取请求协议 | `IpHelper::scheme()` |
| `setContextPort()` | 设置上下文端口 | `IpHelper::setContextPort($port)` |
| `port()` | 获取请求端口 | `IpHelper::port()` |
| `setContextScript()` | 设置上下文脚本名 | `IpHelper::setContextScript($script)` |
| `scriptName()` | 获取脚本名 | `IpHelper::scriptName()` |

---

### 6. SecurityHelper - 安全加密
**文件**: `src/Utils/SecurityHelper.php`

| 方法 | 功能描述 | 使用示例 |
|------|----------|----------|
| `forceUtf8()` | 强制把字符串/数组转为UTF-8 | `SecurityHelper::forceUtf8($data, 'GBK')` |
| `urlencode()` | 安全URL编码 | `SecurityHelper::urlencode($url)` |
| `urldecode()` | 安全URL解码 | `SecurityHelper::urldecode($url)` |
| `jsonEncode()` | 数组转JSON | `SecurityHelper::jsonEncode($data, true)` |
| `jsonDecode()` | JSON转数组 | `SecurityHelper::jsonDecode($json)` |
| `removeJsonComments()` | 移除JSON中的注释 | `SecurityHelper::removeJsonComments($jsonString)` |
| `base64DecodeFileData()` | 解码客户端提交的base64数据 | `SecurityHelper::base64DecodeFileData($data)` |
| `codeConversion()` | 字符串编码转换 | `SecurityHelper::codeConversion($str, 'utf-8', 'gbk')` |
| `generateToken()` | 生成Token | `SecurityHelper::generateToken($key, $data)` |
| `decodeToken()` | 解密Token | `SecurityHelper::decodeToken($key, $token)` |
| `base64UrlEncode()` | Base64 URL安全编码 | `SecurityHelper::base64UrlEncode($string)` |
| `base64UrlDecode()` | Base64 URL安全解码 | `SecurityHelper::base64UrlDecode($string)` |
| `encrypt()` | AES加密数据 | `SecurityHelper::encrypt($data, $key, 'AES-256-CBC')` |
| `decrypt()` | AES解密数据 | `SecurityHelper::decrypt($data, $key, 'AES-256-CBC')` |

---

### 7. UrlHelper - URL 处理
**文件**: `src/Utils/UrlHelper.php`

| 方法 | 功能描述 | 使用示例 |
|------|----------|----------|
| `urlAddArg()` | 将参数添加到URL | `UrlHelper::urlAddArg($url, 'k', 'v')` |
| `jump()` | 高级安全跳转逻辑 | `UrlHelper::jump('提示', $url, 3)` |
| `isValidSlug()` | 验证SEO Slug是否符合规范 | `UrlHelper::isValidSlug($slug)` |

---

### 8. CookieHelper - Cookie 操作
**文件**: `src/Utils/CookieHelper.php`

| 方法 | 功能描述 | 使用示例 |
|------|----------|----------|
| `set()` | 下发或清除Cookie | `CookieHelper::set('name', 'value', $expiry, $config)` |

**特性**:
- 兼容 PHP 7.2+ 以及 Swoole 协程环境
- 支持 SameSite 属性

---

### 9. UuidHelper - UUID 生成
**文件**: `src/Utils/UuidHelper.php`

| 方法 | 功能描述 | 使用示例 |
|------|----------|----------|
| `generate()` | 生成UUID v7（时间有序） | `UuidHelper::generate(true)` |
| `toBinary()` | UUID字符串转二进制 | `UuidHelper::toBinary($uuid)` |
| `fromBinary()` | UUID二进制转字符串 | `UuidHelper::fromBinary($binary)` |

---

### 10. HttpClient - HTTP 客户端
**文件**: `src/Utils/HttpClient.php`

| 方法 | 功能描述 | 使用示例 |
|------|----------|----------|
| `request()` | 通用HTTP请求 | `$client->request(['url'=>$url, 'method'=>'GET'])` |
| `multiGet()` | 并发GET请求 | `$client->multiGet([$url1, $url2], $options)` |
| `addSupportedMethod()` | 动态扩展支持的HTTP方法 | `HttpClient::addSupportedMethod('COPY')` |

**异常类**:
- `HttpClientException`
- `HttpClientNetworkException`
- `HttpClientHttpException`
- `HttpClientMultiException`

---

### 11. SafeHelper - 安全过滤
**文件**: `src/Utils/SafeHelper.php`

| 方法 | 功能描述 | 使用示例 |
|------|----------|----------|
| `safeWord()` | 安全过滤字符串，仅保留[a-zA-Z0-9_] | `SafeHelper::safeWord($s, 32)` |
| `filterKeyword()` | 过滤敏感词 | `SafeHelper::filterKeyword($arr, $keyword)` |
| `txtToHtml()` | txt转换到html | `SafeHelper::txtToHtml($s)` |
| `brToChars()` | html换行转换为\r\n | `SafeHelper::brToChars($data)` |
| `filterAllHtml()` | 过滤全部html标签 | `SafeHelper::filterAllHtml($text, true)` |
| `filterHtml()` | 过滤html标签并保留允许的标签 | `SafeHelper::filterHtml($text, '<p><br>')` |
| `makeTag()` | 从任意语言字符串创建tag/slug | `SafeHelper::makeTag('中文 标签', ['ascii'=>true])` |
| `makeHashtag()` | 生成hashtag风格的tag | `SafeHelper::makeHashtag('Café', ['style'=>'camel'])` |
| `filterStr()` | 过滤emoji | `SafeHelper::filterStr($str)` |
| `maskEmail()` | 邮箱脱敏 | `SafeHelper::maskEmail($email)` |
| `isValidPassword()` | 验证密码强度 | `SafeHelper::isValidPassword($password)` |
| `randomSixDigit()` | 生成6位随机数 | `SafeHelper::randomSixDigit()` |
| `randomStr()` | 生成随机长度字符串 | `SafeHelper::randomStr(16, false)` |
| `uniqid()` | 生成唯一身份ID | `SafeHelper::uniqid()` |
| `inString()` | 判断字符串是否在逗号分隔的字符串中 | `SafeHelper::inString($s, $str)` |
| `generateId()` | 生成高熵任务ID | `SafeHelper::generateId($context)` |
| `filterUgcLinks()` | 过滤UGC外链 | `SafeHelper::filterUgcLinks($html, $whitelist)` |

---

### 12. FormatHelper - 格式化
**文件**: `src/Utils/FormatHelper.php`

| 方法 | 功能描述 | 使用示例 |
|------|----------|----------|
| `humanDate()` | 人性化时间戳 | `FormatHelper::humanDate($timestamp, $lang)` |
| `formatNumber()` | 格式化数字为1K+/1M+ | `FormatHelper::formatNumber(1234567)` |
| `humanSize()` | 格式化文件大小 | `FormatHelper::humanSize(1024)` |

---

### 13. LinkHelper - 链接处理
**文件**: `src/Utils/LinkHelper.php`

| 方法 | 功能描述 | 使用示例 |
|------|----------|----------|
| `encode62()` | 数字转62进制 | `LinkHelper::encode62(12345)` |
| `decode62()` | 62进制转数字 | `LinkHelper::decode62('abc')` |
| `makeSlug()` | 生成带签名的短Slug | `LinkHelper::makeSlug($id, $ts, $salt)` |
| `parseSlug()` | 解析Slug并验证签名 | `LinkHelper::parseSlug($slug, $salt)` |

---

### 14. FileHasher - 文件哈希
**文件**: `src/Utils/FileHasher.php`

| 方法 | 功能描述 | 使用示例 |
|------|----------|----------|
| `hash()` | 计算文件哈希 | `FileHasher::hash('/file.txt', 'sha256', 'hex')` |
| `sha256()` | 计算SHA256（快捷方法） | `FileHasher::sha256('/file.txt', 'hex')` |

**常量**:
- `ALGORITHM_SHA256`, `ALGORITHM_SHA1`, `ALGORITHM_MD5`
- `FORMAT_RAW`, `FORMAT_HEX`, `FORMAT_BASE64`

---

### 15. Runtime - 运行时信息
**文件**: `src/Utils/Runtime.php`

| 方法 | 功能描述 | 使用示例 |
|------|----------|----------|
| `getPidFile()` | 获取PID文件路径 | `Runtime::getPidFile()` |
| `setPidFile()` | 设置PID文件路径 | `Runtime::setPidFile($path)` |
| `reload()` | 触发热重载信号(SIGUSR1) | `Runtime::reload()` |
| `isSwoole()` | 判断是否处于Swoole环境 | `Runtime::isSwoole()` |
| `inCoroutine()` | 判断是否处于协程内 | `Runtime::inCoroutine()` |

---

### 16. Validator - 数据验证
**文件**: `src/Utils/Validator.php`

| 方法 | 功能描述 | 使用示例 |
|------|----------|----------|
| `make()` | 静态创建并执行验证 | `Validator::make($data, $rules, $messages, $db)` |
| `validate()` | 批量验证数据 | `$validator->validate($data)` |
| `validateField()` | 验证单个字段 | `$validator->validateField($value, 'required\|email')` |
| `getErrors()` | 获取所有错误 | `$validator->getErrors()` |
| `getErrorMessage()` | 获取错误消息字符串 | `$validator->getErrorMessage()` |
| `setRules()` | 设置规则 | `$validator->setRules($rules)` |
| `setMessages()` | 设置自定义错误消息 | `$validator->setMessages($messages)` |
| `setDatabase()` | 设置数据库连接 | `$validator->setDatabase($db)` |
| `getGcCallCount()` | 获取GC调用统计 | `Validator::getGcCallCount()` |
| `resetGcCallCount()` | 重置GC调用计数 | `Validator::resetGcCallCount()` |

**支持规则**: `required`, `string`, `int`/`integer`, `numeric`, `email`, `url`, `min`, `max`, `between`, `array`, `bool`, `regex`, `date`, `ip`, `uuid`, `mime`, `fileSize`, `unique`, `exists`

---

## 二、App\Utils (app/Utils/)

### 1. I18nDateFormatter - 国际化日期
**文件**: `app/Utils/I18nDateFormatter.php`

| 方法 | 功能描述 | 使用示例 |
|------|----------|----------|
| `registerFormats()` | 注册/覆盖某个locale的格式 | `I18nDateFormatter::registerFormats('es_MX', $dateF, $timeF)` |
| `format()` | 格式化日期时间 | `$formatter->format($datetime, 'long', 'short')` |
| `setTimezone()` | 设置时区 | `$formatter->setTimezone('Asia/Shanghai')` |
| `getTimezone()` | 获取时区 | `$formatter->getTimezone()` |
| `setLang()` | 设置语言 | `$formatter->setLang('zh-CN')` |
| `getLocale()` | 获取当前locale | `$formatter->getLocale()` |

**格式长度**: `none`, `short`, `medium`, `long`, `full`

---

### 2. Paginator - 分页器
**文件**: `app/Utils/Paginator.php`

| 方法 | 功能描述 | 使用示例 |
|------|----------|----------|
| `simple()` | 简单分页（上一页/下一页） | `Paginator::simple($total, $pageSize, $page, $urlGenerator)` |
| `paginate()` | 生成分页链接 | `Paginator::paginate($total, $pageSize, $page, 10, $urlGenerator)` |

---

### 3. FileValidator - 文件验证
**文件**: `app/Utils/FileValidator.php`

| 方法 | 功能描述 | 使用示例 |
|------|----------|----------|
| `validate()` | 验证单个上传文件 | `$validator->validate($_FILES['file'])` |
| `validateMultiple()` | 批量验证多个文件 | `$validator->validateMultiple($_FILES)` |
| `setAllowedExtensions()` | 设置允许的扩展名 | `$validator->setAllowedExtensions(['jpg','png'])` |
| `setAllowedMimeTypes()` | 设置允许的MIME类型 | `$validator->setAllowedMimeTypes(['image/jpeg'])` |
| `setMaxFileSize()` | 设置最大文件大小 | `$validator->setMaxFileSize(5 * 1024 * 1024)` |
| `setImageDefaults()` | 设置预定义图片配置 | `$validator->setImageDefaults()` |
| `setDocumentDefaults()` | 设置预定义文档配置 | `$validator->setDocumentDefaults()` |
| `generateSafeFilename()` | 生成安全的文件名 | `FileValidator::generateSafeFilename($name, 'pre_')` |

---

### 4. HtmlParseHelper - HTML 解析 (Trait)
**文件**: `app/Utils/HtmlParseHelper.php`

| 方法 | 功能描述 | 使用示例 |
|------|----------|----------|
| `fetchUrl()` | 简易抓取URL内容 | `$this->fetchUrl($url)` |
| `detectEncoding()` | 从响应头/HTML猜测编码 | `$this->detectEncoding($html, $headers)` |
| `normalizeToUtf8()` | 统一转为UTF-8 | `$this->normalizeToUtf8($html, 'GBK')` |
| `loadDom()` | 安全加载HTML为DOMDocument | `$this->loadDom($html, $headers)` |
| `absolutizeUrl()` | 相对URL转绝对URL | `$this->absolutizeUrl($base, $href)` |

---

### 5. HttpLink - HTTP 链接工具
**文件**: `app/Utils/HttpLink.php`

| 方法 | 功能描述 | 使用示例 |
|------|----------|----------|
| `httpUrl()` | 获取当前站点根URL | `HttpLink::httpUrl()` |
| `httpUrlPath()` | 获取带路径的站点URL | `HttpLink::httpUrlPath(0)` |

---

## 三、使用建议

### 优先使用主程序工具的场景

| 场景 | 推荐工具 | 避免自定义 |
|------|----------|------------|
| 删除目录 | `DirectoryHelper::rmdirRecursive()` | 避免手动 `scandir` + `unlink` |
| ZIP操作 | `ZipUtility::zip/unzip()` | 避免原生 `ZipArchive` 直接操作 |
| IP处理 | `IpHelper::ip()` | 避免直接 `$_SERVER['REMOTE_ADDR']` |
| 文件锁 | `FileHelper::lock()` | 避免手动 `fopen` + `flock` |
| 数组排序 | `ArrayHelper::multiSortKey()` | 避免原生 `array_multisort` 复杂调用 |
| 日期格式化 | `I18nDateFormatter::format()` | 避免 `date()` 硬编码格式 |

### 命名空间引用

```php
<?php
namespace Plugins\your_plugin\Services;

// 框架工具类
use Framework\Utils\DirectoryHelper;
use Framework\Utils\ZipUtility;
use Framework\Utils\IpHelper;
use Framework\Utils\FileHelper;
use Framework\Utils\ArrayHelper;
use Framework\Utils\SecurityHelper;
use Framework\Utils\UrlHelper;
use Framework\Utils\CookieHelper;
use Framework\Utils\UuidHelper;
use Framework\Utils\HttpClient;
use Framework\Utils\SafeHelper;
use Framework\Utils\FormatHelper;
use Framework\Utils\LinkHelper;
use Framework\Utils\FileHasher;
use Framework\Utils\Runtime;
use Framework\Utils\Validator;

// 应用工具类
use App\Utils\I18nDateFormatter;
use App\Utils\Paginator;
use App\Utils\FileValidator;
use App\Utils\HtmlParseHelper;
use App\Utils\HttpLink;
```

---

**文档维护**: 当主程序新增/修改工具类时，同步更新此文档
