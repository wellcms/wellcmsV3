<!DOCTYPE html>
<html lang="<?php echo $lang_code; ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WellCMS 3.0 <?php echo $lang['step_install']; ?></title>
    <link rel="stylesheet" href="../app/views/css/tailwind.min.css">
</head>

<body class="bg-gradient-to-br from-blue-600 to-indigo-900 min-h-screen flex items-center justify-center p-4">
    <div class="glass-panel w-full max-w-5xl md:h-[800px] min-h-[600px] flex flex-col overflow-hidden animate-[fadeIn_0.5s_ease-out]">
        <!-- 进度条 -->
        <div class="w-full h-1.5 bg-gray-100">
            <div id="progressInner" class="h-full bg-blue-600 transition-all duration-500 ease-out" style="width: 0%"></div>
        </div>

        <!-- 头部 -->
        <div class="px-4 md:px-8 py-4 md:py-6 border-b border-gray-100 flex flex-col md:flex-row justify-between items-center gap-4 md:gap-0">
            <div>
                <h1 class="text-2xl font-black text-gray-800 tracking-tight">WellCMS <span class="text-blue-600">3.0</span></h1>
                <p class="text-xs text-gray-400 mt-0.5 uppercase tracking-widest font-semibold">Industrial Grade CMS Framework</p>
            </div>

            <div class="flex items-center gap-6">
                <!-- 语言切换 -->
                <div class="flex bg-gray-100 p-1 rounded-lg text-[10px] font-bold uppercase shrink-0">
                    <a href="?lang=zh&step=<?php echo $step; ?>" class="px-3 py-1.5 rounded-md <?php echo $lang_code === 'zh' ? 'bg-white shadow-sm text-blue-600' : 'text-gray-400 hover:text-gray-600'; ?>">ZH</a>
                    <a href="?lang=en&step=<?php echo $step; ?>" class="px-3 py-1.5 rounded-md <?php echo $lang_code === 'en' ? 'bg-white shadow-sm text-blue-600' : 'text-gray-400 hover:text-gray-600'; ?>">EN</a>
                </div>

                <div class="flex gap-2 md:gap-4 text-[10px] md:text-sm overflow-x-auto no-scrollbar scroll-smooth">
                    <span class="step-item transition-all whitespace-nowrap" data-step="1"><?php echo $lang['step_license']; ?></span>
                    <span class="text-gray-300">/</span>
                    <span class="step-item transition-all whitespace-nowrap" data-step="2"><?php echo $lang['step_env']; ?></span>
                    <span class="text-gray-300">/</span>
                    <span class="step-item transition-all whitespace-nowrap" data-step="3"><?php echo $lang['step_config']; ?></span>
                    <span class="text-gray-300">/</span>
                    <span class="step-item transition-all whitespace-nowrap" data-step="4"><?php echo $lang['step_install']; ?></span>
                    <span class="text-gray-300">/</span>
                    <span class="step-item transition-all whitespace-nowrap" data-step="5"><?php echo $lang['step_done']; ?></span>
                </div>
            </div>
        </div>

        <!-- 主内容区 -->
        <div class="flex-1 overflow-y-auto px-4 md:px-8 py-6 md:py-10 scroll-smooth">