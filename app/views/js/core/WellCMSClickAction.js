/**
 * WellCMS Click Action Module
 */
const WellCMSClickAction = {
initClickActions() {
  this.clickHandler = new GlobalClickHandler({ ui: this });
}
};
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