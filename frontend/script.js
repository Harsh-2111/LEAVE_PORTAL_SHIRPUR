document.addEventListener('DOMContentLoaded', () => {
  const roleSelect = document.getElementById('role-select');
  const roleTitle = document.getElementById('role-title');
  const appContainer = document.getElementById('app-container');
  const loginForm = document.getElementById('login-section');
  const loginSection = document.getElementById('login-section');
  const landingPage = document.getElementById('landing-page');
  const loginMessage = document.getElementById('message-box');
  const studentPage = document.getElementById('student-page');
  const wardenPage = document.getElementById('teacher-page');
  const adminPage = document.getElementById('admin-page');
  const securityPage = document.getElementById('security-page');
  const logoutModal = document.getElementById('logout-confirm-modal');
  const changePasswordModal = document.getElementById('change-password-modal');
  const forgotPasswordModal = document.getElementById('forgot-password-modal');
  const signupModal = document.getElementById('signup-modal');
  const state = {
    csrfToken: '',
    user: null,
    wardenApprovedTotal: 0,
    forgotResetToken: '',
    forgotCsrfToken: '',
    signupToken: '',
    signupCsrfToken: ''
  };
  const optionalScriptLoads = new Map();
  let loginProgressTimer = null;
  let wardenAttendanceTimer = null;
  let securityQrScanner = null;
  let handlingSecurityScan = false;
  let loadingWardenAttendance = false;
  let wardenPendingPage = 1;
  let wardenPendingTotal = 0;
  let wardenFollowupPage = 1;
  let wardenFollowupTotal = 0;
  let wardenApprovedPage = 1;

  function loadOptionalScript(url, isAvailable) {
    if (isAvailable()) return Promise.resolve();
    if (optionalScriptLoads.has(url)) return optionalScriptLoads.get(url);

    const load = new Promise((resolve, reject) => {
      const script = document.createElement('script');
      script.src = url;
      script.async = true;
      script.onload = () => isAvailable()
        ? resolve()
        : reject(new Error('A required portal feature could not be initialized.'));
      script.onerror = () => reject(new Error('A required portal feature could not be loaded. Check your connection and try again.'));
      document.head.appendChild(script);
    }).catch(error => {
      optionalScriptLoads.delete(url);
      throw error;
    });

    optionalScriptLoads.set(url, load);
    return load;
  }

  function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, character => ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#39;'
    })[character]);
  }

  function openChangePasswordModal() {
    if (!changePasswordModal) return;
    const form = document.getElementById('change-password-form');
    const message = document.getElementById('change-password-message');
    const mustChangePassword = Boolean(state.user?.must_change_password);
    if (form) form.reset();
    resetChangePasswordVisibility();
    if (message) hideMessage(message);
    document.getElementById('change-password-title').textContent = mustChangePassword ? 'First-time Password Change Required' : 'Change Password';
    document.getElementById('change-password-description').textContent = mustChangePassword
      ? 'Set a private password of at least 8 characters before continuing. Your temporary password will stop working after this change.'
      : 'Use a password you have not used before.';
    document.getElementById('close-change-password-modal').classList.toggle('hidden', mustChangePassword);
    document.getElementById('cancel-change-password').classList.toggle('hidden', mustChangePassword);
    document.getElementById('forced-change-logout').classList.toggle('hidden', !mustChangePassword);
    changePasswordModal.classList.remove('hidden');
  }

  function closeChangePasswordModal() {
    if (!changePasswordModal) return;
    const form = document.getElementById('change-password-form');
    const message = document.getElementById('change-password-message');
    if (form) form.reset();
    resetChangePasswordVisibility();
    if (message) hideMessage(message);
    changePasswordModal.classList.add('hidden');
    document.getElementById('close-change-password-modal').classList.remove('hidden');
    document.getElementById('cancel-change-password').classList.remove('hidden');
    document.getElementById('forced-change-logout').classList.add('hidden');
  }

  function openForgotPasswordModal() {
    document.getElementById('forgot-password-identity-form').reset();
    document.getElementById('forgot-password-reset-form').reset();
    resetForgotPasswordVisibility();
    document.getElementById('forgot-password-identity-form').classList.remove('hidden');
    document.getElementById('forgot-password-reset-form').classList.add('hidden');
    hideMessage(document.getElementById('forgot-password-identity-message'));
    hideMessage(document.getElementById('forgot-password-reset-message'));
    state.forgotResetToken = '';
    state.forgotCsrfToken = '';
    forgotPasswordModal.classList.remove('hidden');
  }

  function closeForgotPasswordModal() {
    document.getElementById('forgot-password-identity-form').reset();
    document.getElementById('forgot-password-reset-form').reset();
    resetForgotPasswordVisibility();
    document.getElementById('forgot-password-identity-form').classList.remove('hidden');
    document.getElementById('forgot-password-reset-form').classList.add('hidden');
    hideMessage(document.getElementById('forgot-password-identity-message'));
    hideMessage(document.getElementById('forgot-password-reset-message'));
    state.forgotResetToken = '';
    state.forgotCsrfToken = '';
    forgotPasswordModal.classList.add('hidden');
  }

  function openSignupModal() {
    document.getElementById('signup-identity-form').reset();
    document.getElementById('signup-password-form').reset();
    document.getElementById('signup-identity-form').classList.remove('hidden');
    document.getElementById('signup-password-form').classList.add('hidden');
    hideMessage(document.getElementById('signup-identity-message'));
    hideMessage(document.getElementById('signup-password-message'));
    state.signupToken = '';
    state.signupCsrfToken = '';
    signupModal.classList.remove('hidden');
  }

  function closeSignupModal() {
    document.getElementById('signup-identity-form').reset();
    document.getElementById('signup-password-form').reset();
    document.getElementById('signup-identity-form').classList.remove('hidden');
    document.getElementById('signup-password-form').classList.add('hidden');
    hideMessage(document.getElementById('signup-identity-message'));
    hideMessage(document.getElementById('signup-password-message'));
    state.signupToken = '';
    state.signupCsrfToken = '';
    signupModal.classList.add('hidden');
  }

  function redirectRegisteredStudentToLogin(sapId, message) {
    closeSignupModal();
    roleSelect.value = 'student';
    updateRoleTitle();
    document.getElementById('user-id').value = sapId;
    document.getElementById('password').value = '';
    document.getElementById('password').focus();
    showMessage(loginMessage, message, 'text-blue-700 bg-blue-50');
  }

  async function handleSignupVerification(event) {
    event.preventDefault();
    const button = document.getElementById('verify-signup-button');
    const message = document.getElementById('signup-identity-message');
    button.disabled = true;
    const formData = new FormData();
    formData.append('action', 'verify');
    formData.append('sap_id', document.getElementById('signup-sap-id').value.trim());
    formData.append('parent_contact', document.getElementById('signup-parent-contact').value.trim());
    formData.append('parent_email', document.getElementById('signup-parent-email').value.trim());

    try {
      const response = await fetch('/api/index.php?endpoint=signup.php', { method: 'POST', body: formData });
      const result = await response.json();
      if (!response.ok || !result.success) {
        if (result.account_exists) {
          redirectRegisteredStudentToLogin(
            document.getElementById('signup-sap-id').value.trim(),
            result.message || 'This account is already registered. Please log in instead.'
          );
          return;
        }
        showMessage(message, result.message || 'Account details could not be verified.', 'text-red-700 bg-red-50');
        return;
      }

      state.signupToken = result.signup_token;
      state.signupCsrfToken = result.csrf_token;
      document.getElementById('signup-identity-form').classList.add('hidden');
      document.getElementById('signup-password-form').classList.remove('hidden');
      hideMessage(message);
    } catch (error) {
      console.error(error);
      showMessage(message, 'Account verification failed. Please try again.', 'text-red-700 bg-red-50');
    } finally {
      button.disabled = false;
    }
  }

  async function handleSignupCreate(event) {
    event.preventDefault();
    const button = document.getElementById('create-student-account-button');
    const message = document.getElementById('signup-password-message');
    const password = document.getElementById('signup-password').value;
    const confirmPassword = document.getElementById('signup-confirm-password').value;
    if (password.length < 8 || password.length > 72) {
      showMessage(message, 'Choose a password between 8 and 72 characters.', 'text-red-700 bg-red-50');
      return;
    }
    if (password !== confirmPassword) {
      showMessage(message, 'Password and confirmation do not match.', 'text-red-700 bg-red-50');
      return;
    }

    button.disabled = true;
    const formData = new FormData();
    formData.append('action', 'create');
    formData.append('csrf_token', state.signupCsrfToken);
    formData.append('signup_token', state.signupToken);
    formData.append('password', password);
    formData.append('confirm_password', confirmPassword);

    try {
      const response = await fetch('/api/index.php?endpoint=signup.php', { method: 'POST', body: formData });
      const result = await response.json();
      if (!response.ok || !result.success) {
        if (result.account_exists) {
          redirectRegisteredStudentToLogin(
            document.getElementById('signup-sap-id').value.trim(),
            result.message || 'This account is already registered. Please log in instead.'
          );
          return;
        }
        showMessage(message, result.message || 'Account could not be created.', 'text-red-700 bg-red-50');
        return;
      }

      const sapId = document.getElementById('signup-sap-id').value.trim();
      closeSignupModal();
      document.getElementById('user-id').value = sapId;
      document.getElementById('password').value = '';
      showMessage(loginMessage, result.message || 'Account created. Sign in with your new password.', 'text-green-700 bg-green-50');
    } catch (error) {
      console.error(error);
      showMessage(message, 'Account creation failed. Please try again.', 'text-red-700 bg-red-50');
    } finally {
      button.disabled = false;
    }
  }

  function resetForgotPasswordVisibility() {
    document.querySelectorAll('#forgot-password-reset-form [data-password-toggle]').forEach(button => {
      const input = document.getElementById(button.dataset.passwordToggle);
      if (!input) return;
      input.type = 'password';
      const label = `Show ${button.dataset.passwordLabel}`;
      button.setAttribute('aria-label', label);
      button.title = label;
    });
  }

  async function handleForgotPasswordIdentity(event) {
    event.preventDefault();
    const button = document.getElementById('verify-forgot-password-button');
    const message = document.getElementById('forgot-password-identity-message');
    button.disabled = true;
    const formData = new FormData(event.currentTarget);
    formData.append('action', 'verify');
    formData.append('sap_id', document.getElementById('forgot-sap-id').value.trim());
    formData.append('contact', document.getElementById('forgot-contact').value.trim());
    formData.append('email', document.getElementById('forgot-email').value.trim());

    try {
      const response = await fetch('/api/index.php?endpoint=forgot_password.php', { method: 'POST', body: formData });
      const result = await response.json();
      if (!response.ok || !result.success) {
        showMessage(message, result.message || 'Password recovery could not be completed.', 'text-red-700 bg-red-50');
        return;
      }

      state.forgotResetToken = result.reset_token;
      state.forgotCsrfToken = result.csrf_token;
      document.getElementById('forgot-password-identity-form').classList.add('hidden');
      document.getElementById('forgot-password-reset-form').classList.remove('hidden');
      hideMessage(message);
    } catch (error) {
      console.error(error);
      showMessage(message, 'Password recovery request failed. Please try again.', 'text-red-700 bg-red-50');
    } finally {
      button.disabled = false;
    }
  }

  async function handleForgotPasswordReset(event) {
    event.preventDefault();
    const button = document.getElementById('reset-forgot-password-button');
    const message = document.getElementById('forgot-password-reset-message');
    const newPassword = document.getElementById('forgot-new-password').value;
    const confirmPassword = document.getElementById('forgot-confirm-password').value;
    if (newPassword.length < 8) {
      showMessage(message, 'New password must be at least 8 characters long.', 'text-red-700 bg-red-50');
      return;
    }
    if (newPassword !== confirmPassword) {
      showMessage(message, 'New password and confirmation do not match.', 'text-red-700 bg-red-50');
      return;
    }

    button.disabled = true;
    const formData = new FormData();
    formData.append('action', 'reset');
    formData.append('csrf_token', state.forgotCsrfToken);
    formData.append('reset_token', state.forgotResetToken);
    formData.append('new_password', newPassword);
    formData.append('confirm_password', confirmPassword);

    try {
      const response = await fetch('/api/index.php?endpoint=forgot_password.php', { method: 'POST', body: formData });
      const result = await response.json();
      if (!response.ok || !result.success) {
        showMessage(message, result.message || 'Password reset could not be completed.', 'text-red-700 bg-red-50');
        return;
      }

      showMessage(message, result.message || 'Password reset successfully.', 'text-green-700 bg-green-50');
      state.forgotResetToken = '';
      state.forgotCsrfToken = '';
      setTimeout(() => closeForgotPasswordModal(), 1400);
    } catch (error) {
      console.error(error);
      showMessage(message, 'Password reset request failed. Please try again.', 'text-red-700 bg-red-50');
    } finally {
      button.disabled = false;
    }
  }

  function resetChangePasswordVisibility() {
    document.querySelectorAll('#change-password-form [data-password-toggle]').forEach(button => {
      const input = document.getElementById(button.dataset.passwordToggle);
      if (!input) return;
      input.type = 'password';
      const label = `Show ${button.dataset.passwordLabel}`;
      button.setAttribute('aria-label', label);
      button.title = label;
    });
  }

  function showMessage(element, message, type) {
    if (!element) return;
    element.textContent = message;
    element.className = `mt-4 p-3 rounded-lg text-sm ${type}`;
    element.classList.remove('hidden');
  }

  function hideMessage(element) {
    if (!element) return;
    element.textContent = '';
    element.classList.add('hidden');
  }

  function createPaginationControls(page, total, pageSize, onPageChange) {
    const totalPages = Math.max(1, Math.ceil(total / pageSize));
    if (totalPages <= 1) return null;
    const controls = document.createElement('div');
    controls.className = 'mt-4 flex flex-wrap items-center justify-between gap-3';
    const summary = document.createElement('span');
    summary.className = 'text-sm text-gray-600';
    summary.textContent = `Page ${page} of ${totalPages} · ${total} records`;
    controls.appendChild(summary);
    const buttons = document.createElement('div');
    buttons.className = 'flex gap-2';
    [
      ['Previous', page > 1, page - 1],
      ['Next', page < totalPages, page + 1]
    ].forEach(([label, enabled, targetPage]) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.textContent = label;
      button.disabled = !enabled;
      button.className = 'rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold disabled:opacity-50';
      button.addEventListener('click', () => onPageChange(targetPage));
      buttons.appendChild(button);
    });
    controls.appendChild(buttons);
    return controls;
  }

  function resetLoginProgress() {
    const wrapper = document.getElementById('login-progress-wrapper');
    const bar = document.getElementById('login-progress-bar');
    const text = document.getElementById('login-progress-text');
    if (loginProgressTimer) {
      clearInterval(loginProgressTimer);
      loginProgressTimer = null;
    }
    if (wrapper) wrapper.classList.add('hidden');
    if (bar) {
      bar.style.width = '0%';
    }
    if (text) {
      text.textContent = '0%';
    }
  }

  function animateLoginProgress() {
    const wrapper = document.getElementById('login-progress-wrapper');
    const bar = document.getElementById('login-progress-bar');
    const text = document.getElementById('login-progress-text');
    if (!wrapper || !bar || !text) return;
    let progress = 0;
    if (loginProgressTimer) {
      clearInterval(loginProgressTimer);
    }
    wrapper.classList.remove('hidden');
    loginProgressTimer = setInterval(() => {
      progress = Math.min(progress + 9 + Math.random() * 12, 96);
      bar.style.width = `${progress}%`;
      text.textContent = `${Math.round(progress)}%`;
    }, 100);
  }

  function updateRoleTitle() {
    const labels = {
      student: 'Student Login',
      warden: 'Warden Login',
      admin: 'Admin Login',
      security: 'Security Login'
    };
    roleTitle.textContent = labels[roleSelect.value] || 'Login';
    document.getElementById('student-forgot-password-button')?.classList.toggle('hidden', roleSelect.value !== 'student');
    document.getElementById('student-signup-button')?.classList.toggle('hidden', roleSelect.value !== 'student');
    document.getElementById('student-auth-divider')?.classList.toggle('hidden', roleSelect.value !== 'student');
  }

  function hideAllPages() {
    appContainer.classList.remove('login-shell');
    landingPage.classList.add('hidden');
    loginSection.classList.add('hidden');
    studentPage.classList.add('hidden');
    wardenPage.classList.add('hidden');
    adminPage.classList.add('hidden');
    securityPage.classList.add('hidden');
  }

  function showLogin() {
    hideAllPages();
    appContainer.classList.remove('portal-wide');
    appContainer.classList.add('login-shell');
    studentPage.replaceChildren();
    wardenPage.replaceChildren();
    adminPage.replaceChildren();
    securityPage.replaceChildren();
    landingPage.classList.remove('hidden');
    loginSection.classList.remove('hidden');
    document.getElementById('user-id').value = '';
    document.getElementById('password').value = '';
    document.getElementById('password').type = 'password';
    hideMessage(loginMessage);
    resetLoginProgress();
  }

  function getCsrfFormData(extra = {}) {
    const formData = new FormData();
    formData.append('csrf_token', state.csrfToken);
    Object.entries(extra).forEach(([key, value]) => formData.append(key, value));
    return formData;
  }

  async function showAuthenticatedPortal() {
    if (state.user?.role === 'student') await showStudentPage();
    else if (state.user?.role === 'warden') showWardenPage();
    else if (state.user?.role === 'admin') showAdminPage();
    else if (state.user?.role === 'security') showSecurityPage();
  }

  async function restoreSession() {
    try {
      const response = await fetch('/api/index.php?endpoint=session.php', { cache: 'no-store' });
      if (!response.ok) return;
      const result = await response.json();
      if (!result.success || !result.user) return;

      state.user = result.user;
      state.csrfToken = result.csrf_token || '';
      if (state.user.must_change_password) {
        openChangePasswordModal();
        return;
      }
      await showAuthenticatedPortal();
    } catch (error) {
      console.warn('Unable to restore the current session.', error);
    }
  }

  async function handleLogin() {
    const loginId = document.getElementById('user-id').value.trim();
    const password = document.getElementById('password').value;
    if (!loginId || !password) {
      showMessage(loginMessage, 'Please enter both ID and password.', 'error');
      return;
    }

    animateLoginProgress();
    const formData = new FormData();
    formData.append('role', roleSelect.value);
    formData.append('login_id', loginId);
    formData.append('password', password);
    try {
      const response = await fetch('/api/index.php?endpoint=login.php', { method: 'POST', body: formData });
      const result = await response.json();
      if (!result.success) {
        resetLoginProgress();
        showMessage(loginMessage, result.message || 'Login failed.', 'error');
        return;
      }

      const bar = document.getElementById('login-progress-bar');
      const text = document.getElementById('login-progress-text');
      if (bar) bar.style.width = '100%';
      if (text) text.textContent = '100%';
      state.user = result.user;
      state.csrfToken = result.csrf_token || '';
      hideMessage(loginMessage);
      setTimeout(async () => {
        resetLoginProgress();
        if (state.user.must_change_password) openChangePasswordModal();
        else await showAuthenticatedPortal();
      }, 350);
    } catch (error) {
      console.error(error);
      resetLoginProgress();
      showMessage(loginMessage, 'Login failed. Please try again.', 'error');
    }
  }

  async function logoutUser() {
    await stopSecurityScanner();
    if (window.notificationPollTimer) {
      clearInterval(window.notificationPollTimer);
      window.notificationPollTimer = null;
    }
    if (wardenAttendanceTimer !== null) {
      clearInterval(wardenAttendanceTimer);
      wardenAttendanceTimer = null;
    }
    try {
      await fetch('/api/index.php?endpoint=logout.php', { method: 'POST', body: getCsrfFormData() });
    } catch (error) {
      console.error(error);
    }
    state.user = null;
    state.csrfToken = '';
    logoutModal.classList.add('hidden');
    showLogin();
  }

  function notificationBellMarkup(role) {
    return `<div class="notification-wrapper relative">
      <button type="button" id="${role}-notifications-button" class="notification-bell" aria-label="Notifications" aria-expanded="false">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg>
        <span id="${role}-notification-count" class="notification-count" aria-hidden="true"></span>
      </button>
      <div id="${role}-notification-panel" class="notification-panel hidden">
        <div class="notification-panel-header"><strong>Notifications</strong><div class="notification-panel-actions"><button type="button" id="${role}-notifications-clear">Clear all</button><button type="button" id="${role}-notifications-close" class="notification-close" aria-label="Close notifications">×</button></div></div>
        <div id="${role}-notification-list" class="notification-list"><p class="notification-empty">Loading notifications...</p></div>
      </div>
    </div>`;
  }

  async function notificationRequest(action = '', notificationId = 0) {
    const form = action ? getCsrfFormData({ action, notification_id: notificationId }) : null;
    return fetch('/api/index.php?endpoint=get_notifications.php', action ? { method: 'POST', body: form } : {});
  }

  async function setupNotifications(role) {
    const button = document.getElementById(`${role}-notifications-button`);
    const panel = document.getElementById(`${role}-notification-panel`);
    if (!button || !panel) return;
    const badge = document.getElementById(`${role}-notification-count`);
    const list = document.getElementById(`${role}-notification-list`);

    async function loadNotifications() {
      try {
        const response = await notificationRequest();
        const result = await response.json();
        const items = result.items || [];
        const unreadCount = Number(result.unread_count || 0);
        badge.textContent = unreadCount > 0 ? (unreadCount > 99 ? '99+' : String(unreadCount)) : '';
        badge.classList.toggle('has-unread', unreadCount > 0);
        if (!items.length) {
          list.innerHTML = '<p class="notification-empty">No notifications yet.</p>';
          return;
        }
        list.innerHTML = items.map(item => `<button type="button" class="notification-item${item.read_at ? '' : ' unread'}" data-notification-id="${item.id}" data-leave-id="${item.leave_id || ''}">
          <span class="notification-item-title">${escapeHtml(item.title)}</span>
          <span>${escapeHtml(item.message)}</span>
          <small>${new Date(item.created_at).toLocaleString()}</small>
        </button>`).join('');
      } catch (error) {
        list.innerHTML = '<p class="notification-empty">Notifications could not be loaded.</p>';
      }
    }

    button.addEventListener('click', async () => {
      panel.classList.toggle('hidden');
      button.setAttribute('aria-expanded', String(!panel.classList.contains('hidden')));
      if (!panel.classList.contains('hidden')) await loadNotifications();
    });
    document.getElementById(`${role}-notifications-clear`).addEventListener('click', async () => {
      await notificationRequest('clear');
      await loadNotifications();
    });
    document.getElementById(`${role}-notifications-close`).addEventListener('click', () => {
      panel.classList.add('hidden');
      button.setAttribute('aria-expanded', 'false');
    });
    list.addEventListener('click', async event => {
      const item = event.target.closest('.notification-item');
      if (!item) return;
      await notificationRequest('read', Number(item.dataset.notificationId));
      panel.classList.add('hidden');
      if (role === 'warden') {
        await refreshPendingRequests();
        document.querySelector('.notification-wrapper')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
      } else {
        await loadStudentHistory();
      }
      const leaveId = item.dataset.leaveId;
      if (leaveId) setTimeout(() => document.getElementById(`leave-${leaveId}`)?.scrollIntoView({ behavior: 'smooth', block: 'center' }), 250);
      await loadNotifications();
    });
    await loadNotifications();
    if (window.notificationPollTimer) clearInterval(window.notificationPollTimer);
    window.notificationPollTimer = setInterval(() => { if (!document.hidden) loadNotifications(); }, 5000);
  }

  async function showStudentPage() {
    hideAllPages();
    appContainer.classList.add('portal-wide');
    studentPage.classList.remove('hidden');
    studentPage.innerHTML = `
      <div class="max-w-5xl mx-auto">
        <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
          <h1 class="text-3xl font-bold text-gray-800">Student Portal</h1>
            <div class="flex flex-wrap items-center gap-3">
              <div class="inline-flex items-center gap-2 rounded-full border border-gray-200 bg-white px-3 py-2 shadow-sm" aria-label="Student profile">
                <span id="student-profile-icon" class="flex h-9 w-9 items-center justify-center rounded-full bg-blue-100 text-blue-700" aria-hidden="true">
                  <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>
                </span>
                <span id="student-profile-display-name" class="max-w-[180px] truncate text-sm font-semibold text-gray-700"></span>
              </div>
              ${notificationBellMarkup('student')}
              <button type="button" id="student-change-password-button" class="bg-amber-500 text-white px-4 py-2 rounded-lg font-semibold hover:bg-amber-600">Change Password</button>
              <button type="button" data-logout-button class="bg-red-600 text-white px-4 py-2 rounded-lg font-semibold">Logout</button>
            </div>
        </div>
        <section class="bg-white rounded-xl p-5 border border-gray-200 shadow-sm mb-6">
          <h2 class="text-2xl font-semibold text-gray-700 mb-4">Submit Leave Request</h2>
          <form id="student-leave-form" class="grid md:grid-cols-2 gap-4">
            <label class="block text-sm font-medium text-gray-700">SAP ID
              <input id="student-sap-id" type="text" readonly required class="mt-2 w-full p-3 border border-gray-300 rounded-lg bg-gray-100" />
            </label>
            <label class="block text-sm font-medium text-gray-700">Full name
              <input id="student-profile-name" type="text" readonly required class="mt-2 w-full p-3 border border-gray-300 rounded-lg bg-gray-100" />
            </label>
            <label class="block text-sm font-medium text-gray-700">Student mobile number
              <span class="mt-2 flex w-full overflow-hidden rounded-lg border border-gray-300 bg-white focus-within:ring-2 focus-within:ring-blue-500">
                <span class="flex items-center border-r border-gray-300 bg-gray-100 px-3 text-gray-700" aria-label="Country code +91">+91</span>
                <input id="student-profile-contact" type="tel" inputmode="numeric" autocomplete="tel-national" maxlength="10" pattern="[0-9]{10}" required class="min-w-0 flex-1 border-0 p-3 outline-none focus:ring-0" placeholder="10-digit mobile number" aria-describedby="student-contact-help" />
              </span>
              <span id="student-contact-help" class="mt-1 block text-xs text-gray-500">Enter exactly 10 digits. The +91 country code is fixed.</span>
            </label>
            <label class="block text-sm font-medium text-gray-700">Parent email
              <input id="student-profile-parent-email" type="email" readonly required class="mt-2 w-full p-3 border border-gray-300 rounded-lg bg-gray-100" />
            </label>
            <label class="block text-sm font-medium text-gray-700">Parent contact number
              <input id="student-profile-parent-contact" type="tel" readonly required class="mt-2 w-full p-3 border border-gray-300 rounded-lg bg-gray-100" />
            </label>
            <label class="block text-sm font-medium text-gray-700">Year of study
              <input id="student-profile-year" type="text" readonly required class="mt-2 w-full p-3 border border-gray-300 rounded-lg bg-gray-100" />
            </label>
            <label class="block text-sm font-medium text-gray-700">Course
              <input id="student-profile-course" type="text" readonly required class="mt-2 w-full p-3 border border-gray-300 rounded-lg bg-gray-100" />
            </label>
            <label class="block text-sm font-medium text-gray-700">Branch
              <input id="student-profile-branch" type="text" readonly required class="mt-2 w-full p-3 border border-gray-300 rounded-lg bg-gray-100" />
            </label>
            <label class="block text-sm font-medium text-gray-700">School
              <select id="student-school" required class="mt-2 w-full p-3 border border-gray-300 rounded-lg"></select>
            </label>
            <label class="block text-sm font-medium text-gray-700">Hostel
              <select id="student-hostel" required class="mt-2 w-full p-3 border border-gray-300 rounded-lg"></select>
            </label>
            <label class="block text-sm font-medium text-gray-700">Room number
              <input id="student-room-number" type="text" maxlength="50" required class="mt-2 w-full p-3 border border-gray-300 rounded-lg" />
            </label>
            <label class="block text-sm font-medium text-gray-700">Leave from date
              <input id="student-leave-start" type="date" required class="mt-2 w-full p-3 border border-gray-300 rounded-lg" />
            </label>
            <label class="block text-sm font-medium text-gray-700">Leave from time
              <input id="student-leave-start-time" type="time" required class="mt-2 w-full p-3 border border-gray-300 rounded-lg" />
            </label>
            <label class="block text-sm font-medium text-gray-700">Return date
              <input id="student-leave-end" type="date" required class="mt-2 w-full p-3 border border-gray-300 rounded-lg" />
            </label>
            <label class="block text-sm font-medium text-gray-700">Return time
              <input id="student-leave-end-time" type="time" required class="mt-2 w-full p-3 border border-gray-300 rounded-lg" />
            </label>
            <p id="student-leave-days" class="md:col-span-2 text-sm text-gray-600" aria-live="polite">Select both dates to see the number of leave days.</p>
            <label class="block text-sm font-medium text-gray-700 md:col-span-2">Home address
              <textarea id="student-home-address" rows="2" maxlength="150" class="mt-2 w-full p-3 border border-gray-300 rounded-lg" placeholder="Enter your home address (up to 150 characters)"></textarea>
            </label>
            <label class="block text-sm font-medium text-gray-700 md:col-span-2">Reason for leave
              <textarea id="student-leave-reason" rows="3" maxlength="2000" required class="mt-2 w-full p-3 border border-gray-300 rounded-lg"></textarea>
            </label>
            <fieldset class="md:col-span-2 grid gap-4 rounded-lg border border-gray-200 p-4 md:grid-cols-3">
              <legend class="px-2 text-sm font-semibold text-gray-700">Emergency contact (optional)</legend>
              <label class="block text-sm font-medium text-gray-700">Contact name
                <input id="student-emergency-name" type="text" maxlength="150" class="mt-2 w-full rounded-lg border border-gray-300 p-3" />
              </label>
              <label class="block text-sm font-medium text-gray-700">Relationship
                <input id="student-emergency-relationship" type="text" maxlength="50" class="mt-2 w-full rounded-lg border border-gray-300 p-3" placeholder="e.g. Mother" />
              </label>
              <label class="block text-sm font-medium text-gray-700">Phone number
                <input id="student-emergency-phone" type="tel" inputmode="tel" maxlength="20" class="mt-2 w-full rounded-lg border border-gray-300 p-3" />
              </label>
            </fieldset>
            <label class="md:col-span-2 flex items-start gap-3 text-sm text-gray-700">
              <input id="student-details-confirmed" type="checkbox" required class="mt-1 h-5 w-5" />
              <span>I confirm that the details above are correct.</span>
            </label>
            <div class="md:col-span-2 flex flex-wrap items-center gap-3">
              <button id="student-submit-leave" type="submit" disabled class="bg-green-600 disabled:opacity-50 text-white px-5 py-3 rounded-lg font-semibold">Submit request</button>
              <span id="student-form-message" class="text-sm" aria-live="polite"></span>
            </div>
          </form>
        </section>
        <section class="bg-white rounded-xl p-5 border border-gray-200 shadow-sm mb-6">
          <h2 class="text-2xl font-semibold text-gray-700 mb-3">Your Leave History</h2>
          <div id="student-history-table"></div>
        </section>
        <section class="mb-6 rounded-xl border border-blue-200 bg-white p-5 shadow-sm">
          <h2 class="mb-3 text-xl font-semibold text-gray-800">Hostel Announcements</h2>
          <div id="student-announcements" aria-live="polite">Loading announcements...</div>
        </section>
        <section class="mb-6 rounded-xl border border-indigo-200 bg-white p-5 shadow-sm">
          <h2 class="mb-3 text-xl font-semibold text-gray-800">Leave Calendar</h2>
          <div id="student-leave-calendar" aria-live="polite">Loading calendar...</div>
        </section>
        <section class="bg-green-50 border border-green-200 rounded-xl p-5 shadow-sm">
          <h2 class="text-xl font-semibold text-green-800 mb-2">Gate Pass</h2>
          <div id="student-gate-pass"><p class="text-gray-500">No active gate pass available.</p></div>
        </section>
      </div>
    `;
    document.getElementById('student-change-password-button').addEventListener('click', openChangePasswordModal);

    const sapInput = document.getElementById('student-sap-id');
    const schoolSelect = document.getElementById('student-school');
    const hostelSelect = document.getElementById('student-hostel');
    const startInput = document.getElementById('student-leave-start');
    const endInput = document.getElementById('student-leave-end');
    const startTimeInput = document.getElementById('student-leave-start-time');
    const endTimeInput = document.getElementById('student-leave-end-time');
    const studentContactInput = document.getElementById('student-profile-contact');
    const submitButton = document.getElementById('student-submit-leave');
    const profileDisplayName = document.getElementById('student-profile-display-name');
    const profileIcon = document.getElementById('student-profile-icon');
    profileDisplayName.textContent = state.user.name || 'Student';
    const now = new Date();
    const localToday = new Date(now.getTime() - now.getTimezoneOffset() * 60000);
    const today = localToday.toISOString().slice(0, 10);
    sapInput.value = state.user.login_id;
    startInput.min = today;
    endInput.min = today;

    let expectedSchool = '';
    try {
      const response = await fetch(`/api/index.php?endpoint=student_lookup.php&sap_id=${encodeURIComponent(state.user.login_id)}`);
      const result = await response.json();
      if (!result.success) throw new Error(result.message || 'Student profile unavailable.');
      const profile = result.student;
      state.user.name = profile.name || state.user.name;
      profileDisplayName.textContent = state.user.name || 'Student';
      profileIcon.textContent = profile.gender === 'female' ? '👧' : '👦';
      profileIcon.setAttribute('aria-label', profile.gender === 'female' ? 'Girl student' : 'Boy student');
      const fields = {
        'student-profile-name': profile.name,
        'student-profile-parent-email': profile.parent_email,
        'student-profile-parent-contact': profile.parent_contact,
        'student-profile-year': profile.year,
        'student-profile-course': profile.course,
        'student-profile-branch': profile.branch
      };
      Object.entries(fields).forEach(([id, value]) => {
        document.getElementById(id).value = value || '';
      });
      studentContactInput.value = String(profile.student_contact || '').replace(/\D/g, '').replace(/^91(?=\d{10}$)/, '');

      expectedSchool = ['BTech', 'MBATech'].includes(profile.course) ? 'MPSTME' : 'SPTM';
      schoolSelect.innerHTML = `
        <option value="MPSTME" ${expectedSchool === 'MPSTME' ? 'selected' : ''} ${expectedSchool !== 'MPSTME' ? 'disabled' : ''}>MPSTME</option>
        <option value="SPTM" ${expectedSchool === 'SPTM' ? 'selected' : ''} ${expectedSchool !== 'SPTM' ? 'disabled' : ''}>SPTM</option>
      `;
      const hostels = profile.gender === 'female'
        ? ['NEW GIRLS HOSTEL', 'OLD GIRLS HOSTEL']
        : ['BOYS HOSTEL 1', 'NEW BOYS HOSTEL'];
      hostelSelect.innerHTML = '<option value="">Select hostel</option>' + hostels.map(hostel => `<option value="${hostel}">${hostel}</option>`).join('');
      const requiredProfileFields = Object.entries(fields);
      const profileComplete = requiredProfileFields.every(([, value]) => Boolean(value)) && Boolean(profile.gender && expectedSchool);
      submitButton.disabled = !profileComplete;
      if (!profileComplete) {
        const message = document.getElementById('student-form-message');
        message.textContent = 'Your profile is incomplete. Contact the administrator to update your student record.';
        message.className = 'text-sm text-red-600';
      }
    } catch (error) {
      const message = document.getElementById('student-form-message');
      message.textContent = error.message || 'Unable to load your student profile.';
      message.className = 'text-sm text-red-600';
    }

    const updateLeaveDays = () => {
      const output = document.getElementById('student-leave-days');
      endInput.min = startInput.value || today;
      if (!startInput.value || !endInput.value) {
        output.textContent = 'Select both dates to see the number of leave days.';
        output.className = 'md:col-span-2 text-sm text-gray-600';
      } else if (
        endInput.value < startInput.value
        || (endInput.value === startInput.value && startTimeInput.value && endTimeInput.value && endTimeInput.value <= startTimeInput.value)
      ) {
        output.textContent = 'Return date and time must be after the leave start date and time.';
        output.className = 'md:col-span-2 text-sm text-red-600';
      } else {
        const start = new Date(`${startInput.value}T00:00:00Z`);
        const end = new Date(`${endInput.value}T00:00:00Z`);
        const days = Math.round((end - start) / 86400000) + 1;
        output.textContent = `${days} leave day${days === 1 ? '' : 's'} (counting both dates).`;
        output.className = 'md:col-span-2 text-sm text-gray-600';
      }
    };
    [startInput, endInput, startTimeInput, endTimeInput].forEach(input => input.addEventListener('change', updateLeaveDays));
    studentContactInput.addEventListener('input', () => {
      studentContactInput.value = studentContactInput.value.replace(/\D/g, '').slice(0, 10);
    });

    document.getElementById('student-leave-form').addEventListener('submit', async event => {
      event.preventDefault();
      const message = document.getElementById('student-form-message');
      const form = document.getElementById('student-leave-form');
      const requiredEntries = Array.from(form.querySelectorAll('[required]'));
      const invalidField = requiredEntries.find(field => {
        if (field.type === 'checkbox') return !field.checked;
        return !String(field.value || '').trim();
      });
      if (invalidField) {
        invalidField.focus();
        invalidField.reportValidity();
        message.textContent = 'Please fill in all required leave request fields.';
        message.className = 'text-sm text-red-600';
        return;
      }
      if (studentContactInput.value.length !== 10 || !/^\d{10}$/.test(studentContactInput.value)) {
        studentContactInput.focus();
        message.textContent = 'Enter exactly 10 digits for your mobile number.';
        message.className = 'text-sm text-red-600';
        return;
      }
      if (endInput.value < startInput.value || (endInput.value === startInput.value && endTimeInput.value <= startTimeInput.value)) {
        message.textContent = 'Return date and time must be after the leave start date and time.';
        message.className = 'text-sm text-red-600';
        return;
      }
      submitButton.disabled = true;
      const requestData = getCsrfFormData({
        sap_id: sapInput.value,
        student_contact: studentContactInput.value,
        start_date: startInput.value,
        start_time: startTimeInput.value,
        end_date: endInput.value,
        end_time: endTimeInput.value,
        home_address: document.getElementById('student-home-address').value.trim(),
        reason: document.getElementById('student-leave-reason').value.trim(),
        emergency_contact_name: document.getElementById('student-emergency-name').value.trim(),
        emergency_contact_relationship: document.getElementById('student-emergency-relationship').value.trim(),
        emergency_contact_phone: document.getElementById('student-emergency-phone').value.trim(),
        school: schoolSelect.value,
        hostel: hostelSelect.value,
        room_no: document.getElementById('student-room-number').value.trim(),
        details_confirmed: document.getElementById('student-details-confirmed').checked ? '1' : '0'
      });
      try {
        const response = await fetch('/api/index.php?endpoint=submit_student_leave.php', { method: 'POST', body: requestData });
        const result = await response.json();
        message.textContent = result.message || (result.success ? 'Request submitted.' : 'Unable to submit request.');
        message.className = `text-sm ${result.success ? 'text-green-700' : 'text-red-600'}`;
        if (result.success) {
          startInput.value = '';
          endInput.value = '';
          startTimeInput.value = '';
          endTimeInput.value = '';
          document.getElementById('student-home-address').value = '';
          document.getElementById('student-leave-reason').value = '';
          document.getElementById('student-room-number').value = '';
          document.getElementById('student-emergency-name').value = '';
          document.getElementById('student-emergency-relationship').value = '';
          document.getElementById('student-emergency-phone').value = '';
          document.getElementById('student-details-confirmed').checked = false;
          updateLeaveDays();
          await loadStudentHistory();
        }
      } catch (error) {
        message.textContent = 'Request failed. Please try again.';
        message.className = 'text-sm text-red-600';
      } finally {
        submitButton.disabled = false;
      }
    });
    await loadStudentHistory();
    await setupNotifications('student');
    void setupAnnouncements('student');
    void loadLeaveCalendar('student');
  }

  async function handleChangePassword(event) {
    event.preventDefault();
    const oldPassword = document.getElementById('old-password')?.value || '';
    const newPassword = document.getElementById('new-password')?.value || '';
    const confirmPassword = document.getElementById('confirm-password')?.value || '';
    const message = document.getElementById('change-password-message');

    if (!oldPassword || !newPassword || !confirmPassword) {
      showMessage(message, 'All password fields are required.', 'error');
      return;
    }

    if (newPassword.length < 8) {
      showMessage(message, 'New password must be at least 8 characters long.', 'error');
      return;
    }

    if (newPassword !== confirmPassword) {
      showMessage(message, 'New password and confirmation do not match.', 'error');
      return;
    }

    try {
      const response = await fetch('/api/index.php?endpoint=change_password.php', {
        method: 'POST',
        body: getCsrfFormData({
          current_password: oldPassword,
          new_password: newPassword,
          confirm_password: confirmPassword
        })
      });
      const responseText = await response.text();
      let result;
      try {
        result = JSON.parse(responseText);
      } catch {
        throw new Error(`Password service returned an invalid response (HTTP ${response.status}).`);
      }
      if (!response.ok || !result.success) {
        showMessage(message, result.message || `Password update failed (HTTP ${response.status}).`, 'error');
        return;
      }

      const wasRequired = Boolean(state.user?.must_change_password);
      if (state.user) state.user.must_change_password = false;
      if (wasRequired) {
        closeChangePasswordModal();
        await showAuthenticatedPortal();
      } else {
        showMessage(message, 'Password updated successfully.', 'success');
        setTimeout(() => closeChangePasswordModal(), 1200);
      }
    } catch (error) {
      console.error(error);
      showMessage(message, error.message || 'Password update failed. Please try again.', 'error');
    }
  }

  async function loadStudentHistory() {
    const target = document.getElementById('student-history-table');
    if (!target) return;
    try {
      const response = await fetch('/api/index.php?endpoint=my_leaves.php');
      const result = await response.json();
      const items = result.items || [];
      if (!items.length) {
        target.innerHTML = '<p class="text-gray-500">No leave records yet.</p>';
        return;
      }
      target.innerHTML = `
        <div class="overflow-x-auto">
          <table class="min-w-full bg-white border border-gray-300 rounded-lg">
            <thead class="bg-gray-200 text-gray-600 uppercase text-sm"><tr>
              <th class="py-3 px-4 text-left">From</th><th class="py-3 px-4 text-left">To</th><th class="py-3 px-4 text-left">Room</th><th class="py-3 px-4 text-left">Address</th>
              <th class="py-3 px-4 text-left">Days</th><th class="py-3 px-4 text-left">School / Hostel</th>
              <th class="py-3 px-4 text-left">Reason</th><th class="py-3 px-4 text-left">Status</th><th class="py-3 px-4 text-left">Pass / Actions</th>
            </tr></thead>
            <tbody>${items.map(item => `
              <tr id="leave-${item.id}" class="border-b border-gray-200 hover:bg-gray-100">
                <td class="py-3 px-4">${item.start_date} ${item.start_time || ''}</td><td class="py-3 px-4">${item.end_date} ${item.end_time || ''}</td><td class="py-3 px-4">${item.room_no || '-'}</td><td class="py-3 px-4">${escapeHtml(item.home_address || '-')}</td>
                <td class="py-3 px-4">${item.leave_days}</td><td class="py-3 px-4">${item.school || '-'} / ${item.hostel || '-'}</td>
                <td class="py-3 px-4">${escapeHtml(item.reason)}</td><td class="py-3 px-4">${escapeHtml(item.status)}</td>
                <td class="py-3 px-4"><div class="flex flex-wrap gap-2">${['Granted', 'CHECKED_OUT'].includes(item.status) && item.qr_code_data ? `<button type="button" class="bg-blue-600 text-white px-3 py-1 rounded" data-qrcode="${item.id}">View</button>` : ''}${['Pending Verification', 'Granted'].includes(item.status) ? `<button type="button" class="rounded bg-red-600 px-3 py-1 text-white" data-cancel-leave="${item.id}">Cancel</button>` : '-'}</div></td>
              </tr>`).join('')}</tbody>
          </table>
        </div>
      `;
      target.querySelectorAll('[data-qrcode]').forEach(button => {
        button.addEventListener('click', () => {
          const pass = items.find(item => String(item.id) === button.dataset.qrcode);
          if (pass) renderGatePass(pass);
        });
      });
      target.querySelectorAll('[data-cancel-leave]').forEach(button => {
        button.addEventListener('click', async () => {
          if (!window.confirm('Cancel this leave request? Any approved gate pass will be invalidated.')) return;
          button.disabled = true;
          try {
            const response = await fetch('/api/index.php?endpoint=cancel_leave.php', {
              method: 'POST',
              body: getCsrfFormData({ leave_id: button.dataset.cancelLeave })
            });
            const cancellation = await response.json();
            if (!response.ok || !cancellation.success) throw new Error(cancellation.message || 'Unable to cancel leave.');
            await loadStudentHistory();
          } catch (error) {
            console.error(error);
            window.alert(error.message || 'Unable to cancel leave.');
            button.disabled = false;
          }
        });
      });
      renderGatePass(items.find(item => ['Granted', 'CHECKED_OUT'].includes(item.status) && item.qr_code_data));
    } catch (error) {
      console.error(error);
      target.innerHTML = '<p class="text-red-600">Unable to load history.</p>';
    }
  }

  function renderGatePass(pass) {
    const target = document.getElementById('student-gate-pass');
    if (!target) return;
    if (!pass || !pass.qr_code_data) {
      target.innerHTML = '<p class="text-gray-500">No active gate pass available.</p>';
      return;
    }
    const returnInstructions = pass.status === 'CHECKED_OUT'
      ? '<p class="mb-3 rounded-lg bg-amber-100 p-3 font-semibold text-amber-900">You are currently checked out. Show this same QR code to security when you return so they can record your gate entry.</p>'
      : '<p class="mb-3 rounded-lg bg-green-100 p-3 font-semibold text-green-900">Show this QR code to security when leaving campus.</p>';
    target.innerHTML = `
      ${returnInstructions}
      <p class="text-green-800 font-semibold">Pass token: ${pass.pass_token || 'Not available'}</p>
      <p class="text-sm text-gray-700 mb-4">Valid ${pass.start_date} ${pass.start_time || ''} to ${pass.end_date} ${pass.end_time || ''}</p>
      <div class="gate-pass-qr-card flex justify-center mb-4"><div id="gate-pass-qr" role="img" aria-label="Gate pass QR code"></div></div>
      <div class="flex flex-wrap gap-3">
        <button type="button" id="download-gate-pass-qr" class="bg-blue-600 text-white px-4 py-2 rounded">Download QR</button>
        <button type="button" id="download-student-pdf" class="bg-purple-600 text-white px-4 py-2 rounded">Download PDF</button>
      </div>
      <p id="gate-pass-download-message" class="mt-3 text-sm" aria-live="polite"></p>
    `;
    const qrContainer = document.getElementById('gate-pass-qr');
    qrContainer.textContent = 'Loading QR code...';
    const qrPayload = pass.pass_token ? `HLP1:${pass.pass_token}` : pass.qr_code_data;
    loadOptionalScript(
      'https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js',
      () => typeof window.QRCode === 'function'
    ).then(() => {
      if (qrContainer.isConnected) {
        qrContainer.replaceChildren();
        new window.QRCode(qrContainer, { text: qrPayload, width: 256, height: 256, correctLevel: window.QRCode.CorrectLevel.H });
      }
    }).catch(error => {
      console.error(error);
      if (qrContainer.isConnected) {
        qrContainer.textContent = 'QR generator unavailable. Use the pass token at the gate.';
      }
    });

    function createQuietZoneQr(sourceCanvas) {
      const quietZone = 40;
      const output = document.createElement('canvas');
      output.width = sourceCanvas.width + quietZone * 2;
      output.height = sourceCanvas.height + quietZone * 2;
      const context = output.getContext('2d');
      if (!context) throw new Error('Unable to prepare the QR image.');
      context.fillStyle = '#ffffff';
      context.fillRect(0, 0, output.width, output.height);
      context.imageSmoothingEnabled = false;
      context.drawImage(sourceCanvas, quietZone, quietZone);
      return output;
    }

    document.getElementById('download-gate-pass-qr').addEventListener('click', () => {
      const canvas = qrContainer.querySelector('canvas');
      const message = document.getElementById('gate-pass-download-message');
      try {
        if (!canvas) throw new Error('The QR code is not ready yet. Refresh and try again.');
        const link = document.createElement('a');
        link.href = createQuietZoneQr(canvas).toDataURL('image/png');
        link.download = `gate-pass-${pass.sap_id}.png`;
        link.click();
        message.textContent = 'QR code downloaded.';
        message.className = 'mt-3 text-sm text-green-700';
      } catch (error) {
        console.error(error);
        message.textContent = error.message || 'QR download failed.';
        message.className = 'mt-3 text-sm text-red-600';
      }
    });
    document.getElementById('download-student-pdf').addEventListener('click', async () => {
      const message = document.getElementById('gate-pass-download-message');
      try {
        await loadOptionalScript(
          'https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js',
          () => Boolean(window.jspdf?.jsPDF)
        );
        if (!window.jspdf?.jsPDF) throw new Error('PDF support did not load. Refresh the page and try again.');
        const pdf = new window.jspdf.jsPDF({ unit: 'mm', format: 'a4' });
        const pageWidth = pdf.internal.pageSize.getWidth();
        const margin = 14;
        const qrCanvas = qrContainer.querySelector('canvas');
        const studentName = document.getElementById('student-profile-name')?.value || state.user?.name || 'Student';
        const parentEmail = document.getElementById('student-profile-parent-email')?.value || 'Not provided';
        const parentContact = document.getElementById('student-profile-parent-contact')?.value || 'Not provided';

        pdf.setFillColor(248, 250, 252);
        pdf.rect(10, 10, pageWidth - 20, 277, 'F');
        pdf.setDrawColor(30, 64, 175);
        pdf.roundedRect(10, 10, pageWidth - 20, 277, 4, 4, 'S');
        pdf.setTextColor(17, 24, 39);
        pdf.setFont('helvetica', 'bold');
        pdf.setFontSize(20);
        pdf.text('NMIMS Leave - Gate Pass', margin, 25);
        pdf.setFont('helvetica', 'normal');
        pdf.setFontSize(10);
        pdf.text('Approved leave pass. Present this pass to hostel security.', margin, 32);
        pdf.setDrawColor(148, 163, 184);
        pdf.line(margin, 38, pageWidth - margin, 38);

        pdf.setTextColor(31, 41, 55);
        pdf.setFont('helvetica', 'bold');
        pdf.setFontSize(12);
        pdf.text(`Student: ${studentName}`, margin, 52);
        pdf.text(`SAP ID: ${pass.sap_id}`, margin, 62);
        pdf.text(`School: ${pass.school || 'N/A'}`, margin, 72);
        pdf.text(`Hostel: ${pass.hostel || 'N/A'}`, margin, 82);
        pdf.text(`Leave: ${pass.start_date} to ${pass.end_date}`, margin, 96);
        pdf.text(`Duration: ${pass.leave_days || 1} day(s)`, margin, 106);
        pdf.text(`Pass token: ${pass.pass_token || 'N/A'}`, margin, 116);
        pdf.setFont('helvetica', 'normal');
        pdf.text('Reason:', margin, 130);
        const reasonLines = pdf.splitTextToSize(pass.reason || 'Not specified', 112).slice(0, 5);
        pdf.text(reasonLines, margin, 137);

        if (qrCanvas) {
          pdf.addImage(createQuietZoneQr(qrCanvas).toDataURL('image/png'), 'PNG', pageWidth - margin - 48, 48, 48, 48);
          pdf.setFontSize(9);
          pdf.text('Scan at security', pageWidth - margin - 24, 101, { align: 'center' });
        }

        pdf.setFont('helvetica', 'bold');
        pdf.setFontSize(11);
        pdf.text('Parent contact', margin, 184);
        pdf.setFont('helvetica', 'normal');
        pdf.setFontSize(10);
        pdf.text(`Email: ${parentEmail}`, margin, 192);
        pdf.text(`Phone: ${parentContact}`, margin, 200);
        pdf.setDrawColor(148, 163, 184);
        pdf.line(margin, 270, pageWidth - margin, 270);
        pdf.setFontSize(9);
        pdf.text(`Generated: ${new Date().toLocaleString()}`, margin, 278);
        pdf.save(`gate-pass-${pass.sap_id}.pdf`);
        message.textContent = 'Gate pass PDF downloaded.';
        message.className = 'mt-3 text-sm text-green-700';
      } catch (error) {
        console.error(error);
        message.textContent = error.message || 'PDF download failed.';
        message.className = 'mt-3 text-sm text-red-600';
      }
    });
  }

  function showWardenPage() {
    hideAllPages();
    appContainer.classList.add('portal-wide');
    wardenPage.classList.remove('hidden');
    const hostelGroup = state.user?.warden_hostel || 'Assigned Hostel';
    wardenPendingPage = 1;
    wardenFollowupPage = 1;
    wardenApprovedPage = 1;
    wardenPage.innerHTML = `
      <div class="max-w-5xl mx-auto">
        <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
          <div><h1 class="text-3xl font-bold text-gray-800">${hostelGroup} Warden</h1><p class="text-sm text-gray-600 mt-1">Signed in as ${state.user?.name || 'Warden'}</p></div>
          <div class="flex items-center gap-3">${notificationBellMarkup('warden')}<button type="button" data-logout-button class="bg-red-600 text-white px-4 py-2 rounded-lg font-semibold">Logout</button></div>
        </div>
        <section class="bg-white rounded-xl p-5 border border-gray-200 shadow-sm">
          <h2 class="text-2xl font-semibold text-gray-700 mb-4">Pending Verification</h2>
          <div id="warden-pending-list" class="space-y-4"></div>
        </section>
        <section class="mt-4">
          <button id="warden-followup-toggle" type="button" aria-expanded="false" class="rounded-lg bg-amber-600 px-4 py-2 font-semibold text-white hover:bg-amber-700">Show Not Reached/Wrong person Leave</button>
          <div id="warden-followup-section" class="hidden mt-4 rounded-xl border border-amber-200 bg-white p-5 shadow-sm">
            <h2 class="mb-4 text-2xl font-semibold text-gray-700">Not Reached / Wrong Person Follow-up</h2>
            <div id="warden-followup-list" class="overflow-x-auto" aria-live="polite">Open this section to load follow-up requests.</div>
          </div>
        </section>
        <section class="mt-6 rounded-xl border border-blue-200 bg-white p-5 shadow-sm">
          <h2 class="mb-3 text-xl font-semibold text-gray-800">Hostel Announcements</h2>
          <div id="warden-announcements" aria-live="polite">Loading announcements...</div>
        </section>
        <section class="mt-6 rounded-xl border border-indigo-200 bg-white p-5 shadow-sm">
          <h2 class="mb-3 text-xl font-semibold text-gray-800">Hostel Leave Calendar</h2>
          <div id="warden-leave-calendar" aria-live="polite">Loading calendar...</div>
        </section>
        <section class="bg-white rounded-xl p-5 border border-gray-200 shadow-sm mt-6">
          <h2 class="text-2xl font-semibold text-gray-700 mb-4">Daily Gate Attendance</h2>
          <div class="gate-attendance-controls mb-4">
            <label for="attendanceDate" class="text-sm font-semibold text-gray-700">Attendance date</label>
            <input id="attendanceDate" type="date" class="rounded-lg border border-gray-300 px-3 py-2" />
            <label for="gateAttendanceFilter" class="text-sm font-semibold text-gray-700">Show</label>
            <select id="gateAttendanceFilter" class="rounded-lg border border-gray-300 px-3 py-2">
              <option value="all">All gate activity</option>
              <option value="outside">Currently outside</option>
              <option value="exited_today">Exited on date</option>
              <option value="entered_today">Entered on date</option>
              <option value="defaulters">Overdue defaulters</option>
            </select>
            <button id="download-gate-departures" type="button" class="gate-export-button gate-export-departures">📥 Download Gate Departures (Left)</button>
            <button id="download-gate-arrivals" type="button" class="gate-export-button gate-export-arrivals">📥 Download Gate Arrivals (Entered)</button>
          </div>
          <div id="warden-gate-attendance" class="overflow-x-auto" aria-live="polite">Loading gate attendance...</div>
        </section>
        <section class="bg-white rounded-xl p-5 border border-gray-200 shadow-sm mt-6">
          <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
            <h2 class="text-2xl font-semibold text-gray-700">Approved Leave History</h2>
            <button id="warden-download-approved-csv" type="button" disabled class="bg-green-700 text-white px-4 py-2 rounded-lg font-semibold disabled:opacity-50">Download CSV</button>
          </div>
          <div id="warden-approved-history" class="overflow-x-auto" aria-live="polite"></div>
        </section>
      </div>
    `;
    const attendanceDate = document.getElementById('attendanceDate');
    const today = new Date();
    attendanceDate.value = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;
    refreshPendingRequests();
    document.getElementById('warden-followup-toggle').addEventListener('click', toggleWardenFollowups);
    loadWardenApprovedHistory();
    loadWardenGateAttendance();
    attendanceDate.addEventListener('change', loadWardenGateAttendance);
    document.getElementById('gateAttendanceFilter').addEventListener('change', loadWardenGateAttendance);
    document.getElementById('download-gate-departures').addEventListener('click', () => downloadGateReport('left'));
    document.getElementById('download-gate-arrivals').addEventListener('click', () => downloadGateReport('entered'));
    if (wardenAttendanceTimer !== null) clearInterval(wardenAttendanceTimer);
    wardenAttendanceTimer = setInterval(() => {
      if (!document.hidden) void loadWardenGateAttendance();
    }, 60000);
    document.getElementById('warden-download-approved-csv').addEventListener('click', downloadWardenApprovedCsv);
    void setupNotifications('warden');
    void setupAnnouncements('warden');
    void loadLeaveCalendar('warden');
  }

  async function loadWardenGateAttendance() {
    const target = document.getElementById('warden-gate-attendance');
    const dateInput = document.getElementById('attendanceDate');
    const filterInput = document.getElementById('gateAttendanceFilter');
    if (!target || !dateInput || !filterInput || !dateInput.value || loadingWardenAttendance) return;
    target.className = 'overflow-x-auto';
    loadingWardenAttendance = true;

    try {
      const params = new URLSearchParams({ date: dateInput.value, filter: filterInput.value });
      const response = await fetch(`/api/index.php?endpoint=get_warden_gate_logs.php&${params.toString()}`);
      const result = await response.json();
      if (!response.ok || !result.success) throw new Error(result.message || 'Unable to load gate attendance.');
      const items = result.items || [];
      if (!items.length) {
        target.textContent = 'No gate attendance records found for this date and filter.';
        return;
      }

      const table = document.createElement('table');
      table.className = 'min-w-full border border-gray-300 text-sm';
      const headings = ['SAP ID', 'Student', 'Room', 'Hostel Block', 'Destination', 'Parent Phone', 'Gate OUT', 'Gate IN', 'Return Status'];
      const thead = document.createElement('thead');
      thead.className = 'bg-gray-100 text-gray-700';
      const headingRow = document.createElement('tr');
      headings.forEach(label => {
        const cell = document.createElement('th');
        cell.className = 'whitespace-nowrap px-3 py-2 text-left';
        cell.textContent = label;
        headingRow.appendChild(cell);
      });
      thead.appendChild(headingRow);
      table.appendChild(thead);

      const tbody = document.createElement('tbody');
      items.forEach(item => {
        const row = document.createElement('tr');
        row.className = 'border-t border-gray-200 align-top';
        [
          item.sap_id,
          item.student_name,
          item.room_no,
          item.hostel_block,
          item.destination,
          item.parent_phone,
          item.gate_out_time,
          item.gate_in_time
        ].forEach(value => {
          const cell = document.createElement('td');
          cell.className = 'whitespace-nowrap px-3 py-2';
          cell.textContent = value || '-';
          row.appendChild(cell);
        });

        const statusCell = document.createElement('td');
        statusCell.className = 'px-3 py-2 align-top';
        const status = item.live_status || item.return_timing_status || 'PENDING_EXIT';
        const badge = document.createElement('span');
        badge.className = `gate-status-badge ${gateAttendanceBadgeClass(status)}`;
        badge.textContent = [status, item.variance_summary].filter(Boolean).join(' · ');
        statusCell.appendChild(badge);
        row.appendChild(statusCell);
        tbody.appendChild(row);
      });
      table.appendChild(tbody);
      target.replaceChildren(table);
    } catch (error) {
      console.error(error);
      target.textContent = error.message || 'Unable to load gate attendance.';
      target.className = 'overflow-x-auto text-sm text-red-600';
    } finally {
      loadingWardenAttendance = false;
    }
  }

  function gateAttendanceBadgeClass(status) {
    if (status === 'LATE_RETURN' || status === 'OVERDUE') return 'gate-status-late';
    if (status === 'EARLY_RETURN') return 'gate-status-early';
    if (status === 'ON_TIME') return 'gate-status-on-time';
    if (status === 'CURRENTLY OUTSIDE' || status === 'OUTSIDE') return 'gate-status-outside';
    return 'gate-status-pending';
  }

  function downloadGateReport(type) {
    const date = document.getElementById('attendanceDate')?.value;
    if (!date || !['left', 'entered'].includes(type)) return;
    const params = new URLSearchParams({ date, type });
    window.location.assign(`/api/index.php?endpoint=export_gate_attendance.php&${params.toString()}`);
  }

  async function refreshPendingRequests() {
    const target = document.getElementById('warden-pending-list');
    if (!target) return;
    target.className = 'space-y-4';
    try {
      const params = new URLSearchParams({
        status: 'Pending Verification',
        limit: '20',
        page: String(wardenPendingPage)
      });
      const response = await fetch(`/api/index.php?endpoint=list_leaves.php&${params.toString()}`);
      const result = await response.json();
      if (!response.ok || !result.success) throw new Error(result.message || 'Unable to load pending requests.');
      const items = result.items || [];
      wardenPendingTotal = Number(result.total) || 0;
      if (!items.length) {
        if (wardenPendingPage > 1) {
          wardenPendingPage -= 1;
          await refreshPendingRequests();
          return;
        }
        target.textContent = 'No pending verification requests.';
        return;
      }
      const content = document.createDocumentFragment();
      items.forEach(item => {
        const article = document.createElement('article');
        article.id = `leave-${item.id}`;
        article.className = 'border border-gray-200 rounded-lg p-4 bg-gray-50';
        const fields = [
          [`${item.name || ''} (${item.sap_id || ''})`, 'font-semibold'],
          [`${item.course || ''} | ${item.year || ''} | ${item.gender || ''}`, ''],
          [`Room: ${item.room_no || '-'}`, ''],
          [`Dates: ${item.start_date} ${item.start_time || ''} to ${item.end_date} ${item.end_time || ''} (${item.leave_days} days)`, ''],
          [`School / Hostel: ${item.school || '-'} / ${item.hostel || '-'}`, ''],
          [`Student mobile: ${item.student_contact || 'Not provided'}`, ''],
          [`Home address: ${item.home_address || 'Not provided'}`, ''],
          [`Emergency contact: ${item.emergency_contact_name || 'Not provided'}${item.emergency_contact_relationship ? ` (${item.emergency_contact_relationship})` : ''} | ${item.emergency_contact_phone || 'Not provided'}`, ''],
          [`Reason: ${item.reason || '-'}`, ''],
          [`Call parent: ${item.parent_contact || 'Contact unavailable'} | ${item.parent_email || ''}`, '']
        ];
        fields.forEach(([value, className]) => {
          const paragraph = document.createElement('p');
          paragraph.className = className;
          paragraph.textContent = value;
          article.appendChild(paragraph);
        });
        const actions = document.createElement('div');
        actions.className = 'mt-3 flex flex-wrap gap-2';
        [
          ['Confirmed', 'bg-green-600'],
          ['Denied', 'bg-red-600'],
          ['Not Reachable', 'bg-yellow-500'],
          ['Wrong Person', 'bg-gray-700']
        ].forEach(([outcome, color]) => {
          const button = document.createElement('button');
          button.type = 'button';
          button.className = `${color} rounded px-3 py-2 text-white`;
          button.textContent = outcome;
          button.dataset.leaveId = item.id;
          button.dataset.outcome = outcome;
          button.dataset.calledNumber = item.parent_contact || '';
          button.addEventListener('click', () => {
            const selectedOutcome = button.dataset.outcome;
            const leaveId = button.dataset.leaveId;
            if (selectedOutcome === 'Not Reachable' || selectedOutcome === 'Wrong Person') {
              showCallRemarksForm(article, {
                leaveId,
                outcome: selectedOutcome,
                calledNumber: button.dataset.calledNumber || '',
                sourceRow: article
              });
              return;
            }
            saveWardenCallOutcome({
              leaveId,
              outcome: selectedOutcome,
              calledNumber: button.dataset.calledNumber || '',
              remarks: '',
              sourceRow: article,
              submitButton: button
            });
          });
          actions.appendChild(button);
        });
        article.appendChild(actions);
        const remarks = document.createElement('div');
        remarks.className = 'mt-3';
        remarks.dataset.remarksContainer = item.id;
        article.appendChild(remarks);
        content.appendChild(article);
      });
      target.replaceChildren(content);
      const controls = createPaginationControls(wardenPendingPage, wardenPendingTotal, 20, page => {
        wardenPendingPage = page;
        void refreshPendingRequests();
      });
      if (controls) target.appendChild(controls);
    } catch (error) {
      console.error(error);
      target.textContent = error.message || 'Unable to load pending requests.';
      target.className = 'space-y-4 text-red-600';
    }
  }

  function showCallRemarksForm(article, { leaveId, outcome, calledNumber, sourceRow }) {
    const container = article?.querySelector(`[data-remarks-container="${leaveId}"]`);
    if (!container) return;
    const label = outcome === 'Not Reachable'
      ? 'Explain why the parent could not be reached'
      : 'Explain why the person who answered was not the parent';
    container.innerHTML = `
      <label class="block text-sm font-medium text-gray-700">
        ${label} <span class="text-red-600">*</span>
        <textarea data-call-remarks required maxlength="2000" rows="2" class="mt-2 w-full rounded-lg border border-gray-300 p-3" placeholder="Enter the reason for this call outcome"></textarea>
      </label>
      <button type="button" data-save-call-outcome class="mt-2 rounded-lg bg-blue-700 px-4 py-2 font-semibold text-white hover:bg-blue-800">Save ${outcome}</button>
    `;
    const remarksInput = container.querySelector('[data-call-remarks]');
    const validateRemarks = () => {
      remarksInput.setCustomValidity(remarksInput.value.trim() ? '' : 'Please enter a reason for this outcome.');
      return remarksInput.reportValidity();
    };
    remarksInput.addEventListener('input', () => remarksInput.setCustomValidity(''));
    container.querySelector('[data-save-call-outcome]').addEventListener('click', () => {
      if (!validateRemarks()) return;
      saveWardenCallOutcome({
        leaveId,
        outcome,
        calledNumber,
        remarks: remarksInput.value.trim(),
        sourceRow,
        submitButton: container.querySelector('[data-save-call-outcome]')
      });
    });
    remarksInput.focus();
  }

  async function saveWardenCallOutcome({ leaveId, outcome, calledNumber = '', remarks = '', sourceRow, submitButton }) {
    const rowButtons = sourceRow?.querySelectorAll('button') || [];
    rowButtons.forEach(button => { button.disabled = true; });
    const originalButtonText = submitButton?.textContent;
    if (submitButton) submitButton.textContent = 'Saving...';

    try {
      const response = await fetch('/api/index.php?endpoint=record_call.php', {
        method: 'POST',
        body: getCsrfFormData({ leave_id: leaveId, outcome, called_number: calledNumber, remarks })
      });
      const result = await response.json();
      if (!response.ok || !result.success) throw new Error(result.message || 'Call result update failed.');

      sourceRow?.remove();
      const refreshes = [refreshPendingRequests(), loadWardenApprovedHistory()];
      const followupSection = document.getElementById('warden-followup-section');
      if (followupSection && !followupSection.classList.contains('hidden')) {
        refreshes.push(loadWardenFollowups());
      }
      void Promise.allSettled(refreshes);
    } catch (error) {
      console.error(error);
      rowButtons.forEach(button => { button.disabled = false; });
      if (submitButton && originalButtonText) submitButton.textContent = originalButtonText;
      window.alert(error.message || 'Call result failed.');
    }
  }

  async function toggleWardenFollowups() {
    const section = document.getElementById('warden-followup-section');
    const button = document.getElementById('warden-followup-toggle');
    if (!section || !button) return;
    const show = section.classList.contains('hidden');
    section.classList.toggle('hidden', !show);
    button.setAttribute('aria-expanded', String(show));
    button.textContent = show
      ? 'Hide Not Reached/Wrong person Leave'
      : 'Show Not Reached/Wrong person Leave';
    if (show) await loadWardenFollowups();
  }

  async function loadWardenFollowups() {
    const target = document.getElementById('warden-followup-list');
    if (!target) return;
    target.className = 'overflow-x-auto';
    target.textContent = 'Loading follow-up requests...';

    try {
      const params = new URLSearchParams({ page: String(wardenFollowupPage), limit: '20' });
      const response = await fetch(`/api/index.php?endpoint=get_warden_followup_requests.php&${params.toString()}`);
      const result = await response.json();
      if (!response.ok || !result.success) throw new Error(result.message || 'Unable to load follow-up requests.');
      const items = result.items || [];
      wardenFollowupTotal = Number(result.total) || 0;
      if (!items.length) {
        if (wardenFollowupPage > 1) {
          wardenFollowupPage -= 1;
          await loadWardenFollowups();
          return;
        }
        target.textContent = 'No Not Reachable or Wrong Person requests need follow-up.';
        return;
      }

      const table = document.createElement('table');
      table.className = 'min-w-full border border-gray-300 text-sm';
      const headings = ['SAP ID', 'Student', 'Dates', 'School / Hostel', 'Reason for Leave', 'Latest Call Outcome', 'Call Remarks', 'Called By / At', 'Resolve'];
      const thead = document.createElement('thead');
      thead.className = 'bg-gray-100 text-gray-700';
      const headerRow = document.createElement('tr');
      headings.forEach(label => {
        const cell = document.createElement('th');
        cell.className = 'whitespace-nowrap px-3 py-2 text-left';
        cell.textContent = label;
        headerRow.appendChild(cell);
      });
      thead.appendChild(headerRow);
      table.appendChild(thead);

      const tbody = document.createElement('tbody');
      items.forEach(item => {
        const row = document.createElement('tr');
        row.className = 'border-t border-gray-200 align-top';
        [
          item.sap_id,
          item.name,
          `${item.start_date} to ${item.end_date} (${item.leave_days} days)`,
          `${item.school || '-'} / ${item.hostel || '-'}`,
          item.reason,
          item.followup_outcome,
          item.followup_remarks,
          `${item.called_by_name || '-'} / ${item.followup_called_at || '-'}`
        ].forEach(value => {
          const cell = document.createElement('td');
          cell.className = 'min-w-32 px-3 py-2';
          cell.textContent = value || '-';
          row.appendChild(cell);
        });

        const actionCell = document.createElement('td');
        actionCell.className = 'min-w-64 px-3 py-2';
        const remarks = document.createElement('textarea');
        remarks.required = true;
        remarks.maxLength = 2000;
        remarks.rows = 2;
        remarks.className = 'mb-2 w-full rounded-lg border border-gray-300 p-2';
        remarks.placeholder = 'Required reason for approving or rejecting';
        remarks.setAttribute('aria-label', `Resolution remarks for ${item.name}`);
        remarks.addEventListener('input', () => remarks.setCustomValidity(''));
        actionCell.appendChild(remarks);

        const actions = document.createElement('div');
        actions.className = 'flex flex-wrap gap-2';
        [
          ['Confirmed', 'Approve', 'bg-green-700 hover:bg-green-800'],
          ['Denied', 'Reject', 'bg-red-700 hover:bg-red-800']
        ].forEach(([outcome, label, color]) => {
          const button = document.createElement('button');
          button.type = 'button';
          button.className = `rounded px-3 py-2 font-semibold text-white ${color}`;
          button.textContent = label;
          button.addEventListener('click', () => {
            remarks.setCustomValidity(remarks.value.trim() ? '' : 'Please enter a reason for approving or rejecting.');
            if (!remarks.reportValidity()) return;
            saveWardenCallOutcome({
              leaveId: String(item.id),
              outcome,
              calledNumber: item.parent_contact || '',
              remarks: remarks.value.trim(),
              sourceRow: row,
              submitButton: button
            });
          });
          actions.appendChild(button);
        });
        actionCell.appendChild(actions);
        row.appendChild(actionCell);
        tbody.appendChild(row);
      });
      table.appendChild(tbody);
      const controls = createPaginationControls(wardenFollowupPage, wardenFollowupTotal, 20, page => {
        wardenFollowupPage = page;
        void loadWardenFollowups();
      });
      target.replaceChildren(table);
      if (controls) target.appendChild(controls);
    } catch (error) {
      console.error(error);
      target.textContent = error.message || 'Unable to load follow-up requests.';
      target.className = 'overflow-x-auto text-sm text-red-600';
    }
  }

  async function loadWardenApprovedHistory() {
    const target = document.getElementById('warden-approved-history');
    if (!target) return;
    target.className = 'overflow-x-auto';
    target.textContent = 'Loading approved leaves...';

    try {
      const params = new URLSearchParams({ status: 'Granted', limit: '100', page: String(wardenApprovedPage) });
      const response = await fetch(`/api/index.php?endpoint=list_leaves.php&${params.toString()}`);
      const result = await response.json();
      if (!response.ok || !result.success) throw new Error(result.message || 'Unable to load approved leave history.');
      const items = result.items || [];
      state.wardenApprovedTotal = Number(result.total) || 0;
      document.getElementById('warden-download-approved-csv').disabled = state.wardenApprovedTotal === 0;
      if (!items.length) {
        if (wardenApprovedPage > 1) {
          wardenApprovedPage -= 1;
          await loadWardenApprovedHistory();
          return;
        }
        target.textContent = 'No approved leaves found for this hostel group.';
        return;
      }

      const table = document.createElement('table');
      table.className = 'min-w-full border border-gray-300 text-sm';
      const columns = [
        ['SAP ID', 'sap_id'], ['Student', 'name'], ['Dates', 'dates'], ['Days', 'leave_days'],
        ['School / Hostel', 'location'], ['Emergency contact', 'emergency'], ['Reason', 'reason'], ['Approved on', 'granted_at']
      ];
      const head = document.createElement('thead');
      head.className = 'bg-gray-100 text-gray-700';
      const headerRow = document.createElement('tr');
      columns.forEach(([label]) => {
        const cell = document.createElement('th');
        cell.className = 'px-3 py-2 text-left whitespace-nowrap';
        cell.textContent = label;
        headerRow.appendChild(cell);
      });
      head.appendChild(headerRow);
      table.appendChild(head);

      const body = document.createElement('tbody');
      items.forEach(item => {
        const row = document.createElement('tr');
        row.className = 'border-t border-gray-200 align-top';
        const values = {
          sap_id: item.sap_id,
          name: item.name,
          dates: `${item.start_date} ${item.start_time || ''} to ${item.end_date} ${item.end_time || ''}`,
          leave_days: item.leave_days,
          location: `${item.school || '-'} / ${item.hostel || '-'}`,
          emergency: [item.emergency_contact_name, item.emergency_contact_relationship, item.emergency_contact_phone].filter(Boolean).join(' · ') || '-',
          reason: item.reason,
          granted_at: item.granted_at
        };
        columns.forEach(([, key]) => {
          const cell = document.createElement('td');
          cell.className = 'px-3 py-2';
          cell.textContent = values[key] ?? '-';
          row.appendChild(cell);
        });
        body.appendChild(row);
      });
      table.appendChild(body);
      target.replaceChildren(table);
      const controls = createPaginationControls(wardenApprovedPage, state.wardenApprovedTotal, 100, page => {
        wardenApprovedPage = page;
        void loadWardenApprovedHistory();
      });
      if (controls) target.appendChild(controls);
    } catch (error) {
      console.error(error);
      target.textContent = error.message || 'Unable to load approved leave history.';
      target.className = 'overflow-x-auto text-sm text-red-600';
    }
  }

  async function downloadWardenApprovedCsv() {
    const button = document.getElementById('warden-download-approved-csv');
    if (!button) return;
    button.disabled = true;
    const originalText = button.textContent;
    button.textContent = 'Preparing CSV...';
    try {
      const items = [];
      let exportTotal = state.wardenApprovedTotal;
      for (let page = 1; items.length < exportTotal; page++) {
        const params = new URLSearchParams({ status: 'Granted', limit: '100', page: String(page) });
        const response = await fetch(`/api/index.php?endpoint=list_leaves.php&${params.toString()}`);
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || 'Unable to prepare approved leave export.');
        exportTotal = Number(result.total) || 0;
        const pageItems = result.items || [];
        if (!pageItems.length) break;
        items.push(...pageItems);
      }
      if (items.length !== exportTotal) throw new Error('Approved leave records changed during export. Please try again.');
      const columns = [
        ['SAP ID', 'sap_id'], ['Student Name', 'name'], ['Gender', 'gender'], ['Course', 'course'],
        ['Year', 'year'], ['Branch', 'branch'], ['Batch', 'batch'], ['Start Date', 'start_date'],
        ['End Date', 'end_date'], ['Leave Days', 'leave_days'], ['School', 'school'],
        ['Hostel', 'hostel'], ['Reason', 'reason'], ['Approved At', 'granted_at']
      ];
      const csvCell = value => {
        let text = String(value ?? '');
        if (/^\s*[=+@-]/.test(text)) text = `'${text}`;
        return `"${text.replace(/"/g, '""')}"`;
      };
      const rows = [
        columns.map(([label]) => csvCell(label)).join(','),
        ...items.map(item => columns.map(([, key]) => csvCell(item[key])).join(','))
      ];
      const blob = new Blob([`\uFEFF${rows.join('\r\n')}`], { type: 'text/csv;charset=utf-8' });
      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = `approved-leaves-${state.user?.warden_gender || 'hostel'}-${new Date().toISOString().slice(0, 10)}.csv`;
      link.click();
      setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (error) {
      console.error(error);
      window.alert(error.message || 'Unable to prepare approved leave export.');
    } finally {
      button.disabled = state.wardenApprovedTotal === 0;
      button.textContent = originalText;
    }
  }

  async function setupAnnouncements(role) {
    const target = document.getElementById(`${role}-announcements`);
    if (!target) return;
    try {
      const response = await fetch('/api/index.php?endpoint=announcements.php');
      const result = await response.json();
      if (!response.ok || !result.success) throw new Error(result.message || 'Unable to load announcements.');
      const items = result.items || [];
      if (!items.length) {
        target.textContent = 'No current announcements.';
        return;
      }
      target.replaceChildren(...items.map(item => {
        const article = document.createElement('article');
        article.className = 'mb-3 rounded-lg border border-blue-100 bg-blue-50 p-4';
        const title = document.createElement('h3');
        title.className = 'font-semibold text-gray-900';
        title.textContent = item.title;
        const body = document.createElement('p');
        body.className = 'mt-1 whitespace-pre-wrap text-sm text-gray-700';
        body.textContent = item.body;
        const created = document.createElement('small');
        created.className = 'mt-2 block text-gray-500';
        created.textContent = new Date(item.created_at).toLocaleString();
        article.append(title, body, created);
        return article;
      }));
    } catch (error) {
      console.error(error);
      target.textContent = error.message || 'Unable to load announcements.';
      target.className = 'text-sm text-red-600';
    }
  }

  async function loadLeaveCalendar(role) {
    const target = document.getElementById(`${role}-leave-calendar`);
    if (!target) return;
    const from = new Date();
    const to = new Date(from.getFullYear(), from.getMonth() + 2, 0);
    const formatDate = date => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
    const params = new URLSearchParams({ from: formatDate(from), to: formatDate(to) });
    try {
      const response = await fetch(`/api/index.php?endpoint=leave_calendar.php&${params.toString()}`);
      const result = await response.json();
      if (!response.ok || !result.success) throw new Error(result.message || 'Unable to load leave calendar.');
      const items = result.items || [];
      if (!items.length) {
        target.textContent = 'No active leave requests in this calendar range.';
        return;
      }
      const table = document.createElement('table');
      table.className = 'min-w-full border border-gray-200 text-sm';
      const head = document.createElement('thead');
      head.className = 'bg-gray-100 text-left';
      const headerRow = document.createElement('tr');
      (role === 'student' ? ['From', 'To', 'Status'] : ['Student', 'SAP ID', 'From', 'To', 'Hostel', 'Overlapping leaves', 'Status']).forEach(label => {
        const th = document.createElement('th');
        th.className = 'whitespace-nowrap px-3 py-2';
        th.textContent = label;
        headerRow.appendChild(th);
      });
      head.appendChild(headerRow);
      table.appendChild(head);
      const body = document.createElement('tbody');
      items.forEach(item => {
        const row = document.createElement('tr');
        row.className = 'border-t border-gray-200';
        const values = role === 'student'
          ? [item.start_date, item.end_date, item.status]
          : [item.name, item.sap_id, item.start_date, item.end_date, item.hostel, Number(item.overlap_count) > 0 ? `${item.overlap_count} overlapping request(s)` : 'No overlap', item.status];
        values.forEach(value => {
          const cell = document.createElement('td');
          cell.className = 'whitespace-nowrap px-3 py-2';
          cell.textContent = value || '-';
          row.appendChild(cell);
        });
        body.appendChild(row);
      });
      table.appendChild(body);
      target.replaceChildren(table);
    } catch (error) {
      console.error(error);
      target.textContent = error.message || 'Unable to load leave calendar.';
      target.className = 'overflow-x-auto text-sm text-red-600';
    }
  }

  async function setupAdminPolicies() {
    const form = document.getElementById('admin-leave-policy-form');
    if (!form) return;
    const fields = ['max_leave_days', 'max_requests_per_month', 'minimum_notice_hours'];
    const message = document.getElementById('admin-policy-message');
    const load = async () => {
      try {
        const params = new URLSearchParams({ hostel: form.elements.hostel.value });
        const response = await fetch(`/api/index.php?endpoint=leave_policies.php&${params.toString()}`);
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || 'Unable to load leave policy.');
        fields.forEach(field => { form.elements[field].value = result.items[0]?.[field] ?? ''; });
        message.textContent = '';
      } catch (error) {
        message.textContent = error.message || 'Unable to load leave policy.';
        message.className = 'ml-3 text-sm text-red-600';
      }
    };
    form.elements.hostel.addEventListener('change', load);
    form.addEventListener('submit', async event => {
      event.preventDefault();
      const data = Object.fromEntries(new FormData(form).entries());
      try {
        const response = await fetch('/api/index.php?endpoint=leave_policies.php', { method: 'POST', body: getCsrfFormData(data) });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || 'Unable to save leave policy.');
        message.textContent = result.message;
        message.className = 'ml-3 text-sm text-green-700';
      } catch (error) {
        message.textContent = error.message || 'Unable to save leave policy.';
        message.className = 'ml-3 text-sm text-red-600';
      }
    });
    await load();
  }

  async function setupAdminAnnouncements() {
    const form = document.getElementById('admin-announcement-form');
    const target = document.getElementById('admin-announcement-list');
    if (!form || !target) return;
    const message = document.getElementById('admin-announcement-message');
    const load = async () => {
      try {
        const response = await fetch('/api/index.php?endpoint=announcements.php');
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || 'Unable to load announcements.');
        if (!result.items.length) {
          target.textContent = 'No current announcements.';
          return;
        }
        target.replaceChildren(...result.items.map(item => {
          const article = document.createElement('article');
          article.className = 'flex flex-wrap items-start justify-between gap-3 rounded-lg border border-gray-200 p-3';
          const copy = document.createElement('div');
          const title = document.createElement('strong');
          title.textContent = item.title;
          const body = document.createElement('p');
          body.className = 'whitespace-pre-wrap text-sm text-gray-700';
          body.textContent = `${item.body} · ${item.target_role}${item.target_hostel ? ` · ${item.target_hostel}` : ''}`;
          copy.append(title, body);
          const remove = document.createElement('button');
          remove.type = 'button';
          remove.className = 'rounded bg-red-600 px-3 py-1 text-sm font-semibold text-white';
          remove.textContent = 'Delete';
          remove.addEventListener('click', async () => {
            try {
              const response = await fetch('/api/index.php?endpoint=announcements.php', {
                method: 'POST',
                body: getCsrfFormData({ action: 'delete', id: item.id })
              });
              const result = await response.json();
              if (!response.ok || !result.success) throw new Error(result.message || 'Unable to delete announcement.');
              await load();
            } catch (error) {
              window.alert(error.message || 'Unable to delete announcement.');
            }
          });
          article.append(copy, remove);
          return article;
        }));
      } catch (error) {
        console.error(error);
        target.textContent = error.message || 'Unable to load announcements.';
        target.className = 'mt-4 text-sm text-red-600';
      }
    };
    form.addEventListener('submit', async event => {
      event.preventDefault();
      const values = Object.fromEntries(new FormData(form).entries());
      try {
        const response = await fetch('/api/index.php?endpoint=announcements.php', { method: 'POST', body: getCsrfFormData(values) });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || 'Unable to publish announcement.');
        message.textContent = result.message;
        message.className = 'text-sm text-green-700';
        form.reset();
        await load();
      } catch (error) {
        message.textContent = error.message || 'Unable to publish announcement.';
        message.className = 'text-sm text-red-600';
      }
    });
    await load();
  }

  async function loadAdminAuditHistory() {
    const target = document.getElementById('admin-audit-history');
    if (!target) return;
    const filter = document.getElementById('admin-audit-filter');
    const refresh = document.getElementById('admin-audit-refresh');
    if (refresh && !refresh.dataset.bound) {
      refresh.dataset.bound = '1';
      refresh.addEventListener('click', loadAdminAuditHistory);
    }
    const params = new URLSearchParams({ limit: '50' });
    if (filter?.value.trim()) params.set('action', filter.value.trim());
    target.textContent = 'Loading audit history...';
    try {
      const response = await fetch(`/api/index.php?endpoint=audit_history.php&${params.toString()}`);
      const result = await response.json();
      if (!response.ok || !result.success) throw new Error(result.message || 'Unable to load audit history.');
      if (!result.items.length) {
        target.textContent = 'No audit events found.';
        return;
      }
      const table = document.createElement('table');
      table.className = 'min-w-full border border-gray-200 text-sm';
      const headings = ['When', 'Actor', 'Role', 'Action', 'Entity', 'Details'];
      const thead = document.createElement('thead');
      thead.className = 'bg-gray-100 text-left';
      const headerRow = document.createElement('tr');
      headings.forEach(label => {
        const cell = document.createElement('th');
        cell.className = 'whitespace-nowrap px-3 py-2';
        cell.textContent = label;
        headerRow.appendChild(cell);
      });
      thead.appendChild(headerRow);
      table.appendChild(thead);
      const tbody = document.createElement('tbody');
      result.items.forEach(item => {
        const row = document.createElement('tr');
        row.className = 'border-t border-gray-200';
        [new Date(item.created_at).toLocaleString(), item.actor_name, item.actor_role, item.action, `${item.entity}${item.entity_id ? ` #${item.entity_id}` : ''}`, typeof item.details === 'string' ? item.details : JSON.stringify(item.details || {})].forEach(value => {
          const cell = document.createElement('td');
          cell.className = 'max-w-sm break-words px-3 py-2';
          cell.textContent = value || '-';
          row.appendChild(cell);
        });
        tbody.appendChild(row);
      });
      table.appendChild(tbody);
      target.replaceChildren(table);
    } catch (error) {
      console.error(error);
      target.textContent = error.message || 'Unable to load audit history.';
      target.className = 'overflow-x-auto text-sm text-red-600';
    }
  }

  function extractPassToken(decodedValue) {
    const value = String(decodedValue || '').trim();
    const compactMatch = value.match(/^HLP1:([a-f\d]{32})$/i);
    if (compactMatch) return compactMatch[1];
    const passIdMatch = value.match(/^Pass ID:\s*([a-f\d]{32})\s*$/im);
    if (passIdMatch) return passIdMatch[1];
    const tokenMatch = value.match(/^([a-f\d]{32})$/i);
    if (tokenMatch) return tokenMatch[1];
    try {
      const parsedUrl = new URL(value);
      return parsedUrl.searchParams.get('token') || parsedUrl.searchParams.get('pass_token') || '';
    } catch {
      return '';
    }
  }

  function renderSecurityResult(result) {
    const target = document.getElementById('security-result');
    if (!target) return;
    const card = document.createElement('section');
    card.className = `mt-4 min-w-0 rounded-lg border-2 p-3 sm:p-5 ${result.valid_qr ? 'border-green-500 bg-green-50' : 'border-amber-500 bg-amber-50'}`;

    const heading = document.createElement('h3');
    heading.className = 'break-words text-xl font-bold text-gray-900 sm:text-2xl';
    heading.textContent = result.student_name || 'Student pass';
    card.appendChild(heading);

    const badge = document.createElement('p');
    const hasGateDecision = ['accept', 'reject'].includes(result.gate_decision);
    const hasExited = Boolean(result.gate_out_time);
    const hasEntered = Boolean(result.gate_in_time);
    const scheduledStart = result.start_date
      ? new Date(`${result.start_date}T${result.start_time || '00:00:00'}+05:30`)
      : null;
    const exitNotYetValid = result.status === 'Granted'
      && scheduledStart instanceof Date
      && !Number.isNaN(scheduledStart.getTime())
      && Date.now() < scheduledStart.getTime();
    const badgeColor = hasGateDecision && result.gate_decision === 'reject'
      ? 'bg-red-700 text-white'
      : result.status === 'CHECKED_OUT' ? 'bg-amber-600 text-white'
      : result.status === 'CHECKED_IN' ? 'bg-green-700 text-white'
      : exitNotYetValid ? 'bg-amber-600 text-white'
      : result.valid_qr ? 'bg-green-700 text-white' : 'bg-amber-600 text-white';
    badge.className = `mt-2 inline-flex max-w-full whitespace-normal break-words rounded px-3 py-1 text-sm font-bold sm:text-lg ${badgeColor}`;
    badge.textContent = result.status === 'CHECKED_OUT'
      ? 'CURRENTLY OUTSIDE'
      : result.status === 'CHECKED_IN'
        ? 'RETURN RECORDED'
        : exitNotYetValid
          ? 'NOT YET VALID FOR EXIT'
        : hasGateDecision && result.gate_decision === 'reject'
          ? 'ALREADY REJECTED AT GATE'
          : result.valid_qr ? 'PASS VERIFIED' : 'PASS FOUND - QR SIGNATURE UNVERIFIED';
    card.appendChild(badge);

    const details = document.createElement('dl');
    details.className = 'mt-4 grid min-w-0 grid-cols-1 gap-2 sm:grid-cols-2 sm:gap-3';
    [
      ['SAP ID', result.sap_id],
      ['Room number', result.room_no],
      ['Status', result.status],
      ['Valid from', result.start_date],
      ['Valid until', result.end_date],
      ['Pass token', result.pass_token],
      ['Gate decision', hasGateDecision ? (result.gate_decision === 'accept' ? 'Accepted' : 'Rejected') : 'Not decided'],
      ['Gate exit', result.gate_out_time],
      ['Gate entry', result.gate_in_time],
      ['Return timing', [result.return_timing_status, result.variance_summary].filter(Boolean).join(' · ')],
      ['Reason', result.reason]
    ].forEach(([label, value]) => {
      const row = document.createElement('div');
      row.className = 'min-w-0 rounded border border-gray-200 bg-white p-3';
      const term = document.createElement('dt');
      term.className = 'text-xs font-semibold uppercase text-gray-500';
      term.textContent = label;
      const description = document.createElement('dd');
      description.className = `mt-1 ${label === 'Pass token' ? 'break-all' : 'break-words'} text-sm font-semibold text-gray-900 sm:text-base`;
      description.textContent = value || 'Not provided';
      row.append(term, description);
      details.appendChild(row);
    });
    card.appendChild(details);

    if (exitNotYetValid) {
      const notice = document.createElement('p');
      notice.className = 'mt-4 rounded-lg bg-amber-100 p-3 text-sm font-semibold text-amber-900';
      notice.textContent = `This pass is not valid for gate exit until ${scheduledStart.toLocaleString('en-IN', {
        timeZone: 'Asia/Kolkata',
        dateStyle: 'medium',
        timeStyle: 'short'
      })} IST.`;
      card.appendChild(notice);
    } else if (result.valid_qr && result.pass_token && result.status === 'Granted' && !hasExited && result.gate_decision !== 'reject') {
      const actions = document.createElement('div');
      actions.className = 'mt-4 grid grid-cols-1 gap-2 sm:grid-cols-2';
      const exitButton = document.createElement('button');
      exitButton.type = 'button';
      exitButton.className = 'w-full whitespace-normal rounded-lg bg-green-700 px-4 py-3 text-base font-bold text-white hover:bg-green-800';
      exitButton.textContent = 'Record Gate Exit (OUT)';
      exitButton.addEventListener('click', () => submitGateDecision(result, 'exit', actions));
      actions.appendChild(exitButton);
      if (!hasGateDecision) {
        const rejectButton = document.createElement('button');
        rejectButton.type = 'button';
        rejectButton.className = 'w-full whitespace-normal rounded-lg bg-red-700 px-4 py-3 text-base font-bold text-white hover:bg-red-800';
        rejectButton.textContent = 'Reject pass';
        rejectButton.addEventListener('click', () => submitGateDecision(result, 'reject', actions));
        actions.appendChild(rejectButton);
      }
      card.appendChild(actions);
    } else if (result.valid_qr && result.pass_token && result.status === 'CHECKED_OUT' && hasExited && !hasEntered && result.gate_entry_available === true) {
      const actions = document.createElement('div');
      actions.className = 'mt-4';
      const entryButton = document.createElement('button');
      entryButton.type = 'button';
      entryButton.className = 'w-full whitespace-normal rounded-lg bg-cyan-700 px-4 py-3 text-base font-bold text-white hover:bg-cyan-800';
      entryButton.textContent = 'Record Gate Entry (IN)';
      entryButton.addEventListener('click', () => submitGateDecision(result, 'entry', actions));
      actions.appendChild(entryButton);
      card.appendChild(actions);
    } else if (result.valid_qr && result.status === 'CHECKED_OUT' && hasExited && !hasEntered) {
      const notice = document.createElement('p');
      notice.className = 'mt-4 rounded-lg bg-amber-100 p-3 text-sm font-semibold text-amber-900';
      notice.textContent = 'Gate entry can be recorded after 24 hours have elapsed from the recorded gate exit.';
      card.appendChild(notice);
    }

    target.replaceChildren(card);
    target.classList.remove('hidden');
  }

  async function submitGateDecision(pass, decision, actionContainer) {
    const buttons = actionContainer.querySelectorAll('button');
    buttons.forEach(button => { button.disabled = true; });
    try {
      const response = await fetch('/api/index.php?endpoint=gate_decision.php', {
        method: 'POST',
        body: getCsrfFormData({
          leave_id: String(pass.leave_id),
          pass_token: pass.pass_token,
          decision
        })
      });
      const result = await response.json();
      if (!response.ok || !result.success) throw new Error(result.message || 'Unable to record the gate decision.');
      const savedDecision = result.decision;
      const outcome = document.createElement('p');
      outcome.className = `mt-4 rounded-lg p-3 text-center text-lg font-bold ${['exit', 'entry', 'accept'].includes(savedDecision) ? 'bg-green-700 text-white' : 'bg-red-700 text-white'}`;
      outcome.textContent = result.message;
      actionContainer.replaceWith(outcome);
      await loadGateDecisionHistory();
      if (savedDecision === 'exit' || savedDecision === 'entry') {
        await verifySecurityPass({ token: pass.pass_token });
      }
    } catch (error) {
      console.error(error);
      const target = document.getElementById('security-result');
      showMessage(target, error.message || 'Unable to record the gate decision.', 'error');
    }
  }

  async function loadGateDecisionHistory() {
    const target = document.getElementById('security-gate-history');
    if (!target) return;
    try {
      const response = await fetch('/api/index.php?endpoint=gate_history.php');
      const result = await response.json();
      if (!response.ok || !result.success) throw new Error(result.message || 'Unable to load gate history.');
      const items = result.items || [];
      if (!items.length) {
        target.textContent = 'No gate decisions recorded yet.';
        return;
      }
      const table = document.createElement('table');
      table.className = 'min-w-full border border-gray-300 text-sm';
      const headings = ['Student', 'SAP ID', 'Decision', 'Leave dates', 'Checked at'];
      const thead = document.createElement('thead');
      thead.className = 'bg-gray-100 text-gray-700';
      const headingRow = document.createElement('tr');
      headings.forEach(label => {
        const cell = document.createElement('th');
        cell.className = 'whitespace-nowrap px-3 py-2 text-left';
        cell.textContent = label;
        headingRow.appendChild(cell);
      });
      thead.appendChild(headingRow);
      table.appendChild(thead);
      const tbody = document.createElement('tbody');
      items.forEach(item => {
        const row = document.createElement('tr');
        row.className = 'border-t border-gray-200';
        [
          item.student_name,
          item.sap_id,
          item.action === 'gate_pass_accepted' ? 'Accepted' : 'Rejected',
          `${item.start_date} to ${item.end_date}`,
          item.created_at
        ].forEach(value => {
          const cell = document.createElement('td');
          cell.className = 'px-3 py-2';
          cell.textContent = value || '-';
          row.appendChild(cell);
        });
        tbody.appendChild(row);
      });
      table.appendChild(tbody);
      target.replaceChildren(table);
    } catch (error) {
      console.error(error);
      target.textContent = error.message || 'Unable to load gate history.';
      target.className = 'overflow-x-auto text-sm text-red-600';
    }
  }

  async function verifySecurityPass({ sapId = '', token = '' } = {}) {
    const target = document.getElementById('security-result');
    const params = new URLSearchParams();
    if (sapId) params.set('sap_id', sapId);
    if (token) params.set('token', token);
    showMessage(target, 'Checking pass...', 'info');
    try {
      const response = await fetch(`/api/index.php?endpoint=verify_pass.php&${params.toString()}`);
      const result = await response.json();
      if (!response.ok || !result.success) {
        const failureMessage = token && response.status === 404
          ? 'Invalid QR: no approved leave pass matches this code.'
          : result.message || 'Pass verification failed.';
        showMessage(target, failureMessage, 'error');
        return;
      }
      if (token && !result.valid_qr) {
        showMessage(target, 'Invalid QR: this code is not a signed leave pass.', 'error');
        return;
      }
      renderSecurityResult(result);
    } catch (error) {
      console.error(error);
      showMessage(target, 'Verification request failed. Check the network and try again.', 'error');
    }
  }

  async function stopSecurityScanner() {
    const scanner = securityQrScanner;
    securityQrScanner = null;
    if (!scanner) return;
    try {
      if (scanner.isScanning) await scanner.stop();
      scanner.clear();
    } catch (error) {
      console.warn('Unable to stop QR scanner cleanly.', error);
    }
  }

  async function startSecurityScanner() {
    const reader = document.getElementById('security-qr-reader');
    const result = document.getElementById('security-result');
    if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) {
      showMessage(result, 'Camera scanning requires HTTPS and camera permission. Open the secure site on your device.', 'error');
      return;
    }
    try {
      await loadOptionalScript(
        'https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js',
        () => typeof window.Html5Qrcode === 'function'
      );
    } catch (error) {
      console.error(error);
      showMessage(result, error.message || 'QR scanner could not be loaded. Try again.', 'error');
      return;
    }

    await stopSecurityScanner();
    reader.classList.remove('hidden');
    reader.style.display = 'block';
    reader.style.width = '100%';
    reader.style.minHeight = '280px';
    await new Promise(resolve => requestAnimationFrame(resolve));
    const scanner = new window.Html5Qrcode('security-qr-reader', {
      verbose: false,
      formatsToSupport: [window.Html5QrcodeSupportedFormats.QR_CODE],
      experimentalFeatures: { useBarCodeDetectorIfSupported: true }
    });
    securityQrScanner = scanner;
    const scanButton = document.getElementById('security-scan-button');
    const stopButton = document.getElementById('security-stop-scan-button');
    scanButton.disabled = true;
    stopButton.classList.remove('hidden');

    try {
      const availableSize = Math.min(reader.clientWidth || window.innerWidth - 48, window.innerHeight * 0.55);
      const boxSize = Math.min(300, Math.max(180, Math.floor(availableSize * 0.78)));
      await scanner.start(
        { facingMode: 'environment' },
        { fps: 20, qrbox: { width: boxSize, height: boxSize }, aspectRatio: 1 },
        async decodedText => {
          if (handlingSecurityScan) return;
          handlingSecurityScan = true;
          const token = extractPassToken(decodedText);
          await stopSecurityScanner();
          reader.classList.add('hidden');
          reader.style.display = 'none';
          stopButton.classList.add('hidden');
          scanButton.disabled = false;
          if (!token) {
            showMessage(result, 'Invalid QR: this code is not a designated hostel leave pass.', 'error');
          } else {
            await verifySecurityPass({ token });
          }
          handlingSecurityScan = false;
        }
      );
      reader.scrollIntoView({ behavior: 'smooth', block: 'center' });
    } catch (error) {
      console.error(error);
      await stopSecurityScanner();
      reader.classList.add('hidden');
      reader.style.display = 'none';
      stopButton.classList.add('hidden');
      scanButton.disabled = false;
      showMessage(result, 'Could not open the camera. Allow camera access in your browser and try again.', 'error');
    }
  }

  function showSecurityPage() {
    hideAllPages();
    appContainer.classList.add('portal-wide');
    securityPage.classList.remove('hidden');
    securityPage.innerHTML = `
      <div class="max-w-3xl mx-auto">
        <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
          <h1 class="text-3xl font-bold text-gray-800">Security Portal</h1>
          <button type="button" data-logout-button class="bg-red-600 text-white px-4 py-2 rounded-lg font-semibold">Logout</button>
        </div>
        <section class="bg-white rounded-xl p-5 border border-gray-200 shadow-sm">
          <h2 class="text-2xl font-semibold text-gray-700 mb-4">Verify Gate Pass</h2>
          <div class="grid md:grid-cols-2 gap-4">
            <label class="block text-sm font-medium text-gray-700">Student SAP ID<input id="security-sap-id" type="text" inputmode="numeric" class="mt-2 w-full p-3 border border-gray-300 rounded-lg" placeholder="Enter SAP ID" /></label>
            <label class="block text-sm font-medium text-gray-700">Pass token<input id="security-pass-token" type="text" class="mt-2 w-full p-3 border border-gray-300 rounded-lg" placeholder="Enter pass token" /></label>
          </div>
          <div class="mt-5 flex flex-wrap gap-3">
            <button id="verify-pass-btn" type="button" class="bg-blue-600 text-white px-5 py-3 rounded-lg font-semibold">Verify Pass</button>
            <button id="security-scan-button" type="button" class="bg-green-700 text-white px-5 py-3 rounded-lg font-semibold">Verify by scanning</button>
            <button id="security-stop-scan-button" type="button" class="hidden bg-gray-700 text-white px-5 py-3 rounded-lg font-semibold">Stop camera</button>
          </div>
          <div id="security-qr-reader" class="hidden mt-5 w-full max-w-md mx-auto overflow-hidden rounded-lg"></div>
          <div id="security-result" class="mt-4 hidden" aria-live="polite"></div>
        </section>
        <section class="mt-6 bg-white rounded-xl p-4 sm:p-5 border border-gray-200 shadow-sm">
          <h2 class="text-xl font-semibold text-gray-700 mb-4 sm:text-2xl">Gate Decision History</h2>
          <div id="security-gate-history" class="overflow-x-auto text-sm" aria-live="polite">Loading gate history...</div>
        </section>
      </div>
    `;
    document.getElementById('verify-pass-btn').addEventListener('click', async () => {
      const sapId = document.getElementById('security-sap-id').value.trim();
      const token = document.getElementById('security-pass-token').value.trim();
      if (Boolean(sapId) === Boolean(token)) {
        showMessage(document.getElementById('security-result'), 'Enter either a SAP ID or a pass token, not both.', 'error');
        return;
      }
      await verifySecurityPass({ sapId, token });
    });
    document.getElementById('security-scan-button').addEventListener('click', startSecurityScanner);
    document.getElementById('security-stop-scan-button').addEventListener('click', async () => {
      await stopSecurityScanner();
      const reader = document.getElementById('security-qr-reader');
      reader.classList.add('hidden');
      reader.style.display = 'none';
      document.getElementById('security-stop-scan-button').classList.add('hidden');
      document.getElementById('security-scan-button').disabled = false;
    });
    loadGateDecisionHistory();
  }

  function showAdminPage() {
    hideAllPages();
    appContainer.classList.add('portal-wide');
    adminPage.classList.remove('hidden');
    adminPage.innerHTML = `
      <div class="max-w-4xl mx-auto">
        <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
          <h1 class="text-3xl font-bold text-gray-800">Admin Portal</h1>
          <button type="button" data-logout-button class="bg-red-600 text-white px-4 py-2 rounded-lg font-semibold">Logout</button>
        </div>
        <section class="bg-white rounded-xl p-5 border border-gray-200 shadow-sm">
          <h2 class="text-2xl font-semibold text-gray-700 mb-4">Import Students CSV</h2>
          <p class="text-sm text-gray-600 mb-3"><a href="/frontend/student_roster_template.csv" class="text-blue-600 underline">Student CSV template</a>. Save as UTF-8 CSV from Excel; XLSX files are not accepted. Maximum 2,500 rows and 2 MB. Student phone, batch, and room number are not required in the roster.</p>
          <p class="mb-4 rounded-lg border border-blue-200 bg-blue-50 p-3 text-sm text-blue-900">This imports or updates student records only; it does not create passwords. Students create their own account using their SAP ID, either parent contact number, and matching official email ID from this roster.</p>
          <form id="student-import-form" enctype="multipart/form-data">
            <input type="file" name="students_csv" accept=".csv,text/csv" required class="block w-full text-sm text-gray-500" />
            <label class="mt-4 flex items-start gap-3 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
              <input id="reset-student-accounts" type="checkbox" name="reset_student_accounts" value="1" class="mt-1 h-4 w-4 accent-amber-600" />
              <span><strong>Require students in this CSV to sign up again.</strong> Their existing passwords will stop working and they must verify their SAP ID, either parent contact, and official email before creating a new password. Student profiles and leave history are preserved. This applies only to valid student rows in this upload.</span>
            </label>
            <button type="submit" class="mt-4 bg-green-600 text-white px-5 py-3 rounded-lg font-semibold disabled:cursor-wait disabled:opacity-60">Import Students</button>
          </form>
          <div id="admin-import-result" class="mt-4 hidden"></div>
        </section>
        <section class="mt-6 rounded-xl border border-amber-200 bg-white p-5 shadow-sm">
          <h2 class="mb-3 text-xl font-semibold text-gray-800">Optional Leave Policies</h2>
          <p class="mb-4 text-sm text-gray-600">Blank values do not restrict existing leave requests. Policies apply only to new submissions for the selected hostel.</p>
          <form id="admin-leave-policy-form" class="grid gap-3 md:grid-cols-2">
            <label class="text-sm font-medium text-gray-700">Hostel
              <select name="hostel" class="mt-1 w-full rounded-lg border border-gray-300 p-3">
                <option>BOYS HOSTEL 1</option><option>NEW BOYS HOSTEL</option><option>NEW GIRLS HOSTEL</option><option>OLD GIRLS HOSTEL</option>
              </select>
            </label>
            <label class="text-sm font-medium text-gray-700">Maximum leave days
              <input name="max_leave_days" type="number" min="1" max="3650" class="mt-1 w-full rounded-lg border border-gray-300 p-3" />
            </label>
            <label class="text-sm font-medium text-gray-700">Maximum requests per month
              <input name="max_requests_per_month" type="number" min="1" max="3650" class="mt-1 w-full rounded-lg border border-gray-300 p-3" />
            </label>
            <label class="text-sm font-medium text-gray-700">Minimum notice (hours)
              <input name="minimum_notice_hours" type="number" min="0" max="3650" class="mt-1 w-full rounded-lg border border-gray-300 p-3" />
            </label>
            <div class="md:col-span-2"><button type="submit" class="rounded-lg bg-amber-600 px-4 py-2 font-semibold text-white">Save policy</button><span id="admin-policy-message" class="ml-3 text-sm" aria-live="polite"></span></div>
          </form>
        </section>
        <section class="mt-6 rounded-xl border border-blue-200 bg-white p-5 shadow-sm">
          <h2 class="mb-3 text-xl font-semibold text-gray-800">Announcements</h2>
          <form id="admin-announcement-form" class="grid gap-3">
            <input name="title" maxlength="150" required class="rounded-lg border border-gray-300 p-3" placeholder="Announcement title" />
            <textarea name="body" maxlength="5000" rows="3" required class="rounded-lg border border-gray-300 p-3" placeholder="Message"></textarea>
            <div class="grid gap-3 md:grid-cols-3">
              <select name="target_role" class="rounded-lg border border-gray-300 p-3"><option value="all">Everyone</option><option value="student">Students</option><option value="warden">Wardens</option></select>
              <select name="target_hostel" class="rounded-lg border border-gray-300 p-3"><option value="">All hostels</option><option>BOYS HOSTEL 1</option><option>NEW BOYS HOSTEL</option><option>NEW GIRLS HOSTEL</option><option>OLD GIRLS HOSTEL</option></select>
              <input name="expires_at" type="datetime-local" class="rounded-lg border border-gray-300 p-3" aria-label="Announcement expiry (optional)" />
            </div>
            <button type="submit" class="w-fit rounded-lg bg-blue-700 px-4 py-2 font-semibold text-white">Publish announcement</button>
            <p id="admin-announcement-message" class="text-sm" aria-live="polite"></p>
          </form>
          <div id="admin-announcement-list" class="mt-4 space-y-3">Loading announcements...</div>
        </section>
        <section class="mt-6 rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
          <h2 class="mb-3 text-xl font-semibold text-gray-800">Audit History</h2>
          <div class="mb-3 flex flex-wrap gap-2"><input id="admin-audit-filter" class="rounded-lg border border-gray-300 p-2" placeholder="Filter action" /><button id="admin-audit-refresh" type="button" class="rounded-lg bg-gray-700 px-4 py-2 text-white">Refresh</button></div>
          <div id="admin-audit-history" class="overflow-x-auto">Loading audit history...</div>
        </section>
      </div>
    `;
    document.getElementById('student-import-form').addEventListener('submit', async event => {
      event.preventDefault();
      const form = event.currentTarget;
      const fileInput = form.querySelector('input[name="students_csv"]');
      const submitButton = form.querySelector('button[type="submit"]');
      const resultTarget = document.getElementById('admin-import-result');
      const file = fileInput.files[0];
      if (!file || !file.name.toLowerCase().endsWith('.csv') || file.size > 2 * 1024 * 1024) {
        showMessage(resultTarget, 'Choose a CSV file smaller than 2 MB.', 'error');
        return;
      }
      if (document.getElementById('reset-student-accounts').checked
        && !window.confirm('Reset accounts for valid students in this CSV? Their existing passwords will stop working and each student will need to sign up again. Student profiles and leave history will be preserved.')) {
        return;
      }
      submitButton.disabled = true;
      submitButton.textContent = 'Importing...';
      showMessage(resultTarget, 'Validating and importing student records...', 'text-blue-700 bg-blue-50');
      const formData = new FormData(form);
      formData.append('csrf_token', state.csrfToken);
      try {
        const response = await fetch('/api/index.php?endpoint=import_students.php', { method: 'POST', body: formData });
        const responseText = await response.text();
        let result;
        try {
          result = JSON.parse(responseText);
        } catch {
          throw new Error(`Import server returned an unreadable response (HTTP ${response.status}). Check the deployment function logs before trying again.`);
        }
        const importSucceeded = response.ok && result.success;
        const statusNote = response.ok ? '' : ` (HTTP ${response.status})`;
        showMessage(
          resultTarget,
          `${result.message || (importSucceeded ? 'Import complete.' : 'The server could not complete the import.')}${statusNote}`,
          importSucceeded
            ? result.error_count > 0 ? 'text-amber-800 bg-amber-50' : 'text-green-700 bg-green-50'
            : 'text-red-700 bg-red-50'
        );
        if (result.errors?.length) {
          const errors = document.createElement('ul');
          errors.className = 'mt-2 list-inside list-disc';
          result.errors.forEach(error => {
            const item = document.createElement('li');
            item.textContent = `Row ${error.row}${error.sap_id ? ` (SAP ${error.sap_id})` : ''}: ${error.message}`;
            errors.appendChild(item);
          });
          if (result.error_count > result.errors.length) {
            const remaining = document.createElement('li');
            remaining.textContent = `${result.error_count - result.errors.length} additional row error(s) omitted.`;
            errors.appendChild(remaining);
          }
          resultTarget.appendChild(errors);
        }
        if (!importSucceeded) return;
      } catch (error) {
        console.error(error);
        showMessage(resultTarget, `${error.message || 'Import request failed.'} Do not upload again until you confirm whether these students were added.`, 'text-red-700 bg-red-50');
      } finally {
        submitButton.disabled = false;
        submitButton.textContent = 'Import Students';
      }
    });
    setupAdminPolicies();
    setupAdminAnnouncements();
    loadAdminAuditHistory();
  }

  loginForm.addEventListener('submit', event => {
    event.preventDefault();
    handleLogin();
  });
  ['user-id', 'password'].forEach(id => {
    document.getElementById(id).addEventListener('keydown', event => {
      if (event.key === 'Enter') {
        event.preventDefault();
        handleLogin();
      }
    });
  });
  document.getElementById('toggle-password').addEventListener('click', event => {
    const input = document.getElementById('password');
    const toggle = event.currentTarget;
    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    toggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    toggle.title = show ? 'Hide password' : 'Show password';
  });
  document.querySelectorAll('[data-password-toggle]').forEach(button => {
    button.addEventListener('click', () => {
      const input = document.getElementById(button.dataset.passwordToggle);
      if (!input) return;
      const show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      const label = `${show ? 'Hide' : 'Show'} ${button.dataset.passwordLabel}`;
      button.setAttribute('aria-label', label);
      button.title = label;
    });
  });
  roleSelect.addEventListener('change', updateRoleTitle);
  document.addEventListener('click', event => {
    if (event.target.closest('[data-logout-button]')) logoutModal.classList.remove('hidden');
  });
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden && document.getElementById('warden-gate-attendance')) {
      void loadWardenGateAttendance();
    }
  });
  document.getElementById('cancel-change-password')?.addEventListener('click', closeChangePasswordModal);
  document.getElementById('close-change-password-modal')?.addEventListener('click', closeChangePasswordModal);
  document.getElementById('forced-change-logout')?.addEventListener('click', logoutUser);
  document.getElementById('change-password-form')?.addEventListener('submit', handleChangePassword);
  document.getElementById('student-forgot-password-button')?.addEventListener('click', openForgotPasswordModal);
  document.getElementById('close-forgot-password-modal')?.addEventListener('click', closeForgotPasswordModal);
  document.getElementById('forgot-password-identity-form')?.addEventListener('submit', handleForgotPasswordIdentity);
  document.getElementById('forgot-password-reset-form')?.addEventListener('submit', handleForgotPasswordReset);
  document.getElementById('student-signup-button')?.addEventListener('click', openSignupModal);
  document.getElementById('close-signup-modal')?.addEventListener('click', closeSignupModal);
  document.getElementById('signup-identity-form')?.addEventListener('submit', handleSignupVerification);
  document.getElementById('signup-password-form')?.addEventListener('submit', handleSignupCreate);
  document.getElementById('confirm-logout').addEventListener('click', logoutUser);
  document.getElementById('cancel-logout').addEventListener('click', () => logoutModal.classList.add('hidden'));
  updateRoleTitle();
  showLogin();
  restoreSession();
});
