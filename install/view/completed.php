<?php if (!defined('IN_WELLCMS')) exit(); ?>
<div class="grid grid-cols-1 lg:grid-cols-2 gap-10 py-4 animate-[fadeIn_0.5s_ease-out]">
    <!-- 左侧：成功标识与主信息 -->
    <div class="flex flex-col items-center justify-center p-10 bg-green-50/30 rounded-3xl border border-green-100/50 space-y-8 text-center">
        <div class="relative">
            <div class="w-32 h-32 bg-green-100 rounded-full flex items-center justify-center text-green-600 shadow-inner">
                <svg class="w-16 h-16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path>
                </svg>
            </div>
            <div class="absolute -top-1 -right-1 w-8 h-8 bg-green-500 rounded-full border-4 border-white animate-ping"></div>
        </div>

        <div>
            <h2 class="text-3xl font-black text-gray-800 tracking-tight mb-3"><?php echo $lang['done_title']; ?></h2>
            <p class="text-gray-500 font-medium leading-relaxed"><?php echo $lang['done_desc']; ?></p>
        </div>
    </div>

    <!-- 右侧：安全建议与配置指南 -->
    <div class="space-y-6">
        <!-- 重要提醒 -->
        <div class="bg-amber-50 border border-amber-100 p-6 rounded-2xl flex gap-4">
            <div class="shrink-0 text-amber-500">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                </svg>
            </div>
            <div class="space-y-1">
                <h4 class="text-sm font-bold text-amber-900"><?php echo $lang['security_advice']; ?></h4>
                <p class="text-xs text-amber-700 leading-relaxed">
                    <?php echo $lang['security_advice_content']; ?>
                </p>
            </div>
        </div>

        <!-- 运行目录配置指南 -->
        <div class="bg-blue-50 border border-blue-100 p-6 rounded-2xl space-y-4">
            <div class="flex gap-4">
                <div class="shrink-0 text-blue-500">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                </div>
                <div class="space-y-1">
                    <h4 class="text-sm font-bold text-blue-900"><?php echo $lang['pub_root_warning']; ?></h4>
                    <p class="text-xs text-blue-700 leading-relaxed">
                        <?php echo $lang['pub_root_desc']; ?>
                    </p>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 pt-2">
                <div class="bg-white/60 p-4 rounded-xl border border-blue-100/50">
                    <p class="text-[11px] font-bold text-blue-800 mb-1 flex items-center gap-1">
                        <span class="w-1.5 h-1.5 bg-blue-400 rounded-full"></span>
                        <?php echo $lang['bt_panel_title']; ?>
                    </p>
                    <p class="text-[11px] text-blue-600 leading-tight"><?php echo $lang['bt_panel_step']; ?></p>
                </div>
                <div class="bg-white/60 p-4 rounded-xl border border-blue-100/50">
                    <p class="text-[11px] font-bold text-blue-800 mb-1 flex items-center gap-1">
                        <span class="w-1.5 h-1.5 bg-blue-400 rounded-full"></span>
                        <?php echo $lang['lnmp_title']; ?>
                    </p>
                    <p class="text-[11px] text-blue-600 leading-tight"><?php echo $lang['lnmp_step']; ?></p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    window.addEventListener('DOMContentLoaded', () => {
        const btns = document.getElementById('actionButtons');
        if (btns) {
            btns.appendChild(createButton('<?php echo $lang['visit_home']; ?>', '../', 'secondary'));
            btns.appendChild(createButton('<?php echo $lang['visit_admin']; ?>', '../admin/', 'primary'));
        }
    });

    sessionStorage.removeItem('wellcms_install_config');
    document.getElementById('progressInner').style.width = '100%';
</script>