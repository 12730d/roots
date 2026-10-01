(function () {
	// Guard: ensure nav exists
	const nav = document.querySelector('[data-bc-nav]');
	if (!nav) { return; }

	// Elements
	const hamburger = nav.querySelector('.bc-nav__hamburger');
	const panel = nav.querySelector('#bc-nav-panel');
	const profile = nav.querySelector('[data-bc-profile]');
	const avatarBtn = profile?.querySelector('.bc-nav__avatar');
	const notifBtn = nav.querySelector('.bc-nav__notifications');
	const notifCountEl = nav.querySelector('.bc-nav__notif-count');

	// Helper: toggle class and aria


	// Hamburger toggle (mobile-first)
	if (hamburger && panel) {
		hamburger.addEventListener('click', function () {
			const expanded = this.getAttribute('aria-expanded') === 'true';
			this.setAttribute('aria-expanded', expanded ? 'false' : 'true');
			nav.classList.toggle('bc-nav--open');
		});

		// Close menu on escape when open
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') {
				if (nav.classList.contains('bc-nav--open')) {
					nav.classList.remove('bc-nav--open');
					hamburger.setAttribute('aria-expanded', 'false');
					hamburger.focus();
				}
			}
		});
	}

	// Profile dropdown toggle with click-outside-to-close
	if (avatarBtn && profile) {
		function closeProfile() {
			profile.classList.remove('bc-profile--open');
			avatarBtn.setAttribute('aria-expanded', 'false');
			const dropdown = profile.querySelector('.bc-nav__dropdown');
			if (dropdown) { dropdown.setAttribute('aria-hidden', 'true'); }
		}
		function openProfile() {
			profile.classList.add('bc-profile--open');
			avatarBtn.setAttribute('aria-expanded', 'true');
			const dropdown = profile.querySelector('.bc-nav__dropdown');
			if (dropdown) { dropdown.setAttribute('aria-hidden', 'false'); dropdown.querySelector('a, button, input')?.focus(); }
		}

		avatarBtn.addEventListener('click', function (e) {
			e.stopPropagation();
			if (profile.classList.contains('bc-profile--open')) {
				closeProfile();
			} else {
				openProfile();
			}
		});

		// Click outside to close profile dropdown
		document.addEventListener('click', function (e) {
			if (!profile.contains(e.target)) {
				closeProfile();
			}
		});

		// Esc to close profile dropdown
		profile.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') {
				closeProfile();
				avatarBtn.focus();
			}
		});
	}

	// Minimal keyboard navigation for menu items
	(function enableKeyboardNav() {
		const menu = nav.querySelector('.bc-nav__links');
		if (!menu) { return; }
		const items = Array.prototype.slice.call(menu.querySelectorAll('a[role="menuitem"], a, button'));
		menu.addEventListener('keydown', function (e) {
			const target = e.target;
			const idx = items.indexOf(target);
			if (idx === -1) { return; }

			if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
				e.preventDefault();
				const next = items[(idx + 1) % items.length];
				next?.focus();
			} else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
				e.preventDefault();
				const prev = items[(idx - 1 + items.length) % items.length];
				prev?.focus();
			}
		});
	})();

	// Fetch notification count (simple, small payload). Can poll or use server push as needed.
	if (notifBtn && notifCountEl) {
		function updateNotifications() {
			const xhr = new XMLHttpRequest();
			xhr.open('GET', '/get_recent_notifications', true);
			// Ensure credentials (cookies) are sent with the request
			xhr.withCredentials = true;
			xhr.onreadystatechange = function () {
				if (xhr.readyState === 4) {
					if (xhr.status === 200) {
						try {
							const data = JSON.parse(xhr.responseText);
							// Support both 'count' and 'pending_count' for backward compatibility
							const c = Number.parseInt(data.count || data.pending_count || 0, 10) || 0;
							notifCountEl.textContent = c;
							notifCountEl.style.display = c > 0 ? 'inline-block' : 'none';
						} catch (e) {
							// Fail gracefully -> hide badge
							console.error('Error parsing notifications response:', e);
							notifCountEl.style.display = 'none';
						}
					} else if (xhr.status === 401) {
						// User is not logged in - hide badge and don't poll anymore
						console.warn('Not authenticated for notifications');
						notifCountEl.style.display = 'none';
						// Stop polling if unauthorized
						if (globalThis.notificationPollInterval) {
							clearInterval(globalThis.notificationPollInterval);
						}
					} else {
						// Other errors - fail gracefully
						console.error('Notifications request failed with status:', xhr.status);
						notifCountEl.style.display = 'none';
					}
				}
			};
			xhr.onerror = function () {
				console.error('Network error fetching notifications');
				notifCountEl.style.display = 'none';
			};
			xhr.send();
		}
		// Initial load
		updateNotifications();
		// Low-frequency polling: e.g., every 60s, keeps within < 50kb overhead since payload tiny.
		// Store interval ID so we can clear it if needed
		globalThis.notificationPollInterval = setInterval(updateNotifications, 60000);
	}

	// Respect reduced motion preferences (remove animation if user prefers)
	if (globalThis.matchMedia?.('(prefers-reduced-motion: reduce)').matches) {
		document.documentElement.classList.add('bc-reduced-motion');
	}
})();
