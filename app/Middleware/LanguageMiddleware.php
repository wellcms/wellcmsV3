<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Middleware;

class LanguageMiddleware implements \Framework\Http\Interfaces\MiddlewareInterface
{
    /** @var array */
    private $i18nConfig;
    /** @var \App\I18n\LanguageManager */
    private $languageManager;
    /** @var \App\Utils\I18nDateFormatter */
    private $i18nDateFormatter;

    public function __construct(array $i18nConfig, \App\I18n\LanguageManager $languageManager, \App\Utils\I18nDateFormatter $i18nDateFormatter)
    {
        $this->i18nConfig = $i18nConfig;
        $this->languageManager = $languageManager;
        $this->i18nDateFormatter = $i18nDateFormatter;
    }

    public function process(\Framework\Http\Interfaces\ServerRequestInterface $request, \Framework\Http\Interfaces\RequestHandlerInterface $handler): \Framework\Http\Interfaces\ResponseInterface
    {

        // hook app_Middleware_LanguageMiddleware_process_start.php

        // 1. 确定 Locale (优先级: GET > COOKIE > Route Attribute > Config Default)
        $locale = $request->getQueryParams()['locale']
            ?? $request->getCookieParams()['locale']
            ?? $request->getAttribute('locale')
            ?? $this->i18nConfig['locale']
            ?? 'zh';

        // hook app_Middleware_LanguageMiddleware_process_before.php

        // 2. 规范化 Locale (例如 zh-cn -> zh)
        $locale = strtolower(trim($locale));
        $locale = strtr($locale, ['_' => '-']);
        $wellMap = ['zh-cn' => 'zh', 'zh-tw' => 'tw', 'en-us' => 'en', 'zh-hans' => 'zh', 'zh-hant' => 'tw'];
        $wellLocale = $wellMap[$locale] ?? explode('-', $locale)[0];

        // hook app_Middleware_LanguageMiddleware_process_center.php

        // 3. 同步到日期格式化工具 (协程安全，通过 StatefulTrait 隔离)
        $this->i18nDateFormatter->setLang($locale);

        $param0 = $request->getQueryParams()[0] ?? '';

        // hook app_Middleware_LanguageMiddleware_process_after.php

        // 4. 根据入口类型加载相应的语言包
        if ('admin' === $param0) {
            $this->languageManager->loadAdmin($wellLocale);
        } elseif ('install' === $param0) {
            $this->languageManager->loadInstall($wellLocale);
        } else {
            $this->languageManager->loadLanguage($wellLocale);
        }

        // hook app_Middleware_LanguageMiddleware_process_end.php

        // 5. 将语言处理器注入请求属性，并同步到协程栈
        $request = $request->withAttribute('locale', $wellLocale)
            ->withAttribute(\App\Interfaces\LanguageLoaderInterface::class, $this->languageManager);

        \Framework\Http\Psr7\RequestStack::push($request);
        try {
            return $handler->handle($request);
        } finally {
            \Framework\Http\Psr7\RequestStack::pop();
        }
    }
}
