<?php if (!defined('IN_WELLCMS')) exit(); ?>
<form id="configForm" class="space-y-6 md:space-y-10">
    <div class="grid grid-cols-1 md:grid-cols-2 gap-8 md:gap-12">
        <!-- 数据库配置 -->
        <div class="space-y-6">
            <div class="flex items-center gap-3 pb-2 border-b border-blue-100">
                <div class="w-8 h-8 bg-blue-50 rounded-lg flex items-center justify-center text-blue-600">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"></path>
                    </svg>
                </div>
                <h3 class="font-bold text-gray-800 tracking-tight"><?php echo $lang['db_config']; ?></h3>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="col-span-2">
                    <label class="block text-xs font-bold text-gray-400 uppercase mb-1.5 ml-1"><?php echo $lang['db_type']; ?></label>
                    <div class="relative">
                        <select name="db[type]" id="dbType" class="w-full px-4 py-2.5 bg-gray-50 border border-gray-100 rounded-xl focus:ring-4 focus:ring-blue-100 focus:border-blue-400 transition-all outline-none text-sm font-medium appearance-none">
                            <option value="mysql"><?php echo $lang['db_type_mysql']; ?></option>
                            <option value="pgsql" selected><?php echo $lang['db_type_pgsql']; ?></option>
                        </select>
                        <div class="absolute right-4 top-1/2 -translate-y-1/2 pointer-events-none text-gray-400">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path d="M19 9l-7 7-7-7"></path>
                            </svg>
                        </div>
                    </div>

                    <!-- 驱动警告提示 -->
                    <div id="driverWarning" class="hidden mt-3 p-3 bg-red-50 border border-red-100 rounded-xl flex gap-x-3 items-center animate-bounce">
                        <div class="shrink-0 text-red-500">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                            </svg>
                        </div>
                        <p class="text-xs text-red-700 font-medium">
                            <span id="missingDriverName"></span> <?php echo $lang['driver_missing_tip'] ?? 'Driver not found. Please install the PHP extension.'; ?>
                        </p>
                    </div>

                    <p class="text-[12px] text-blue-500 mt-1 ml-1 flex items-center gap-1">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <?php echo $lang['pgsql_recommend']; ?>
                    </p>
                </div>
                <div class="col-span-2">
                    <label class="block text-xs font-bold text-gray-400 uppercase mb-1.5 ml-1"><?php echo $lang['db_host']; ?></label>
                    <input type="text" name="db[host]" value="127.0.0.1" class="w-full px-4 py-2.5 bg-gray-50 border border-gray-100 rounded-xl focus:ring-4 focus:ring-blue-100 focus:border-blue-400 transition-all outline-none text-sm font-medium" required>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-400 uppercase mb-1.5 ml-1"><?php echo $lang['db_port']; ?></label>
                    <input type="text" name="db[port]" value="5432" class="w-full px-4 py-2.5 bg-gray-50 border border-gray-100 rounded-xl focus:ring-4 focus:ring-blue-100 focus:border-blue-400 transition-all outline-none text-sm font-medium" required>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-400 uppercase mb-1.5 ml-1"><?php echo $lang['db_name']; ?></label>
                    <input type="text" name="db[name]" value="wellcms" class="w-full px-4 py-2.5 bg-gray-50 border border-gray-100 rounded-xl focus:ring-4 focus:ring-blue-100 focus:border-blue-400 transition-all outline-none text-sm font-medium" required>
                </div>
                <div class="col-span-2">
                    <label class="block text-xs font-bold text-gray-400 uppercase mb-1.5 ml-1"><?php echo $lang['db_user']; ?></label>
                    <input type="text" name="db[user]" value="root" class="w-full px-4 py-2.5 bg-gray-50 border border-gray-100 rounded-xl focus:ring-4 focus:ring-blue-100 focus:border-blue-400 transition-all outline-none text-sm font-medium" required>
                </div>
                <div class="col-span-2">
                    <label class="block text-xs font-bold text-gray-400 uppercase mb-1.5 ml-1"><?php echo $lang['db_pass']; ?></label>
                    <input type="password" name="db[pass]" class="w-full px-4 py-2.5 bg-gray-50 border border-gray-100 rounded-xl focus:ring-4 focus:ring-blue-100 focus:border-blue-400 transition-all outline-none text-sm font-medium">
                </div>
                <div class="col-span-2">
                    <label class="block text-xs font-bold text-gray-400 uppercase mb-1.5 ml-1"><?php echo $lang['db_prefix']; ?></label>
                    <input type="text" name="db[prefix]" value="well_" class="w-full px-4 py-2.5 bg-gray-50 border border-gray-100 rounded-xl focus:ring-4 focus:ring-blue-100 focus:border-blue-400 transition-all outline-none text-sm font-medium" required>
                </div>
            </div>

            <button type="button" id="btnTestDb" class="w-full py-2 bg-gray-100 hover:bg-gray-200 text-gray-600 rounded-lg text-xs font-bold transition-all active:scale-95 flex items-center justify-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <?php echo $lang['test_db']; ?>
            </button>
            <p id="dbTestResult" class="text-[10px] text-center"></p>
        </div>

        <!-- 管理员配置 -->
        <div class="space-y-6">
            <div class="flex items-center gap-3 pb-2 border-b border-green-100">
                <div class="w-8 h-8 bg-green-50 rounded-lg flex items-center justify-center text-green-600">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                    </svg>
                </div>
                <h3 class="font-bold text-gray-800 tracking-tight"><?php echo $lang['admin_config']; ?></h3>
            </div>

            <div class="space-y-4">
                <div>
                    <label class="block text-xs font-bold text-gray-400 uppercase mb-1.5 ml-1"><?php echo $lang['site_name']; ?></label>
                    <input type="text" name="sitename" value="My WellCMS" class="w-full px-4 py-2.5 bg-gray-50 border border-gray-100 rounded-xl focus:ring-4 focus:ring-green-100 focus:border-green-400 transition-all outline-none text-sm font-medium" required>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-400 uppercase mb-1.5 ml-1"><?php echo $lang['admin_user']; ?></label>
                    <input type="text" name="admin[user]" value="admin" class="w-full px-4 py-2.5 bg-gray-50 border border-gray-100 rounded-xl focus:ring-4 focus:ring-green-100 focus:border-green-400 transition-all outline-none text-sm font-medium" required>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-400 uppercase mb-1.5 ml-1"><?php echo $lang['admin_email']; ?></label>
                    <input type="email" name="admin[email]" value="admin@example.com" class="w-full px-4 py-2.5 bg-gray-50 border border-gray-100 rounded-xl focus:ring-4 focus:ring-green-100 focus:border-green-400 transition-all outline-none text-sm font-medium" required>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-400 uppercase mb-1.5 ml-1"><?php echo $lang['admin_pass']; ?></label>
                    <input type="password" name="admin[pass]" class="w-full px-4 py-2.5 bg-gray-50 border border-gray-100 rounded-xl focus:ring-4 focus:ring-green-100 focus:border-green-400 transition-all outline-none text-sm font-medium" required>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-400 uppercase mb-1.5 ml-1"><?php echo $lang['admin_pass_confirm']; ?></label>
                    <input type="password" name="admin[pass_confirm]" class="w-full px-4 py-2.5 bg-gray-50 border border-gray-100 rounded-xl focus:ring-4 focus:ring-green-100 focus:border-green-400 transition-all outline-none text-sm font-medium" required>
                </div>
            </div>

            <div class="p-4 bg-amber-50 rounded-xl border border-amber-100">
                <p class="text-[12px] text-amber-700 leading-relaxed">
                    <?php echo $lang['security_tip']; ?>
                </p>
            </div>
        </div>
    </div>
</form>

<script>
    window.addEventListener('DOMContentLoaded', () => {
        const btns = document.getElementById('actionButtons');
        if (btns) {
            btns.appendChild(createButton(i18n.prev, '?step=2', 'secondary'));
            btns.appendChild(createButton(i18n.next, null, 'primary', 'btnFinalSubmit'));
        }

        const drivers = {
            mysql: <?php echo extension_loaded('pdo_mysql') ? 'true' : 'false'; ?>,
            pgsql: <?php echo extension_loaded('pdo_pgsql') ? 'true' : 'false'; ?>
        };

        const dbTypeSelect = document.getElementById('dbType');
        const driverWarning = document.getElementById('driverWarning');
        const missingDriverName = document.getElementById('missingDriverName');
        const btnTestDb = document.getElementById('btnTestDb');

        function checkDriver() {
            const type = dbTypeSelect.value;
            if (!drivers[type]) {
                driverWarning.classList.remove('hidden');
                missingDriverName.innerText = type === 'mysql' ? 'pdo_mysql' : 'pdo_pgsql';
                btnTestDb.disabled = true;
                btnTestDb.classList.add('opacity-50', 'cursor-not-allowed');
            } else {
                driverWarning.classList.add('hidden');
                btnTestDb.disabled = false;
                btnTestDb.classList.remove('opacity-50', 'cursor-not-allowed');
            }
        }

        // 切换数据库类型时更新端口和检查驱动
        dbTypeSelect.addEventListener('change', function() {
            const portInput = document.querySelector('input[name="db[port]"]');
            if (this.value === 'pgsql') {
                portInput.value = '5432';
            } else {
                portInput.value = '3306';
            }
            checkDriver();
        });

        // 初始检查
        checkDriver();

        // 数据库连接测试
        document.getElementById('btnTestDb').addEventListener('click', function() {
            const formData = new FormData(document.getElementById('configForm'));
            const resEl = document.getElementById('dbTestResult');
            resEl.innerText = 'Connecting...';
            resEl.className = 'text-[10px] text-center text-gray-400';

            fetch('?action=check_db', {
                    method: 'POST',
                    body: formData
                })
                .then(res => res.json())
                .then(data => {
                    if (data.status) {
                        resEl.innerText = '✔ Success';
                        resEl.className = 'text-[10px] text-center text-green-500 font-bold';
                    } else {
                        resEl.innerText = '✘ Error: ' + data.message;
                        resEl.className = 'text-[10px] text-center text-red-500 font-medium';
                    }
                });
        });

        // 提交处理
        document.getElementById('btnFinalSubmit').addEventListener('click', function() {
            const form = document.getElementById('configForm');
            if (!form.reportValidity()) return;

            const type = dbTypeSelect.value;
            if (!drivers[type]) {
                alert('Missing driver: ' + (type === 'mysql' ? 'pdo_mysql' : 'pdo_pgsql'));
                return;
            }

            const pass = form.querySelector('input[name="admin[pass]"]').value;
            const confirm = form.querySelector('input[name="admin[pass_confirm]"]').value;
            if (pass !== confirm) {
                alert('Passwords do not match.');
                return;
            }

            const config = {};
            new FormData(form).forEach((v, k) => config[k] = v);
            sessionStorage.setItem('wellcms_install_config', JSON.stringify(config));
            window.location.href = '?step=4';
        });
    });
</script>