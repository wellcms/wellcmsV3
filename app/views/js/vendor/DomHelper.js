/**
 * WellCMS DOM Helpers
 */
const DomHelper = {
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
},
getLoadedScript() {
  return Array.from(document.querySelectorAll("script[src]")).map(
    (s) => s.src,
  );
},
getScriptSection(s) {
  return (
    s.match(/<script[^>]+ajax-eval="true"[^>]*>([\s\S]+?)<\/script>/gi) || []
  );
},
getScriptSrc(s) {
  const matches =
    s.match(/<script[^>]*?src=\s*"([^"]+)"[^>]*><\/script>/gi) || [];
  return matches.map((m) => m.match(/src=\s*"([^"]+)"/i)[1]);
},
getStylesheetLink(s) {
  const matches = s.match(/<link[^>]*?href=\s*"([^"]+)"[^>]*>/gi) || [];
  return matches.map((m) => m.match(/href=\s*"([^"]+)"/i)[1]);
},
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
},
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
},
requireScripts(scripts, callback) {
  Promise.all(scripts.map((src) => this.loadScript(src)))
    .then(callback)
    .catch((err) => console.error("Error loading scripts:", err));
},
loadScript(src) {
  return new Promise((resolve, reject) => {
    const script = document.createElement("script");
    script.src = src;
    script.onload = resolve;
    script.onerror = reject;
    document.head.appendChild(script);
  });
}
};