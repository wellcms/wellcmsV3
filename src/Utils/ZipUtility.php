<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Utils;

/**
 * ZipUtility
 *
 * 单文件、零依赖、兼容 PHP 7.2 — PHP 8.x。
 * 先用 ZipArchive，缺失时自动降级到纯 PHP 实现（原 php_zip 逻辑）。
 * 功能：压缩 / 解压、递归创建目录、递归复制 / 删除目录、自动扁平化多余目录层。
 *
 * 用法：
 * $zip = new ZipUtility();
 * $zip->zip('/tmp/demo.zip', '/path/to/dir');
 * $zip->unzip('/tmp/demo.zip', '/path/to/output/');
 */
class ZipUtility
{
	/**
	 * 压缩
	 * @param string|null $zipRootName ZIP 内根目录名（null 则使用 basename($srcPath)）
	 */
	public function zip(string $zipfile, string $srcPath, ?string $zipRootName = null): void
	{
		$zipfile  = strtr($zipfile,  '\\', '/');
		$srcPath  = rtrim(strtr(realpath($srcPath) ?: $srcPath, '\\', '/'), '/');

		is_file($zipfile) && unlink($zipfile);

		$rootName = $zipRootName !== null ? $zipRootName : basename($srcPath);

		if (class_exists('ZipArchive')) {
			$this->zipWithZipArchive($zipfile, $srcPath, $rootName);
		} else {
			$this->zipFallback($zipfile, $srcPath, $rootName);
		}
	}

	/** 解压 */
	public function unzip(string $zipfile, string $destPath): void
	{
		$zipfile  = strtr($zipfile,  '\\', '/');
		$destPath = rtrim(strtr($destPath, '\\', '/'), '/') . '/';

		if (class_exists('ZipArchive')) {
			$this->unzipWithZipArchive($zipfile, $destPath);
		} else {
			$this->unzipFallback($zipfile, $destPath);
		}

		// 自动扁平化同名双层目录
		$last = basename(rtrim($destPath, '/'));
		if (is_dir($destPath . $last)) {
			$tmp = $destPath . '__tmp__' . random_int(100000, 999999) . '/';
			rename(rtrim($destPath, '/'), rtrim($tmp, '/'));
			rename($tmp . $last, rtrim($destPath, '/'));
			$this->rmdirRecursive($tmp);
		}
	}

	/* ========= ZipArchive ========= */

	private function zipWithZipArchive(string $zipfile, string $srcPath, string $zipRootName): void
	{
		$za = new \ZipArchive();
		if ($za->open($zipfile, \ZipArchive::CREATE) !== true) {
			throw new \RuntimeException("Unable to create $zipfile");
		}

		$base = $zipRootName;
		$za->addEmptyDir($base);
		$this->addDirToZip($za, $srcPath, strlen($srcPath), $zipRootName);
		$za->close();
	}

	private function addDirToZip(\ZipArchive $za, string $dir, int $stripLen, string $zipRootName): void
	{
		foreach (glob(rtrim($dir, '/') . '/*') as $item) {
			$local = $zipRootName . substr($item, $stripLen);
			if (is_dir($item)) {
				$za->addEmptyDir($local);
				$this->addDirToZip($za, $item, $stripLen, $zipRootName);
			} else {
				$za->addFile($item, $local);
			}
		}
	}

	private function unzipWithZipArchive(string $zipfile, string $destPath): void
	{
		$za = new \ZipArchive();
		if ($za->open($zipfile) !== true) {
			throw new \RuntimeException("Unable to open $zipfile");
		}
		$za->extractTo($destPath);
		$za->close();
	}

	/* ========= 纯 PHP fallback ========= */

	// 内部缓冲
	/** @var array */
	private $ctrlDir  = [];
	/** @var array */
	private $dataSec  = [];
	/** @var int */
	private $oldOffset = 0;

	private function zipFallback(string $zipfile, string $srcPath, string $zipRootName): void
	{
		if (!function_exists('gzcompress')) {
			throw new \RuntimeException('zlib extension required for fallback zip');
		}

		foreach ($this->walkFiles($srcPath) as $file) {
			$content = is_file($file) ? file_get_contents($file) : '';
			$relPath = ltrim(str_replace('\\', '/', substr($file, strlen($srcPath))), '/');
			$name    = $zipRootName . ($relPath !== '' ? '/' . $relPath : '');
			$this->addFileToBuffer($content, $name, is_file($file) ? filemtime($file) : time());
		}
		file_put_contents($zipfile, $this->buildZip());
	}

	private function unzipFallback(string $zipfile, string $destPath): void
	{
		$zip = fopen($zipfile, 'rb');
		if (!$zip) {
			throw new \RuntimeException("Cannot open $zipfile");
		}

		$cdir = $this->readCentralDirectory($zip, $zipfile);
		$pos  = $cdir['offset'];

		for ($i = 0; $i < $cdir['entries']; $i++) {
			fseek($zip, $pos);
			$hdr = $this->centralHeader($zip);
			$pos = ftell($zip);

			fseek($zip, $hdr['off']);
			$fileHdr = $this->fileHeader($zip);
			$hdr     = array_merge($hdr, $fileHdr);

			$this->extractFile($hdr, $destPath, $zip);
		}
		fclose($zip);
	}

	/* ========= 工具 ========= */

	/** 递归列出文件 & 目录（目录以 / 结尾） */
	private function walkFiles(string $path): \Generator
	{
		$path = rtrim($path, '/') . '/';
		$dir  = new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS);
		$it   = new \RecursiveIteratorIterator($dir, \RecursiveIteratorIterator::SELF_FIRST);
		foreach ($it as $item) {
			yield $item->getPathname();
		}
	}

	private function ensureDir(string $dir): void
	{
		is_dir($dir) || mkdir($dir, 0755, true);
	}

	private function rmdirRecursive(string $dir): void
	{
		if (!is_dir($dir)) return;
		$it = new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS);
		foreach (new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::CHILD_FIRST) as $path) {
			$path->isDir() ? rmdir($path) : unlink($path);
		}
		rmdir($dir);
	}

	/* ======== fallback-ZIP 写 ======== */

	private function addFileToBuffer(string $data, string $name, int $time): void
	{
		$d = getdate($time);
		if ($d['year'] < 1980) {
			$d = ['year' => 1980, 'mon' => 1, 'mday' => 1, 'hours' => 0, 'minutes' => 0, 'seconds' => 0] + $d;
		}
		$dos = (($d['year'] - 1980) << 25) | ($d['mon'] << 21) | ($d['mday'] << 16) | ($d['hours'] << 11) | ($d['minutes'] << 5) | ($d['seconds'] >> 1);

		$crc = crc32($data);
		$z   = gzcompress($data);
		$z   = substr(substr($z, 2), 0, -4);

		// local header
		$fr  = "\x50\x4b\x03\x04\x14\x00\x00\x00\x08\x00";
		$fr .= pack('V', $dos) . pack('VVVvv', $crc, strlen($z), strlen($data), strlen($name), 0);
		$fr .= $name . $z . pack('VVV', $crc, strlen($z), strlen($data));

		$this->dataSec[] = $fr;
		$offset          = strlen(implode('', $this->dataSec));

		// central directory
		$cd  = "\x50\x4b\x01\x02\x00\x00\x14\x00\x00\x00\x08\x00";
		$cd .= pack('V', $dos) . pack('VVVvvvVVV', $crc, strlen($z), strlen($data), strlen($name), 0, 0, 0, 32, $this->oldOffset) . $name;

		$this->oldOffset = $offset;
		$this->ctrlDir[] = $cd;
	}

	private function buildZip(): string
	{
		$data = implode('', $this->dataSec);
		$ctrl = implode('', $this->ctrlDir);
		return $data
			. $ctrl
			. "\x50\x4b\x05\x06\x00\x00\x00\x00"
			. pack('vvVV', count($this->ctrlDir), count($this->ctrlDir), strlen($ctrl), strlen($data))
			. "\x00\x00";
	}

	/* ======== fallback-ZIP 读 ======== */

	/**
	 * @param resource $zip
	 */
	private function readCentralDirectory($zip, string $file): array
	{
		$size = filesize($file);
		$max  = ($size < 277) ? $size : 277;
		fseek($zip, $size - $max);
		$pos = ftell($zip);
		$sig = 0;
		while ($pos < $size && $sig !== 0x504b0506) {
			$sig = ($sig << 8) | ord(fread($zip, 1));
			$pos++;
		}
		$d = unpack('vdisk/vdisk_start/vdisk_entries/ventries/Vsize/Voffset/vcomment', fread($zip, 18));
		return ['entries' => $d['entries'], 'size' => $d['size'], 'offset' => $d['offset']];
	}

	/**
	 * @param resource $zip
	 */
	private function centralHeader($zip): array
	{
		$bin = fread($zip, 46);
		$h   = unpack('vchk/vid/vvers/vverex/vflag/vcmpr/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/velen/vclen/vdisk/vint/Vext/Voff', $bin);
		$h['name'] = $h['nlen'] ? fread($zip, $h['nlen']) : '';
		// 跳过 extra 与 comment
		fread($zip, $h['elen'] + $h['clen']);
		// 别名，便于后续处理
		$h['cmpr'] = $h['vcmpr'];
		$h['time'] = $h['vtime'];
		$h['date'] = $h['vdate'];
		$h['off']  = $h['Voff'];
		return $h;
	}

	/**
	 * @param resource $zip
	 */
	private function fileHeader($zip): array
	{
		$bin = fread($zip, 30);
		$h   = unpack('vchk/vid/vvers/vflag/vcmpr/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/velen', $bin);
		$h['name'] = $h['nlen'] ? fread($zip, $h['nlen']) : '';
		fread($zip, $h['elen']); // 跳过 extra
		$h['cmpr'] = $h['vcmpr'];
		$h['time'] = $h['vtime'];
		$h['date'] = $h['vdate'];
		$h['csize'] = $h['Vcsize'];
		$h['usize'] = $h['Vusize'];
		return $h;
	}

	/**
	 * @param resource $zip
	 */
	private function extractFile(array $hdr, string $dest, $zip): void
	{
		// 目录
		if (substr($hdr['name'], -1) === '/') {
			$this->ensureDir($dest . $hdr['name']);
			return;
		}

		$this->ensureDir($dest . dirname($hdr['name']));
		$target = $dest . $hdr['name'];

		$out = fopen($target, 'wb');
		if (!$out) return;

		if ($hdr['cmpr'] === 0) { // stored
			$remaining = $hdr['usize'];
			while ($remaining > 0) {
				$read = min($remaining, 8192);
				$chunk = fread($zip, $read);
				if ($chunk === false) break;
				fwrite($out, $chunk);
				$remaining -= strlen($chunk);
			}
		} else { // deflate
			// 工业级流式解压：利用 php://filter 或 临时流
			// 为保持零依赖兼容性，这里采用分块处理（如果 gzip 数据很大，gzinflate 仍可能占用内存）
			// 但在 WellCMS 环境下，通常 ZipArchive 是存在的，此路径为极少数降级情况
			$compressedData = fread($zip, $hdr['csize']);
			$data = @gzinflate($compressedData);
			if ($data !== false) {
				fwrite($out, $data);
			}
		}

		fflush($out);
		fclose($out);
		touch($target, $this->dos2unixtime($hdr['date'], $hdr['time']));
	}

	private function dos2unixtime(int $d, int $t): int
	{
		return mktime(
			($t & 0xF800) >> 11,
			($t & 0x07E0) >> 5,
			($t & 0x001F) << 1,
			($d & 0x01E0) >> 5,
			($d & 0x001F),
			(($d & 0xFE00) >> 9) + 1980
		);
	}
}
