<?php if (!defined('IN_WELLCMS')) exit(); ?>
<div class="space-y-6">
    <div class="flex items-center gap-3 mb-6">
        <div class="w-10 h-10 bg-blue-100 rounded-xl flex items-center justify-center text-blue-600">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
            </svg>
        </div>
        <div>
            <h2 class="text-xl font-bold text-gray-800"><?php echo $lang['license_title']; ?></h2>
            <p class="text-sm text-gray-500"><?php echo $lang['license_desc']; ?></p>
        </div>
    </div>

    <div class="bg-gray-50 rounded-2xl p-6 border border-gray-100 h-[380px] overflow-y-auto text-sm leading-relaxed text-gray-600 prose prose-blue prose-sm max-w-none">
        <p class="font-bold text-gray-800"><?php echo $lang['license_content_title']; ?></p>
        <p class="text-blue-500 font-medium italic underline decoration-blue-200"><?php echo $lang['license_mit']; ?></p>

        <h4 class="text-gray-800 font-bold mt-4 mb-2"><?php echo $lang['license_term_1_title']; ?></h4>
        <p><?php echo $lang['license_term_1_content']; ?></p>

        <h4 class="text-gray-800 font-bold mt-4 mb-2"><?php echo $lang['license_term_2_title']; ?></h4>
        <p><?php echo $lang['license_term_2_content']; ?></p>

        <h4 class="text-red-600 font-bold mt-4 mb-2"><?php echo $lang['license_term_3_title']; ?></h4>
        <div class="bg-red-50 p-4 border-l-4 border-red-500 rounded-r-lg text-red-700 italic">
            <?php echo $lang['license_term_3_content']; ?>
        </div>

        <p class="mt-8 text-center text-gray-400 italic">—— WellCMS Team</p>
    </div>
</div>

<script>
    // 确保 DOM 加载后执行按钮注入
    window.addEventListener('DOMContentLoaded', () => {
        const btns = document.getElementById('actionButtons');
        if (btns) {
            btns.appendChild(createButton('<?php echo $lang['accept_license']; ?>', '?step=2', 'primary'));
        }
    });
</script>