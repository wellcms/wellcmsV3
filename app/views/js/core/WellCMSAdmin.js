/**
 * WellCMS Admin Module
 */
const WellCMSAdmin = {
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
};