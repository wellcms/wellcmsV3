<?php if (!defined('IN_WELLCMS')) exit(); ?>
<div class="grid grid-cols-1 lg:grid-cols-5 gap-10 py-4">
    <!-- 左侧：状态与动画 -->
    <div class="lg:col-span-2 flex flex-col items-center justify-center p-8 bg-blue-50/30 rounded-3xl border border-blue-100/50 space-y-8 h-fit lg:sticky lg:top-0">
        <div class="relative">
            <div class="w-32 h-32 border-4 border-blue-100 rounded-full"></div>
            <div class="w-32 h-32 border-4 border-blue-600 border-t-transparent rounded-full animate-spin absolute top-0 left-0"></div>
            <div class="absolute inset-0 flex items-center justify-center">
                <svg class="w-12 h-12 text-blue-600 animate-pulse" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                </svg>
            </div>
        </div>

        <div class="text-center">
            <h2 id="processStatus" class="text-2xl font-black text-gray-800 tracking-tight mb-2"><?php echo $lang['installing_title']; ?></h2>
            <p class="text-gray-400 text-sm font-medium leading-relaxed px-4"><?php echo $lang['installing_desc']; ?></p>
        </div>
    </div>

    <!-- 右侧：实时日志 -->
    <div class="lg:col-span-3 space-y-4">
        <div class="flex items-center gap-2 px-1">
            <div class="w-2 h-2 bg-green-500 rounded-full animate-ping"></div>
            <span class="text-xs font-bold text-gray-400 uppercase tracking-widest">Deployment Engine Logs</span>
        </div>
        <div class="w-full bg-gray-900 rounded-2xl p-6 shadow-2xl relative overflow-hidden group">
            <div class="absolute top-0 left-0 w-full h-1 bg-gradient-to-r from-blue-500 via-indigo-500 to-purple-500 opacity-50"></div>
            <div id="processLog" class="font-mono text-[11px] leading-relaxed text-blue-300 h-[460px] overflow-y-auto scrollbar-hide space-y-1">
                <div class="text-gray-500 select-none"># Initializing WellCMS 3.0 Deployment Engine...</div>
            </div>
        </div>
    </div>
</div>

<script>
    const config = JSON.parse(sessionStorage.getItem('wellcms_install_config'));
    const logEl = document.getElementById('processLog');
    const statusEl = document.getElementById('processStatus');

    function addLog(msg, type = 'ok') {
        const time = new Date().toLocaleTimeString('en-GB');
        const color = {
            ok: 'text-green-400',
            err: 'text-red-400',
            info: 'text-blue-400'
        } [type];
        const logHtml = `
            <div class="flex gap-3 animate-[fadeIn_0.3s_ease] py-0.5 border-b border-white/5 last:border-0">
                <span class="text-gray-600 select-none shrink-0">${time}</span>
                <span class="${color} uppercase font-black text-[9px] bg-white/5 px-1 rounded h-fit mt-1 shrink-0">${type}</span>
                <span class="text-gray-300">${msg}</span>
            </div>
        `;
        logEl.insertAdjacentHTML('beforeend', logHtml);
        logEl.scrollTop = logEl.scrollHeight;
    }

    if (!config) {
        statusEl.innerText = 'Config snapshot lost';
        statusEl.classList.add('text-red-500');
        addLog('Fatal error: No config in sessionStorage', 'err');
    } else {
        addLog('Deployment session established', 'info');

        const fd = new FormData();
        Object.entries(config).forEach(([k, v]) => fd.append(k, v));

        fetch('?action=install', {
                method: 'POST',
                body: fd
            })
            .then(res => {
                if (!res.ok) {
                    return res.text().then(text => {
                        throw new Error(`HTTP ${res.status}: ${text.substring(0, 100)}`);
                    });
                }
                return res.text().then(text => {
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        throw new Error(`Invalid JSON: ${text.substring(0, 200)}`);
                    }
                });
            })
            .then(data => {
                if (data.status) {
                    addLog('SQL Schema imported', 'ok');
                    addLog('Config files generated (config/*.php)', 'ok');
                    addLog('I18n locale locked', 'ok');
                    addLog('AOT pre-compilation complete', 'ok');
                    addLog('Deployment lock established', 'ok');
                    statusEl.innerText = '<?php echo $lang['done_title']; ?>';
                    statusEl.classList.add('text-green-600');

                    setTimeout(() => window.location.href = '?step=5', 2000);
                } else {
                    addLog('Failed: ' + data.message, 'err');
                    statusEl.innerText = 'Deployment Failed';
                    statusEl.classList.add('text-red-500');
                }
            })
            .catch(err => {
                addLog('Error: ' + err.message, 'err');
                statusEl.innerText = 'Communication Fault';
                console.error(err);
            });
    }
</script>