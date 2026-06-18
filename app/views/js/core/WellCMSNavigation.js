/**
 * WellCMS Navigation Module
 */
const WellCMSNavigation = {
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
},
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
};