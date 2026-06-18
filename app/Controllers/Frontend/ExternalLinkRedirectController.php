<?php

declare(strict_types=1);

namespace App\Controllers\Frontend;

use App\Controllers\Base\BaseController;
use App\Services\LinkService;
use Framework\Http\Interfaces\ResponseInterface;
use Framework\Http\Psr7\RequestUtils;

class ExternalLinkRedirectController extends BaseController
{
    use \App\Traits\Frontend\FrontendTrait;

    /**
     * 外链安全跳转提示页
     */
    public function external(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $target = RequestUtils::param('target', '');
        $url = base64_decode(rawurldecode($target), true);

        // 1. 基础校验：解码失败或非URL格式
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            return $this->errorMessage($this->language->get('error_param'), 400);
        }

        // 2. 如果目标实际上是站内或白名单（配置变更导致），直接 302 跳转
        // 注意：危险协议拦截已由 SafeHelper::filterUgcLinks() 作为唯一真实来源处理，
        // 控制器层面不再重复检查。SafeHelper 负责在内容渲染时拦截，此处仅做站内判断。
        $linkService = $this->container->get(LinkService::class);
        if ($linkService->isInternal($url)) {
            $responseFactory = $this->container->get(\Framework\Http\Interfaces\ResponseFactoryInterface::class);
            return $responseFactory->createResponse(302)->withHeader('Location', $url);
        }

        $data = [
            'header' => [
                'title' => $this->language->get('external_link_title'),
            ],
            'target_url' => $url,
            'navigation' => $this->getNavigation(),
            'language' => [
                'external_link_title' => $this->language->get('external_link_title'),
                'external_link_warning' => $this->language->get('external_link_warning'),
                'external_link_continue' => $this->language->get('external_link_continue'),
                'cancel' => $this->language->get('cancel'),
            ],
        ];

        $routeMeta = $request->getAttributes()['_route_meta'] ?? ['layout' => 'external_link_redirect'];
        return $this->render($routeMeta['layout'], $data, false);
    }
}
