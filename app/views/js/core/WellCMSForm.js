/**
 * WellCMS Form Module
 */
const WellCMSForm = {
initForms() {
  this.formHandler = new GlobalFormHandler(".ajax-form", { ui: this });
},
initTagInputs() {
  const containers = document.querySelectorAll('[data-type="tag-input"]');
  containers.forEach((container) => this.setupTagInput(container));
},
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
},
initOTPInputs() {
  document
    .querySelectorAll('[data-type="otp-group"]')
    .forEach((container) => {
      this.setupOTPInput(container);
    });
},
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
},
initPasswordStrength() {
  document
    .querySelectorAll('[data-type="password-strength"]')
    .forEach((input) => {
      this.setupPasswordStrength(input);
    });
},
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
},
initStarRatings() {
  document
    .querySelectorAll('[data-type="star-rating"]')
    .forEach((container) => {
      this.setupStarRating(container);
    });
},
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
};