<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Scheduler\Interfaces;

/**
 * JobInterface
 * 这是一个标记接口。
 * 实现此接口的类表明它可以被 TaskExecutor 调度。
 * 具体的 handle 方法签名由业务类自由定义，TaskExecutor 会通过反射进行参数注入。
 */
interface JobInterface
{
    // 移除 handle 方法定义
    // 依靠 "Convention over Configuration"（约定优于配置）：
    // 只要类中包含与 task.methodName (默认 handle) 同名的方法即可。
}
