<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Services\Storage\Support;

class Util
{
    /**
     * @param bool $hash
     */
    public static function normalizeHash($hash)
    {
        $hash = trim((string)$hash);
        if (preg_match('/^[a-fA-F0-9]{32,64}$/', $hash)) return strtolower($hash);
        $h = preg_replace('/[^a-zA-Z0-9_\-]/', '', $hash);
        if ($h && strlen($h) >= 16 && strlen($h) <= 128) return strtolower($h);
        return null;
    }

    public static function uploadError(string $err)
    {
        $m = [
            UPLOAD_ERR_INI_SIZE   => 'The file size exceeds the server limit.',
            UPLOAD_ERR_FORM_SIZE  => 'The file size exceeds the form limit.',
            UPLOAD_ERR_PARTIAL    => 'The file was only partially uploaded.',
            UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the file upload.',
            UPLOAD_ERR_OK         => 'Upload successful.'
        ];
        return isset($m[$err]) ? $m[$err] : 'Unknown error';
    }
}
