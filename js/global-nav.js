/*
  global-nav.js
  - Vanilla JS (ES5) for wide compatibility including IE11+ (avoid modern syntax)
  - Behavior: mobile menu toggle, user menu toggle, click-outside-to-close, keyboard accessibility, ESC handling
  - Optional Shadow DOM mounting: add data-use-shadow="1" on `.bc-nav` root to create a shadow root and inject internal markup + CSS for stricter isolation.

  Notes for IE11: If supporting IE11, polyfill Element.closest and classList if needed.
*/

(function () {
  // Polyfill for Element.closest (IE11)
  if (!Element.prototype.closest) {
    Element.prototype.closest = function (s) {
      var el = this;
      do {
        if (el.matches && el.matches(s)) return el;
        el = el.parentElement || el.parentNode;
      } while (el !== null && el.nodeType === 1);
      return null;
    };
  }

  var navROOTS = document.querySelectorAll('[data-component="global-nav"]');
  for (var i = 0; i < navROOTS.length; i++) {
    (function (root) {
      // If requested and supported, mount into Shadow DOM for full isolation
      var useShadow = root.getAttribute('data-use-shadow') === '1' && root.attachShadow;
      if (useShadow) {
        try {
          var shadow = root.attachShadow({ mode: 'open' });
          // Move inner HTML into shadow and add styles (load CSS via fetch and inline here if available)
          // Simpler approach: copy current innerHTML and inject style tag with computed CSS (recommended to include compiled CSS inlined for production)
          var inner = root.innerHTML;
          var styleEl = document.createElement('style');
          // Attempt to fetch CSS file to isolate styles (best production step: inline CSS into this style).
          // Fallback: rely on existing CSS in the main document.
          styleEl.textContent = '';
          shadow.appendChild(styleEl);
          shadow.innerHTML += inner; // move markup
          // Keep root empty to avoid duplication
          root.innerHTML = '';
          // point querySelector into shadow root
          root._scope = shadow;
        } catch (e) {
          console.warn('Shadow DOM mount failed, falling back to light DOM isolation:', e);
          root._scope = root;
        }
      } else {
        root._scope = root; // operate in light DOM
      }

      // scoped query helper
      function $(sel) {
        return root._scope.querySelector(sel);
      }
      function $all(sel) {
        return root._scope.querySelectorAll(sel);
      }

      var toggleBtn = $(".bc-nav__toggle");
      var menu = $(".bc-nav__menu");
      var userTriggers = $all(".bc-user__trigger");

      // --- Horizontal Scroll Logic (Transform based to allow Dropdowns) ---
    var navMenu = root._scope.querySelector('.bc-nav__menu');
    var scrollContainer = root._scope.querySelector('.bc-nav__scroll-container');
    var navList = root._scope.querySelector('.bc-nav__list');
    var btnLeft = root._scope.querySelector('.bc-nav__scroll-btn--left');
    var btnRight = root._scope.querySelector('.bc-nav__scroll-btn--right');

    if (navList && btnLeft && btnRight && scrollContainer) {
      var currentTranslate = 0;

      function updateScrollButtons() {
        var containerWidth = scrollContainer.clientWidth;
        var listWidth = navList.scrollWidth;
        var maxTranslate = Math.max(0, listWidth - containerWidth);

        if (maxTranslate <= 0) {
          currentTranslate = 0;
          navList.style.transform = 'translateX(0)';
          btnLeft.classList.remove('is-visible');
          btnRight.classList.remove('is-visible');
          return;
        }

        // Show arrows when content overflows (buttons start to disappear)
        // Right arrow always visible when there's overflow content to scroll to
        btnRight.classList.add('is-visible');
        
        // Left arrow visible only when we've scrolled left
        if (currentTranslate < -10) {
          btnLeft.classList.add('is-visible');
        } else {
          btnLeft.classList.remove('is-visible');
        }

        // Hide right arrow when we've reached the end
        if (currentTranslate <= -maxTranslate + 10) {
          btnRight.classList.remove('is-visible');
        }
      }

      btnLeft.addEventListener('click', function() {
        currentTranslate = Math.min(0, currentTranslate + 200);
        navList.style.transform = 'translateX(' + currentTranslate + 'px)';
        updateScrollButtons();
      });

      btnRight.addEventListener('click', function() {
        var maxTranslate = navList.scrollWidth - scrollContainer.clientWidth;
        currentTranslate = Math.max(-maxTranslate, currentTranslate - 200);
        navList.style.transform = 'translateX(' + currentTranslate + 'px)';
        updateScrollButtons();
      });

      window.addEventListener('resize', function() {
        // Reset if container becomes large enough
        var maxTranslate = Math.max(0, navList.scrollWidth - scrollContainer.clientWidth);
        if (currentTranslate < -maxTranslate) {
          currentTranslate = -maxTranslate;
          navList.style.transform = 'translateX(' + currentTranslate + 'px)';
        }
        updateScrollButtons();
      });
      
      setTimeout(updateScrollButtons, 500);
    }

      // Hamburger toggle (mobile)
      if (toggleBtn) {
        toggleBtn.addEventListener('click', function (e) {
          var expanded = this.getAttribute('aria-expanded') === 'true';
          this.setAttribute('aria-expanded', expanded ? 'false' : 'true');
          var open = !expanded;
          if (open) {
            menu.classList.add('is-open');
            menu.setAttribute('aria-hidden', 'false');
          } else {
            menu.classList.remove('is-open');
            menu.setAttribute('aria-hidden', 'true');
          }
        });
      }

      // Helper to close all open menus
      function closeAllMenus(exceptMenu) {
        // Close user menus
        var userMenus = root._scope.querySelectorAll('.bc-user__menu.is-open');
        for (var i = 0; i < userMenus.length; i++) {
          if (userMenus[i] !== exceptMenu) {
            userMenus[i].classList.remove('is-open');
            userMenus[i].setAttribute('aria-hidden', 'true');
            var trigger = userMenus[i].parentElement.querySelector('.bc-user__trigger');
            if (trigger) {
              trigger.setAttribute('aria-expanded', 'false');
            }
          }
        }
        // Close generic dropdowns
        var dropdowns = root._scope.querySelectorAll('.bc-dropdown__menu.is-open');
        for (var j = 0; j < dropdowns.length; j++) {
          if (dropdowns[j] !== exceptMenu) {
            dropdowns[j].classList.remove('is-open');
            dropdowns[j].setAttribute('aria-hidden', 'true');
            var dTrigger = dropdowns[j].parentElement.querySelector('.bc-dropdown__trigger');
            if (dTrigger) {
              dTrigger.setAttribute('aria-expanded', 'false');
            }
          }
        }
        // Close all submenus
        var submenus = root._scope.querySelectorAll('.bc-dropdown__submenu-wrap.is-open');
        for (var k = 0; k < submenus.length; k++) {
          var wrap = submenus[k];
          var subMenu = wrap.querySelector('.bc-dropdown__submenu');
          wrap.classList.remove('is-open');
          if (subMenu) subMenu.setAttribute('aria-hidden', 'true');
          var sTrigger = wrap.querySelector('.bc-dropdown__submenu-trigger');
          if (sTrigger) {
            sTrigger.setAttribute('aria-expanded', 'false');
          }
        }
      }

      // Click-outside to close menus
      document.addEventListener('click', function (ev) {
        var target = ev.target;
        // If click not inside root, close all
        if (!target.closest || !target.closest('[data-component="global-nav"]')) {
          if (menu && menu.classList.contains('is-open')) {
            menu.classList.remove('is-open');
            menu.setAttribute('aria-hidden', 'true');
            if (toggleBtn) toggleBtn.setAttribute('aria-expanded', 'false');
          }
          closeAllMenus();
        }
      }, true);

      // User menu toggle (multiple instances)
      for (var u = 0; u < userTriggers.length; u++) {
        (function (trigger) {
          trigger.addEventListener('click', function (e) {
            if (this.hasAttribute('data-bs-toggle')) return;
            e.stopPropagation();
            var parent = this.closest('.bc-user');
            if (!parent) return;
            var userMenu = parent.querySelector('.bc-user__menu');
            if (!userMenu) return;

            var expanded = this.getAttribute('aria-expanded') === 'true';
            closeAllMenus(expanded ? null : userMenu);

            this.setAttribute('aria-expanded', expanded ? 'false' : 'true');
            if (!expanded) {
              userMenu.classList.add('is-open');
              userMenu.setAttribute('aria-hidden', 'false');
            } else {
              userMenu.classList.remove('is-open');
              userMenu.setAttribute('aria-hidden', 'true');
            }
          });
        })(userTriggers[u]);
      }

      // Generic Dropdown Toggle
      var dropdownTriggers = $all('.bc-dropdown__trigger');
      for (var j = 0; j < dropdownTriggers.length; j++) {
        (function (trigger) {
          trigger.addEventListener('click', function (e) {
            if (this.hasAttribute('data-bs-toggle')) return;
            e.stopPropagation();
            var parent = this.closest('.bc-dropdown');
            if (!parent) return;
            var dropdownMenu = parent.querySelector('.bc-dropdown__menu');
            if (!dropdownMenu) return;

            var expanded = this.getAttribute('aria-expanded') === 'true';
            closeAllMenus(expanded ? null : dropdownMenu);

            this.setAttribute('aria-expanded', expanded ? 'false' : 'true');
            if (!expanded) {
              dropdownMenu.classList.add('is-open');
              dropdownMenu.setAttribute('aria-hidden', 'false');
            } else {
              dropdownMenu.classList.remove('is-open');
              dropdownMenu.setAttribute('aria-hidden', 'true');
            }
          });
        })(dropdownTriggers[j]);
      }

      function closeAllSubmenus(exceptWrap) {
        var openSubmenus = root._scope.querySelectorAll('.bc-dropdown__submenu-wrap.is-open');
        for (var s = 0; s < openSubmenus.length; s++) {
          if (exceptWrap && openSubmenus[s] === exceptWrap) continue;
          openSubmenus[s].classList.remove('is-open');
          var subMenu = openSubmenus[s].querySelector('.bc-dropdown__submenu');
          if (subMenu) subMenu.setAttribute('aria-hidden', 'true');
          var subTrig = openSubmenus[s].querySelector('.bc-dropdown__submenu-trigger');
          if (subTrig) {
            subTrig.setAttribute('aria-expanded', 'false');
          }
        }
      }

      // Second-level submenu toggle inside dropdown panels
      var submenuTriggers = $all('.bc-dropdown__submenu-trigger');
      for (var s = 0; s < submenuTriggers.length; s++) {
        (function (trigger) {
          trigger.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var wrap = this.closest('.bc-dropdown__submenu-wrap');
            if (!wrap) return;
            var subMenu = wrap.querySelector('.bc-dropdown__submenu');
            if (!subMenu) return;

            closeAllSubmenus(wrap);

            var expanded = this.getAttribute('aria-expanded') === 'true';
            this.setAttribute('aria-expanded', expanded ? 'false' : 'true');

            if (!expanded) {
              wrap.classList.add('is-open');
              subMenu.setAttribute('aria-hidden', 'false');
            } else {
              wrap.classList.remove('is-open');
              subMenu.setAttribute('aria-hidden', 'true');
            }
          });
        })(submenuTriggers[s]);
      }

      // Keyboard: ESC closes opened menus
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' || e.keyCode === 27) {
          if (menu && menu.classList.contains('is-open')) { menu.classList.remove('is-open'); menu.setAttribute('aria-hidden', 'true'); if (toggleBtn) toggleBtn.setAttribute('aria-expanded', 'false'); }
          var openUserMenus = root._scope.querySelectorAll('.bc-user__menu.is-open');
          for (var um = 0; um < openUserMenus.length; um++) {
            openUserMenus[um].classList.remove('is-open');
            openUserMenus[um].setAttribute('aria-hidden', 'true');
            var ut = openUserMenus[um].parentElement.querySelector('.bc-user__trigger');
            if (ut) ut.setAttribute('aria-expanded', 'false');
          }
        }
      });

      // Touch target enforcement: ensure interactive elements have min 44px (CSS ensures this)

      // Role-based admin visibility (for progressive enhancement)
      var isAdmin = root.getAttribute('data-is-admin') === '1';
      if (isAdmin) {
        var adminBtn = root._scope.querySelector('[data-role="admin"]');
        if (adminBtn) adminBtn.setAttribute('aria-hidden', 'false');
      }

    })(navROOTS[i]);
  }
})();