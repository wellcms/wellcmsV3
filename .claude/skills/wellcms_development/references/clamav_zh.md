# ClamAV 深度安全扫描指南

本文档旨在评估 WellCMS 开启 ClamAV 上传扫描的必要性，并提供详细的配置指南。

## 1. 安全评估

### 为什么要开启？
*   **防御多语位文件 (Polyglot) 攻击**：高级攻击者可以构造既是合法图片（绕过 `getimagesize`）又包含恶意脚本的文件。ClamAV 能识别这类隐藏载荷。
*   **防止病毒传播**：对于允许用户下载附件的站点，ClamAV 能防止服务器成为勒索病毒或木马的分发点。
*   **合规性要求**：政企项目或正式商业运营通常要求对用户上传内容进行杀毒引擎扫描，以符合安全审计标准。

## 2. 对比：开启 vs 禁用

| 维度 | 开启 (ClamAV) | 禁用 (仅靠后缀+重命名) |
| :--- | :--- | :--- |
| **安全性** | **极高**。能拦截已知 WebShell 和各种病毒。 | **高**。依靠重命名和后缀混淆阻止 99% 的执行攻击。 |
| **性能** | **有损**。全文件扫描会增加上传延迟。 | **极快**。几乎是瞬时的。 |
| **维护成本** | **较高**。需要系统安装服务并定期更新病毒库。 | **极低**。零配置。 |
| **误报率** | **存在**。偶尔会将复杂的二进制数据识别为可疑。 | **几乎为零**。 |

---

## 3. 详细开启教程

### 第一步：在 Linux 服务器上安装 ClamAV
**Ubuntu/Debian 系：**
```bash
sudo apt-get update
sudo apt-get install clamav clamav-daemon
sudo systemctl start clamav-freshclam
sudo systemctl start clamav-daemon
```

**CentOS/RHEL 系：**
```bash
sudo yum install epel-release
sudo yum install clamav clamav-update clamd
sudo freshclam
sudo systemctl start clamd@scan
```

### 第二步：配置 PHP `open_basedir`
如果您使用宝塔面板（BT-Panel）等环境，PHP 会因为权限限制无法检测 `/var/run/clamav/` 目录。

您需要修改站点配置文件或 `php.ini`，将 ClamAV 的 Socket 目录加入允许列表：

**示例（宝塔面板）：**
在站点设置 -> 网站目录 -> 防跨站攻击（open_basedir）中，手动编辑：
`open_basedir=/www/wwwroot/你的站点/:/tmp/:/var/run/clamav/`

### 第三步：配置 WellCMS
在 `config/config.php`（或对应的配置文件）中配置 Socket 路径：

```php
'upload' => [
    'clamav_socket' => '/var/run/clamav/clamd.ctl', // 你的 ClamAV Socket 路径
    // ... 其他配置
],
```

### 第四步：测试验证
尝试上传一个文件。您可以使用标准的 [EICAR 测试文件](https://www.eicar.org/?page_id=3950) 来验证扫描引擎是否能正确拦截病毒。
