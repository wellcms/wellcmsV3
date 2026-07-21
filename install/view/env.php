<?php if (!defined('IN_WELLCMS')) exit(); ?>
<div class="space-y-8">
    <div class="flex items-center gap-3">
        <div class="w-10 h-10 bg-indigo-100 rounded-xl flex items-center justify-center text-indigo-600">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"></path>
            </svg>
        </div>
        <div>
            <h2 class="text-xl font-bold text-gray-800"><?php echo $lang['env_title']; ?></h2>
            <p class="text-sm text-gray-500"><?php echo $lang['env_desc']; ?></p>
        </div>
    </div>

    <div class="overflow-x-auto border border-gray-100 rounded-2xl shadow-sm bg-white no-scrollbar">
        <table class="w-full text-left border-collapse">
            <thead class="bg-gray-50/50">
                <tr>
                    <th class="px-6 py-4 text-xs font-bold text-gray-500 uppercase tracking-wider"><?php echo $lang['env_item']; ?></th>
                    <th class="px-6 py-4 text-xs font-bold text-gray-500 uppercase tracking-wider"><?php echo $lang['env_required']; ?></th>
                    <th class="px-6 py-4 text-xs font-bold text-gray-500 uppercase tracking-wider"><?php echo $lang['env_current']; ?></th>
                    <th class="px-6 py-4 text-xs font-bold text-gray-500 uppercase tracking-wider text-center"><?php echo $lang['env_status']; ?></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                <?php
                $can_continue = true;
                foreach ($env as $key => $item):
                    $is_rec = $item['is_recommended'] ?? false;
                    $is_db = $item['is_db_driver'] ?? false;
                    // 如果状态不正常，且既不是建议项也不是数据库驱动项，则拦截
                    if (!$item['status'] && !$is_rec && !$is_db) $can_continue = false;
                ?>
                    <tr class="hover:bg-gray-50/40 transition-colors">
                        <td class="px-6 py-4 text-sm font-semibold text-gray-700"><?php echo $item['name']; ?></td>
                        <td class="px-6 py-4 text-xs text-gray-400"><?php echo $item['required']; ?></td>
                        <td class="px-6 py-4 text-sm font-mono <?php echo $item['status'] ? 'text-gray-600' : ($is_rec ? 'text-gray-400' : 'text-red-500'); ?>">
                            <?php echo $item['current']; ?>
                        </td>
                        <td class="px-6 py-4 text-center">
                            <?php if ($item['status']): ?>
                                <span class="inline-flex items-center justify-center w-6 h-6 bg-green-100 text-green-600 rounded-full">
                                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                    </svg>
                                </span>
                            <?php else: ?>
                                <span class="inline-flex items-center justify-center w-6 h-6 <?php echo $is_rec ? 'bg-amber-100 text-amber-600' : 'bg-red-100 text-red-600'; ?> rounded-full">
                                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path>
                                    </svg>
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    window.addEventListener('DOMContentLoaded', () => {
        const btns = document.getElementById('actionButtons');
        if (btns) {
            btns.appendChild(createButton(i18n.prev, '?step=1', 'secondary'));
            if (<?php echo $can_continue ? 'true' : 'false'; ?>) {
                btns.appendChild(createButton(i18n.next, '?step=3', 'primary'));
            } else {
                btns.appendChild(createButton('Fix Issues', null, 'disabled'));
            }
        }
    });
</script>