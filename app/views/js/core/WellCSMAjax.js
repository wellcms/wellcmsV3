/**
 * WellCMS AJAX Module
 */
const WellCSMAjax = {
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
        path = "?" + path.replace(/\x2f/g, "-") + ".html";
        break;
      case 1:
        path = path.replace(/\x2f/g, "-") + ".html";
        break;
      case 2:
        path = "/" + path + ".html";
        break;
      case 3:
        path = "/" + path;
        break;
      default:
        path = "?" + path.replace(/\x2f/g, "-") + ".html";
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
},
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
,
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
,
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
};