/**
 * WellCMS UI Core & Utilities
 * Integrated version of wellcms.js and main.js
 * Copyright (C) www.wellcms.com
 */

class WellCMSUI {
  constructor() {
    this.initTheme();
    this.initEvents();
    this.initGlobalHelpers(); // Expose helpers to window if needed
    this.initExtensions(); // Init prototype extensions

    // Form Handler instance
    this.formHandler = null;
    this.initForms();
    this.initTagInputs();
    this.initOTPInputs();
    this.initPasswordStrength();
    this.initStarRatings();
    this.initSideNavScroll();
    this.initClickActions();
    this.initAdminLayout();
  }

  // =========================================================================
  // Theme & UI Core
  // =========================================================================

  initTheme() {
    if (
      localStorage.theme === "dark" ||
      (!("theme" in localStorage) &&
        window.matchMedia("(prefers-color-scheme: dark)").matches)
    ) {
      document.documentElement.classList.add("dark");
    } else {
      document.documentElement.classList.remove("dark");
    }
  }

  toggleDarkMode() {
    if (document.documentElement.classList.contains("dark")) {
      document.documentElement.classList.remove("dark");
      localStorage.theme = "light";
    } else {
      document.documentElement.classList.add("dark");
      localStorage.theme = "dark";
    }
  }

  // =========================================================================
  // Event Delegation
  // =========================================================================

  initEvents() {
    document.addEventListener("click", (e) => {
      // Dropdown
      const dropdownToggle = e.target.closest('[data-toggle="dropdown"]');
      if (dropdownToggle) {
        const targetId = dropdownToggle.dataset.target;
        const target = document.getElementById(targetId);
        document.querySelectorAll(".dropdown-menu").forEach((el) => {
          if (el.id !== targetId) el.classList.add("hidden");
        });
        if (target) {
          target.classList.toggle("hidden");
          e.stopPropagation();
        }
      } else {
        if (
          !e.target.closest(".dropdown-menu") &&
          !e.target.closest('[data-toggle="dropdown"]')
        ) {
          document
            .querySelectorAll(".dropdown-menu")
            .forEach((el) => el.classList.add("hidden"));
        }
      }

      // Mobile Menu Toggle (Unified Nav)
      const mobileMenuToggle = e.target.closest("#mobile-menu-toggle");
      if (mobileMenuToggle) {
        const nav = document.getElementById("main-nav");
        const backdrop = document.getElementById("nav-backdrop");
        if (nav && backdrop) {
          backdrop.classList.remove("hidden");
          setTimeout(() => {
            backdrop.classList.add("opacity-100");
            nav.classList.remove("-translate-x-full");
          }, 10);
        }
      }

      // Close Mobile Nav
      const navCloseBtn = e.target.closest("#nav-close");
      const isNavBackdrop = e.target.id === "nav-backdrop";
      if (navCloseBtn || isNavBackdrop) {
        const nav = document.getElementById("main-nav");
        const backdrop = document.getElementById("nav-backdrop");
        if (nav && backdrop) {
          nav.classList.add("-translate-x-full");
          backdrop.classList.remove("opacity-100");
          setTimeout(() => backdrop.classList.add("hidden"), 300);
        }
      }

      // Modal Toggle
      const modalOpen = e.target.closest('[data-toggle="modal"]');
      if (modalOpen) {
        const targetId = modalOpen.dataset.target;
        this.openModal(targetId);
      }

      const modalClose = e.target.closest("[data-modal-close]");
      if (modalClose) {
        const modal = modalClose.closest(".modal");
        if (modal) this.closeModal(modal.id);
      }

      // Modal Backdrop
      if (e.target.classList.contains("modal-backdrop")) {
        const modal = e.target.closest(".modal");
        if (modal) this.closeModal(modal.id);
      }

      // External Link Confirm
      const externalLink = e.target.closest('a[data-external="1"]');
      if (externalLink) {
        e.preventDefault();
        const dialogData = JSON.parse(
          externalLink.getAttribute("data-external-dialog"),
        );
        // 从全局语言包读取文案（动态，跟随站点语言更新，覆盖数据库中的旧文本）
        // 向后兼容：旧版 data-external-dialog 存储完整 JSON（含 title/confirm）
        // 优先级：window.wellcms_lang > dialogData(旧格式) > 硬编码默认值
        const lang = window.wellcms_lang || {};
        const title = lang.external_link_title || dialogData.title || "Security Notice";
        const body = (lang.external_link_confirm || dialogData.confirm || "This link points to a website outside this site. We are not responsible for external content.\n\nYou are about to visit:\n{url}\n\nIf you find suspicious or harmful links, please report the post using the report icon!")
            .replace("{url}", dialogData.url);
        const confirmText = lang.external_link_continue || dialogData.confirmText || "Continue";
        const cancelText = lang.cancel || dialogData.cancelText || "Cancel";

        this.confirm(
          {
            title: title,
            body: body.replace(/\n/g, "<br>"),
            confirmText: confirmText,
            cancelText: cancelText,
          },
          () => {
            window.open(
              dialogData.url,
              "_blank",
              "noopener,noreferrer",
            );
          },
        );
      }
    });
  }

  // =========================================================================
  // Admin Layout Logic (Sidebar & Accordion)
  // =========================================================================

  initAdminLayout() {
    document.addEventListener("DOMContentLoaded", () => {
      const sidebar = document.getElementById("sidebar");
      const sidebarToggle = document.getElementById("sidebar-toggle");
      const backdrop = document.getElementById("sidebar-backdrop");

      // 1. Sidebar Toggle Logic (Mobile)
      const toggleSidebar = (show) => {
        if (!sidebar) return;
        const isVisible = !sidebar.classList.contains("-translate-x-full");
        const shouldShow = show !== undefined ? show : !isVisible;

        if (shouldShow) {
          sidebar.classList.remove("-translate-x-full");
          if (backdrop) backdrop.classList.remove("hidden");
          document.body.style.overflow = "hidden";
        } else {
          sidebar.classList.add("-translate-x-full");
          if (backdrop) backdrop.classList.add("hidden");
          document.body.style.overflow = "";
        }
      };

      if (sidebarToggle) {
        sidebarToggle.addEventListener("click", (e) => {
          e.stopPropagation();
          toggleSidebar();
        });
      }

      if (backdrop) {
        backdrop.addEventListener("click", () => toggleSidebar(false));
      }

      // 2. Accordion Logic (Primary -> Secondary)
      const menuToggles = document.querySelectorAll("[data-menu-toggle]");
      menuToggles.forEach((toggle) => {
        toggle.addEventListener("click", (e) => {
          const targetId = toggle.getAttribute("data-menu-toggle");
          const content = document.querySelector(
            `[data-menu-content="${targetId}"]`,
          );
          const arrow = toggle.querySelector(".menu-arrow");
          if (!content) return;
          const isOpen = !content.classList.contains("hidden");

          // Close others
          document.querySelectorAll("[data-menu-content]").forEach((el) => {
            if (el !== content) {
              el.classList.add("hidden");
              // Reset arrows of others
              const otherToggle = document.querySelector(
                `[data-menu-toggle="${el.getAttribute("data-menu-content")}"]`,
              );
              if (otherToggle) {
                const otherArrow = otherToggle.querySelector(".menu-arrow");
                if (otherArrow) otherArrow.classList.remove("rotate-180");
              }
            }
          });

          // Toggle current
          if (isOpen) {
            content.classList.add("hidden");
            if (arrow) arrow.classList.remove("rotate-180");
          } else {
            content.classList.remove("hidden");
            if (arrow) arrow.classList.add("rotate-180");

            // Scroll into view if needed (on small screens)
            setTimeout(() => {
              content.scrollIntoView({ behavior: "smooth", block: "nearest" });
            }, 300);
          }
        });
      });

      // 3. User Dropdown Logic (Conflict Prevention)
      const userDropdown = document.querySelector('[data-toggle="dropdown"]');
      if (userDropdown) {
        userDropdown.addEventListener("click", (e) => {
          e.stopPropagation();
        });
      }
    });
  }

  openModal(id) {
    const modal = document.getElementById(id);
    if (modal) {
      modal.classList.remove("hidden");
      modal.classList.add("flex");
      document.body.style.overflow = "hidden";
    }
  }

  closeModal(id) {
    const modal = document.getElementById(id);
    if (modal) {
      modal.classList.add("hidden");
      modal.classList.remove("flex");
      document.body.style.overflow = "";
    }
  }

  // =========================================================================
  // Dynamic Dialogs & Notifications
  // =========================================================================

  /**
   * Show a generic dialog
   * @param {Object} options
   */
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
                        <div class="relative transform overflow-hidden rounded-2xl bg-white dark:bg-slate-900 border border-white/20 text-left shadow-2xl transition-all w-full max-w-[calc(100vw-2rem)] ${maxWidthClass} glass-panel">
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
  }

  alert(message, title = "Alert", size = "md") {
    if (typeof message === "object") {
      this.dialog({ title, ...message, type: "info", size });
    } else {
      this.dialog({ title, body: message, type: "info", size });
    }
  }
  success(message, title = "Success", size = "md") {
    if (typeof message === "object") {
      this.dialog({ title, ...message, type: "success", size });
    } else {
      this.dialog({ title, body: message, type: "success", size });
    }
  }
  error(message, title = "Error", size = "md") {
    if (typeof message === "object") {
      this.dialog({ title, ...message, type: "error", size });
    } else {
      this.dialog({ title, body: message, type: "error", size });
    }
  }
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
  }

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
  }

  /**
   * 显示消息弹窗 (支持同步/异步数据)
   * @param {HTMLElement} btn - 触发弹窗的按钮元素 (用于定位)
   * @param {Array|null} messagesData - (可选) 外部传入的消息数据。如果不传，则自动异步获取。
   */
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

  /**
   * 显示用户菜单 (支持同步/异步数据)
   * @param {HTMLElement} btn - 触发按钮
   * @param {Object|null} userData - (可选) 外部传入的用户数据。如果不传，则自动异步获取。
   */
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
                        <img src="${user.avatar || "https://ui-avatars.com/api/?name=User&background=random"}" class="h-10 w-10 rounded-full border border-white dark:border-gray-600 shadow-sm">
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

  toggleLangMenu(btn) {
    const container = btn.closest(".lang-switcher");
    const menu = container.querySelector(".lang-menu");

    if (menu.classList.contains("hidden")) {
      menu.classList.remove("hidden");
      // Small timeout to trigger transition
      setTimeout(() => {
        menu.classList.remove("opacity-0", "scale-95");
        menu.classList.add("opacity-100", "scale-100");
      }, 10);

      const closeHandler = (e) => {
        if (!container.contains(e.target)) {
          menu.classList.add("opacity-0", "scale-95");
          menu.classList.remove("opacity-100", "scale-100");
          setTimeout(() => menu.classList.add("hidden"), 200);
          document.removeEventListener("click", closeHandler);
        }
      };
      setTimeout(() => document.addEventListener("click", closeHandler), 0);
    } else {
      menu.classList.add("opacity-0", "scale-95");
      menu.classList.remove("opacity-100", "scale-100");
      setTimeout(() => menu.classList.add("hidden"), 200);
    }
  }

  initSideNavScroll() {
    // Navigation Scroll logic
    const container = document.getElementById("nav-scroll-container");
    const upArrow = document.getElementById("nav-up-arrow");
    const downArrow = document.getElementById("nav-down-arrow");

    if (!container || !upArrow || !downArrow) return;

    const updateArrows = () => {
      const { scrollTop, scrollHeight, clientHeight } = container;

      if (scrollTop > 10) {
        upArrow.classList.remove("opacity-0", "pointer-events-none");
      } else {
        upArrow.classList.add("opacity-0", "pointer-events-none");
      }

      if (scrollTop + clientHeight < scrollHeight - 10) {
        downArrow.classList.remove("opacity-0", "pointer-events-none");
      } else {
        downArrow.classList.add("opacity-0", "pointer-events-none");
      }
    };

    // Continuous scroll logic
    let scrollInterval = null;
    const startScrolling = (direction) => {
      if (scrollInterval) return;
      // Initial small jump for tactile feedback
      container.scrollBy({ top: direction * 40, behavior: "smooth" });
      // Continuous scrolling
      scrollInterval = setInterval(() => {
        container.scrollBy({ top: direction * 5, behavior: "auto" });
      }, 10);
    };

    const stopScrolling = () => {
      if (scrollInterval) {
        clearInterval(scrollInterval);
        scrollInterval = null;
      }
    };

    // Up Arrow Events
    upArrow.addEventListener("mousedown", () => startScrolling(-1));
    upArrow.addEventListener(
      "touchstart",
      (e) => {
        if (e.cancelable) e.preventDefault();
        startScrolling(-1);
      },
      { passive: false },
    );

    // Down Arrow Events
    downArrow.addEventListener("mousedown", () => startScrolling(1));
    downArrow.addEventListener(
      "touchstart",
      (e) => {
        if (e.cancelable) e.preventDefault();
        startScrolling(1);
      },
      { passive: false },
    );

    // Stop Events
    [upArrow, downArrow].forEach((arrow) => {
      window.addEventListener("mouseup", stopScrolling);
      arrow.addEventListener("mouseleave", stopScrolling);
      arrow.addEventListener("touchend", stopScrolling);
    });

    container.addEventListener("scroll", updateArrows);
    window.addEventListener("resize", updateArrows);
    setTimeout(updateArrows, 500);
    const sidebar = document.getElementById("sidebar");
    if (sidebar) sidebar.addEventListener("mouseenter", updateArrows);
  }

  // =========================================================================
  // Utilities (Ported from wellcms.js)
  // =========================================================================

  initGlobalHelpers() {
    // Core Utilities
    window.intval = this.intval;
    window.floatval = this.floatval;
    window.time = this.time;
    window.jsonEncode = this.jsonEncode;
    window.jsonDecode = this.jsonDecode;
    window.isEmpty = this.isEmpty;
    window.md5 = this.md5;

    // PHP-like String/Array Utils (Ported for compatibility)
    window.strpos = (str, s) => (str ? str.indexOf(s) : -1);
    window.substr = (str, start, len) => {
      if (!str) return "";
      let end;
      if (start < 0) start = str.length + start;
      if (len === undefined) end = str.length;
      else if (len > 0) end = start + len;
      else end = str.length + len;
      return str.substring(start, end);
    };
    window.explode = (sep, s) => s.split(sep);
    window.implode = (glue, arr) => arr.join(glue);
    window.inArray = (v, arr) => arr.indexOf(v) !== -1;
    window.urlEncode = encodeURIComponent;
    window.urlDecode = decodeURIComponent;

    // Legacy Network Adapters (Callback Style support for getRequest/postRequest)
    window.getRequest = (url, callback, retry = 1) => {
      this.get(url, retry).then((res) => {
        // Old getRequest: callback(code, message/data)
        // New get: {code, data, message}
        if (callback) callback(res.code, res.data || res.message);
      });
    };

    window.postRequest = (url, data, callback, progressCallback) => {
      // Handle argument shifting from original: postRequest(url, data, callback, progress)
      // or postRequest(url, callback, progress) if data is function? (Original had weird check)
      this.post(url, data, progressCallback)
        .then((res) => {
          // Old postRequest: callback(code, message, ...)
          // Note: Old postRequest signature was loose.
          // Our new post returns full JSON object.
          // We try to map it back to common usage: callback(response) or callback(code, msg)
          // Looking at wellcms.js: callback(parsedResponse) (Line 446).
          if (callback) callback(res);
        })
        .catch((err) => {
          if (callback) callback({ code: -1, message: err.message });
        });
    };

    // Helper visibility for inline scripts
    window.url = this.url.bind(this);
    window.showAlert = this.alert.bind(this);
    window.isEmptyCollection = (v) => this.isEmpty(v);
  }

  initExtensions() {
    // Prototype Extensions
    if (!HTMLElement.prototype.serializeObject) {
      HTMLElement.prototype.serializeObject = function () {
        const obj = {};
        const formData = new FormData(this);
        formData.forEach((value, key) => {
          if (obj[key]) {
            if (!Array.isArray(obj[key])) {
              obj[key] = [obj[key]];
            }
            obj[key].push(value);
          } else {
            obj[key] = value;
          }
        });
        return obj;
      };
    }
  }

  initForms() {
    this.formHandler = new GlobalFormHandler(".ajax-form", { ui: this });
  }

  initClickActions() {
    this.clickHandler = new GlobalClickHandler({ ui: this });
  }

  initTagInputs() {
    const containers = document.querySelectorAll('[data-type="tag-input"]');
    containers.forEach((container) => this.setupTagInput(container));
  }

  setupTagInput(container) {
    const input = container.querySelector('input[type="text"]');
    if (!input) return;

    const name = container.dataset.name || "tags";
    // Check if there is already a hidden input, if not create one
    let hiddenInput = container.querySelector(
      `input[type="hidden"][name="${name}"]`,
    );
    if (!hiddenInput) {
      hiddenInput = document.createElement("input");
      hiddenInput.type = "hidden";
      hiddenInput.name = name;
      container.appendChild(hiddenInput);
    }

    const updateHidden = () => {
      const tags = Array.from(container.querySelectorAll(".tag-chip")).map(
        (t) => t.dataset.value,
      );
      hiddenInput.value = tags.join(",");
      hiddenInput.dispatchEvent(new Event("change", { bubbles: true }));
    };

    const addTag = (val) => {
      // Strip punctuation and special symbols using Unicode property escapes
      // \p{L} matches any kind of letter from any language
      // \p{N} matches any kind of numeric character in any script
      // [^\p{L}\p{N}] matches anything that is NOT a letter or a number
      // The 'u' flag is required for Unicode property escapes
      val = val.replace(/[^\p{L}\p{N}]/gu, "").trim();
      if (!val) {
        input.value = ""; // Clear if it became empty after stripping
        return;
      }

      const existing = Array.from(container.querySelectorAll(".tag-chip")).map(
        (t) => t.dataset.value,
      );
      if (existing.includes(val)) {
        input.value = "";
        return;
      }

      const chip = document.createElement("span");
      chip.className =
        "tag-chip px-2 py-1 bg-blue-100 dark:bg-blue-900/50 text-blue-700 dark:text-blue-300 rounded text-sm flex items-center";
      chip.dataset.value = val;
      chip.innerHTML = `${val}<button type="button" class="ml-1 hover:text-blue-900 dark:hover:text-blue-200">×</button>`;

      chip.querySelector("button").onclick = () => {
        chip.remove();
        updateHidden();
      };

      container.insertBefore(chip, input);
      input.value = "";
      updateHidden();
    };

    input.addEventListener("keydown", (e) => {
      if (e.key === "Enter") {
        e.preventDefault();
        addTag(input.value);
      } else if (e.key === "Backspace" && input.value === "") {
        const chips = container.querySelectorAll(".tag-chip");
        if (chips.length > 0) {
          chips[chips.length - 1].remove();
          updateHidden();
        }
      }
    });

    // Initialize existing chips (support for server-rendered or pre-filled tags)
    container.querySelectorAll(".tag-chip").forEach((chip) => {
      const val = chip.dataset.value || chip.innerText.trim().replace("×", "");
      chip.dataset.value = val;
      if (!chip.querySelector("button")) {
        chip.innerHTML = `${val}<button type="button" class="ml-1 hover:text-blue-900 dark:hover:text-blue-200">×</button>`;
      }
      chip.querySelector("button").onclick = () => {
        chip.remove();
        updateHidden();
      };
    });
    updateHidden();
  }

  initOTPInputs() {
    document
      .querySelectorAll('[data-type="otp-group"]')
      .forEach((container) => {
        this.setupOTPInput(container);
      });
  }

  setupOTPInput(container) {
    const inputs = container.querySelectorAll("input");
    inputs.forEach((input, index) => {
      input.addEventListener("input", (e) => {
        if (e.target.value.length === 1 && index < inputs.length - 1) {
          inputs[index + 1].focus();
        }
      });
      input.addEventListener("keydown", (e) => {
        if (e.key === "Backspace" && e.target.value === "" && index > 0) {
          inputs[index - 1].focus();
        }
      });
      input.addEventListener("paste", (e) => {
        e.preventDefault();
        const text = (e.clipboardData || window.clipboardData)
          .getData("text")
          .slice(0, inputs.length);
        text.split("").forEach((char, i) => {
          if (inputs[i]) inputs[i].value = char;
        });
        if (text.length === inputs.length) inputs[inputs.length - 1].focus();
      });
    });
  }

  initPasswordStrength() {
    document
      .querySelectorAll('[data-type="password-strength"]')
      .forEach((input) => {
        this.setupPasswordStrength(input);
      });
  }

  setupPasswordStrength(input) {
    const container =
      input.closest(".password-strength-container") || input.parentElement;
    const barsContainer = container.querySelector('[data-role="bars"]');
    const textEl = container.querySelector('[data-role="text"]');
    const hintEl = container.querySelector('[data-role="hint"]');

    if (!barsContainer) return;

    input.addEventListener("input", function () {
      const val = this.value;
      let score = 0;
      if (val.length > 5) score++;
      if (val.length > 8) score++;
      if (/[A-Z]/.test(val)) score++;
      if (/[0-9]/.test(val)) score++;
      if (/[^A-Za-z0-9]/.test(val)) score++;

      const bars = barsContainer.children;
      for (let i = 0; i < bars.length; i++) {
        bars[i].className =
          `flex-1 rounded-full transition-colors ${i < score ? (score > 3 ? "bg-green-500" : score > 2 ? "bg-yellow-500" : "bg-red-500") : "bg-gray-200 dark:bg-gray-700"}`;
      }
      if (textEl)
        textEl.innerText = score > 3 ? "强烈" : score > 2 ? "中等" : "弱";
      if (hintEl)
        hintEl.innerText = score > 3 ? "非常棒！" : "尝试包含符号或数字";
    });
  }

  initStarRatings() {
    document
      .querySelectorAll('[data-type="star-rating"]')
      .forEach((container) => {
        this.setupStarRating(container);
      });
  }

  setupStarRating(container) {
    const stars = container.querySelectorAll("button");
    const input = container.querySelector('input[type="hidden"]');

    stars.forEach((star, index) => {
      star.addEventListener("click", () => {
        const rating = index + 1;
        if (input) input.value = rating;

        stars.forEach((s, i) => {
          if (i < rating) {
            s.classList.add("text-yellow-400");
            s.classList.remove("text-gray-300");
          } else {
            s.classList.remove("text-yellow-400");
            s.classList.add("text-gray-300");
          }
        });

        if (input) input.dispatchEvent(new Event("change"));
      });
    });
  }

  // Type Checkers
  isEmpty(value) {
    if (value === null || value === undefined) return true;
    if (typeof value === "string") return value.trim() === "";
    if (Array.isArray(value)) return value.length === 0;
    if (typeof value === "object") return Object.keys(value).length === 0;
    return false;
  }

  intval(s) {
    const i = parseInt(s);
    return isNaN(i) ? 0 : i;
  }
  floatval(s) {
    const r = parseFloat(s);
    return isNaN(r) ? 0 : r;
  }
  time() {
    return Math.floor(Date.now() / 1000);
  }

  jsonEncode(data) {
    try {
      return JSON.stringify(data);
    } catch (e) {
      console.error(e);
      return null;
    }
  }
  jsonDecode(json) {
    try {
      return JSON.parse(json);
    } catch (e) {
      console.error(e);
      return null;
    }
  }

  /**
   * 生成 URL，逻辑与 PHP CoreUrlGenerator::url / buildPath 保持一致。
   *
   * url_rewrite_on 模式说明：
   *   0 → ?route-with-dash.html
   *   1 → route-with-dash.html
   *   2 → /route/keep/slash.html
   *   3 → /route/keep/slash
   *
   * 调用样例：
   *   url('user/home/1')                mode 0: '?user-home-1.html'
   *   url('user/home/1')                mode 1: 'user-home-1.html'
   *   url('user/home/1')                mode 2: '/user/home/1.html'
   *   url('user/home/1')                mode 3: '/user/home/1'
   *   url('')                           任意模式: '/'
   *   url('user/home', {id: 1})         mode 2: '/user/home.html?id=1'
   *   url('user/home', {id: 1, name: 'a b'})
   *                                     mode 2: '/user/home.html?id=1&name=a+b'
   *
   * @param {string} route  路由字符串，如 'user/home/1'
   * @param {object} extra  查询参数对象，如 {id: 1}
   * @param {object} config 配置项，{ path: './', url_rewrite_on: 0 }
   * @returns {string}
   */
  url(route, extra = {}, config = { path: "./", url_rewrite_on: 0 }) {
    if (undefined === config.url_rewrite_on) {
      config.url_rewrite_on = 0;
    }

    // 模拟 PHP CoreUrlGenerator::buildPath
    let path = route ? route.replace(/^\/+|\/+$/g, "") : "";
    if (!path) {
      path = "/";
    } else {
      switch (config.url_rewrite_on) {
        case 0:
          path = "?" + path.replace(/\//g, "-") + ".html";
          break;
        case 1:
          path = path.replace(/\//g, "-") + ".html";
          break;
        case 2:
          path = "/" + path + ".html";
          break;
        case 3:
          path = "/" + path;
          break;
        default:
          path = "?" + path.replace(/\//g, "-") + ".html";
      }
    }

    // 如果配置了 path 且不是默认当前目录，拼接前缀（兼容二级目录部署）
    if (config.path && config.path !== "./" && config.path !== "") {
      const base = config.path.replace(/\/+$/, "");
      if (path.startsWith("/")) {
        path = base + path;
      } else if (path.startsWith("?")) {
        path = base + path;
      } else {
        path = base + "/" + path;
      }
    }

    // 添加附加参数
    if (extra && Object.keys(extra).length !== 0) {
      const sep = path.includes("?") ? "&" : "?";
      path += sep + new URLSearchParams(extra).toString();
    }

    return path;
  }

  // =========================================================================
  // Networking (AJAX / Fetch)
  // =========================================================================

  async get(url, params = {}, retry = 1) {
    if (typeof params === "number") {
      retry = params;
      params = {};
    }

    let finalUrl = url;
    if (params && Object.keys(params).length > 0) {
      const sep = finalUrl.includes("?") ? "&" : "?";
      finalUrl += sep + new URLSearchParams(params).toString();
    }

    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 15000);
    try {
      const response = await fetch(finalUrl, {
        method: "GET",
        headers: { "X-Requested-With": "XMLHttpRequest" },
        signal: controller.signal,
      });
      clearTimeout(timeout);
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      const text = await response.text();

      // Auto Parse JSON or HTML
      try {
        return JSON.parse(text);
      } catch (e) {
        // If HTML, we can offer to parse it
        const parseHTML = (selector) => {
          const parser = new DOMParser();
          const doc = parser.parseFromString(text, "text/html");
          return selector ? doc.querySelector(selector) : doc;
        };
        return { code: 0, data: text, isHtml: true, parse: parseHTML };
      }
    } catch (error) {
      clearTimeout(timeout);
      if (retry > 1) return this.get(url, params, retry - 1);
      return { code: -1, message: error.message };
    }
  }

  /**
   * Post Request with File Upload Support (Simple)
   */
  post(url, data, onProgress = null) {
    return new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest();
      xhr.open("POST", url, true);
      xhr.setRequestHeader("X-Requested-With", "XMLHttpRequest");

      let payload = data;
      if (!(data instanceof FormData)) {
        // Convert plain object to FormData for standard PHP compatibility
        payload = new FormData();
        for (let key in data) {
          if (Array.isArray(data[key])) {
            data[key].forEach((val) => payload.append(key + "[]", val));
          } else {
            payload.append(key, data[key]);
          }
        }
      }

      xhr.upload.onprogress = (e) => {
        if (e.lengthComputable && onProgress) {
          onProgress((e.loaded / e.total) * 100);
        }
      };

      xhr.onload = () => {
        if (xhr.status >= 200 && xhr.status < 300) {
          try {
            const json = JSON.parse(xhr.responseText);
            resolve(json);
          } catch (e) {
            console.error("JSON Parse Error. Raw Response:", xhr.responseText);
            reject(new Error("Invalid JSON response"));
          }
        } else {
          reject(new Error(`HTTP ${xhr.status}`));
        }
      };
      xhr.onerror = () => reject(new Error("Network Error"));
      xhr.send(payload);
    });
  }

  // =========================================================================
  // Advanced File Upload (Chunk / Drag & Drop)
  // =========================================================================

  /**
   * Chunked File Upload with Resume/Fast Upload capability
   * @param {Object} options { url, file, onProgress(%), onChunk(i, total), chunkSize }
   */
  async uploadFileWithProgress(options) {
    const {
      url,
      file,
      onProgress,
      onChunk,
      preferredChunkSize = 2 * 1024 * 1024,
      imageProcessor,
      extraData = {}, // Add extraData support
    } = options;

    if (!file) throw new Error("No file selected");

    const filename = file.name;
    const filesize = file.size;

    // Calculate Hash for Deduplication / Resume (Optimized for large files)
    let filehash = "";
    if (window.crypto && window.crypto.subtle) {
      let hashData;
      if (filesize > 5 * 1024 * 1024) {
        // Semi-hash for large files: first 1MB + last 1MB + metadata for performance
        const head = file.slice(0, 1024 * 1024);
        const tail = file.slice(filesize - 1024 * 1024);
        hashData = new Blob([head, tail, filename, filesize.toString()]);
      } else {
        hashData = file;
      }
      const buf = await hashData.arrayBuffer();
      const digest = await crypto.subtle.digest("SHA-256", buf);
      filehash = Array.from(new Uint8Array(digest))
        .map((b) => b.toString(16).padStart(2, "0"))
        .join("");
    } else {
      filehash = this.md5(filename + filesize);
    }

    // Append extra helper
    const appendExtra = (fd) => {
      for (let key in extraData) {
        fd.append(key, extraData[key]);
      }
    };

    // 1. Init / Probe for Fast Upload
    const initFd = new FormData();
    initFd.append("action", "init");
    initFd.append("filename", filename);
    initFd.append("filesize", filesize);
    initFd.append("filehash", filehash);
    appendExtra(initFd);

    let initResp;
    try {
      initResp = await this.post(url, initFd);
    } catch (e) {
      // Fallback for servers not supporting chunking: Direct Upload
      console.warn("Init failed, falling back to direct upload");
      const directFd = new FormData();
      directFd.append("file", file);
      appendExtra(directFd);
      return await this.post(url, directFd, onProgress);
    }

    if (
      initResp.is_fast ||
      (initResp.data && initResp.data.status === "complete")
    ) {
      if (onProgress) onProgress(100);
      return initResp; // Fast Upload Success
    }

    // 2. Chunk Loop
    const data = initResp.data || {};
    const chunkSize = data.chunkSize || preferredChunkSize;
    const totalChunks = Math.ceil(filesize / chunkSize);
    const uploadedChunks = new Set(data.uploaded || []);
    const uploadId = data.uploadId || filehash;

    for (let i = 1; i <= totalChunks; i++) {
      if (uploadedChunks.has(i)) continue;

      const start = (i - 1) * chunkSize;
      const end = Math.min(start + chunkSize, filesize);
      const chunk = file.slice(start, end);

      const fd = new FormData();
      fd.append("action", "upload_chunk");
      fd.append("uploadId", uploadId);
      fd.append("chunk", i);
      fd.append("chunks", totalChunks);
      fd.append("filehash", filehash);
      fd.append("file", chunk, `${filename}.part${i}`);
      appendExtra(fd);

      await this.post(url, fd);

      if (onChunk) onChunk(i, totalChunks);
      if (onProgress) onProgress(Math.min(99, (i / totalChunks) * 100));
    }

    // 3. Complete
    const completeFd = new FormData();
    completeFd.append("action", "complete");
    completeFd.append("uploadId", uploadId);
    completeFd.append("filehash", filehash);
    appendExtra(completeFd);

    const finalResp = await this.post(url, completeFd);
    if (onProgress) onProgress(100);
    return finalResp;
  }

  /**
   * Initialize Drag & Drop Zone
   * @param {string} selector
   * @param {Function} onDrop (files) => void
   */
  initDragDrop(selector, onDrop) {
    const zone = document.querySelector(selector);
    if (!zone) return;

    ["dragenter", "dragover", "dragleave", "drop"].forEach((eventName) => {
      zone.addEventListener(eventName, preventDefaults, false);
    });

    function preventDefaults(e) {
      e.preventDefault();
      e.stopPropagation();
    }

    ["dragenter", "dragover"].forEach((eventName) => {
      zone.addEventListener(
        eventName,
        () =>
          zone.classList.add(
            "bg-blue-50",
            "dark:bg-blue-900/20",
            "border-blue-500",
          ),
        false,
      );
    });

    ["dragleave", "drop"].forEach((eventName) => {
      zone.addEventListener(
        eventName,
        () =>
          zone.classList.remove(
            "bg-blue-50",
            "dark:bg-blue-900/20",
            "border-blue-500",
          ),
        false,
      );
    });

    zone.addEventListener(
      "drop",
      (e) => {
        const dt = e.dataTransfer;
        const files = dt.files;
        if (onDrop) onDrop(files);
      },
      false,
    );
  }

  // =========================================================================
  // Image Processing & Files
  // =========================================================================

  async processImage(file, width, height, watermark = {}) {
    if (!file || !file.type.startsWith("image")) return file;
    return new Promise((resolve) => {
      const img = new Image();
      img.onload = () => {
        const canvas = document.createElement("canvas");

        // 计算画布宽高
        if (width && height) {
          canvas.width = width;
          canvas.height = height;
        } else if (width) {
          canvas.width = width;
          canvas.height = width * (img.height / img.width);
        } else if (height) {
          canvas.height = height;
          canvas.width = height * (img.width / img.height);
        } else {
          canvas.width = img.width;
          canvas.height = img.height;
        }

        const ctx = canvas.getContext("2d");

        // 如果同时指定了宽高，执行居中裁切（Cover 模式）避免拉伸
        if (width && height) {
          const targetRatio = width / height;
          const imgRatio = img.width / img.height;
          let sx, sy, sw, sh;

          if (imgRatio > targetRatio) {
            // 原图太宽，截取中间部分
            sh = img.height;
            sw = img.height * targetRatio;
            sx = (img.width - sw) / 2;
            sy = 0;
          } else {
            // 原图太高，截取中间部分
            sw = img.width;
            sh = img.width / targetRatio;
            sx = 0;
            sy = (img.height - sh) / 2;
          }
          ctx.drawImage(img, sx, sy, sw, sh, 0, 0, canvas.width, canvas.height);
        } else {
          // 仅指定单边，按比例缩放
          ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
        }

        if (watermark.text) {
          const fontSize = Math.max(14, Math.floor(canvas.width * 0.04));
          ctx.font = `bold ${fontSize}px Arial, sans-serif`;
          ctx.fillStyle = "#ffffff";
          ctx.textAlign = "right";
          ctx.textBaseline = "bottom";
          ctx.shadowColor = "rgba(0,0,0,0.8)";
          ctx.shadowBlur = 3;
          ctx.shadowOffsetX = 1;
          ctx.shadowOffsetY = 1;
          const padding = 10;
          ctx.fillText(
            watermark.text,
            canvas.width - padding,
            canvas.height - padding,
          );
        }

        canvas.toBlob(
          (blob) => {
            if (blob) {
              try {
                const newFile = new File([blob], file.name, {
                  type: file.type,
                });
                resolve(newFile);
              } catch (e) {
                blob.name = file.name;
                resolve(blob);
              }
            } else {
              resolve(file);
            }
          },
          file.type,
          0.95,
        );
      };
      img.onerror = () => resolve(file);
      img.src = URL.createObjectURL(file);
    });
  }

  /**
   * Advanced Image Cropper Modal
   * @param {File} file - Image file to crop
   * @param {Object} options - Custom options { width, height, title, confirmText, cancelText, shape }
   * @returns {Promise<File>} - Cropped image file
   */
  async openAvatarCropper(file, options = {}) {
    const {
      width = 400,
      height = 400,
      title = "Adjust Your Image",
      confirmText = "Crop & Save",
      cancelText = "Cancel",
      shape = "square", // 'circle' or 'square'
    } = options;

    return new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.onload = (e) => {
        const imageUrl = e.target.result;
        const isCircle = shape === "circle";
        const maskClass = isCircle ? "rounded-full" : "rounded-lg";

        const modalBody = `
                    <div class="flex flex-col items-center">
                        <div class="relative w-full aspect-square max-w-[320px] bg-slate-100 dark:bg-slate-800 rounded-2xl overflow-hidden cursor-move mb-6 group" id="cropper-viewport">
                            <img src="${imageUrl}" id="cropper-img" class="absolute max-w-none origin-center" style="transform: translate(0,0) scale(1);">
                            <div class="absolute inset-0 border-[40px] border-black/50 pointer-events-none">
                                <div class="w-full h-full border-2 border-white/80 ${maskClass} shadow-[0_0_0_9999px_rgba(0,0,0,0.5)]"></div>
                            </div>
                            <div class="absolute bottom-4 left-4 right-4 text-[10px] text-white/50 text-center opacity-0 group-hover:opacity-100 transition-opacity">Drag to reposition • Scroll to zoom</div>
                        </div>
                        <div class="w-full space-y-4">
                            <div class="flex items-center space-x-4">
                                <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM10 7v3m0 0v3m0-3h3m-3 0H7" stroke-width="2"/></svg>
                                <input type="range" id="cropper-zoom" min="0.5" max="3" step="0.01" value="1" class="flex-1 h-1.5 bg-gray-200 dark:bg-gray-700 rounded-lg appearance-none cursor-pointer accent-blue-600">
                                <svg class="w-5 h-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM10 7v3m0 0v3m0-3h3m-3 0H7" stroke-width="2"/></svg>
                            </div>
                            <div class="flex justify-between items-center bg-blue-50 dark:bg-blue-900/20 p-3 rounded-xl border border-blue-100 dark:border-blue-900/30">
                                <span class="text-xs text-blue-600 dark:text-blue-400 font-medium">Preview</span>
                                <div class="w-10 h-10 ${maskClass} overflow-hidden border-2 border-white shadow-sm">
                                    <canvas id="cropper-preview" class="w-full h-full"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                `;

        this.dialog({
          title: title,
          body: modalBody,
          confirmText: confirmText,
          cancelText: cancelText,
          onConfirm: () => {
            const canvas = document.createElement("canvas");
            canvas.width = width;
            canvas.height = height;
            const ctx = canvas.getContext("2d");

            const img = document.getElementById("cropper-img");
            const viewport = document.getElementById("cropper-viewport");
            const vRect = viewport.getBoundingClientRect();
            const iRect = img.getBoundingClientRect();

            // Calculate crop area
            const scaleFactor = img.naturalWidth / iRect.width;
            const cropX = (vRect.left - iRect.left) * scaleFactor;
            const cropY = (vRect.top - iRect.top) * scaleFactor;
            const cropW = vRect.width * scaleFactor;
            const cropH = vRect.height * scaleFactor;

            ctx.drawImage(img, cropX, cropY, cropW, cropH, 0, 0, width, height);
            canvas.toBlob((blob) => {
              const newName = file.name.replace(/\.[^.]+$/, "") + ".png";
              const result = new File([blob], newName, { type: "image/png" });
              resolve(result);
            }, "image/png");
          },
          onCancel: () => reject("Canceled"),
        });

        // Cropper Logic
        const img = document.getElementById("cropper-img");
        const zoomIdx = document.getElementById("cropper-zoom");
        const viewport = document.getElementById("cropper-viewport");
        let isDragging = false;
        let startX,
          startY,
          translateX = 0,
          translateY = 0,
          scale = 1;

        const updateTransform = () => {
          img.style.transform = `translate(${translateX}px, ${translateY}px) scale(${scale})`;
          drawPreview();
        };

        const drawPreview = () => {
          const preview = document.getElementById("cropper-preview");
          if (!preview) return;
          preview.width = 100;
          preview.height = 100;
          const ctx = preview.getContext("2d");

          const vRect = viewport.getBoundingClientRect();
          const iRect = img.getBoundingClientRect();
          const s = img.naturalWidth / iRect.width;

          ctx.drawImage(
            img,
            (vRect.left - iRect.left) * s,
            (vRect.top - iRect.top) * s,
            vRect.width * s,
            vRect.height * s,
            0,
            0,
            100,
            100,
          );
        };

        img.onload = () => {
          // Initial fit
          const vRatio = viewport.offsetWidth / viewport.offsetHeight;
          const iRatio = img.naturalWidth / img.naturalHeight;
          if (iRatio > vRatio) {
            img.style.height = "100%";
          } else {
            img.style.width = "100%";
          }
          setTimeout(drawPreview, 50);
        };

        const startDrag = (e) => {
          // 阻止浏览器默认的图片拖拽行为，这是解决 PC 端点击后无法立即拖拽的关键
          if (e.type === "mousedown") e.preventDefault();

          isDragging = true;
          const clientX = e.type.startsWith("touch")
            ? e.touches[0].clientX
            : e.clientX;
          const clientY = e.type.startsWith("touch")
            ? e.touches[0].clientY
            : e.clientY;
          startX = clientX - translateX;
          startY = clientY - translateY;
          viewport.style.cursor = "grabbing";
        };

        const moveDrag = (e) => {
          if (!isDragging) return;
          // 移动端阻止默认滚动
          if (e.type === "touchmove") e.preventDefault();

          const clientX = e.type.startsWith("touch")
            ? e.touches[0].clientX
            : e.clientX;
          const clientY = e.type.startsWith("touch")
            ? e.touches[0].clientY
            : e.clientY;
          translateX = clientX - startX;
          translateY = clientY - startY;
          updateTransform();
        };

        const endDrag = () => {
          if (!isDragging) return;
          isDragging = false;
          viewport.style.cursor = "move";
        };

        // 为图片和容器禁用原生拖拽，并禁止文本选中
        img.draggable = false;
        viewport.style.userSelect = "none";

        viewport.onmousedown = startDrag;
        viewport.addEventListener("touchstart", startDrag, { passive: false });

        window.addEventListener("mousemove", moveDrag);
        window.addEventListener("touchmove", moveDrag, { passive: false });

        window.addEventListener("mouseup", endDrag);
        window.addEventListener("touchend", endDrag);

        zoomIdx.oninput = (e) => {
          scale = e.target.value;
          updateTransform();
        };

        viewport.onwheel = (e) => {
          e.preventDefault();
          scale = Math.min(Math.max(0.5, scale - e.deltaY * 0.001), 5);
          zoomIdx.value = scale;
          updateTransform();
        };
      };
      reader.readAsDataURL(file);
    });
  }

  // =========================================================================
  // Crypto (MD5)
  // =========================================================================
  md5(string) {
    function RotateLeft(lValue, iShiftBits) {
      return (lValue << iShiftBits) | (lValue >>> (32 - iShiftBits));
    }
    function AddUnsigned(lX, lY) {
      var lX4, lY4, lX8, lY8, lResult;
      lX8 = lX & 0x80000000;
      lY8 = lY & 0x80000000;
      lX4 = lX & 0x40000000;
      lY4 = lY & 0x40000000;
      lResult = (lX & 0x3fffffff) + (lY & 0x3fffffff);
      if (lX4 & lY4) return lResult ^ 0x80000000 ^ lX8 ^ lY8;
      if (lX4 | lY4) {
        if (lResult & 0x40000000) return lResult ^ 0xc0000000 ^ lX8 ^ lY8;
        else return lResult ^ 0x40000000 ^ lX8 ^ lY8;
      } else {
        return lResult ^ lX8 ^ lY8;
      }
    }
    function F(x, y, z) {
      return (x & y) | (~x & z);
    }
    function G(x, y, z) {
      return (x & z) | (y & ~z);
    }
    function H(x, y, z) {
      return x ^ y ^ z;
    }
    function I(x, y, z) {
      return y ^ (x | ~z);
    }
    function FF(a, b, c, d, x, s, ac) {
      a = AddUnsigned(a, AddUnsigned(AddUnsigned(F(b, c, d), x), ac));
      return AddUnsigned(RotateLeft(a, s), b);
    }
    function GG(a, b, c, d, x, s, ac) {
      a = AddUnsigned(a, AddUnsigned(AddUnsigned(G(b, c, d), x), ac));
      return AddUnsigned(RotateLeft(a, s), b);
    }
    function HH(a, b, c, d, x, s, ac) {
      a = AddUnsigned(a, AddUnsigned(AddUnsigned(H(b, c, d), x), ac));
      return AddUnsigned(RotateLeft(a, s), b);
    }
    function II(a, b, c, d, x, s, ac) {
      a = AddUnsigned(a, AddUnsigned(AddUnsigned(I(b, c, d), x), ac));
      return AddUnsigned(RotateLeft(a, s), b);
    }
    function ConvertToWordArray(string) {
      var lWordCount;
      var lMessageLength = string.length;
      var lNumberOfWords_temp1 = lMessageLength + 8;
      var lNumberOfWords_temp2 =
        (lNumberOfWords_temp1 - (lNumberOfWords_temp1 % 64)) / 64;
      var lNumberOfWords = (lNumberOfWords_temp2 + 1) * 16;
      var lWordArray = Array(lNumberOfWords - 1);
      var lBytePosition = 0;
      var lByteCount = 0;
      while (lByteCount < lMessageLength) {
        lWordCount = (lByteCount - (lByteCount % 4)) / 4;
        lBytePosition = (lByteCount % 4) * 8;
        lWordArray[lWordCount] =
          lWordArray[lWordCount] |
          (string.charCodeAt(lByteCount) << lBytePosition);
        lByteCount++;
      }
      lWordCount = (lByteCount - (lByteCount % 4)) / 4;
      lBytePosition = (lByteCount % 4) * 8;
      lWordArray[lWordCount] = lWordArray[lWordCount] | (0x80 << lBytePosition);
      lWordArray[lNumberOfWords - 2] = lMessageLength << 3;
      lWordArray[lNumberOfWords - 1] = lMessageLength >>> 29;
      return lWordArray;
    }
    function WordToHex(lValue) {
      var WordToHexValue = "",
        WordToHexValue_temp = "",
        lByte,
        lCount;
      for (lCount = 0; lCount <= 3; lCount++) {
        lByte = (lValue >>> (lCount * 8)) & 255;
        WordToHexValue_temp = "0" + lByte.toString(16);
        WordToHexValue =
          WordToHexValue +
          WordToHexValue_temp.substr(WordToHexValue_temp.length - 2, 2);
      }
      return WordToHexValue;
    }
    var x = ConvertToWordArray(string);
    var k, AA, BB, CC, DD, a, b, c, d;
    var S11 = 7,
      S12 = 12,
      S13 = 17,
      S14 = 22;
    var S21 = 5,
      S22 = 9,
      S23 = 14,
      S24 = 20;
    var S31 = 4,
      S32 = 11,
      S33 = 16,
      S34 = 23;
    var S41 = 6,
      S42 = 10,
      S43 = 15,
      S44 = 21;
    a = 0x67452301;
    b = 0xefcdab89;
    c = 0x98badcfe;
    d = 0x10325476;
    for (k = 0; k < x.length; k += 16) {
      AA = a;
      BB = b;
      CC = c;
      DD = d;
      a = FF(a, b, c, d, x[k + 0], S11, 0xd76aa478);
      d = FF(d, a, b, c, x[k + 1], S12, 0xe8c7b756);
      c = FF(c, d, a, b, x[k + 2], S13, 0x242070db);
      b = FF(b, c, d, a, x[k + 3], S14, 0xc1bdceee);
      a = FF(a, b, c, d, x[k + 4], S11, 0xf57c0faf);
      d = FF(d, a, b, c, x[k + 5], S12, 0x4787c62a);
      c = FF(c, d, a, b, x[k + 6], S13, 0xa8304613);
      b = FF(b, c, d, a, x[k + 7], S14, 0xfd469501);
      a = FF(a, b, c, d, x[k + 8], S11, 0x698098d8);
      d = FF(d, a, b, c, x[k + 9], S12, 0x8b44f7af);
      c = FF(c, d, a, b, x[k + 10], S13, 0xffff5bb1);
      b = FF(b, c, d, a, x[k + 11], S14, 0x895cd7be);
      a = FF(a, b, c, d, x[k + 12], S11, 0x6b901122);
      d = FF(d, a, b, c, x[k + 13], S12, 0xfd987193);
      c = FF(c, d, a, b, x[k + 14], S13, 0xa679438e);
      b = FF(b, c, d, a, x[k + 15], S14, 0x49b40821);
      a = GG(a, b, c, d, x[k + 1], S21, 0xf61e2562);
      d = GG(d, a, b, c, x[k + 6], S22, 0xc040b340);
      c = GG(c, d, a, b, x[k + 11], S23, 0x265e5a51);
      b = GG(b, c, d, a, x[k + 0], S24, 0xe9b6c7aa);
      a = GG(a, b, c, d, x[k + 5], S21, 0xd62f105d);
      d = GG(d, a, b, c, x[k + 10], S22, 0x2441453);
      c = GG(c, d, a, b, x[k + 15], S23, 0xd8a1e681);
      b = GG(b, c, d, a, x[k + 4], S24, 0xe7d3fbc8);
      a = GG(a, b, c, d, x[k + 9], S21, 0x21e1cde6);
      d = GG(d, a, b, c, x[k + 14], S22, 0xc33707d6);
      c = GG(c, d, a, b, x[k + 3], S23, 0xf4d50d87);
      b = GG(b, c, d, a, x[k + 8], S24, 0x455a14ed);
      a = GG(a, b, c, d, x[k + 13], S21, 0xa9e3e905);
      d = GG(d, a, b, c, x[k + 2], S22, 0xfcefa3f8);
      c = GG(c, d, a, b, x[k + 7], S23, 0x676f02d9);
      b = GG(b, c, d, a, x[k + 12], S24, 0x8d2a4c8a);
      a = HH(a, b, c, d, x[k + 5], S31, 0xfffa3942);
      d = HH(d, a, b, c, x[k + 8], S32, 0x8771f681);
      c = HH(c, d, a, b, x[k + 11], S33, 0x6d9d6122);
      b = HH(b, c, d, a, x[k + 14], S34, 0xfde5380c);
      a = HH(a, b, c, d, x[k + 1], S31, 0xa4beea44);
      d = HH(d, a, b, c, x[k + 4], S32, 0x4bdecfa9);
      c = HH(c, d, a, b, x[k + 7], S33, 0xf6bb4b60);
      b = HH(b, c, d, a, x[k + 10], S34, 0xbebfbc70);
      a = HH(a, b, c, d, x[k + 13], S31, 0x289b7ec6);
      d = HH(d, a, b, c, x[k + 0], S32, 0xeaa127fa);
      c = HH(c, d, a, b, x[k + 3], S33, 0xd4ef3085);
      b = HH(b, c, d, a, x[k + 6], S34, 0x4881d05);
      a = HH(a, b, c, d, x[k + 9], S31, 0xd9d4d039);
      d = HH(d, a, b, c, x[k + 12], S32, 0xe6db99e5);
      c = HH(c, d, a, b, x[k + 15], S33, 0x1fa27cf8);
      b = HH(b, c, d, a, x[k + 2], S34, 0xc4ac5665);
      a = II(a, b, c, d, x[k + 0], S41, 0xf4292244);
      d = II(d, a, b, c, x[k + 7], S42, 0x432aff97);
      c = II(c, d, a, b, x[k + 14], S43, 0xab9423a7);
      b = II(b, c, d, a, x[k + 5], S44, 0xfc93a039);
      a = II(a, b, c, d, x[k + 12], S41, 0x655b59c3);
      d = II(d, a, b, c, x[k + 3], S42, 0x8f0ccc92);
      c = II(c, d, a, b, x[k + 10], S43, 0xffeff47d);
      b = II(b, c, d, a, x[k + 1], S44, 0x85845dd1);
      a = II(a, b, c, d, x[k + 8], S41, 0x6fa87e4f);
      d = II(d, a, b, c, x[k + 15], S42, 0xfe2ce6e0);
      c = II(c, d, a, b, x[k + 6], S43, 0xa3014314);
      b = II(b, c, d, a, x[k + 13], S44, 0x4e0811a1);
      a = II(a, b, c, d, x[k + 4], S41, 0xf7537e82);
      d = II(d, a, b, c, x[k + 11], S42, 0xbd3af235);
      c = II(c, d, a, b, x[k + 2], S43, 0x2ad7d2bb);
      b = II(b, c, d, a, x[k + 9], S44, 0xeb86d391);
      a = AddUnsigned(a, AA);
      b = AddUnsigned(b, BB);
      c = AddUnsigned(c, CC);
      d = AddUnsigned(d, DD);
    }
    var temp = WordToHex(a) + WordToHex(b) + WordToHex(c) + WordToHex(d);
    return temp.toLowerCase();
  }

  // =========================================================================
  // Specialized UI Components
  // =========================================================================

  /**
   * Initializes a complete avatar upload workflow on a page
   * @param {Object} options - { container, img, dropZone, input, progressBar, [uploadUrl, csrf] }
   */
  initAvatarUpload(options) {
    const {
      container: containerSelector,
      img: imgSelector,
      dropZone: dropZoneSelector,
      input: inputSelector,
      progress: progressSelector,
      progressContainer: progressContainerSelector,
    } = options;

    const container = document.querySelector(containerSelector);
    const imgs = document.querySelectorAll(imgSelector);
    const dropZone = document.querySelector(dropZoneSelector);
    const input = document.querySelector(inputSelector);
    const progressBar = document.querySelector(progressSelector);
    const progressContainer = document.querySelector(progressContainerSelector);

    if (!dropZone || !input) return;

    // 1. Initialize Drag & Drop
    this.initDragDrop(dropZoneSelector, (files) => {
      if (files.length > 0) handleUpload(files[0]);
    });

    // 2. Click Triggers
    const trigger = () => input.click();
    dropZone.onclick = trigger;
    if (container) container.onclick = trigger;

    input.onchange = (e) => {
      if (e.target.files.length > 0) handleUpload(e.target.files[0]);
    };

    // 3. Centralized Upload Logic
    const handleUpload = async (file) => {
      if (!file.type.startsWith("image/")) {
        this.toast("Please select an image", "error");
        return;
      }

      try {
        // Interactive Cropper
        const croppedFile = await this.openAvatarCropper(file, {
          width: 400,
          height: 400,
          title: "Adjust Your Image",
          confirmText: "Crop & Save",
          cancelText: "Cancel",
          shape: "circle", // 'circle' or 'square'
        });

        // Show Progress
        if (progressContainer) progressContainer.classList.remove("hidden");
        if (progressBar) progressBar.style.width = "0%";

        const uploadUrl = dropZone.dataset.uploadUrl || "/api/user/avatar";
        const csrfToken = dropZone.dataset.csrf || "";

        const formData = new FormData();
        formData.append("avatar", croppedFile);
        if (csrfToken) formData.append("_csrf_token", csrfToken);

        // Perform Upload
        const res = await this.post(uploadUrl, formData, (percent) => {
          if (progressBar) progressBar.style.width = percent + "%";
        });

        if (res.code === 0) {
          this.toast("Avatar updated!", "success");
          // Local Refresh Preview
          const reader = new FileReader();
          reader.onload = (e) => {
            if (imgs) imgs.forEach((el) => (el.src = e.target.result));
          };
          reader.readAsDataURL(croppedFile);
        } else {
          this.toast(res.message || "Error", "error");
        }
      } catch (err) {
        if (err === "Canceled") return;
        this.toast("Upload failed: " + err.message, "error");
      } finally {
        if (progressContainer) progressContainer.classList.add("hidden");
        input.value = ""; // Reset input
      }
    };
  }

  // =========================================================================
  // Form & Error Handling
  // =========================================================================

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

  // =========================================================================
  // AJAX Modal & Remote Content Handling (Migrated from legacy scripts)
  // =========================================================================

  /**
   * Loads a remote URL and displays it in a modal
   * @param {string} url - Remote URL
   * @param {string} title - Modal Title
   * @param {Object} options - {size, delay, callback, arg}
   */
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

  getTitleBodyScriptCss(s) {
    // Preliminary clean for high performance
    s = s.trim().replace(/<!--[\s\S]*?-->/g, ""); // Remove all comments robustly

    // Extract scripts and links before heavy DOM parsing
    const script_sections = this.getScriptSection(s);
    const stylesheet_links = this.getStylesheetLink(s);
    const loadedScripts = this.getLoadedScript();
    const scriptSrcs = this.getScriptSrc(s).filter(
      (src) => !loadedScripts.includes(src),
    );

    // Use DOMParser to safely extract and clean the body
    const parser = new DOMParser();
    const doc = parser.parseFromString(s, "text/html");

    // Extract Title
    let title = "";
    const titleEl = doc.querySelector("title");
    if (titleEl) title = titleEl.innerText;

    // Extract Body/Content
    let contentParent = doc.body;
    const ajaxBody =
      doc.querySelector(".ajax-body") || doc.querySelector("#body");
    if (ajaxBody) contentParent = ajaxBody;

    // Security: Remove side-effect tags from the content
    const sideEffectTags = contentParent.querySelectorAll(
      "script, link, style, meta, title, iframe",
    );
    sideEffectTags.forEach((tag) => tag.remove());

    let body = contentParent.innerHTML;

    // Fallback for non-standard fragment returns
    if (!body.trim() && s.length > 0) {
      // If body is empty but string exists, it might be a naked fragment
      // We'll trust the sanitizer if it's not a full HTML document
      if (!s.toLowerCase().includes("<body")) {
        body = s;
      }
    }

    return {
      title,
      body,
      script_sections,
      script_srcs: scriptSrcs,
      stylesheet_links,
    };
  }

  getLoadedScript() {
    return Array.from(document.querySelectorAll("script[src]")).map(
      (s) => s.src,
    );
  }

  getScriptSection(s) {
    return (
      s.match(/<script[^>]+ajax-eval="true"[^>]*>([\s\S]+?)<\/script>/gi) || []
    );
  }

  getScriptSrc(s) {
    const matches =
      s.match(/<script[^>]*?src=\s*"([^"]+)"[^>]*><\/script>/gi) || [];
    return matches.map((m) => m.match(/src=\s*"([^"]+)"/i)[1]);
  }

  getStylesheetLink(s) {
    const matches = s.match(/<link[^>]*?href=\s*"([^"]+)"[^>]*>/gi) || [];
    return matches.map((m) => m.match(/href=\s*"([^"]+)"/i)[1]);
  }

  evalScript(arr, args) {
    if (!arr) return;
    arr.forEach((script) => {
      const code = script.replace(/<script([^>]*)>([\s\S]+?)<\/script>/i, "$2");
      try {
        const scriptElement = document.createElement("script");
        scriptElement.textContent = code;
        document.body.appendChild(scriptElement);
      } catch (e) {
        console.error("evalScript error:", e);
      }
    });
    if (args && args.callback && typeof args.callback === "function") {
      args.callback(args.jmodal, args.arg);
    } else if (
      args &&
      args.callback &&
      typeof window[args.callback] === "function"
    ) {
      window[args.callback](args.jmodal, args.arg);
    }
  }

  evalStylesheet(arr) {
    if (!arr) return;
    window.requiredCss = window.requiredCss || {};
    arr.forEach((link) => {
      if (!window.requiredCss[link]) {
        const linkElement = document.createElement("link");
        linkElement.rel = "stylesheet";
        linkElement.href = link;
        document.head.appendChild(linkElement);
        window.requiredCss[link] = true;
      }
    });
  }

  requireScripts(scripts, callback) {
    Promise.all(scripts.map((src) => this.loadScript(src)))
      .then(callback)
      .catch((err) => console.error("Error loading scripts:", err));
  }

  loadScript(src) {
    return new Promise((resolve, reject) => {
      const script = document.createElement("script");
      script.src = src;
      script.onload = resolve;
      script.onerror = reject;
      document.head.appendChild(script);
    });
  }
}

// =============================================================================
// Helper Classes
// =============================================================================

class GlobalFormHandler {
  constructor(selector, options) {
    this.selector = selector;
    this.options = options || {};
    this.ui = this.options.ui;
    this.init();
  }

  init() {
    document.addEventListener("submit", async (e) => {
      const form = e.target.closest(this.selector);
      if (form) {
        e.preventDefault();
        await this.submit(form);
      }
    });
  }

  async submit(form) {
    const btn = form.querySelector('[type="submit"]');
    if (btn && btn.disabled) return; // Prevent double submission

    let orgText = "";
    // Priority: btn[data-loading-text] > form[data-loading-text] > options.loadingText > default
    const customLoadingText =
      (btn ? btn.dataset.loadingText : null) ||
      form.dataset.loadingText ||
      this.options.loadingText ||
      "Loading...";

    const modalSize = form.dataset.modalSize || this.options.modalSize || "md";
    const timeout = form.dataset.timeout || this.options.timeout || 1;
    const loadingHtml = `<span class="animate-spin inline-block mr-1">↻</span> ${customLoadingText}`;

    if (btn) {
      orgText = btn.innerHTML;
      btn.disabled = true;
      btn.innerHTML = loadingHtml;
    }

    try {
      const rteTextarea = form.querySelector('textarea[data-well-rte="1"]');
      if (rteTextarea && rteTextarea._wellRTE && typeof rteTextarea._wellRTE.updateSource === 'function') {
        rteTextarea._wellRTE.updateSource();
      }
      const data = new FormData(form);
      const res = await window.wellcms.post(
        form.action || window.location.href,
        data,
      );

      if (btn) {
        btn.disabled = false;
        btn.innerHTML = res.code === 0 ? "Success" : orgText; // Revert text on error to allow retry
        if (res.code === 0) setTimeout(() => (btn.innerHTML = orgText), 2000);
      }

      if (res.code === 0) {
        // 1. Success UX: Non-blocking feedback
        if (res.data?.alert) {
          this.ui.success(
            res.message || "Success",
            res.data.title || "Success",
            modalSize,
          );
        } else {
          this.ui.toast(res.message || "Success", "success");
        }

        // 2. Consistent Redirect Closure
        if (res.data?.redirect?.url) {
          // Priority Chain: Server Delay > Local Data Attribute > Local Option Timeout > Default 1s
          const delaySec = res.data.redirect.delay || timeout;
          const delayMs = delaySec * 1000;

          // Dispatch success event before redirect
          form.dispatchEvent(new CustomEvent('wellcms:form-success', { detail: res }));

          setTimeout(
            () => (window.location.href = res.data.redirect.url),
            delayMs,
          );
        } else {
          // Dispatch success event even if no redirect
          form.dispatchEvent(new CustomEvent('wellcms:form-success', { detail: res }));
        }
      } else {
        // 3. Error UX: Field Specific or Blocking Alert
        if (res.data?.field) {
          this.ui.showInputError(res.data.field, res.message);
        } else if (
          res.message &&
          document.querySelector(`[name="${res.message}"]`)
        ) {
          this.ui.showInputError(res.message, "Invalid Input");
        } else {
          // Failure uses Blocking alert to ensure it's read
          this.ui.error(res.message || "Operation Failed", "Error", modalSize);
        }
      }
    } catch (err) {
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = orgText;
      }
      console.error("GlobalFormHandler Error:", err);
      if (this.ui && typeof this.ui.error === "function") {
        this.ui.error("Network or Server Error: " + err.message);
      }
    }
  }
}

class GlobalClickHandler {
  constructor(options) {
    this.ui = options.ui;
    this.init();
  }

  init() {
    document.addEventListener("click", async (e) => {
      const el = e.target.closest(".ajax-get, .ajax-post, [data-modal-title]");
      if (!el || el.classList.contains("loading")) return;

      e.preventDefault();
      e.stopPropagation();
      e.stopImmediatePropagation();

      // Handle Declarative AJAX Modal
      if (el.hasAttribute("data-modal-title")) {
        const url =
          el.getAttribute("data-modal-url") || el.getAttribute("href");
        const title = el.getAttribute("data-modal-title");
        const arg = el.getAttribute("data-modal-arg");
        const callback = el.getAttribute("data-modal-callback");
        const size = el.getAttribute("data-modal-size");

        this.ui.ajaxModal(url, title, { size, callback, arg });
        return;
      }

      const url = el.getAttribute("data-href") || el.getAttribute("href") || el.getAttribute("data-url");
      if (!url) {
        console.error("No data-href or href attribute found");
        return;
      }

      const confirmMsg = el.getAttribute("data-confirm");
      const argStr = el.getAttribute("data-arg") || el.getAttribute("data-json");
      let postData = null;
      if (argStr) {
        try {
          postData = JSON.parse(argStr);
        } catch (e) {
          console.error("Failed to parse data-arg:", argStr);
        }
      }

      //console.log("Click Handler Triggered:", {argStr });
      const executeAction = async () => {
        el.classList.add("loading");
        const orgHtml = el.innerHTML;
        try {
          const isPost = el.classList.contains("ajax-post");
          const res = await (isPost
            ? window.wellcms.post(url, postData)
            : window.wellcms.get(url));

          const redirectUrl = res.data?.redirect?.url;
          const delaySec = res.data?.redirect?.delay || 1; // Default 1s for click-action redirect
          const title = res.data?.title || (res.code === 0 ? "Success" : "Error");
          if (res.message) {
            const isSuccess = res.code === 0;
            const showModal = res.data?.alert || res.data?.modal;
            if (!showModal) {
              this.ui.toast(res.message, isSuccess ? "success" : "error");
            } else {
              this.ui.alert(res.message, title);
            }
          }

          if (redirectUrl) {
            setTimeout(
              () => (window.location.href = redirectUrl),
              delaySec * 1000,
            );
          } else if (res.code === 0 && el.dataset.reload !== "false") {
            setTimeout(() => window.location.reload(), 1000);
          }
        } catch (err) {
          console.error(err);
          this.ui.toast(err.message || "Request failed", "error");
        } finally {
          el.classList.remove("loading");
        }
      };

      if (confirmMsg) {
        this.ui.confirm(confirmMsg, executeAction);
      } else {
        await executeAction();
      }
    });
  }
}

// Initialize
window.wellcms = new WellCMSUI();
window.modal = window.wellcms;
