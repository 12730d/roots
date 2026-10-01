/**
 * Enhanced User Profile Dropdown Handler
 * Professional, smooth, and accessible dropdown management
 */

(function () {
  "use strict";

  const ProfileDropdown = {
    init() {
      this.setup();
      this.attachEventListeners();
    },

    setup() {
      this.profileBtn = document.querySelector(".bc-user__trigger--profile");
      this.profileMenu = document.querySelector("#user-dropdown-menu");
      this.menuItems = this.profileMenu
        ? this.profileMenu.querySelectorAll(".bc-user__item")
        : [];
      this.isOpen = false;
    },

    attachEventListeners() {
      if (!this.profileBtn) return;

      // Toggle menu on button click
      this.profileBtn.addEventListener("click", (e) => {
        e.stopPropagation();
        this.isOpen ? this.close() : this.open();
      });

      // Close menu on item click
      this.menuItems.forEach((item) => {
        item.addEventListener("click", () => {
          this.close();
        });
      });

      // Close menu on outside click
      document.addEventListener("click", (e) => {
        if (
          this.profileBtn &&
          this.profileMenu &&
          !this.profileBtn.contains(e.target) &&
          !this.profileMenu.contains(e.target)
        ) {
          this.close();
        }
      });

      // Close on Escape key
      document.addEventListener("keydown", (e) => {
        if (e.key === "Escape" && this.isOpen) {
          this.close();
          this.profileBtn.focus();
        }
      });

      // Handle arrow key navigation
      this.profileBtn.addEventListener("keydown", (e) => {
        if (e.key === "ArrowDown" && !this.isOpen) {
          e.preventDefault();
          this.open();
          this.focusFirstItem();
        }
      });

      // Menu items keyboard navigation
      this.menuItems.forEach((item, index) => {
        item.addEventListener("keydown", (e) => {
          if (e.key === "ArrowDown") {
            e.preventDefault();
            const nextIndex = (index + 1) % this.menuItems.length;
            this.menuItems[nextIndex].focus();
          } else if (e.key === "ArrowUp") {
            e.preventDefault();
            const prevIndex =
              (index - 1 + this.menuItems.length) % this.menuItems.length;
            if (prevIndex === this.menuItems.length - 1) {
              this.profileBtn.focus();
            } else {
              this.menuItems[prevIndex].focus();
            }
          }
        });
      });
    },

    open() {
      if (!this.profileMenu) return;

      this.profileMenu.setAttribute("aria-hidden", "false");
      this.profileBtn.setAttribute("aria-expanded", "true");
      this.isOpen = true;

      // Smooth animation
      requestAnimationFrame(() => {
        this.profileMenu.classList.add("show");
      });
    },

    close() {
      if (!this.profileMenu) return;

      this.profileMenu.setAttribute("aria-hidden", "true");
      this.profileBtn.setAttribute("aria-expanded", "false");
      this.isOpen = false;
      this.profileMenu.classList.remove("show");
    },

    focusFirstItem() {
      if (this.menuItems.length > 0) {
        this.menuItems[0].focus();
      }
    },
  };

  // Initialize on DOM ready
  document.addEventListener("DOMContentLoaded", () => {
    ProfileDropdown.init();
  });

  // Also try immediate initialization
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", () => {
      ProfileDropdown.init();
    });
  } else {
    ProfileDropdown.init();
  }

  // Expose for manual access if needed
  window.ProfileDropdown = ProfileDropdown;
})();
