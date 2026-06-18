        </div>

        <!-- 底部按钮区 -->
        <div class="px-4 md:px-8 py-5 bg-gray-50/50 border-t border-gray-100 flex flex-col md:flex-row justify-between items-center gap-4" id="installFooter">
            <p class="text-[10px] md:text-xs text-gray-400 font-medium order-2 md:order-1">&copy; 2024 - 2026 WellCMS Team. All Rights Reserved.</p>
            <div class="flex gap-3 w-full md:w-auto justify-end order-1 md:order-2" id="actionButtons">
                <!-- 按钮由各页面动态注入 -->
            </div>
        </div>
        </div>

        <script>
            const currentStep = <?php echo (int)($_GET['step'] ?? 1); ?>;

            // 更新进度条
            const progress = ((currentStep - 1) / 4) * 100;
            document.getElementById('progressInner').style.width = progress + '%';

            // 更新步骤状态样式
            document.querySelectorAll('.step-item').forEach(item => {
                const s = parseInt(item.getAttribute('data-step'));
                if (s === currentStep) {
                    item.classList.add('step-active');
                } else if (s < currentStep) {
                    item.classList.add('text-blue-500', 'font-medium');
                } else {
                    item.classList.add('text-gray-400');
                }
            });

            // 统一语言变量
            const i18n = {
                prev: "<?php echo $lang['prev_step']; ?>",
                next: "<?php echo $lang['next_step']; ?>"
            };

            // 通用按钮工厂
            function createButton(text, href, type = 'primary', id = '') {
                const btn = document.createElement(href ? 'a' : 'button');
                if (href) btn.href = href;
                if (id) btn.id = id;

                const baseClasses = "px-6 py-2.5 rounded-xl font-semibold text-sm transition-all duration-200 active:scale-95 flex items-center gap-2";
                const typeClasses = {
                    primary: "bg-blue-600 text-white hover:bg-blue-700 shadow-lg shadow-blue-200",
                    secondary: "bg-white text-gray-600 border border-gray-200 hover:bg-gray-50",
                    disabled: "bg-gray-100 text-gray-400 cursor-not-allowed"
                } [type];

                btn.className = `${baseClasses} ${typeClasses}`;
                btn.innerHTML = text;
                return btn;
            }
        </script>
        </body>

        </html>