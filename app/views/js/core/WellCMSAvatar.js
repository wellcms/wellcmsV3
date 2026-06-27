/**
 * WellCMS Avatar Module
 */
const WellCMSAvatar = {
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
,
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
,
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
,
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
};