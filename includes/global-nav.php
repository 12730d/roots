<?php

/*
  Global Navigation Partial
  - Include this partial at the top of your layout (e.g., MasterLayout.php)
  - Use `data-is-admin="1"` on the root nav element when the user is an admin
  - To enable Shadow DOM isolation (optional), set data-use-shadow="1" and include `global-nav.js`
*/
?>

<!-- Skip link for keyboard users -->
<a class="bc-skip-link" href="#main">Skip to content</a>

<nav class="bc-nav" data-component="global-nav" aria-label="Main navigation" role="navigation"
  data-is-admin="<?php echo (isset($isAdmin) && $isAdmin) ? '1' : '0'; ?>" data-use-shadow="0">
  <div class="bc-nav__inner">
    <div class="bc-nav__brand">
      <!-- Inline SVG logo for minimal assets + responsive scaling -->
      <a href="/" class="bc-nav__logo" aria-label="Homepage">
        <svg width="28" height="28" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
          <circle cx="12" cy="12" r="10" fill="#2ECC71"></circle>
          <path d="M8 13l2 2 6-6" stroke="#0D1B0D" stroke-width="1.5" fill="none" stroke-linecap="round"
            stroke-linejoin="round" />
        </svg>
        <span class="bc-nav__site-title">ROOTS</span>
      </a>
    </div>

    <button class="bc-nav__toggle" aria-controls="bc-nav-menu" aria-expanded="false" aria-label="Toggle menu">
      <span class="bc-nav__hamburger" aria-hidden="true"></span>
    </button>

    <div class="bc-nav__menu" id="bc-nav-menu" role="menubar">
      <ul class="bc-nav__list">
        <li><a role="menuitem" href="/" class="bc-nav__link">Home</a></li>
        <li><a role="menuitem" href="/about.php" class="bc-nav__link">About</a></li>
        <li><a role="menuitem" href="/support.php" class="bc-nav__link">Support</a></li>
        <li><a role="menuitem" href="/contact.php" class="bc-nav__link">Contact</a></li>
      </ul>
    </div>

    <div class="bc-nav__actions">
      <button class="bc-btn bc-btn--icon bc-notif-btn" aria-label="Notifications" aria-haspopup="false">
        <!-- small inline bell icon -->
        <svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true">
          <path d="M12 22a2 2 0 0 0 2-2H10a2 2 0 0 0 2 2zM18 16v-5a6 6 0 1 0-12 0v5l-2 2v1h16v-1l-2-2z"
            fill="currentColor" />
        </svg>
      </button>

      <button class="bc-btn bc-btn--admin" data-role="admin" aria-label="Admin tools" title="Admin tools">
        <svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true">
          <path d="M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8z" fill="currentColor" />
          <path
            d="M21 12a9 9 0 0 0-.7-3.4l2.1-1.6-2.2-3.8-2.5 1a8.9 8.9 0 0 0-2.9-1.6L15 0h-6l-.7 3.6a8.9 8.9 0 0 0-2.9 1.6l-2.5-1L.9 6.9l2.1 1.6A9 9 0 0 0 3 12c0 1.1.2 2.2.6 3.2l-2.1 1.6 2.2 3.8 2.5-1.1a8.9 8.9 0 0 0 2.9 1.6L9 24h6l.7-3.6a8.9 8.9 0 0 0 2.9-1.6l2.5 1.1 2.2-3.8-2.1-1.6c.4-1 .6-2.1.6-3.2z"
            fill="currentColor" opacity="0.15" />
        </svg>
      </button>

      <!-- Profile avatar + user menu -->
      <div class="bc-user">
        <button class="bc-user__trigger" aria-haspopup="true" aria-expanded="false" aria-controls="bc-user-menu">
          <img src="/img/avatar-default.png" alt="User avatar" class="bc-user__avatar" width="32" height="32">
        </button>

        <div class="bc-user__menu" id="bc-user-menu" role="menu" aria-hidden="true">
          <a role="menuitem" href="/profile.php" class="bc-user__item">Profile Settings</a>
          <a role="menuitem" href="/account.php" class="bc-user__item">Account Preferences</a>
          <a role="menuitem" href="/help.php" class="bc-user__item">Help</a>
          <button role="menuitem" class="bc-user__item bc-user__logout"
            onclick="location.href='/logout.php'">Logout</button>
        </div>
      </div>
    </div>
  </div>
</nav>
