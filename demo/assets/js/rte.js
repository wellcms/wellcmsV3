/**
 * WellCMS Rich Text Editor (WellRTE)
 * A lightweight, vanilla JS editor with Markdown support, Image Upload, and XSS protection.
 */
class WellRTE {
  constructor(selector, options = {}) {
    this.sourceEl = document.querySelector(selector);
    if (!this.sourceEl) {
      console.error("WellRTE: Target element not found", selector);
      return;
    }

    this.options = {
      height: options.height || "h-64",
      autoGrow: options.autoGrow === true, // Default false unless specified
      maxHeight: options.maxHeight || "85vh", // Max height constraint
      placeholder: options.placeholder || "Type something...",
      uploadHandler:
        options.uploadHandler || this.defaultUploadHandler.bind(this),
      ...options,
    };

    this.editorId = "rte_" + Math.random().toString(36).substr(2, 9);
    this.mentionTimer = null;
    this.selectedImg = null;
    this.init();
  }

  destroy() {
    if (this.handleStickyBind) {
      window.removeEventListener("scroll", this.handleStickyBind);
      window.removeEventListener("resize", this.handleStickyBind);
    }
    if (this.isSticky && this.toolbarEl) {
      // Revert stuck toolbar if destroyed while sticky
      if (this.toolbarPlaceholder && this.toolbarPlaceholder.parentNode) {
        this.toolbarPlaceholder.parentNode.insertBefore(
          this.toolbarEl,
          this.toolbarPlaceholder,
        );
        this.toolbarPlaceholder.remove();
      }
      this.toolbarEl.classList.remove("rte-is-sticky");
      Object.assign(this.toolbarEl.style, {
        position: "",
        top: "",
        left: "",
        width: "",
        zIndex: "",
        boxShadow: "",
        borderBottomWidth: "",
      });
    }
  }

  init() {
    // 1. Inject Styles (if not present)
    this.injectStyles();

    // 2. Build DOM
    this.wrapper = document.createElement("div");
    this.wrapper.className =
      "border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden bg-white dark:bg-slate-800 relative";

    // Hide original source but keep it in DOM for form submission
    this.sourceEl.classList.add("hidden");
    this.sourceEl.parentNode.insertBefore(this.wrapper, this.sourceEl);
    // Move source inside wrapper (optional, but keeps things tidy)
    this.wrapper.appendChild(this.sourceEl);

    // Render Toolbar & Editor
    this.wrapper.insertAdjacentHTML("afterbegin", this.getTemplate());

    // References
    this.editorEl = this.wrapper.querySelector(".rte-content");
    this.toolbarEl = this.wrapper.querySelector(".rte-toolbar-scroll");
    /* this.scrollHintEl = this.wrapper.querySelector('.rte-scroll-hint'); */
    this.linkModal = this.wrapper.querySelector(".rte-link-modal");
    this.imageInput = this.wrapper.querySelector(".rte-image-input");

    // 3. Initialize Content
    this.editorEl.innerHTML =
      this.sourceEl.value || `<p>${this.options.placeholder}</p>`;

    // 4. Bind Basic Events
    this.bindEvents();

    // 5. Initial Sync
    this.updateSource();

    // 6. Conditional Features
    // Upload (Image Btn & DragDrop)
    if (typeof this.options.uploadHandler === "function") {
      this.bindDragDrop();
      // Ensure button is visible (it is by default, but strictly we could toggle)
      const imgBtn = this.wrapper.querySelector("#rte-btn-image");
      if (imgBtn) imgBtn.classList.remove("hidden");
    } else {
      // Hide Image Button if no handler
      const imgBtn = this.wrapper.querySelector("#rte-btn-image");
      if (imgBtn) imgBtn.classList.add("hidden");
      // Also hide file input
      const fileInput = this.wrapper.querySelector(".rte-image-input");
      if (fileInput) fileInput.classList.add("hidden");
    }

    // Mentions & Slash Commands
    // Always init, internal logic handles feature toggling based on handler existence
    this.initMention();

    // Auto Save
    if (typeof this.options.autoSaveHandler === "function") {
      this.initAutoSave();
    }

    // Counter (Always on? Or optional? User didn't specify switch for counter, only injection ones)
    // Let's keep counter always on for now as it doesn't need injection.
    this.initCounter();

    // Emoji (User didn't specify switch, usually client side is fine. Keep it.)
    this.bindEmojiEvents();
  }

  initAutoSave() {
    // Debounce Save (e.g. 2s after stop typing)
    let timeout;
    const handler = this.options.autoSaveHandler;

    const save = (isManual = false) => {
      clearTimeout(timeout);
      // If manual, save immediately. If auto, debounce.
      const delay = isManual ? 0 : 2000;

      timeout = setTimeout(() => {
        const content = this.editorEl.innerHTML;
        this.showSaveStatus(isManual ? "Saving..." : "Auto-Saving...");

        // Wrap in try-catch/promise if handler returns promise
        Promise.resolve(handler(content))
          .then(() => {
            this.showSaveStatus("Saved");
          })
          .catch(() => {
            this.showSaveStatus("Error");
          });
      }, delay);
    };

    this.editorEl.addEventListener("input", () => save(false));

    // Manual Button
    const btn = this.wrapper.querySelector(".rte-save-btn");
    if (btn) {
      btn.addEventListener("click", () => save(true));
    }
  }

  showSaveStatus(msg) {
    // Reuse reading time element or add a new one?
    // Let's use a subtle indicator or just log for now to keep UI clean,
    // OFF-SPEC: User didn't ask for UI, but "保命" needs reassurance.
    // Let's append a small dot or text to footer if it exists.
    const footer = this.wrapper.querySelector(".rte-footer");
    if (footer) {
      let statusEl = footer.querySelector(".rte-save-status");
      if (!statusEl) {
        statusEl = document.createElement("span");
        statusEl.className = "rte-save-status text-xs ml-2 opacity-70";
        footer.appendChild(statusEl);
      }
      statusEl.textContent = msg;

      if (msg === "Saved") {
        setTimeout(() => {
          statusEl.textContent = "";
        }, 2000);
      }
    }
  }
  initCounter() {
    this.charsEl = this.wrapper.querySelector(".rte-chars");
    this.readTimeEl = this.wrapper.querySelector(".rte-read-time");

    const update = () => this.updateCounter();
    this.editorEl.addEventListener("input", update);
    // Initial
    update();
  }

  updateCounter() {
    const text = this.editorEl.innerText || "";
    // CJK Friendly: Count characters, not just space-separated words
    const charCount = text.replace(/[\n\r]/g, "").length;

    // Approx 300 chars/min for reading (Social/Light)
    const readTime = Math.ceil(charCount / 300);

    if (this.charsEl) this.charsEl.textContent = `${charCount} chars`;
    if (this.readTimeEl) this.readTimeEl.textContent = `${readTime} min read`;
  }

  initMention() {
    // Create Dropdown (Singleton)
    if (!this.mentionList) {
      this.mentionList = document.createElement("div");
      this.mentionList.className = "rte-mention-list hidden";
      document.body.appendChild(this.mentionList);
    }

    // State
    this.mentionActive = false;
    this.mentionType = null; // '@', '#', or '/'
    this.mentionQuery = "";
    this.mentionIndex = 0;
    this.mentionResults = [];

    // Static Slash Commands (Grouped)
    this.slashCommands = [
      // Headings
      {
        cmd: "formatBlock",
        val: "p",
        label: "Paragraph",
        icon: "¶",
        group: "Basic",
      },
      {
        cmd: "formatBlock",
        val: "h1",
        label: "Heading 1",
        icon: "H1",
        group: "Basic",
      },
      {
        cmd: "formatBlock",
        val: "h2",
        label: "Heading 2",
        icon: "H2",
        group: "Basic",
      },
      {
        cmd: "formatBlock",
        val: "h3",
        label: "Heading 3",
        icon: "H3",
        group: "Basic",
      },
      {
        cmd: "formatBlock",
        val: "h4",
        label: "Heading 4",
        icon: "H4",
        group: "Basic",
      },
      {
        cmd: "formatBlock",
        val: "h5",
        label: "Heading 5",
        icon: "H5",
        group: "Basic",
      },
      {
        cmd: "formatBlock",
        val: "h6",
        label: "Heading 6",
        icon: "H6",
        group: "Basic",
      },
      { cmd: "bold", val: null, label: "Bold", icon: "B", group: "Basic" },
      { cmd: "italic", val: null, label: "Italic", icon: "I", group: "Basic" },
      {
        cmd: "underline",
        val: null,
        label: "Underline",
        icon: "U",
        group: "Basic",
      },
      {
        cmd: "strikeThrough",
        val: null,
        label: "Strike",
        icon: "S",
        group: "Basic",
      },
      // Lists
      {
        cmd: "insertUnorderedList",
        val: null,
        label: "Bullet List",
        icon: "•",
        group: "Lists",
      },
      {
        cmd: "insertOrderedList",
        val: null,
        label: "Ordered List",
        icon: "1.",
        group: "Lists",
      },
      { cmd: "formatBlock", val: "blockquote", label: "Quote", icon: "“" },
      { cmd: "formatBlock", val: "pre", label: "Code Block", icon: "<>" },
      // Media
      { cmd: "insertImage", val: "custom", label: "Upload Image", icon: "📷" },
      { cmd: "insertTable", val: "custom", label: "Table", icon: "▦" },
      { cmd: "insertHorizontalRule", val: null, label: "Divider", icon: "—" },
      // Alignment
      {
        cmd: "justifyLeft",
        val: null,
        label: "Align Left",
        icon: '<svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M4 12h10M4 18h16"/></svg>',
        group: "Align",
      },
      {
        cmd: "justifyCenter",
        val: null,
        label: "Align Center",
        icon: '<svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M7 12h10M4 18h16"/></svg>',
        group: "Align",
      },
      {
        cmd: "justifyRight",
        val: null,
        label: "Align Right",
        icon: '<svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M10 12h10M4 18h16"/></svg>',
        group: "Align",
      },

      // Fonts
      {
        cmd: "fontName",
        val: "system-ui, -apple-system, sans-serif",
        label: "System",
        icon: "F",
        group: "Font",
      },
      // Chinese
      {
        cmd: "fontName",
        val: "'Microsoft YaHei', '微软雅黑', sans-serif",
        label: "微软雅黑",
        icon: "F",
        group: "Font",
      },
      {
        cmd: "fontName",
        val: "SimSun, '宋体', serif",
        label: "宋体",
        icon: "F",
        group: "Font",
      },
      {
        cmd: "fontName",
        val: "SimHei, '黑体', sans-serif",
        label: "黑体",
        icon: "F",
        group: "Font",
      },
      {
        cmd: "fontName",
        val: "KaiTi, '楷体', serif",
        label: "楷体",
        icon: "F",
        group: "Font",
      },
      {
        cmd: "fontName",
        val: "FangSong, '仿宋', serif",
        label: "仿宋",
        icon: "F",
        group: "Font",
      },
      {
        cmd: "fontName",
        val: "'PingFang SC', sans-serif",
        label: "苹方",
        icon: "F",
        group: "Font",
      },
      // Sans Serif
      {
        cmd: "fontName",
        val: "Arial, sans-serif",
        label: "Arial",
        icon: "F",
        group: "Font",
      },
      {
        cmd: "fontName",
        val: "'Arial Black', sans-serif",
        label: "Arial Black",
        icon: "F",
        group: "Font",
      },
      {
        cmd: "fontName",
        val: "Helvetica, sans-serif",
        label: "Helvetica",
        icon: "F",
        group: "Font",
      },
      {
        cmd: "fontName",
        val: "Verdana, sans-serif",
        label: "Verdana",
        icon: "F",
        group: "Font",
      },
      {
        cmd: "fontName",
        val: "Tahoma, sans-serif",
        label: "Tahoma",
        icon: "F",
        group: "Font",
      },
      {
        cmd: "fontName",
        val: "'Trebuchet MS', sans-serif",
        label: "Trebuchet",
        icon: "F",
        group: "Font",
      },
      {
        cmd: "fontName",
        val: "Geneva, sans-serif",
        label: "Geneva",
        icon: "F",
        group: "Font",
      },
      {
        cmd: "fontName",
        val: "Optima, sans-serif",
        label: "Optima",
        icon: "F",
        group: "Font",
      },
      // Serif
      {
        cmd: "fontName",
        val: "Georgia, serif",
        label: "Georgia",
        icon: "F",
        group: "Font",
      },
      {
        cmd: "fontName",
        val: "'Times New Roman', serif",
        label: "Times",
        icon: "F",
        group: "Font",
      },
      {
        cmd: "fontName",
        val: "'Palatino Linotype', 'Book Antiqua', Palatino, serif",
        label: "Palatino",
        icon: "F",
        group: "Font",
      },
      {
        cmd: "fontName",
        val: "'Bookman Old Style', serif",
        label: "Bookman",
        icon: "F",
        group: "Font",
      },
      // Other
      {
        cmd: "fontName",
        val: "Impact, fantasy",
        label: "Impact",
        icon: "F",
        group: "Font",
      },
      {
        cmd: "fontName",
        val: "'Comic Sans MS', cursive",
        label: "Comic Sans",
        icon: "F",
        group: "Font",
      },
      {
        cmd: "fontName",
        val: "'Courier New', monospace",
        label: "Courier",
        icon: "F",
        group: "Font",
      },
      {
        cmd: "fontName",
        val: "Monaco, monospace",
        label: "Monaco",
        icon: "F",
        group: "Font",
      },
      {
        cmd: "fontName",
        val: "Consolas, monospace",
        label: "Consolas",
        icon: "F",
        group: "Font",
      },

      // Font Size
      {
        cmd: "fontSize",
        val: "12px",
        label: "Size 12px",
        icon: "S",
        group: "Size",
      },
      {
        cmd: "fontSize",
        val: "14px",
        label: "Size 14px",
        icon: "S",
        group: "Size",
      },
      {
        cmd: "fontSize",
        val: "16px",
        label: "Size 16px",
        icon: "S",
        group: "Size",
      },
      {
        cmd: "fontSize",
        val: "18px",
        label: "Size 18px",
        icon: "S",
        group: "Size",
      },
      {
        cmd: "fontSize",
        val: "24px",
        label: "Size 24px",
        icon: "S",
        group: "Size",
      },
      {
        cmd: "fontSize",
        val: "36px",
        label: "Size 36px",
        icon: "S",
        group: "Size",
      },
      {
        cmd: "fontSize",
        val: "46px",
        label: "Size 46px",
        icon: "S",
        group: "Size",
      },
      {
        cmd: "fontSize",
        val: "48px",
        label: "Size 48px",
        icon: "S",
        group: "Size",
      },
      {
        cmd: "fontSize",
        val: "72px",
        label: "Size 72px",
        icon: "S",
        group: "Size",
      },
      { cmd: "removeFormat", val: null, label: "Clear Format", icon: "⌫" },
    ];

    const editor = this.editorEl;

    // Input Listener
    editor.addEventListener("keyup", (e) => {
      if (this.isSelectionMention) {
        if (e.key === "Escape") this.closeMention();
        return;
      }

      const sel = window.getSelection();
      if (!sel.rangeCount) return;

      const range = sel.getRangeAt(0);
      let text = "";
      if (range.startContainer.nodeType === 3) {
        text = range.startContainer.textContent;
      } else {
        return;
      }
      const caretPos = range.startOffset;

      // Trigger Checks
      const charBefore = text.slice(caretPos - 1, caretPos);

      if (["@", "#", "/"].includes(charBefore)) {
        // Check handlers
        if (
          ["@", "#"].includes(charBefore) &&
          typeof this.options.mentionHandler !== "function"
        )
          return;
        // Slash commands always enabled? Or config? Let's assume enabled for P1.

        this.startMention(charBefore, range);
      } else if (this.mentionActive) {
        if ([" ", "Enter", "Escape"].includes(e.key)) {
          if (e.key !== "Enter") this.closeMention();
        } else {
          const lastTrigger = text.lastIndexOf(this.mentionType, caretPos);
          if (lastTrigger !== -1) {
            // Limit check for @/# only
            if (["@", "#"].includes(this.mentionType)) {
              const limit = this.mentionType === "@" ? 10 : 5;
              const count = this.editorEl.querySelectorAll(
                this.mentionType === "@" ? ".text-blue-600" : ".text-pink-600",
              ).length;
              if (count >= limit) return;
            }

            this.mentionQuery = text.slice(lastTrigger + 1, caretPos);
            this.renderMentionList();
          } else {
            this.closeMention();
          }
        }
      }
    });

    // Navigation & Selection Trigger
    editor.addEventListener("keydown", (e) => {
      if (e.key === "/" && !this.mentionActive) {
        const sel = window.getSelection();
        if (
          sel.rangeCount > 0 &&
          !sel.isCollapsed &&
          this.editorEl.contains(sel.anchorNode)
        ) {
          // "/" pressed with selection: Trigger Slash Menu WITHOUT replacing text
          e.preventDefault();
          this.isSelectionMention = true;
          this.lastRange = sel.getRangeAt(0).cloneRange();
          this.startMention("/", this.lastRange);
          return;
        }
      }

      if (!this.mentionActive) return;

      if (e.key === "ArrowDown") {
        e.preventDefault();
        this.mentionIndex++;
        if (this.mentionIndex >= this.mentionResults.length)
          this.mentionIndex = 0;
        this.updateActiveItem();
      } else if (e.key === "ArrowUp") {
        e.preventDefault();
        this.mentionIndex--;
        if (this.mentionIndex < 0)
          this.mentionIndex = this.mentionResults.length - 1;
        this.updateActiveItem();
      } else if (e.key === "Enter" || e.key === "Tab") {
        e.preventDefault();
        this.selectMention();
      }
    });

    // Image Controls
    this.editorEl.addEventListener("click", (e) => {
      if (e.target.tagName === "IMG") {
        this.showImageToolbar(e.target);
      } else {
        this.hideImageToolbar();
      }
    });

    this.wrapper
      .querySelectorAll(".rte-image-toolbar button")
      .forEach((btn) => {
        btn.addEventListener("click", (e) => {
          e.preventDefault();
          e.stopPropagation();
          if (btn.dataset.align) {
            this.applyImageAlignment(btn.dataset.align);
          } else if (btn.dataset.size) {
            this.applyImageSize(btn.dataset.size);
          }
        });
      });

    // Close on Click Outside
    document.addEventListener("click", (e) => {
      if (this.mentionActive && !this.mentionList.contains(e.target)) {
        this.closeMention();
      }
      if (this.selectedImg && !this.wrapper.contains(e.target)) {
        this.hideImageToolbar();
      }
    });
  }

  closeMention() {
    this.mentionActive = false;
    this.isSelectionMention = false;
    this.mentionList.classList.add("hidden");
  }

  showImageToolbar(img) {
    this.hideImageToolbar();
    this.selectedImg = img;
    img.classList.add("selected");

    const toolbar = this.wrapper.querySelector(".rte-image-toolbar");
    toolbar.classList.remove("hidden");

    const rect = img.getBoundingClientRect();
    const wrapperRect = this.wrapper.getBoundingClientRect();

    // Position toolbar above the image
    const top = rect.top - wrapperRect.top - 45;
    const left =
      rect.left - wrapperRect.left + rect.width / 2 - toolbar.offsetWidth / 2;

    toolbar.style.top = Math.max(5, top) + "px";
    toolbar.style.left =
      Math.max(5, Math.min(left, wrapperRect.width - toolbar.offsetWidth - 5)) +
      "px";
  }

  hideImageToolbar() {
    if (this.selectedImg) {
      this.selectedImg.classList.remove("selected");
      this.selectedImg = null;
    }
    const toolbar = this.wrapper.querySelector(".rte-image-toolbar");
    if (toolbar) toolbar.classList.add("hidden");
  }

  applyImageAlignment(align) {
    if (!this.selectedImg) return;
    const parent = this.selectedImg.parentElement;
    if (parent && parent.tagName === "P") {
      parent.style.textAlign = align;
    } else {
      // Wrap in P if not already
      const p = document.createElement("p");
      p.style.textAlign = align;
      this.selectedImg.parentNode.insertBefore(p, this.selectedImg);
      p.appendChild(this.selectedImg);
    }
    this.updateSource();
    this.showImageToolbar(this.selectedImg); // Reposition
  }

  applyImageSize(size) {
    if (!this.selectedImg) return;
    this.selectedImg.style.width = size;
    this.updateSource();
    this.showImageToolbar(this.selectedImg); // Reposition
  }

  startMention(type, range) {
    if (type !== "/") this.isSelectionMention = false;
    // Enforce Limits (Only for @/#)
    if (["@", "#"].includes(type)) {
      const limit = type === "@" ? 10 : 5;
      const count = this.editorEl.querySelectorAll(
        type === "@" ? ".text-blue-600" : ".text-pink-600",
      ).length;

      if (count >= limit) {
        this.mentionList.classList.remove("hidden");
        const rect = range.getBoundingClientRect();
        this.mentionList.style.top = rect.bottom + window.scrollY + 5 + "px";
        this.mentionList.style.left = rect.left + window.scrollX + "px";
        this.mentionList.innerHTML = `<div class="p-2 text-red-500 text-xs">Max ${limit} reached</div>`;
        return;
      }
    }

    this.mentionActive = true;
    this.mentionType = type;
    this.mentionQuery = "";
    this.mentionIndex = 0;

    // Position Dropdown Smartly
    const rect = range.getBoundingClientRect();
    this.mentionList.style.top = rect.bottom + window.scrollY + 5 + "px";

    // Check if near right edge (increased threshold for safety)
    if (rect.left + 300 > window.innerWidth) {
      this.mentionList.style.left = "auto";
      // Align menu's right edge to cursor's right edge
      this.mentionList.style.right =
        document.documentElement.clientWidth -
        rect.right -
        window.scrollX +
        "px";
    } else {
      this.mentionList.style.left = rect.left + window.scrollX + "px";
      this.mentionList.style.right = "auto";
    }
    this.mentionList.classList.remove("hidden");

    this.renderMentionList();
  }

  async renderMentionList() {
    try {
      // Distinguish Type
      if (this.mentionType === "/") {
        // Local Slash Commands
        const q = this.mentionQuery.toLowerCase();
        if (!q) {
          // Group Mode (Submenu Style)
          // Group Mode (Mixed: Groups + Root Items)
          // Group Mode (Mixed: Groups + Root Items)
          const activeGroups = ["Basic", "Lists", "Align", "Font", "Size"];
          const groups = activeGroups
            .map((g) => {
              let icon = "¶"; // Default
              if (g === "Lists") icon = "•";
              else if (g === "Align") icon = "≡";
              else if (g === "Font") icon = "F";
              else if (g === "Size") icon = "S";

              return {
                isGroup: true,
                label: g,
                icon: icon,
                children: this.slashCommands.filter((c) => c.group === g),
              };
            })
            .filter((g) => g.children && g.children.length > 0);

          const rootItems = this.slashCommands.filter((c) => !c.group);

          this.mentionResults = [...groups, ...rootItems];
        } else {
          // Search Mode (Flat)
          this.mentionResults = this.slashCommands.filter(
            (cmd) =>
              cmd.label.toLowerCase().includes(q) ||
              cmd.cmd.toLowerCase().includes(q),
          );
        }
      } else {
        // External Mentions
        const results = await this.options.mentionHandler(
          this.mentionType,
          this.mentionQuery,
        );
        this.mentionResults = results || [];
      }

      if (this.mentionResults.length === 0) {
        this.mentionList.innerHTML = `<div class="p-2 text-gray-400 text-xs">No matches</div>`;
        return;
      }

      // Render
      this.mentionList.innerHTML = this.mentionResults
        .map((item, i) => {
          const active = i === this.mentionIndex ? "active" : "";

          if (item.isGroup) {
            // Render Group with Submenu
            const subItems = item.children
              .map(
                (child, ci) => `
                        <div class="rte-submenu-item p-2 hover:bg-gray-100 dark:hover:bg-slate-700 cursor-pointer flex items-center gap-2" data-group="${i}" data-child="${ci}">
                            <div class="w-5 h-5 flex items-center justify-center bg-gray-100 dark:bg-slate-600 rounded text-xs text-gray-500 font-bold">${child.icon}</div>
                            <span class="text-sm whitespace-nowrap text-gray-700 dark:text-gray-300">${child.label}</span>
                        </div>
                    `,
              )
              .join("");

            return `
                        <div class="rte-mention-item group relative ${active}" data-idx="${i}">
                            <div class="w-6 h-6 flex items-center justify-center bg-gray-50 dark:bg-slate-800 rounded text-xs font-bold text-gray-400">${item.icon}</div>
                            <div class="flex-1 font-semibold text-gray-600 dark:text-gray-300 ml-2">${item.label}</div>
                            <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                            
                            <!-- Submenu -->
                            <div class="rte-submenu">
                                ${subItems}
                            </div>
                        </div>
                    `;
          }

          // Normal Item
          let label, iconHtml;
          if (this.mentionType === "/") {
            label = item.label;
            iconHtml = `<div class="w-6 h-6 flex items-center justify-center bg-gray-100 dark:bg-slate-700 rounded text-xs font-bold text-gray-500">${item.icon}</div>`;
          } else {
            label = item.name || item.label || item;
            const avatar = item.avatar || "";
            iconHtml =
              this.mentionType === "@"
                ? avatar
                  ? `<img src="${avatar}" class="rte-mention-avatar">`
                  : `<div class="rte-mention-avatar bg-gray-200"></div>`
                : `<span class="text-blue-500 font-bold">#</span>`;
          }

          return `<div class="rte-mention-item ${active}" data-idx="${i}">
                    ${iconHtml}
                    <span class="ml-2">${label}</span>
                </div>`;
        })
        .join("");

      // Re-bind Click
      this.mentionList.querySelectorAll(".rte-mention-item").forEach((item) => {
        item.addEventListener("mousedown", (e) => e.preventDefault());
        // Hover to activate (fixes submenu overlap) & Position Fixed Submenu
        item.addEventListener("mouseenter", () => {
          clearTimeout(this.mentionTimer);
          this.mentionIndex = parseInt(item.dataset.idx);
          this.updateActiveItem();

          // Close other groups immediately
          this.mentionList
            .querySelectorAll(".rte-mention-item.group")
            .forEach((el) => {
              if (el !== item) el.classList.remove("is-open");
            });

          if (item.classList.contains("group")) {
            item.classList.add("is-open");
            this.positionSubmenu(item);
          }
        });

        item.addEventListener("mouseleave", (e) => {
          if (item.classList.contains("group")) {
            this.mentionTimer = setTimeout(() => {
              item.classList.remove("is-open");
            }, 300); // 300ms grace period to cross gaps/scrollbars
          }
        });

        const submenu = item.querySelector(".rte-submenu");
        if (submenu) {
          submenu.addEventListener("mouseenter", () => {
            clearTimeout(this.mentionTimer);
          });
        }

        item.addEventListener("click", (e) => {
          if (item.classList.contains("group")) {
            e.preventDefault();
            e.stopPropagation();
            const isOpen = item.classList.contains("is-open");

            // Close all other groups first
            this.mentionList
              .querySelectorAll(".rte-mention-item.group")
              .forEach((el) => {
                if (el !== item) el.classList.remove("is-open");
              });

            if (!isOpen) {
              item.classList.add("is-open");
              this.positionSubmenu(item);
            } else {
              item.classList.remove("is-open");
            }
          } else {
            this.mentionIndex = parseInt(item.dataset.idx);
            this.selectMention();
          }
        });
        // Touch Support
        item.addEventListener("touchend", (e) => {
          e.preventDefault(); // Prevent focus loss/ghost click
          if (item.classList.contains("group")) {
            item.dispatchEvent(new Event("click"));
          } else {
            item.dispatchEvent(new Event("mouseenter"));
            this.selectMention();
          }
        });
      });

      // Re-bind Submenu Click
      this.mentionList
        .querySelectorAll(".rte-submenu-item")
        .forEach((subItem) => {
          subItem.addEventListener("mousedown", (e) => e.preventDefault());
          const handleSubClick = (e) => {
            e.stopPropagation();
            // Prevent default to avoid focus loss on touch
            if (e.type === "touchend") e.preventDefault();

            const groupIdx = parseInt(subItem.dataset.group);
            const childIdx = parseInt(subItem.dataset.child);
            const targetItem = this.mentionResults[groupIdx].children[childIdx];
            this.selectMention(targetItem);
          };
          subItem.addEventListener("click", handleSubClick);
          subItem.addEventListener("touchend", handleSubClick);
        });

      this.updateActiveItem();
    } catch (e) {
      console.error("Mention search error:", e);
    }
  }

  positionSubmenu(item) {
    const submenu = item.querySelector(".rte-submenu");
    if (!submenu) return;

    const rect = item.getBoundingClientRect();
    const viewportWidth = window.innerWidth;
    const viewportHeight = window.innerHeight;
    const menuWidth = 180;
    const gap = 5;

    if (rect.right + menuWidth > viewportWidth) {
      submenu.classList.add("pop-left");
      submenu.style.left = "auto";
      submenu.style.right =
        Math.max(10, viewportWidth - rect.left + gap) + "px";
    } else {
      submenu.classList.remove("pop-left");
      submenu.style.left = Math.max(10, rect.right + gap) + "px";
      submenu.style.right = "auto";
    }

    let top = rect.top;
    if (top + 200 > viewportHeight) {
      top = viewportHeight - 210;
    }
    submenu.style.top = Math.max(10, top) + "px";
  }

  updateActiveItem() {
    const items = this.mentionList.querySelectorAll(".rte-mention-item");
    items.forEach((el, i) => {
      if (i === this.mentionIndex) el.classList.add("active");
      else el.classList.remove("active");
    });
  }

  selectMention(directItem = null) {
    const item = directItem || this.mentionResults[this.mentionIndex];
    if (!item || item.isGroup) return; // Don't select groups

    const sel = window.getSelection();
    let range = null;
    if (sel.rangeCount > 0) {
      range = sel.getRangeAt(0);
    } else if (this.lastRange) {
      // Restore lost selection (Mobile/Focus fix)
      sel.removeAllRanges();
      sel.addRange(this.lastRange);
      range = this.lastRange;
    } else {
      return;
    }

    // Remove the trigger char + query (Skip if triggered by selection)
    try {
      if (!this.isSelectionMention) {
        const textNode = range.startContainer;
        const end = range.endOffset;
        // trigger length = 1, query length
        const start = end - (1 + this.mentionQuery.length);

        if (start >= 0) {
          range.setStart(textNode, start);
          range.setEnd(textNode, end);
          range.deleteContents();
        }
      }
    } catch (e) {
      console.error(e);
    }

    // Logic Fork: Slash Command vs Mention
    if (this.mentionType === "/") {
      // Slash Command Execution
      this.closeMention();

      if (item.cmd === "insertImage" && item.val === "custom") {
        if (this.imageInput) this.imageInput.click();
      } else if (item.cmd === "insertTable") {
        this.insertTable();
      } else if (item.cmd === "fontSize") {
        // Custom Pixel Size Logic for Slash Command
        document.execCommand("fontSize", false, "7");
        const fonts = this.editorEl.querySelectorAll('font[size="7"]');
        fonts.forEach((el) => {
          el.removeAttribute("size");
          el.style.fontSize = item.val;
        });
      } else if (item.cmd === "removeFormat") {
        this.cleanFormat();
      } else {
        this.editorEl.focus();
        document.execCommand(item.cmd, false, item.val);
      }
    } else {
      // Standard Mention Insertion
      const label = item.name || item.label || item;
      const insertText = this.mentionType === "@" ? `@${label}` : `#${label}`;
      const colorClass =
        this.mentionType === "@"
          ? "text-blue-600 dark:text-blue-400"
          : "text-pink-600 dark:text-pink-400";

      // Insert Chip
      const span = document.createElement("span");
      span.className = `${colorClass} font-bold select-none mx-0.5`;
      span.setAttribute("contenteditable", "false");
      span.innerText = insertText;

      range.insertNode(span);

      // Add Safe Space
      const space = document.createTextNode("\u00A0");
      range.setStartAfter(span);
      range.insertNode(space);
      range.setStartAfter(space);
      range.collapse(true);
      sel.removeAllRanges();
      sel.addRange(range);

      this.closeMention();
      this.editorEl.focus();
    }
  }

  injectStyles() {
    if (document.getElementById("well-rte-styles")) return;
    const style = document.createElement("style");
    style.id = "well-rte-styles";
    style.textContent = `
            .rte-content h1 { font-size: 2.25rem; font-weight: 700; line-height: 1.2; margin-bottom: 0.5em; }
            .rte-content h2 { font-size: 1.875rem; font-weight: 700; line-height: 1.3; margin-bottom: 0.5em; }
            .rte-content h3 { font-size: 1.5rem; font-weight: 600; line-height: 1.4; margin-bottom: 0.5em; }
            .rte-content h4 { font-size: 1.25rem; font-weight: 600; margin-bottom: 0.5em; }
            .rte-content h5 { font-size: 1.125rem; font-weight: 600; margin-bottom: 0.5em; }
            .rte-content h6 { font-size: 1rem; font-weight: 600; margin-bottom: 0.5em; }
            .rte-content ul { list-style-type: disc; padding-left: 1.5rem; margin-bottom: 1em; }
            .rte-content ol { list-style-type: decimal; padding-left: 1.5rem; margin-bottom: 1em; }
            .rte-content blockquote { border-left: 2px solid #e5e7eb; padding-left: 0.5rem; margin-left: 0; color: #6b7280; font-style: italic; }
            .rte-content p { margin-bottom: 0.5em; }
            .rte-content img { display: inline-block; max-width: 100%; border-radius: 0.5rem; margin: 0.5rem 0; cursor: pointer; transition: all 0.2s; border: 2px solid transparent; }
            .rte-content img.selected { border-color: #3b82f6; box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1); }
            .rte-emoji { font-size: 1.8em; vertical-align: middle; line-height: 1; display: inline-block; margin: 0 0.05em; }
            .rte-content a { color: #2563eb; text-decoration: underline; cursor: pointer; }
            /* Code Blocks (Pre) */
            .rte-content pre { 
                display: block;
                padding: 0.5rem; 
                background: #f3f4f6; 
                border-radius: 0.375rem; 
                overflow-x: auto; 
                font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; 
                white-space: pre; 
                max-width: 100%;
                margin: 0.5rem 0;
                font-size: 0.875rem;
                line-height: 1.5;
                color: #1f2937;
            }
            .dark .rte-content pre { background: #334155; color: #e2e8f0; }

            /* Fix for pasted code with inner div (e.g. VS Code) */
            .rte-content pre > div {
                min-width: 100%;
                width: fit-content;
            }

            /* Inline Code */
            .rte-content code {
                background: #f3f4f6;
                padding: 0.2rem 0.2rem;
                border-radius: 0.25rem;
                font-size: 0.875em;
                font-family: monospace;
                color: #c026d3; /* purple-600 */
            }
            .dark .rte-content code { background: #334155; color: #e2e8f0; }

            /* Reset Code inside Pre */
            .rte-content pre code {
                background: transparent;
                padding: 0;
                border-radius: 0;
                color: inherit;
                font-size: inherit;
            }
            .rte-content img { display: inline-block; max-width: 100%; border-radius: 0.5rem; margin: 0.5rem 0; cursor: pointer; transition: outline 0.2s; }
            .rte-content img.selected { outline: 2px solid #3b82f6; outline-offset: 2px; }
            .dark .rte-content pre code { background: transparent; }
            
            /* Toolbar Scrollbar */
            .rte-toolbar-scroll::-webkit-scrollbar { height: 4px; }
            .rte-toolbar-scroll::-webkit-scrollbar-track { background: transparent; }
            .rte-toolbar-scroll::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 4px; }
            .dark .rte-toolbar-scroll::-webkit-scrollbar-thumb { background: #4b5563; }
            .rte-toolbar-scroll::-webkit-scrollbar-thumb:hover { background: #9ca3af; }
            .rte-toolbar-scroll::-webkit-scrollbar-thumb:hover { background: #9ca3af; }

            /* Emoji Grid */
            .rte-emoji-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(2.5rem, 1fr)); gap: 4px; max-height: 130px; overflow-y: auto; padding: 4px; }
            .rte-emoji-btn { font-size: 1.25rem; padding: 4px; border-radius: 4px; cursor: pointer; text-align: center; }
            .rte-emoji-btn:hover { background: #e5e7eb; }
            .dark .rte-emoji-btn:hover { background: #4b5563; }
            
            /* Drag & Drop Overlay */
            .is-dragging { border: 2px dashed #3b82f6; background: rgba(59, 130, 246, 0.05); }

            /* Word Counter */
            .rte-footer { position: absolute; bottom: 0.5rem; right: 0.75rem; font-size: 0.75rem; color: #9ca3af; pointer-events: none; z-index: 10; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; }
            .dark .rte-footer { color: #64748b; }

            /* Mention/Hashtag Autocomplete */
            .rte-mention-list { position: absolute; z-index: 50; background: white; border: 1px solid #e5e7eb; border-radius: 0.5rem; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); overflow: hidden; min-width: 150px; max-height: 200px; overflow-y: auto; }
            .dark .rte-mention-list { background: #1e293b; border-color: #334155; }
            .rte-mention-item { padding: 0.5rem 0.75rem; cursor: pointer; display: flex; align-items: center; gap: 0.5rem; font-size: 0.875rem; color: #374151; }
            .dark .rte-mention-item { color: #cbd5e1; }
            .rte-mention-item:hover, .rte-mention-item.active { background-color: #f3f4f6; }
            .dark .rte-mention-item:hover, .dark .rte-mention-item.active { background-color: #334155; }
            .rte-mention-avatar { width: 1.5rem; height: 1.5rem; border-radius: 9999px; background-color: #e5e7eb; flex-shrink: 0; }
            /* .rte-mention-list { overflow: visible !important; } Reverted to allow scrolling */
            
            /* Submenu (Fixed to escape overflow) */
            .rte-submenu {
                position: fixed;
                background: white;
                border: 1px solid #e5e7eb;
                border-radius: 0.5rem;
                box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1);
                min-width: 150px;
                display: none;
                z-index: 100;
                max-height: 200px;
                overflow-y: auto;
            }
            /* Bridge to prevent menu from disappearing during mouse transition (covers scrollbar) */
            .rte-submenu::before {
                content: '';
                position: absolute;
                top: 0;
                bottom: 0;
                left: -30px;
                width: 30px;
                background: transparent;
            }
            .rte-submenu.pop-left::before {
                left: auto;
                right: -30px;
            }
            .dark .rte-submenu { background: #1e293b; border-color: #334155; }
            .rte-mention-item:hover .rte-submenu,
            .rte-mention-item.is-open .rte-submenu { display: block; }
            
            /* Tables */
            .rte-content table { width: 100%; border-collapse: collapse; margin-bottom: 1em; table-layout: fixed; }
            .rte-content th, .rte-content td { border: 1px solid #d1d5db; padding: 0.5rem; min-width: 50px; }
            .dark .rte-content th, .dark .rte-content td { border-color: #4b5563; }
            .rte-content th { background-color: #f9fafb; font-weight: 600; text-align: left; }
            .dark .rte-content th { background-color: #1f293b; }
        `;
    document.head.appendChild(style);
  }

  getTemplate() {
    // Dynamic Height Logic
    let heightClass = "";
    let heightStyle = "";
    const h = this.options.height;

    if (this.options.autoGrow) {
      if (typeof h === "string" && h.startsWith("h-")) {
        heightClass = h.replace("h-", "min-h-");
      } else if (typeof h === "number" || !isNaN(h)) {
        heightStyle = `min-height: ${h}px;`;
      } else {
        heightStyle = `min-height: ${h};`;
      }
      heightStyle += `max-height: ${this.options.maxHeight};`;
    } else {
      if (typeof h === "string" && h.startsWith("h-")) {
        heightClass = h;
      } else if (typeof h === "number" || !isNaN(h)) {
        heightStyle = `height: ${h}px;`;
      } else {
        heightStyle = `height: ${h};`;
      }
    }

    return `
            <div class="rte-container flex flex-col w-full relative group">
                <!-- Toolbar -->
                <div class="rte-toolbar-scroll flex flex-nowrap overflow-x-auto items-center gap-1 p-2 border-b border-gray-200 dark:border-gray-700 bg-gray-50/95 dark:bg-slate-900/90 backdrop-blur-sm scroll-smooth transition-all duration-300">
                    <!-- Headings -->
                    <select class="rte-cmd-block h-8 px-2 text-xs bg-white dark:bg-slate-800 border border-gray-300 dark:border-gray-600 rounded pt-1 flex-shrink-0">
                        <option value="" selected>Format</option>
                        <option value="p">Paragraph</option>
                        <option value="h1">Heading 1</option>
                        <option value="h2">Heading 2</option>
                        <option value="h3">Heading 3</option>
                        <option value="h4">Heading 4</option>
                        <option value="h5">Heading 5</option>
                        <option value="h6">Heading 6</option>
                    </select>

                    <!-- Font Family -->
                    <select class="rte-cmd-font h-8 px-2 text-xs bg-white dark:bg-slate-800 border border-gray-300 dark:border-gray-600 rounded pt-1 flex-shrink-0 w-24">
                        <option value="" selected>Font</option>
                        <option value="system-ui, -apple-system, sans-serif">System</option>
                        <!-- Chinese -->
                        <option value="'Microsoft YaHei', '微软雅黑', sans-serif">微软雅黑</option>
                        <option value="SimSun, '宋体', serif">宋体</option>
                        <option value="SimHei, '黑体', sans-serif">黑体</option>
                        <option value="KaiTi, '楷体', serif">楷体</option>
                        <option value="FangSong, '仿宋', serif">仿宋</option>
                        <option value="'PingFang SC', sans-serif">苹方</option>
                        <!-- Sans Serif -->
                        <option value="Arial, sans-serif">Arial</option>
                        <option value="'Arial Black', sans-serif">Arial Black</option>
                        <option value="Helvetica, sans-serif">Helvetica</option>
                        <option value="Verdana, sans-serif">Verdana</option>
                        <option value="Tahoma, sans-serif">Tahoma</option>
                        <option value="'Trebuchet MS', sans-serif">Trebuchet</option>
                        <option value="Geneva, sans-serif">Geneva</option>
                        <option value="Optima, sans-serif">Optima</option>
                        <!-- Serif -->
                        <option value="Georgia, serif">Georgia</option>
                        <option value="'Times New Roman', serif">Times</option>
                        <option value="'Palatino Linotype', 'Book Antiqua', Palatino, serif">Palatino</option>
                        <option value="'Bookman Old Style', serif">Bookman</option>
                        <!-- Other -->
                        <option value="Impact, fantasy">Impact</option>
                        <option value="'Comic Sans MS', cursive">Comic Sans</option>
                        <option value="'Courier New', monospace">Courier</option>
                        <option value="Monaco, monospace">Monaco</option>
                        <option value="Consolas, monospace">Consolas</option>
                    </select>

                    <!-- Font Size -->
                    <select class="rte-cmd-size h-8 px-2 text-xs bg-white dark:bg-slate-800 border border-gray-300 dark:border-gray-600 rounded pt-1 flex-shrink-0 w-16">
                        <option value="" selected>Size</option>
                        <option value="12px">12px</option>
                        <option value="14px">14px</option>
                        <option value="16px">16px</option>
                        <option value="18px">18px</option>
                        <option value="24px">24px</option>
                        <option value="36px">36px</option>
                        <option value="46px">46px</option>
                        <option value="48px">48px</option>
                        <option value="72px">72px</option>
                    </select>
                    <div class="w-px h-6 bg-gray-300 dark:bg-gray-600 mx-1 flex-shrink-0"></div>
                    
                    <!-- Basic -->
                    <button type="button" data-cmd="bold" title="Bold" class="rte-btn p-1.5 rounded hover:bg-gray-200 dark:hover:bg-slate-700 flex-shrink-0"><b class="font-bold">B</b></button>
                    <button type="button" data-cmd="italic" title="Italic" class="rte-btn p-1.5 rounded hover:bg-gray-200 dark:hover:bg-slate-700 flex-shrink-0"><i class="italic">I</i></button>
                    <button type="button" data-cmd="underline" title="Underline" class="rte-btn p-1.5 rounded hover:bg-gray-200 dark:hover:bg-slate-700 flex-shrink-0"><u class="underline">U</u></button>
                    <button type="button" data-cmd="strikeThrough" title="Strike" class="rte-btn p-1.5 rounded hover:bg-gray-200 dark:hover:bg-slate-700 flex-shrink-0"><s class="line-through">S</s></button>
                    
                    <div class="w-px h-6 bg-gray-300 dark:bg-gray-600 mx-1 flex-shrink-0"></div>
                    
                    <!-- Lists -->
                    <button type="button" data-cmd="insertUnorderedList" title="Bullet List" class="rte-btn p-1.5 rounded hover:bg-gray-200 dark:hover:bg-slate-700 flex-shrink-0">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="4" cy="6" r="1.5"/><path d="M9 6h11"/><circle cx="4" cy="12" r="1.5"/><path d="M9 12h11"/><circle cx="4" cy="18" r="1.5"/><path d="M9 18h11"/></svg>
                    </button>
                    <button type="button" data-cmd="insertOrderedList" title="Number List" class="rte-btn p-1.5 rounded hover:bg-gray-200 dark:hover:bg-slate-700 flex-shrink-0">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h12M7 17h12M5 7v.01M5 17v.01m2-10a2 2 0 11-4 0 2 2 0 014 0zm0 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                    </button>
                    <button type="button" onclick="document.execCommand('formatBlock', false, 'blockquote')" title="Quote" class="rte-btn p-1.5 rounded hover:bg-gray-200 dark:hover:bg-slate-700 flex-shrink-0 font-serif font-bold text-lg">“</button>
                    <button type="button" onclick="document.execCommand('formatBlock', false, 'pre')" title="Code Block" class="rte-btn p-1.5 rounded hover:bg-gray-200 dark:hover:bg-slate-700 flex-shrink-0 text-sm font-mono font-bold">&lt;&gt;</button>


                    <div class="w-px h-6 bg-gray-300 dark:bg-gray-600 mx-1 flex-shrink-0"></div>

                    <!-- Alignment -->
                    <button type="button" data-cmd="justifyLeft" title="Align Left" class="rte-btn p-1.5 rounded hover:bg-gray-200 dark:hover:bg-slate-700 flex-shrink-0">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h10M4 18h16"></path></svg>
                    </button>
                    <button type="button" data-cmd="justifyCenter" title="Align Center" class="rte-btn p-1.5 rounded hover:bg-gray-200 dark:hover:bg-slate-700 flex-shrink-0">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M7 12h10M4 18h16"></path></svg>
                    </button>
                    <button type="button" data-cmd="justifyRight" title="Align Right" class="rte-btn p-1.5 rounded hover:bg-gray-200 dark:hover:bg-slate-700 flex-shrink-0">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M10 12h10M4 18h16"></path></svg>
                    </button>

                    <div class="w-px h-6 bg-gray-300 dark:bg-gray-600 mx-1 flex-shrink-0"></div>

                    <!-- Media -->
                    <button type="button" id="rte-btn-link" title="Link" class="p-1.5 rounded hover:bg-gray-200 dark:hover:bg-slate-700 flex-shrink-0 text-blue-600">
                         <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path></svg>
                    </button>
                    <button type="button" id="rte-btn-image" title="Image" class="p-1.5 rounded hover:bg-gray-200 dark:hover:bg-slate-700 flex-shrink-0 text-green-600">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                    </button>
                    <button type="button" id="rte-btn-table" title="Table" class="p-1.5 rounded hover:bg-gray-200 dark:hover:bg-slate-700 flex-shrink-0 text-gray-600">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M3 14h18m-9-4v8m-7-8v8m14-8v8M5 21h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v14a2 2 0 002 2z"></path></svg>
                    </button>
                    <input type="file" class="rte-image-input hidden" accept="image/*" multiple />

                    <div class="w-px h-6 bg-gray-300 dark:bg-gray-600 mx-1 flex-shrink-0"></div>

                    <!-- Utils -->
                     <button type="button" data-cmd="removeFormat" title="Clean" class="rte-btn p-1.5 rounded hover:bg-gray-200 dark:hover:bg-slate-700 flex-shrink-0 text-red-500">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                    </button>

                    <button type="button" id="rte-btn-source" title="View Source" class="p-1.5 rounded hover:bg-gray-200 dark:hover:bg-slate-700 flex-shrink-0 text-purple-600 font-mono text-xs border border-gray-300 dark:border-gray-600">&lt;/&gt;</button>
                </div>
                
                <!-- Floating Image Toolbar (Appears on Image Click) -->
                <div class="rte-image-toolbar hidden absolute z-50 bg-white dark:bg-slate-800 rounded-lg shadow-xl border border-gray-200 dark:border-gray-600 p-1 flex gap-1 animate-in fade-in zoom-in duration-200">
                    <button type="button" data-align="left" title="Align Left" class="p-1.5 rounded hover:bg-gray-100 dark:hover:bg-slate-700 text-gray-600 dark:text-gray-300">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h10M4 18h16"></path></svg>
                    </button>
                    <button type="button" data-align="center" title="Align Center" class="p-1.5 rounded hover:bg-gray-100 dark:hover:bg-slate-700 text-gray-600 dark:text-gray-300">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M7 12h10M4 18h16"></path></svg>
                    </button>
                    <button type="button" data-align="right" title="Align Right" class="p-1.5 rounded hover:bg-gray-100 dark:hover:bg-slate-700 text-gray-600 dark:text-gray-300">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M10 12h10M4 18h16"></path></svg>
                    </button>
                    <div class="w-px h-4 bg-gray-200 dark:bg-gray-700 my-auto mx-1"></div>
                    <button type="button" data-size="100%" title="Full Width" class="p-1 px-2 rounded hover:bg-gray-100 dark:hover:bg-slate-700 text-gray-600 dark:text-gray-300 font-bold text-[10px]">100%</button>
                    <button type="button" data-size="50%" title="Half Width" class="p-1 px-2 rounded hover:bg-gray-100 dark:hover:bg-slate-700 text-gray-600 dark:text-gray-300 font-bold text-[10px]">50%</button>
                </div>
                
                <!-- Scroll Hint -->
                <!-- <div class="rte-scroll-hint absolute right-0 top-0 bottom-0 w-12 bg-gradient-to-l from-white dark:from-slate-800 to-transparent pointer-events-none flex items-center justify-end pr-2 opacity-0 transition-opacity duration-300">
                    <span class="animate-bounce text-gray-400 dark:text-gray-500"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg></span>
                </div> -->
            </div>

            <!-- Content Area -->
            <div class="rte-content p-4 pb-10 ${heightClass} text-sm text-gray-700 dark:text-gray-300 outline-none overflow-y-auto" style="${heightStyle}" contenteditable="true"></div>

            <!-- Bottom Actions (Emoji) -->
            <div class="absolute bottom-2 left-2 z-30">
                 <!-- Emoji Button -->
                <button type="button" id="rte-btn-emoji" title="Emoji" class="p-1.5 rounded-full bg-gray-100 dark:bg-slate-700 hover:bg-gray-200 dark:hover:bg-slate-600 text-yellow-500 shadow-sm transition-colors border border-gray-200 dark:border-gray-600">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.828 14.828a4 4 0 01-5.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                </button>
            </div>
            
            <!-- Emoji Modal (Full Width, Bottom) -->
            <div class="rte-emoji-modal hidden absolute bottom-12 left-0 w-full px-2 z-30">
                <div class="bg-white dark:bg-slate-800 rounded-lg shadow-xl border border-gray-200 dark:border-gray-600 p-2">
                    <div class="rte-emoji-grid">
                        <!-- Emojis injected by JS -->
                    </div>
                </div>
            </div>

            <!-- Toolbar/Footer Extras -->
            <div class="rte-footer flex gap-3 items-center">
                <!-- Manual Save Button (Hidden if no handler) -->
                ${
                  typeof this.options.autoSaveHandler === "function"
                    ? `<button type="button" class="rte-save-btn text-xs bg-gray-100 hover:bg-gray-200 dark:bg-slate-700 dark:hover:bg-slate-600 px-2 py-0.5 rounded text-gray-600 dark:text-gray-300 transition-colors">Save Draft</button>`
                    : ""
                }
                <span class="rte-read-time">0 min read</span>
                <span class="rte-chars">0 words</span>
            </div>

            <!-- Link Modal -->
            <div class="rte-link-modal hidden absolute top-14 left-1/2 transform -translate-x-1/2 z-20 w-80 bg-white dark:bg-slate-800 rounded-xl shadow-2xl border border-gray-200 dark:border-gray-600 p-4">
                <h4 class="text-sm font-bold text-gray-700 dark:text-gray-200 mb-3">Add/Edit Link</h4>
                <div class="space-y-3">
                    <div><label class="block text-xs font-medium text-gray-500 mb-1">Text</label><input type="text" class="rte-link-text w-full text-sm border rounded p-2 dark:bg-slate-900 dark:border-gray-600"></div>
                    <div><label class="block text-xs font-medium text-gray-500 mb-1">URL</label><input type="text" class="rte-link-url w-full text-sm border rounded p-2 dark:bg-slate-900 dark:border-gray-600" placeholder="https://"></div>
                    <div class="flex justify-end space-x-2 pt-2">
                        <button type="button" class="rte-link-cancel px-3 py-1.5 text-xs text-gray-600 hover:bg-gray-100 rounded">Cancel</button>
                        <button type="button" class="rte-link-save px-3 py-1.5 text-xs bg-blue-600 text-white hover:bg-blue-700 rounded">Save</button>
                    </div>
                </div>
            </div>

            <!-- Table Modal -->
            <div class="rte-table-modal hidden absolute top-14 left-1/2 transform -translate-x-1/2 z-20 w-64 bg-white dark:bg-slate-800 rounded-xl shadow-2xl border border-gray-200 dark:border-gray-600 p-4">
                <h4 class="text-sm font-bold text-gray-700 dark:text-gray-200 mb-3">Insert Table</h4>
                <div class="grid grid-cols-2 gap-3 mb-3">
                    <div><label class="block text-xs font-medium text-gray-500 mb-1">Rows</label><input type="number" min="1" max="20" value="3" class="rte-table-rows w-full text-sm border rounded p-2 dark:bg-slate-900 dark:border-gray-600"></div>
                    <div><label class="block text-xs font-medium text-gray-500 mb-1">Cols</label><input type="number" min="1" max="10" value="3" class="rte-table-cols w-full text-sm border rounded p-2 dark:bg-slate-900 dark:border-gray-600"></div>
                </div>
                <div class="flex justify-end space-x-2">
                    <button type="button" class="rte-table-cancel px-3 py-1.5 text-xs text-gray-600 hover:bg-gray-100 rounded">Cancel</button>
                    <button type="button" class="rte-table-insert px-3 py-1.5 text-xs bg-blue-600 text-white hover:bg-blue-700 rounded">Insert</button>
                </div>
            </div>
        `;
  }

  handleMarkdownInput(e) {
    const sel = window.getSelection();
    if (!sel.isCollapsed) return;
    const range = sel.getRangeAt(0);
    const node = range.startContainer;

    // Text up to cursor
    const text = node.textContent.substring(0, range.startOffset);

    // 1. Block Triggers (Start of line)
    if (range.startOffset === text.length) {
      // Ensure at end of typed pattern
      // Headings
      const hMatch = text.match(/^(#{1,6})\s$/);
      if (hMatch) {
        this.closeMention(); // Fix # Conflict
        const level = hMatch[1].length;
        this.applyBlockFormat(`H${level}`, hMatch[0].length);
        return;
      }
      // Lists
      if (/^[-*]\s$/.test(text)) {
        this.closeMention(); // Fix # Conflict
        this.applyBlockFormat("ul", 2);
        return;
      }
      if (/^1\.\s$/.test(text)) {
        this.applyBlockFormat("ol", 3);
        return;
      }
      // Blockquote
      if (/^>\s$/.test(text)) {
        this.applyBlockFormat("blockquote", 2);
        return;
      }
    }

    // 2. Inline Triggers (Bold, Code)
    // Check for **text**<space>
    if (text.endsWith(" ")) {
      // Only trigger on space
      const boldMatch = text.match(/\*\*(.+?)\*\*\s$/);
      if (boldMatch) {
        const content = boldMatch[1];
        const fullLen = boldMatch[0].length;
        this.applyInlineFormat("bold", content, fullLen);
        return;
      }
    }

    // Check for `code`<space> (since user asked for `code` conversion)
    // Usually backticks trigger without space, but here we trigger on input
    if (e.data === "`") {
      // Look for `code` pattern ending in `
      // Note: data is inserted, so text includes current `
      const codeMatch = text.match(/`(.+?)`$/);
      if (codeMatch) {
        const content = codeMatch[1];
        const fullLen = codeMatch[0].length;
        this.applyInlineFormat("code", content, fullLen);
        return;
      }
    }
  }

  applyBlockFormat(tag, deleteLen) {
    const sel = window.getSelection();
    const range = sel.getRangeAt(0);
    // Delete trigger chars
    const node = range.startContainer;
    const start = range.startOffset - deleteLen;
    range.setStart(node, start);
    range.deleteContents();

    if (tag === "ul") document.execCommand("insertUnorderedList");
    else if (tag === "ol") document.execCommand("insertOrderedList");
    else document.execCommand("formatBlock", false, tag);
  }

  applyInlineFormat(type, content, deleteLen) {
    const sel = window.getSelection();
    const range = sel.getRangeAt(0);
    // Delete trigger chars (**text**)
    const node = range.startContainer;
    const start = range.startOffset - deleteLen;
    range.setStart(node, start);
    range.deleteContents();

    if (type === "bold") {
      document.execCommand("bold");
      document.execCommand("insertText", false, content);
      document.execCommand("bold"); // Toggle off? Browsers vary.
      // Better: Insert HTML
      // document.execCommand('insertHTML', false, `<b>${content}</b>&nbsp;`);
    } else if (type === "code") {
      const span = document.createElement("span");
      span.className =
        "font-mono bg-gray-100 dark:bg-slate-700 px-1 rounded text-pink-500";
      span.textContent = content;
      range.insertNode(span);
      range.setStartAfter(span);
      range.collapse(true);
      sel.removeAllRanges();
      sel.addRange(range);
      // Add space after
      document.execCommand("insertText", false, " ");
    }
  }

  bindEvents() {
    const self = this;

    // Backspace Logic: Remove Format first
    this.editorEl.addEventListener("keydown", (e) => {
      if (e.key === "Backspace") {
        const sel = window.getSelection();
        if (!sel.isCollapsed) return;

        const range = sel.getRangeAt(0);
        if (range.startOffset === 0) {
          // Find closest block
          let block = range.commonAncestorContainer;
          if (block.nodeType === 3) block = block.parentElement;

          // Traverse up to find block-level element inside editor
          while (
            block &&
            block !== self.editorEl &&
            ![
              "H1",
              "H2",
              "H3",
              "H4",
              "H5",
              "H6",
              "BLOCKQUOTE",
              "PRE",
              "LI",
            ].includes(block.tagName)
          ) {
            block = block.parentElement;
          }

          if (block && block !== self.editorEl) {
            e.preventDefault();
            if (block.tagName === "LI") {
              document.execCommand("outdent");
            } else if (block.tagName === "PRE") {
              // PRE needs manual handling to preserve newlines as BRs
              const p = document.createElement("p");
              // Get text content, split by newline, join with <br>
              // Or simply use textContent if we don't care about internal highlight spam
              // Using innerText to respect visible text
              const lines = block.innerText.split("\n");
              p.innerHTML = lines.join("<br>") || "<br>"; // Ensure not empty
              block.replaceWith(p);

              // Place cursor at start
              const newRange = document.createRange();
              newRange.setStart(p, 0);
              newRange.collapse(true);
              sel.removeAllRanges();
              sel.addRange(newRange);
            } else {
              document.execCommand("formatBlock", false, "p");
            }
          }
        }
      }
    });

    // Toolbar Buttons (Simple Commands)
    this.wrapper.querySelectorAll(".rte-btn").forEach((btn) => {
      btn.addEventListener("click", (e) => {
        const cmd = btn.dataset.cmd;
        if (!cmd) return;

        if (cmd === "removeFormat") {
          this.cleanFormat();
        } else {
          document.execCommand(cmd, false, null);
        }
        self.updateSource();
      });
    });

    // Smart Markdown Input
    this.editorEl.addEventListener("input", (e) => {
      // Trigger on Space or specific closing chars
      if (
        e.inputType === "insertText" &&
        (e.data === " " || e.data === "*" || e.data === "`")
      ) {
        self.handleMarkdownInput(e);
      }
    });

    // Handle 'Enter' for Divider (---)
    this.editorEl.addEventListener("keydown", (e) => {
      if (e.key === "Enter") {
        const sel = window.getSelection();
        if (sel.isCollapsed) {
          const range = sel.getRangeAt(0);
          const text = range.startContainer.textContent;
          // Check for '---' at end of line (or content)
          // If content is just '---', replace with HR
          if (text.trim() === "---") {
            e.preventDefault();
            document.execCommand("insertHorizontalRule");
            // Remove the '---' text (likely current block)
            const block = range.startContainer.parentElement;
            if (block.textContent.trim() === "---") {
              block.remove();
            } else {
              // Fallback cleanup if inline
              const textNode = range.startContainer;
              textNode.textContent = textNode.textContent.replace("---", "");
            }
            return;
          }
        }
      }
    });

    // Format Block Select
    this.wrapper
      .querySelector(".rte-cmd-block")
      .addEventListener("change", function () {
        document.execCommand("formatBlock", false, this.value);
        this.selectedIndex = 0;
        self.updateSource();
      });

    // Font Family Select
    this.wrapper
      .querySelector(".rte-cmd-font")
      .addEventListener("change", function () {
        if (self.lastRange) {
          const sel = window.getSelection();
          sel.removeAllRanges();
          sel.addRange(self.lastRange);
        }
        document.execCommand("fontName", false, this.value);
        this.selectedIndex = 0;
        self.updateSource();
      });

    // Font Size Select (Custom Pixel Logic)
    this.wrapper
      .querySelector(".rte-cmd-size")
      .addEventListener("change", function () {
        const val = this.value; // e.g., '12px'
        if (val) {
          if (self.lastRange) {
            const sel = window.getSelection();
            sel.removeAllRanges();
            sel.addRange(self.lastRange);
          }
          // Use default size 7 as marker
          document.execCommand("fontSize", false, "7");
          // Replace marker with custom style
          const fonts = self.editorEl.querySelectorAll('font[size="7"]');
          fonts.forEach((el) => {
            el.removeAttribute("size");
            el.style.fontSize = val;
          });
        }
        this.selectedIndex = 0;
        self.updateSource();
      });

    // Toggle Source
    this.wrapper
      .querySelector("#rte-btn-source")
      .addEventListener("click", () => this.toggleSource());

    // Editor Input Sync
    this.editorEl.addEventListener("input", () => this.updateSource());

    // Cursor Tracking (Fix for Emoji Insertion)
    const saveRange = () => {
      const sel = window.getSelection();
      if (sel.rangeCount > 0 && this.editorEl.contains(sel.anchorNode)) {
        this.lastRange = sel.getRangeAt(0);
      }
    };
    this.editorEl.addEventListener("keyup", saveRange);
    this.editorEl.addEventListener("mouseup", saveRange);
    this.editorEl.addEventListener("blur", saveRange);

    // Paste Handling
    this.editorEl.addEventListener("paste", (e) => this.handlePaste(e));

    // Scroll Hint
    const updateScroll = () => {
      const el = self.toolbarEl;
      const hint = self.scrollHintEl;
      const isScrollable = el.scrollWidth > el.clientWidth;
      const isAtEnd = el.scrollLeft + el.clientWidth >= el.scrollWidth - 2;
      if (isScrollable && !isAtEnd) hint.classList.remove("opacity-0");
      else hint.classList.add("opacity-0");
    };
    this.toolbarEl.addEventListener("scroll", updateScroll);
    window.addEventListener("resize", updateScroll);
    setTimeout(updateScroll, 100);

    // Image Button
    this.wrapper
      .querySelector("#rte-btn-image")
      .addEventListener("click", () => this.imageInput.click());
    this.imageInput.addEventListener("change", (e) =>
      this.handleImageUpload(e.target.files),
    );

    // Table Button
    this.wrapper
      .querySelector("#rte-btn-table")
      .addEventListener("click", () => this.insertTable());

    // Table Modal Events
    const tableModal = this.wrapper.querySelector(".rte-table-modal");
    this.wrapper
      .querySelector(".rte-table-cancel")
      .addEventListener("click", () => {
        tableModal.classList.add("hidden");
      });
    this.wrapper
      .querySelector(".rte-table-insert")
      .addEventListener("click", () => {
        const rows =
          parseInt(tableModal.querySelector(".rte-table-rows").value) || 3;
        const cols =
          parseInt(tableModal.querySelector(".rte-table-cols").value) || 3;
        this.execInsertTable(rows, cols);
        tableModal.classList.add("hidden");
      });

    // Link Modal
    this.currentLinkNode = null;
    this.savedRange = null;
    this.wrapper
      .querySelector("#rte-btn-link")
      .addEventListener("click", () => {
        const sel = window.getSelection();
        if (sel.rangeCount > 0) {
          this.savedRange = sel.getRangeAt(0);
          this.currentLinkNode =
            this.savedRange.commonAncestorContainer.parentElement;
          if (this.currentLinkNode.tagName !== "A") this.currentLinkNode = null;
        }

        const modal = this.linkModal;
        const textInput = modal.querySelector(".rte-link-text");
        const urlInput = modal.querySelector(".rte-link-url");

        textInput.value = this.currentLinkNode
          ? this.currentLinkNode.innerText
          : sel.toString();
        urlInput.value = this.currentLinkNode
          ? this.currentLinkNode.getAttribute("href")
          : "";
        modal.classList.remove("hidden");
      });

    this.wrapper
      .querySelector(".rte-link-cancel")
      .addEventListener("click", () => this.linkModal.classList.add("hidden"));
    this.wrapper
      .querySelector(".rte-link-save")
      .addEventListener("click", () => {
        const modal = this.linkModal;
        const text = modal.querySelector(".rte-link-text").value;
        const url = modal.querySelector(".rte-link-url").value;

        if (this.savedRange) {
          const sel = window.getSelection();
          sel.removeAllRanges();
          sel.addRange(this.savedRange);
        }

        // Simple fallback insertion
        if (text) {
          const html = `<a href="${url}" title="${text}">${text}</a>`;
          document.execCommand("insertHTML", false, html);
        } else {
          document.execCommand("createLink", false, url);
        }
        modal.classList.add("hidden");
        self.updateSource();
      });

    // Handle Sticky Toolbar
    this.isSticky = false;
    this.toolbarPlaceholder = null;
    this.handleStickyBind = this.handleSticky.bind(this);
    window.addEventListener("scroll", this.handleStickyBind);
    window.addEventListener("resize", this.handleStickyBind);
    // Initial check
    setTimeout(() => this.handleSticky(), 100);
  }

  handleSticky() {
    if (!this.toolbarEl || !this.wrapper) return;

    const rect = this.wrapper.getBoundingClientRect();
    const toolbarHeight = this.toolbarEl.offsetHeight;

    // Activation Condition:
    // 1. Top of editor is scrolled past 0
    // 2. Bottom of editor is still below toolbar height (so it doesn't overlap footer)
    const shouldStick = rect.top < 0 && rect.bottom > toolbarHeight + 40;

    if (shouldStick) {
      if (!this.isSticky) {
        this.isSticky = true;

        // 1. Create Placeholder to prevent layout jump
        if (!this.toolbarPlaceholder) {
          this.toolbarPlaceholder = document.createElement("div");
          this.toolbarPlaceholder.className = "rte-toolbar-placeholder";
        }
        this.toolbarPlaceholder.style.height = toolbarHeight + "px";
        this.toolbarEl.parentNode.insertBefore(
          this.toolbarPlaceholder,
          this.toolbarEl,
        );

        // 2. Move Toolbar to Body (Injection)
        document.body.appendChild(this.toolbarEl);

        // 3. Apply Sticky Styles
        this.toolbarEl.classList.add("rte-is-sticky");
        Object.assign(this.toolbarEl.style, {
          position: "fixed",
          top: "0",
          left: rect.left + "px",
          width: rect.width + "px",
          zIndex: "9999",
          boxShadow: "0 4px 12px rgba(0,0,0,0.1)",
          borderBottomWidth: "1px",
        });
      } else {
        // Keep synced on scroll/resize
        this.toolbarEl.style.left = rect.left + "px";
        this.toolbarEl.style.width = rect.width + "px";
      }
    } else {
      if (this.isSticky) {
        this.isSticky = false;

        // 1. Move Toolbar back
        if (this.toolbarPlaceholder && this.toolbarPlaceholder.parentNode) {
          this.toolbarPlaceholder.parentNode.insertBefore(
            this.toolbarEl,
            this.toolbarPlaceholder,
          );
          this.toolbarPlaceholder.remove();
          this.toolbarPlaceholder = null;
        }

        // 2. Clear Sticky Styles
        this.toolbarEl.classList.remove("rte-is-sticky");
        Object.assign(this.toolbarEl.style, {
          position: "",
          top: "",
          left: "",
          width: "",
          zIndex: "",
          boxShadow: "",
          borderBottomWidth: "",
        });
      }
    }
  }

  cleanFormat() {
    // Deep Clean: Strip all HTML, normalize whitespace
    const text = this.editorEl.innerText;
    const normalized = text.replace(/\n+/g, "\n").trim();
    this.editorEl.innerHTML = normalized.replace(/\n/g, "<br>");
    this.updateSource();
  }

  updateSource() {
    if (!this.sourceEl.classList.contains("hidden")) return; // In source mode, don't sync back yet
    this.sourceEl.value = this.sanitizeHtml(this.editorEl.innerHTML);
  }

  toggleSource() {
    const btn = this.wrapper.querySelector("#rte-btn-source");
    if (this.sourceEl.classList.contains("hidden")) {
      // Switch to Source
      this.sourceEl.value = this.sanitizeHtml(this.editorEl.innerHTML);
      this.editorEl.classList.add("hidden");
      this.sourceEl.classList.remove("hidden");
      btn.classList.add("bg-purple-100", "text-purple-700");
    } else {
      // Switch to Visual
      this.editorEl.innerHTML = this.sourceEl.value; // Trust source (or sanitize again?)
      this.sourceEl.classList.add("hidden");
      this.editorEl.classList.remove("hidden");
      btn.classList.remove("bg-purple-100", "text-purple-700");
    }
  }

  handlePaste(e) {
    const self = this;
    // 1. Remote Images
    const htmlData = (e.clipboardData || window.clipboardData).getData(
      "text/html",
    );
    if (htmlData && /<img\s+[^>]*src\s*=\s*['"]http/i.test(htmlData)) {
      e.preventDefault();
      document.execCommand("insertHTML", false, htmlData);
      self.updateSource();
      return;
    }

    // 2. Local Files
    const items = (e.clipboardData || e.clipboardData).items;
    let files = [];
    for (let i = 0; i < items.length; i++) {
      if (items[i].kind === "file" && items[i].type.includes("image/")) {
        files.push(items[i].getAsFile());
      }
    }
    if (files.length > 0) {
      e.preventDefault();
      this.handleImageUpload(files);
      return;
    }

    // 3. Markdown
    const text = (e.clipboardData || window.clipboardData).getData("text");
    if (this.isMarkdown(text)) {
      e.preventDefault();
      const html = this.markdownToHtml(text);
      document.execCommand("insertHTML", false, html);
      self.updateSource();
    }
  }

  async handleImageUpload(files) {
    if (!files) return;
    const fileList =
      files instanceof FileList || Array.isArray(files)
        ? Array.from(files)
        : [files];

    for (const file of fileList) {
      if (!file.type.startsWith("image/")) continue;
      try {
        const url = await this.options.uploadHandler(file);
        const img = `<p style="text-align:center;"><img src="${url}" style="width:100%;" data-well-img="1"></p><p><br></p>`;
        document.execCommand("insertHTML", false, img);
        this.updateSource();
      } catch (err) {
        console.error("Upload failed: " + err);
      }
    }
  }

  bindDragDrop() {
    const editor = this.editorEl;

    ["dragenter", "dragover"].forEach((eventName) => {
      editor.addEventListener(
        eventName,
        (e) => {
          e.preventDefault();
          e.stopPropagation();
          editor.classList.add("is-dragging");
        },
        false,
      );
    });

    ["dragleave", "drop"].forEach((eventName) => {
      editor.addEventListener(
        eventName,
        (e) => {
          e.preventDefault();
          e.stopPropagation();
          editor.classList.remove("is-dragging");
        },
        false,
      );
    });

    editor.addEventListener(
      "drop",
      (e) => {
        const dt = e.dataTransfer;
        const files = dt.files;
        if (files && files.length > 0) {
          this.handleImageUpload(files);
        }
      },
      false,
    );
  }

  bindEmojiEvents() {
    const emojiBtn = this.wrapper.querySelector("#rte-btn-emoji");
    const emojiModal = this.wrapper.querySelector(".rte-emoji-modal");

    if (emojiBtn && emojiModal) {
      emojiBtn.addEventListener("click", (e) => {
        e.stopPropagation();
        this.toggleEmojiModal();
      });

      // Close on click outside
      document.addEventListener("click", (e) => {
        if (
          !emojiModal.classList.contains("hidden") &&
          !emojiModal.contains(e.target) &&
          !emojiBtn.contains(e.target)
        ) {
          emojiModal.classList.add("hidden");
        }
      });

      // Populate Grid
      this.initEmojiGrid();
    }
  }

  initEmojiGrid() {
    const grid = this.wrapper.querySelector(".rte-emoji-grid");
    // Classic Set: Faces, Gestures, Hearts, Nature, Objects, Symbols
    const emojis = [
      "😎",
      "🙂",
      "😡",
      "🥵",
      "🤠",
      "🥳",
      "😃",
      "😅",
      "🤣",
      "😉",
      "😇",
      "🥰",
      "😍",
      "😚",
      "😳",
      "🥺",
      "😋",
      "🤪",
      "😝",
      "🤑",
      "🤗",
      "🤭",
      "🤫",
      "🤔",
      "🤐",
      "😏",
      "😒",
      "🤥",
      "😌",
      "🤤",
      "😴",
      "😷",
      "🤒",
      "🤕",
      "🤢",
      "🤮",
      "🤧",
      "🧐",
      "😰",
      "😭",
      "😱",
      "😣",
      "😓",
      "😤",
      "😈",
      "☠️",
      "💩",
      "🤡",
      "👻",
      "🤚",
      "🤏",
      "✌️",
      "🤟",
      "🤙",
      "👈",
      "👉",
      "👆",
      "👇",
      "👍",
      "👎",
      "✊",
      "👏",
      "🤲",
      "🤝",
      "🙏",
      "💪",
      "👀",
      "🧠",
      "❤️",
      "💔",
      "💕",
      "💘",
      "🐶",
      "🎄",
      "☘️",
      "🍀",
      "💐",
      "🌹",
      "🥀",
      "🌸",
      "🌼",
      "🌞",
      "🌛",
      "✨",
      "⚡️",
      "🔥",
      "🌈",
      "☁️",
      "🌧",
      "❄️",
      "☃️",
      "🦴",
      "🍔",
      "🍟",
      "🍕",
      "🎉",
      "🎈",
      "🎁",
    ];

    grid.innerHTML = emojis
      .map((e) => `<div class="rte-emoji-btn">${e}</div>`)
      .join("");

    grid.querySelectorAll(".rte-emoji-btn").forEach((btn) => {
      btn.addEventListener("click", (e) => {
        this.insertEmoji(e.target.textContent);
        this.wrapper.querySelector(".rte-emoji-modal").classList.add("hidden");
      });
    });
  }

  toggleEmojiModal() {
    this.wrapper.querySelector(".rte-emoji-modal").classList.toggle("hidden");
  }

  insertEmoji(emoji) {
    // Restore Cursor Position
    this.editorEl.focus();
    if (this.lastRange) {
      const sel = window.getSelection();
      sel.removeAllRanges();
      sel.addRange(this.lastRange);
    }

    // Insert emoji wrapped in a span for granular size control
    // Adding a trailing non-breaking space to make it easier to type after
    const html = `<span class="rte-emoji">${emoji}</span>&nbsp;`;
    document.execCommand("insertHTML", false, html);

    // Update range after insertion
    const sel = window.getSelection();
    if (sel.rangeCount > 0) this.lastRange = sel.getRangeAt(0);
    this.updateSource();
  }

  defaultUploadHandler(file) {
    return new Promise((resolve, reject) => {
      setTimeout(() => {
        const reader = new FileReader();
        reader.onload = (e) => resolve(e.target.result);
        reader.onerror = reject;
        reader.readAsDataURL(file);
      }, 600);
    });
  }

  sanitizeHtml(html) {
    const parser = new DOMParser();
    const doc = parser.parseFromString(html, "text/html");
    const allowedTags = new Set([
      "p",
      "br",
      "b",
      "i",
      "u",
      "strong",
      "em",
      "h1",
      "h2",
      "h3",
      "h4",
      "h5",
      "h6",
      "ul",
      "ol",
      "li",
      "blockquote",
      "pre",
      "code",
      "img",
      "a",
      "span",
      "div",
      "hr",
      "table",
      "thead",
      "tbody",
      "tr",
      "th",
      "td",
    ]);
    const allowedAttrs = new Set([
      "class",
      "style",
      "src",
      "href",
      "target",
      "alt",
      "title",
      "width",
      "height",
    ]);

    function clean(node) {
      const childNodes = Array.from(node.childNodes);
      childNodes.forEach((child) => {
        if (child.nodeType === 1) {
          const tagName = child.tagName.toLowerCase();
          if (!allowedTags.has(tagName)) {
            child.replaceWith(document.createTextNode(child.textContent));
          } else {
            Array.from(child.attributes).forEach((attr) => {
              const name = attr.name.toLowerCase();
              const val = attr.value.toLowerCase();
              if (!allowedAttrs.has(name)) {
                child.removeAttribute(name);
              } else {
                if (
                  (name === "href" || name === "src") &&
                  /^\s*(javascript|vbscript|data:text\/)/i.test(val)
                ) {
                  child.removeAttribute(name);
                }
                if (
                  name === "style" &&
                  /url\s*\(|expression|javascript:|vbscript:|@import/i.test(val)
                ) {
                  child.removeAttribute(name);
                }
              }
            });
            clean(child);
          }
        }
      });
    }
    clean(doc.body);
    return doc.body.innerHTML;
  }

  isMarkdown(text) {
    if (!text) return false;
    return (
      /^# /.test(text) ||
      /^## /.test(text) ||
      /\*\*.+\*\*/.test(text) ||
      /^-\s/.test(text) ||
      /^>\s/.test(text) ||
      /`{3}/.test(text) ||
      /!\[.*\]\(.*\)/.test(text) ||
      /\|\s*-/.test(text)
    );
  }

  markdownToHtml(text) {
    // Protect Code Blocks and HTML Tags from being formatted
    const placeholders = [];
    const addPlaceholder = (content) => {
      const id = `__RTE_PLACEHOLDER_${placeholders.length}__`;
      placeholders.push({ id, content });
      return id;
    };

    // 1. Extract Code Blocks (fence)
    let html = text.replace(/```([\s\S]*?)```/g, (match, code) => {
      return addPlaceholder(
        `<pre>${code.replace(/</g, "&lt;").replace(/>/g, "&gt;")}</pre>`,
      );
    });

    // 2. Extract Inline Code
    html = html.replace(/`([^`]+)`/g, (match, code) => {
      return addPlaceholder(
        `<code>${code.replace(/</g, "&lt;").replace(/>/g, "&gt;")}</code>`,
      );
    });

    // 3. Normalize Newlines (after extracting code, so we don't break code blocks)
    html = html.replace(/\r\n/g, "\n").replace(/\n{2,}/g, "\n");

    // 4. Headers
    html = html
      .replace(/^###### (.*$)/gim, "<h6>$1</h6>")
      .replace(/^##### (.*$)/gim, "<h5>$1</h5>")
      .replace(/^#### (.*$)/gim, "<h4>$1</h4>")
      .replace(/^### (.*$)/gim, "<h3>$1</h3>")
      .replace(/^## (.*$)/gim, "<h2>$1</h2>")
      .replace(/^# (.*$)/gim, "<h1>$1</h1>");

    // 5. Blockquotes
    html = html.replace(/^> (.*$)/gim, "<blockquote>$1</blockquote>");

    // 6. Horizontal Rule
    html = html.replace(/^---$/gim, "<hr>");

    // 7. Bold & Italic
    html = html.replace(/\*\*\*(.*?)\*\*\*/gim, "<b><i>$1</i></b>");
    html = html.replace(/\*\*(.*?)\*\*/gim, "<b>$1</b>");
    html = html.replace(/\*(.*?)\*/gim, "<i>$1</i>");
    html = html.replace(/__(.*?)__/gim, "<b>$1</b>");
    html = html.replace(/_(.*?)_/gim, "<i>$1</i>");

    // 8. Images and Links
    html = html.replace(
      /!\[(.*?)\]\((.*?)\)/gim,
      "<img src='$2' alt='$1' class='max-w-full h-auto rounded my-2'>",
    );
    html = html.replace(
      /\[(.*?)\]\((.*?)\)/gim,
      "<a href='$2' class='text-blue-600 underline'>$1</a>",
    );

    // 9. Tables
    // Improved Regex: Find blocks of lines starting with |
    const tableRegex = /((?:^\|.*\|\n?)+)/gm;
    html = html.replace(tableRegex, (match) => {
      const lines = match.trim().split("\n");
      if (lines.length < 2) return match;

      // Check if second line is a separator (contains only |, -, :, space)
      const separatorLine = lines[1].trim();
      const cleanSep = separatorLine.replace(/\|/g, "").replace(/\s/g, "");
      if (!/^[:\-]+$/.test(cleanSep)) return match;

      return addPlaceholder(this.buildTableHtml(lines));
    });

    // 10. Lists (Unordered)
    // Match line starting with - or *
    html = html.replace(/^\s*[\-\*]\s+(.*)$/gim, "<ul><li>$1</li></ul>");
    html = html.replace(/<\/ul>\n<ul>/gim, ""); // Merge adjacent lists

    // 11. Lists (Ordered)
    html = html.replace(/^\s*\d+\.\s+(.*)$/gim, "<ol><li>$1</li></ol>");
    html = html.replace(/<\/ol>\n<ol>/gim, ""); // Merge adjacent lists

    // 12. Final Newline to BR (only for remaining text)
    html = html.replace(/\n/g, "<br>");

    // Restore Placeholders
    placeholders.forEach((p) => {
      html = html.replace(p.id, p.content);
    });

    return html;
  }

  insertTable() {
    // Show Modal
    const modal = this.wrapper.querySelector(".rte-table-modal");
    // Save range logic is handled by 'blur' listener on editor, but let's be safe
    const sel = window.getSelection();
    if (sel.rangeCount > 0) {
      this.savedRange = sel.getRangeAt(0);
    }
    modal.classList.remove("hidden");
  }

  execInsertTable(rows, cols) {
    // Restore Range
    if (this.savedRange) {
      const sel = window.getSelection();
      sel.removeAllRanges();
      sel.addRange(this.savedRange);
    }

    let headerHtml = "";
    for (let j = 0; j < cols; j++) {
      headerHtml += `<th class="border border-gray-300 dark:border-gray-600 p-2">Header ${j + 1}</th>`;
    }

    let bodyHtml = "";
    for (let i = 0; i < rows; i++) {
      bodyHtml += "<tr>";
      for (let j = 0; j < cols; j++) {
        bodyHtml += `<td class="border border-gray-300 dark:border-gray-600 p-2">Cell ${i + 1}-${j + 1}</td>`;
      }
      bodyHtml += "</tr>";
    }

    const tableHtml = `
            <table class="w-full border-collapse border border-gray-200 dark:border-gray-700 my-4">
                <thead>
                    <tr class="bg-gray-50 dark:bg-slate-800">
                        ${headerHtml}
                    </tr>
                </thead>
                <tbody>
                    ${bodyHtml}
                </tbody>
            </table>
            <p><br></p>
        `;

    this.editorEl.focus();
    document.execCommand("insertHTML", false, tableHtml);
  }

  buildTableHtml(lines) {
    // Parse Alignments from Separator Line (Index 1)
    const separatorCells = lines[1].split("|").filter((c) => c.trim() !== "");
    const alignments = separatorCells.map((cell) => {
      const c = cell.trim();
      if (c.startsWith(":") && c.endsWith(":")) return "text-center";
      if (c.endsWith(":")) return "text-right";
      return "text-left"; // Default
    });

    let html =
      '<div class="overflow-x-auto my-4"><table class="w-full border-collapse border border-gray-200 dark:border-gray-700 uppercase text-xs"><thead><tr>';

    // Header
    const headerCells = lines[0].split("|").filter((c) => c.trim() !== "");
    headerCells.forEach((cell, index) => {
      const alignClass = alignments[index] || "text-left";
      html += `<th class="px-4 py-2 border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-slate-800 font-semibold ${alignClass}">${cell.trim()}</th>`;
    });
    html += "</tr></thead><tbody>";

    // Body (Skip index 1 which is separator)
    for (let i = 2; i < lines.length; i++) {
      const cells = lines[i].split("|").filter((c) => c.trim() !== "");
      if (cells.length === 0) continue;
      html += "<tr>";
      cells.forEach((cell, index) => {
        const alignClass = alignments[index] || "text-left";
        html += `<td class="px-4 py-2 border-b border-gray-100 dark:border-gray-700 ${alignClass}">${cell.trim()}</td>`;
      });
      html += "</tr>";
    }
    html += "</tbody></table></div>";
    return html;
  }
}
