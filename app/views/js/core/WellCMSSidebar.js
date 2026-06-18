/**
 * WellCMS Sidebar Module
 */
const WellCMSSidebar = {
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
};