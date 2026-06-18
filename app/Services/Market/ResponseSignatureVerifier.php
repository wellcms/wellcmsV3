<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
 */

namespace App\Services\Market;

/**
 * 响应签名验证器
 */
class ResponseSignatureVerifier
{
    /** @var array */
    protected $appConfig;

    public function __construct(array $appConfig)
    {
        $this->appConfig = $appConfig;
    }

    /**
     * 验证签名
     */
    public function verify(array $data, array $fields): bool
    {
        if (empty($data['_sign']) || empty($data['_sign_payload'])) {
            return false;
        }
        
        $payload = [];
        foreach ($data['_sign_payload'] as $field) {
            if (isset($data[$field])) {
                $payload[$field] = $data[$field];
            }
        }
        
        $computed = SignatureHelper::sign($payload, $this->appConfig['auth_key'] ?? '');
        return hash_equals($data['_sign'], $computed);
    }
}
