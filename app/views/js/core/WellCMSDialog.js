/** WellCMS Dialog Module */
const WellCMSDialog = {
openModal(id) {
  const modal = document.getElementById(id);
  if (modal) {
    modal.classList.remove("hidden");
    modal.classList.add("flex");
    document.body.style.overflow = "hidden";
  }
},
closeModal(id) {
  const modal = document.getElementById(id);
  if (modal) {
    modal.classList.add("hidden");
    modal.classList.remove("flex");
    document.body.style.overflow = "";
  }
},
dialog(options) {
  const {
    title = "Message",
    body = "",
    type = "info",
    onConfirm,
    onCancel,
    confirmText = "OK",
    cancelText = "Cancel",
    size = "md", // Support: sm, md, lg, xl, full
  } = options;

  const sizeClasses = {
    sm: "sm:max-w-sm",
    md: "sm:max-w-lg",
    lg: "sm:max-w-4xl",
    xl: "sm:max-w-6xl",
    full: "sm:max-w-[95vw]",
  };

  const dialogId = "wellcms-global-dialog";
  const maxWidthClass = sizeClasses[size] || sizeClasses.md;

  let iconHtml = "";
  // Icons...
  if (type === "success")
    iconHtml = `<div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-green-100 dark:bg-green-900/30 mb-4"><svg class="h-6 w-6 text-green-600 dark:text-green-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg></div>`;
  else if (type === "error")
    iconHtml = `<div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-red-100 dark:bg-red-900/30 mb-4"><svg class="h-6 w-6 text-red-600 dark:text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg></div>`;
  else if (type === "confirm")
    iconHtml = `<div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-yellow-100 dark:bg-yellow-900/30 mb-4"><svg class="h-6 w-6 text-yellow-600 dark:text-yellow-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg></div>`;

  let buttonsHtml = "";
  if (type === "confirm" || onCancel || options.cancelText) {
    buttonsHtml = `
              <button type="button" class="w-full inline-flex justify-center rounded-xl border border-transparent shadow-sm px-4 py-2 bg-blue-600 text-base font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 sm:ml-3 sm:w-auto sm:text-sm" id="dialog-confirm-btn">${confirmText}</button>
              <button type="button" class="mt-3 w-full inline-flex justify-center rounded-xl border border-gray-300 dark:border-gray-600 shadow-sm px-4 py-2 bg-white dark:bg-slate-800 text-base font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-slate-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm" id="dialog-cancel-btn">${cancelText}</button>
          `;
  } else {
    buttonsHtml = `
              <button type="button" class="w-full inline-flex justify-center rounded-xl border border-transparent shadow-sm px-4 py-2 bg-blue-600 text-base font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 sm:ml-3 sm:w-auto sm:text-sm" id="dialog-confirm-btn">${confirmText}</button>
          `;
  }

  const html = `
          <div id="${dialogId}" class="relative z-[60]" aria-labelledby="modal-title" role="dialog" aria-modal="true">
              <div class="fixed inset-0 bg-gray-900/50 backdrop-blur-sm transition-opacity" id="dialog-backdrop"></div>
              <div class="fixed inset-0 z-[61] overflow-y-auto">
                  <div class="flex min-h-full items-center justify-center p-4 text-center">
                      <div class="relative transform overflow-hidden rounded-2xl bg-white dark:bg-slate-900 border border-white/20 text-left shadow-2xl transition-all w-full max-w-[calc(100vw-2rem)] ${maxWidthClass}\x20glass-panel">
                           <button type="button" class="absolute top-4 right-4 text-gray-400 hover:text-gray-500 hover:bg-gray-100 dark:hover:bg-white/10 rounded-full p-1 transition-colors" id="dialog-close-x">
                              <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                              </svg>
                          </button>
                          <div class="px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                              <div class="sm:flex sm:items-start">
                                  <div class="mt-3 text-center sm:mt-0 sm:text-left w-full">
                                      ${iconHtml}
                                      <h3 class="text-lg font-medium leading-6 text-gray-900 dark:text-white text-center mb-4" id="modal-title">${title}</h3>
                                      <div class="mt-2 max-h-[70vh] overflow-y-auto custom-scrollbar">
                                          <div class="text-sm text-gray-500 dark:text-gray-400 break-words">${body}</div>
                                      </div>
                                  </div>
                              </div>
                          </div>
                          <div class="bg-gray-50 dark:bg-slate-800/50 px-4 py-3 sm:flex sm:flex-row-reverse sm:px-6 border-t border-gray-100 dark:border-gray-800">
                              ${buttonsHtml}
                          </div>
                      </div>
                  </div>
              </div>
          </div>
      `;

  document.body.insertAdjacentHTML("beforeend", html);

  // Execute Scripts
  const dialogEl = document.getElementById(dialogId);
  const scripts = dialogEl.querySelectorAll("script");
  scripts.forEach((oldScript) => {
    const newScript = document.createElement("script");
    Array.from(oldScript.attributes).forEach((attr) =>
      newScript.setAttribute(attr.name, attr.value),
    );
    newScript.appendChild(document.createTextNode(oldScript.innerHTML));
    oldScript.parentNode.replaceChild(newScript, oldScript);
  });

  const confirmBtn = document.getElementById("dialog-confirm-btn");
  const cancelBtn = document.getElementById("dialog-cancel-btn");
  const closeXBtn = document.getElementById("dialog-close-x");
  const backdrop = document.getElementById("dialog-backdrop");

  const closeDialog = () => {
    dialogEl.remove();
    if (onCancel) onCancel();
  };

  if (confirmBtn)
    confirmBtn.onclick = () => {
      if (onConfirm) onConfirm();
      dialogEl.remove();
    };

  if (cancelBtn) cancelBtn.onclick = closeDialog;
  if (closeXBtn) closeXBtn.onclick = closeDialog;
  if (backdrop) backdrop.onclick = closeDialog;
},
alert(message, title = "Alert", size = "md") {
  if (typeof message === "object") {
    this.dialog({ title, ...message, type: "info", size });
  } else {
    this.dialog({ title, body: message, type: "info", size });
  }
},
success(message, title = "Success", size = "md") {
  if (typeof message === "object") {
    this.dialog({ title, ...message, type: "success", size });
  } else {
    this.dialog({ title, body: message, type: "success", size });
  }
},
error(message, title = "Error", size = "md") {
  if (typeof message === "object") {
    this.dialog({ title, ...message, type: "error", size });
  } else {
    this.dialog({ title, body: message, type: "error", size });
  }
},
confirm(message, onConfirm, onCancel) {
  const dialogOptions =
    typeof message === "object"
      ? { title: "Confirm", type: "confirm", onConfirm, onCancel, ...message }
      : {
          title: "Confirm",
          body: message,
          type: "confirm",
          onConfirm,
          onCancel,
        };
  this.dialog(dialogOptions);
},
toast(message, type = "info", duration = 3000) {
  let container = document.getElementById("wellcms-toast-container");
  if (!container) {
    container = document.createElement("div");
    container.id = "wellcms-toast-container";
    container.className =
      "fixed top-20 right-4 z-[70] flex flex-col gap-3 pointer-events-none";
    document.body.appendChild(container);
  }
  const toastId = "toast-" + Date.now();
  let bgClass = "bg-white dark:bg-slate-800",
    textClass = "text-gray-800 dark:text-white",
    iconHtml = "";
  if (type === "success")
    iconHtml = `<svg class="w-5 h-5 text-green-500 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>`;
  else if (type === "error")
    iconHtml = `<svg class="w-5 h-5 text-red-500 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>`;
  else if (type === "warning")
    iconHtml = `<svg class="w-5 h-5 text-yellow-500 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>`;
  else
    iconHtml = `<svg class="w-5 h-5 text-blue-500 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>`;

  const html = `
          <div id="${toastId}" class="${bgClass} ${textClass} shadow-lg rounded-xl p-4 flex items-center pointer-events-auto transform transition-all duration-300 translate-x-full opacity-0 glass-panel border border-gray-100 dark:border-gray-700 w-[calc(100vw-2rem)] sm:max-w-sm">
              ${iconHtml}<div class="text-sm font-medium">${message}</div>
          </div>
      `;
  container.insertAdjacentHTML("beforeend", html);
  const toastEl = document.getElementById(toastId);
  requestAnimationFrame(() =>
    toastEl.classList.remove("translate-x-full", "opacity-0"),
  );
  setTimeout(() => {
    if (toastEl) {
      toastEl.classList.add("translate-x-full", "opacity-0");
      setTimeout(() => toastEl.remove(), 300);
    }
  }, duration);
},
showInputError(name, message) {
  console.log("showInputError called for:", name); // Debug
  const el = document.querySelector(`[name="${name}"]`);
  if (!el) return;

  const parent = el.parentElement;

  // Remove existing error
  const existingError = parent.querySelector(".input-error-tooltip");
  if (existingError) existingError.remove();

  // Ensure parent is relative for positioning
  if (getComputedStyle(parent).position === "static") {
    parent.style.position = "relative";
  }

  // Create Tooltip
  const tooltip = document.createElement("div");

  // Tailwind fallback to Inline for Robustness
  tooltip.className =
    "input-error-tooltip absolute z-50 inline-flex items-center rounded shadow-lg opacity-0 pointer-events-none";

  // Inline Styles: Premium Solid Color for perfect match with arrow
  const bgColor = "#e11d48"; // Rose-600

  tooltip.style.background = bgColor;
  tooltip.style.color = "#ffffff";
  tooltip.style.padding = "6px 12px"; // Adjusted size: Not too big, not too small
  tooltip.style.fontSize = "14px"; // Readable size
  tooltip.style.fontWeight = "500";
  tooltip.style.boxShadow =
    "0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06)";
  tooltip.style.transition = "all 0.3s cubic-bezier(0.16, 1, 0.3, 1)"; // Smooth easing

  // Centering Logic
  tooltip.style.bottom = "70%";
  tooltip.style.left = "50%"; // Center
  tooltip.style.marginBottom = "4px";
  tooltip.style.whiteSpace = "nowrap";
  // Initial Transform (Offset + Slide down effect)
  tooltip.style.transform = "translate(-50%, 4px)";

  // Icon + Message + Arrow (Centered)
  tooltip.innerHTML = `
          <svg style="width:18px; height:18px; margin-right:6px; opacity:0.9;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
          ${message}
          <div style="position:absolute; width:8px; height:8px; background:${bgColor}; transform:rotate(45deg); left:50%; margin-left:-4px; bottom:-4px; box-shadow: 2px 2px 3px rgba(0,0,0,0.05);"></div>
      `;

  parent.appendChild(tooltip);

  // Input visual feedback
  el.classList.add(
    "border-red-500",
    "ring-2",
    "ring-red-500/20",
    "bg-red-50",
    "dark:bg-red-900/10",
  );

  // Shake Animation
  if (!document.getElementById("wellcms-shake-style")) {
    const style = document.createElement("style");
    style.id = "wellcms-shake-style";
    style.innerHTML = `
              @keyframes wellcms-shake {
                  0%, 100% { transform: translateX(0); }
                  10%, 30%, 50%, 70%, 90% { transform: translateX(-4px); }
                  20%, 40%, 60%, 80% { transform: translateX(4px); }
              }
              .wellcms-shake { animation: wellcms-shake 0.5s cubic-bezier(.36,.07,.19,.97) both; }
          `;
    document.head.appendChild(style);
  }
  el.classList.add("wellcms-shake");

  // Show Tooltip
  requestAnimationFrame(() => {
    tooltip.style.opacity = "1";
    tooltip.style.transform = "translate(-50%, 0)"; // Move to final pos
  });

  // Cleanup
  const remove = () => {
    tooltip.style.opacity = "0";
    tooltip.style.transform = "translate(-50%, 4px)"; // Exit anim
    setTimeout(() => tooltip.remove(), 300);

    el.classList.remove(
      "border-red-500",
      "ring-2",
      "ring-red-500/20",
      "bg-red-50",
      "dark:bg-red-900/10",
      "wellcms-shake",
    );
    el.removeEventListener("input", remove);
  };

  // Remove on input and after 5 seconds automatically
  el.addEventListener("input", remove);
  setTimeout(() => {
    if (document.body.contains(tooltip)) remove();
  }, 5000);
}
,
async showMessages(btn, messagesData = null) {
  // 1. 检查弹窗是否已存在 (Toggle)
  const existing = btn.querySelector(".msg-popup");
  if (existing) {
    existing.remove();
    return;
  }

  // 2. 智能定位逻辑
  const rect = btn.getBoundingClientRect();
  const isRightSide = rect.left > window.innerWidth / 2;
  const isBottomSide = rect.top > window.innerHeight / 2;

  let classes =
    "msg-popup absolute w-80 max-w-[85vw] sm:max-w-xs bg-white dark:bg-slate-800 rounded-xl shadow-2xl border border-gray-100 dark:border-gray-700 z-50 overflow-hidden transform transition-all duration-200";

  // 垂直定位 (Vertical)
  if (isBottomSide) {
    classes += " bottom-full mb-2 origin-bottom";
  } else {
    classes += " top-full mt-2 origin-top";
  }

  // 水平定位 (Horizontal)
  if (isRightSide) {
    classes +=
      " right-0 " +
      (isBottomSide ? "origin-bottom-right" : "origin-top-right");
  } else {
    classes +=
      " left-0 " + (isBottomSide ? "origin-bottom-left" : "origin-top-left");
  }

  const popup = document.createElement("div");
  popup.className = classes;
  btn.appendChild(popup);

  // 3. 渲染列表函数
  const renderList = (messages) => {
    let html = `
              <div class="p-4 border-b border-gray-100 dark:border-gray-700 bg-gray-50/50 dark:bg-white/5 flex justify-between items-center">
                  <h3 class="font-bold text-sm">Notifications (${messages.length})</h3>
                  <span class="text-xs text-blue-500 cursor-pointer hover:underline">Mark all read</span>
              </div>
              <div class="max-h-64 overflow-y-auto">
          `;

    if (messages.length > 0) {
      messages.forEach((msg) => {
        const isSystem = msg.type === "System" || msg.type === "Payment";
        const accentColor =
          msg.type === "Payment" ? "bg-green-500" : "bg-blue-500";

        html += `
                      <a href="messages.html?id=${msg.id}" class="block p-4 hover:bg-gray-50 dark:hover:bg-white/5 transition-colors border-b border-gray-50 dark:border-gray-700/50 last:border-0 relative group">
                          <div class="flex justify-between items-start mb-1">
                              <span class="font-bold text-sm ${msg.read ? "text-gray-600 dark:text-gray-400" : "text-gray-900 dark:text-white"}">${msg.user}</span>
                              <span class="text-xs text-gray-400">${msg.time}</span>
                          </div>
                          <p class="text-xs ${msg.read ? "text-gray-500" : "text-gray-700 dark:text-gray-300 font-medium"} line-clamp-2">${msg.content}</p>
                          ${!msg.read ? `<span class="absolute top-4 right-2 w-2 h-2 rounded-full ${accentColor}"></span>` : ""}
                          <div class="absolute inset-y-0 left-0 w-1 ${accentColor} opacity-0 group-hover:opacity-100 transition-opacity"></div>
                      </a>
                  `;
      });
    } else {
      html += `<div class="p-8 text-center text-gray-500 text-sm">No new notifications</div>`;
    }

    html += `</div>
              <div class="p-3 bg-gray-50 dark:bg-slate-900 border-t border-gray-100 dark:border-gray-700 text-center">
                  <a href="messages.html" class="text-xs font-medium text-blue-600 hover:text-blue-700">View All Messages</a>
              </div>
          `;
    popup.innerHTML = html;
  };

  // 4. 数据处理逻辑
  // 情况 1: 外部传入 (同步)
  if (messagesData) {
    renderList(messagesData);
  }
  // 情况 2: 异步获取
  else {
    // Loading State
    popup.innerHTML = `
              <div class="p-4 border-b border-gray-100 dark:border-gray-700 bg-gray-50/50 dark:bg-white/5 flex justify-between items-center">
                  <h3 class="font-bold text-sm">Notifications</h3>
              </div>
              <div class="h-48 flex items-center justify-center text-gray-400">
                  <svg class="animate-spin h-6 w-6" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
              </div>
          `;

    try {
      // Mocking message data for demo
      const res = await new Promise((resolve) => {
        setTimeout(
          () =>
            resolve({
              code: 0,
              data: [
                {
                  id: 1,
                  title: "New Command",
                  desc: "A new order has been received.",
                  time: "2 mins ago",
                  type: "order",
                },
                {
                  id: 2,
                  title: "System Update",
                  desc: "WellCMS 3.0.1 is now available.",
                  time: "1 hour ago",
                  type: "system",
                },
              ],
            }),
          800,
        );
      });

      if (res.code === 0 && res.data) {
        renderList(res.data);
      } else {
        popup.innerHTML = `<div class="p-4 text-center text-red-500 text-sm">Failed to load messages</div>`;
      }
    } catch (err) {
      console.error(err);
      popup.innerHTML = `<div class="p-4 text-center text-red-500 text-sm">Error loading data</div>`;
    }
  }

  // 5. 动画与事件
  requestAnimationFrame(() => {
    popup.classList.add("scale-100", "opacity-100");
    popup.classList.remove("scale-95", "opacity-0");
  });
  popup.classList.add("scale-95", "opacity-0");

  const closeHandler = (e) => {
    if (!btn.contains(e.target)) {
      popup.remove();
      document.removeEventListener("click", closeHandler);
    }
  };
  setTimeout(() => document.addEventListener("click", closeHandler), 0);
}
,
async showUserMenu(btn, userData = null) {
  // 1. 检查菜单是否已存在 (Toggle)
  const existing = btn.querySelector(".user-popup");
  if (existing) {
    existing.remove();
    return;
  }

  // 2. 智能定位
  const rect = btn.getBoundingClientRect();
  const isRightSide = rect.left > window.innerWidth / 2;
  const isBottomSide = rect.top > window.innerHeight / 2;

  let classes =
    "user-popup absolute w-60 max-w-[85vw] sm:max-w-xs bg-white dark:bg-slate-800 rounded-xl shadow-2xl border border-gray-100 dark:border-gray-700 z-50 overflow-hidden transform transition-all duration-200";

  // 垂直定位 (Vertical)
  if (isBottomSide) {
    classes += " bottom-full mb-2 origin-bottom";
  } else {
    classes += " top-full mt-2 origin-top";
  }

  // 水平定位 (Horizontal)
  if (isRightSide) {
    classes +=
      " right-0 " +
      (isBottomSide ? "origin-bottom-right" : "origin-top-right");
  } else {
    classes +=
      " left-0 " + (isBottomSide ? "origin-bottom-left" : "origin-top-left");
  }

  const popup = document.createElement("div");
  popup.className = classes;
  btn.appendChild(popup);

  // 3. 渲染菜单内容的辅助函数
  const renderMenu = (user) => {
    let admin = "";
    if (user.administer && user.links.admin.url) {
      admin = `<a href="${user.links.admin.url}" target="_blank" class="flex items-center px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/10 rounded-lg transition-colors group">
                  <svg class="mr-3 h-5 w-5 text-gray-400 group-hover:text-blue-500 transition-colors" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                  </svg>
                  ${user.links.admin.name}
                  </a>`;
    }
    popup.innerHTML = `
              <div class="p-4 border-b border-gray-100 dark:border-gray-700 bg-gray-50/50 dark:bg-white/5">
                  <div class="flex items-center">
                      <img src="${user.avatar || "https:\x2f\x2fui-avatars.com\x2fapi\x2f?name=User&background=random"}" class="h-10 w-10 rounded-full border border-white dark:border-gray-600 shadow-sm">
                      <div class="ml-3 overflow-hidden">
                          <p class="flex items-start text-sm font-bold text-gray-900 dark:text-white truncate">${user.username || "User"}</p>
                          <p class="flex items-start text-xs text-gray-500 truncate">${user.groupname || ""}</p>
                      </div>
                  </div>
              </div>
              <div class="p-2">
                  <a href="${user.links.home.url}" class="flex items-center px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/10 rounded-lg transition-colors group">
                      <svg class="mr-3 h-5 w-5 text-gray-400 group-hover:text-blue-500 transition-colors" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                      </svg>
                      ${user.links.home.name}
                  </a>
                  <a href="${user.links.profile.url}" class="flex items-center px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/10 rounded-lg transition-colors group">
                      <svg class="mr-3 h-5 w-5 text-gray-400 group-hover:text-blue-500 transition-colors" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                      </svg>
                      ${user.links.profile.name}
                  </a>
                  ${admin}
              </div>
              <div class="p-2 border-t border-gray-100 dark:border-gray-700">
                  <button class="ajax-get w-full flex items-center px-4 py-2 text-sm text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg transition-colors" data-href="${user.links.logout.url}" data-confirm="Are you sure you want to logout?">
                      <svg class="mr-3 h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                      </svg>
                      Logout
                  </button>
              </div>
          `;
  };

  // 4. 数据处理逻辑
  // 情况 1: 如果传入了 userData，直接渲染 (从外部传入需要渲染的数据)
  if (userData) {
    let data = userData;
    if (typeof userData === "string") {
      try {
        data = JSON.parse(userData);
      } catch (e) {
        console.error("Failed to parse userData:", e);
      }
    }
    renderMenu(data);
  }
  // 情况 2: 如果未传数据，则异步获取 (异步获取需要渲染的数据)
  else {
    // 显示 Loading 状态
    popup.innerHTML = `
              <div class="h-32 flex items-center justify-center text-gray-400">
                  <svg class="animate-spin h-6 w-6" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
              </div>
          `;

    try {
      // 模拟 API 调用 (实际项目中请替换为真实 API 路径如 '/api/user/profile')
      // 为了演示，这里使用 setTimeout 模拟延迟，并没有真正发请求，
      // 如果您有真实后端，请使用: const res = await this.post('/api/user/profile');

      // 演示：使用 wellcms.post 发送请求
      // 注意：如果没有 Mock 拦截，这里会 404。
      // 为了演示效果，我们构造一个 Promise 模拟返回
      const res = await new Promise((resolve) => {
        setTimeout(
          () =>
            resolve({
              code: 0,
              data: {
                username: "Mock Admin",
                groupname: "Administrator",
                avatar: "",
                administer: true,
                links: {
                  home: { name: "个人中心", url: "/my/home" },
                  profile: { name: "资料设置", url: "/my/profile" },
                  admin: { name: "后台管理", url: "/admin/panel" },
                },
              },
            }),
          1000,
        );
      });

      if (res.code === 0 && res.data) {
        renderMenu(res.data);
      } else {
        popup.innerHTML = `<div class="p-4 text-center text-red-500 text-sm">Failed to load user info</div>`;
      }
    } catch (err) {
      console.error(err);
      popup.innerHTML = `<div class="p-4 text-center text-red-500 text-sm">Error loading data</div>`;
    }
  }

  // 5. 动画与事件
  requestAnimationFrame(() => {
    popup.classList.add("scale-100", "opacity-100");
    popup.classList.remove("scale-95", "opacity-0");
  });
  popup.classList.add("scale-95", "opacity-0");

  const closeHandler = (e) => {
    if (!btn.contains(e.target)) {
      popup.remove();
      document.removeEventListener("click", closeHandler);
    }
  };
  setTimeout(() => document.addEventListener("click", closeHandler), 0);
}
,
async ajaxModal(url, title, options = {}) {
  const { size = "md", delay = 0, callback, arg } = options;

  // Show Loading State using current dialog system
  const jmodalAttr =
    size === "lg" ? "max-w-4xl" : size === "sm" ? "max-w-sm" : "max-w-lg";

  const jmodal = this.dialog({
    title: title || "Loading...",
    body: `<div class="flex justify-center items-center p-12">
              <svg class="animate-spin h-8 w-8 text-blue-500" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
          </div>`,
  });

  const loadContent = () => {
    return new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest();
      xhr.open("GET", url, true);
      xhr.onload = () => {
        if (xhr.status >= 200 && xhr.status < 300) resolve(xhr.responseText);
        else reject(new Error(`HTTP error! status: ${xhr.status}`));
      };
      xhr.onerror = () => reject(new Error("Network Error or CORS Blocked"));
      xhr.send();
    });
  };

  try {
    const html = await loadContent();
    const result = this.getTitleBodyScriptCss(html);

    // Update Dialog Content
    const dialogEl = document.getElementById("wellcms-global-dialog");
    if (dialogEl) {
      const bodyContainer = dialogEl.querySelector(".break-words");
      const titleEl = dialogEl.querySelector("#modal-title");

      if (bodyContainer) {
        bodyContainer.innerHTML =
          result.body ||
          '<div class="text-center p-8 text-gray-500">Empty Content</div>';
      }
      if (titleEl && (result.title || title)) {
        titleEl.innerText = title || result.title;
      }

      // Adjust size if specified
      const panel = dialogEl.querySelector(".glass-panel");
      if (panel) {
        panel.classList.remove("sm:max-w-lg", "sm:max-w-sm", "sm:max-w-4xl");
        panel.classList.add(`sm:${jmodalAttr}`);
      }
    }

    // Load Styles
    this.evalStylesheet(result.stylesheet_links);

    // Load & Eval Scripts
    const evalArgs = { jmodal, callback, arg };
    if (result.script_srcs.length > 0) {
      this.requireScripts(result.script_srcs, () => {
        this.evalScript(result.script_sections, evalArgs);
      });
    } else {
      this.evalScript(result.script_sections, evalArgs);
    }

    return jmodal;
  } catch (err) {
    console.error("ajaxModal Error:", err);
    this.toast(`${err.message || "Failed to load"} (URL: ${url})`, "error");
    // Cleanup: remove the loading dialog if it's still there
    const loadingDialog = document.getElementById("wellcms-global-dialog");
    if (loadingDialog) loadingDialog.remove();
  }
}
};