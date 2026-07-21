# ClamAV Security Scanning Guide

This document evaluates the necessity of enabling ClamAV for file uploads in WellCMS and provides a detailed guide on how to configure it.

## 1. Security Assessment

### Why is it needed?
*   **Defense against Polyglot Attacks**: Sophisticated attackers can create files that are valid images (passing basic checks like `getimagesize`) but also contain executable malicious scripts. ClamAV can detect these hidden payloads.
*   **Malware Distribution Prevention**: If your site allows users to upload shared files, ClamAV prevents your server from becoming a source of ransomware or trojans for other users.
*   **Regulatory Compliance**: High-security or enterprise projects often require antivirus scanning for all user-generated content to meet security auditing standards.

## 2. Comparison: Enabling vs. Disabling

| Dimension | Enabled (ClamAV) | Disabled (MIME + Renaming) |
| :--- | :--- | :--- |
| **Security** | **Extremely High**. Blocks known WebShells and malware. | **High**. Blocks 99% of execution attacks via renaming. |
| **Performance** | **Has Impact**. Scanning large files adds upload latency. | **Extremely Fast**. Near-instantaneous. |
| **Complexity** | **High**. Requires system setup and virus database updates. | **Low**. No extra configuration needed. |
| **False Positives** | **Occasional**. Might flag complex binary data. | **Near Zero**. |

---

## 3. How to Enable ClamAV

### Step 1: Install ClamAV on your Linux Server
For Ubuntu/Debian:
```bash
sudo apt-get update
sudo apt-get install clamav clamav-daemon
sudo systemctl start clamav-freshclam
sudo systemctl start clamav-daemon
```

For CentOS/RHEL:
```bash
sudo yum install epel-release
sudo yum install clamav clamav-update clamd
sudo freshclam
sudo systemctl start clamd@scan
```

### Step 2: Configure PHP `open_basedir`
If you are using environments like BT-Panel or have restricted paths, PHP will throw an error when checking the ClamAV socket. 

Add `/var/run/clamav/` to your `open_basedir` list in your `php.ini` or site configuration:

**Example (BT-Panel / FastPanel):**
Find the site configuration and edit the `open_basedir`:
`open_basedir=/www/wwwroot/your_site/:/tmp/:/var/run/clamav/`

### Step 3: Configure WellCMS
Update your `config/config.php` (or relevant configuration file) to include the ClamAV socket path:

```php
'upload' => [
    'clamav_socket' => '/var/run/clamav/clamd.ctl', // Path to your clamd socket
    // ... other settings
],
```

### Step 4: Verify
Upload a test file. Check the system logs or use a dummy EICAR test file to verify that the scanner is correctly intercepting threats.
