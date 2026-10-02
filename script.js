document.addEventListener('DOMContentLoaded', () => {
  const roleSelect = document.getElementById('role-select');
  const roleTitle = document.getElementById('role-title');
  const appContainer = document.getElementById('app-container');
  const loginForm = document.getElementById('login-section');
  const loginSection = document.getElementById('login-section');
  const loginMessage = document.getElementById('message-box');
  const studentPage = document.getElementById('student-page');
  const wardenPage = document.getElementById('teacher-page');
  const adminPage = document.getElementById('admin-page');
  const securityPage = document.getElementById('security-page');
  const logoutModal = document.getElementById('logout-confirm-modal');
  const changePasswordModal = document.getElementById('change-password-modal');
  const state = { csrfToken: '', user: null };
  let loginProgressTimer = null;

  function openChangePasswordModal() {
    if (!changePasswordModal) return;
    const form = document.getElementById('change-password-form');
    const message = document.getElementById('change-password-message');
    if (form) form.reset();
    if (message) hideMessage(message);
    changePasswordModal.classList.remove('hidden');
  }

  function closeChangePasswordModal() {
    if (!changePasswordModal) return;
    const form = document.getElementById('change-password-form');
    const message = document.getElementById('change-password-message');
    if (form) form.reset();
    if (message) hideMessage(message);
    changePasswordModal.classList.add('hidden');
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
  }

  function hideAllPages() {
    loginSection.classList.add('hidden');
    studentPage.classList.add('hidden');
    wardenPage.classList.add('hidden');
    adminPage.classList.add('hidden');
    securityPage.classList.add('hidden');
  }

  function showLogin() {
    hideAllPages();
    appContainer.classList.remove('portal-wide');
    studentPage.replaceChildren();
    wardenPage.replaceChildren();
    adminPage.replaceChildren();
    securityPage.replaceChildren();
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
        if (state.user.role === 'student') await showStudentPage();
        else if (state.user.role === 'warden') showWardenPage();
        else if (state.user.role === 'admin') showAdminPage();
        else if (state.user.role === 'security') showSecurityPage();
      }, 350);
    } catch (error) {
      console.error(error);
      resetLoginProgress();
      showMessage(loginMessage, 'Login failed. Please try again.', 'error');
    }
  }

  async function logoutUser() {
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

  async function showStudentPage() {
    hideAllPages();
    appContainer.classList.add('portal-wide');
    studentPage.classList.remove('hidden');
    studentPage.innerHTML = `
      <div class="max-w-5xl mx-auto">
        <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
          <h1 class="text-3xl font-bold text-gray-800">Student Portal</h1>
          <button type="button" data-logout-button class="bg-red-600 text-white px-4 py-2 rounded-lg font-semibold">Logout</button>
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
            <label class="block text-sm font-medium text-gray-700">Student phone number
              <input id="student-profile-contact" type="tel" readonly required class="mt-2 w-full p-3 border border-gray-300 rounded-lg bg-gray-100" />
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
            <label class="block text-sm font-medium text-gray-700">Leave from
              <input id="student-leave-start" type="date" required class="mt-2 w-full p-3 border border-gray-300 rounded-lg" />
            </label>
            <label class="block text-sm font-medium text-gray-700">Leave to
              <input id="student-leave-end" type="date" required class="mt-2 w-full p-3 border border-gray-300 rounded-lg" />
            </label>
            <p id="student-leave-days" class="md:col-span-2 text-sm text-gray-600" aria-live="polite">Select both dates to see the number of leave days.</p>
            <label class="block text-sm font-medium text-gray-700 md:col-span-2">Reason for leave
              <textarea id="student-leave-reason" rows="3" maxlength="2000" required class="mt-2 w-full p-3 border border-gray-300 rounded-lg"></textarea>
            </label>
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
        <section class="bg-green-50 border border-green-200 rounded-xl p-5 shadow-sm">
          <h2 class="text-xl font-semibold text-green-800 mb-2">Gate Pass</h2>
          <div id="student-gate-pass"><p class="text-gray-500">No active gate pass available.</p></div>
        </section>
      </div>
    `;

    const sapInput = document.getElementById('student-sap-id');
    const schoolSelect = document.getElementById('student-school');
    const hostelSelect = document.getElementById('student-hostel');
    const startInput = document.getElementById('student-leave-start');
    const endInput = document.getElementById('student-leave-end');
    const submitButton = document.getElementById('student-submit-leave');
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
      const fields = {
        'student-profile-name': profile.name,
        'student-profile-contact': profile.student_contact,
        'student-profile-parent-email': profile.parent_email,
        'student-profile-parent-contact': profile.parent_contact,
        'student-profile-year': profile.year,
        'student-profile-course': profile.course,
        'student-profile-branch': profile.branch
      };
      Object.entries(fields).forEach(([id, value]) => {
        document.getElementById(id).value = value || '';
      });

      expectedSchool = ['BTech', 'MBATech'].includes(profile.course) ? 'MPSTME' : 'SPTM';
      schoolSelect.innerHTML = `
        <option value="MPSTME" ${expectedSchool === 'MPSTME' ? 'selected' : ''} ${expectedSchool !== 'MPSTME' ? 'disabled' : ''}>MPSTME</option>
        <option value="SPTM" ${expectedSchool === 'SPTM' ? 'selected' : ''} ${expectedSchool !== 'SPTM' ? 'disabled' : ''}>SPTM</option>
      `;
      const hostels = profile.gender === 'female'
        ? ['NEW GIRLS HOSTEL', 'OLD GIRLS HOSTEL']
        : ['BOYS HOSTEL 1', 'NEW BOYS HOSTEL'];
      hostelSelect.innerHTML = '<option value="">Select hostel</option>' + hostels.map(hostel => `<option value="${hostel}">${hostel}</option>`).join('');
      const profileComplete = Object.values(fields).every(value => Boolean(value)) && Boolean(profile.gender && expectedSchool);
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
      } else if (startInput.value >= endInput.value) {
        output.textContent = 'Leave end date must be after the start date.';
        output.className = 'md:col-span-2 text-sm text-red-600';
      } else {
        const start = new Date(`${startInput.value}T00:00:00Z`);
        const end = new Date(`${endInput.value}T00:00:00Z`);
        const days = Math.round((end - start) / 86400000) + 1;
        output.textContent = `${days} leave day${days === 1 ? '' : 's'} (counting both dates).`;
        output.className = 'md:col-span-2 text-sm text-gray-600';
      }
    };
    startInput.addEventListener('change', updateLeaveDays);
    endInput.addEventListener('change', updateLeaveDays);

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
      if (startInput.value >= endInput.value) {
        message.textContent = 'Leave end date must be after the start date.';
        message.className = 'text-sm text-red-600';
        return;
      }
      submitButton.disabled = true;
      const requestData = getCsrfFormData({
        sap_id: sapInput.value,
        start_date: startInput.value,
        end_date: endInput.value,
        reason: document.getElementById('student-leave-reason').value.trim(),
        school: schoolSelect.value,
        hostel: hostelSelect.value,
        details_confirmed: document.getElementById('student-details-confirmed').checked ? '1' : '0'
      });
      try {
        const response = await fetch('/api/index.php?endpoint=submit_student_leave.php', { method: 'POST', body: requestData });
        const result = await response.json();
        message.textContent = result.message || (result.success ? 'Request submitted.' : 'Unable to submit request.');
        message.className = `text-sm ${result.success ? 'text-green-700' : 'text-red-600'}`;
        if (result.success) {
          document.getElementById('student-leave-form').reset();
          sapInput.value = state.user.login_id;
          schoolSelect.value = expectedSchool;
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
    const changePasswordButton = document.getElementById('student-change-password-button');
    if (changePasswordButton) {
      changePasswordButton.addEventListener('click', openChangePasswordModal);
    }
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
      const result = await response.json();
      if (!result.success) {
        showMessage(message, result.message || 'Password change failed.', 'error');
        return;
      }

      showMessage(message, 'Password updated successfully.', 'success');
      setTimeout(() => closeChangePasswordModal(), 1200);
    } catch (error) {
      console.error(error);
      showMessage(message, 'Password update failed. Please try again.', 'error');
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
              <th class="py-3 px-4 text-left">From</th><th class="py-3 px-4 text-left">To</th>
              <th class="py-3 px-4 text-left">Days</th><th class="py-3 px-4 text-left">School / Hostel</th>
              <th class="py-3 px-4 text-left">Reason</th><th class="py-3 px-4 text-left">Status</th><th class="py-3 px-4 text-left">Pass</th>
            </tr></thead>
            <tbody>${items.map(item => `
              <tr class="border-b border-gray-200 hover:bg-gray-100">
                <td class="py-3 px-4">${item.start_date}</td><td class="py-3 px-4">${item.end_date}</td>
                <td class="py-3 px-4">${item.leave_days}</td><td class="py-3 px-4">${item.school || '-'} / ${item.hostel || '-'}</td>
                <td class="py-3 px-4">${item.reason}</td><td class="py-3 px-4">${item.status}</td>
                <td class="py-3 px-4">${item.status === 'Granted' && item.qr_code_data ? `<button type="button" class="bg-blue-600 text-white px-3 py-1 rounded" data-qrcode="${item.id}">View</button>` : '-'}</td>
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
      renderGatePass(items.find(item => item.status === 'Granted'));
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
    target.innerHTML = `
      <p class="text-green-800 font-semibold">Pass token: ${pass.pass_token || 'Not available'}</p>
      <p class="text-sm text-gray-700 mb-4">Valid ${pass.start_date} to ${pass.end_date}</p>
      <div class="flex justify-center mb-4"><div class="p-4 bg-white rounded shadow-md"><div id="gate-pass-qr" role="img" aria-label="Gate pass QR code"></div></div></div>
      <div class="flex flex-wrap gap-3">
        <button type="button" id="download-gate-pass-qr" class="bg-blue-600 text-white px-4 py-2 rounded">Download QR</button>
        <button type="button" id="download-student-pdf" class="bg-purple-600 text-white px-4 py-2 rounded">Download PDF</button>
      </div>
    `;
    const qrContainer = document.getElementById('gate-pass-qr');
    if (typeof window.QRCode === 'function') {
      new window.QRCode(qrContainer, { text: pass.qr_code_data, width: 200, height: 200, correctLevel: window.QRCode.CorrectLevel.M });
    } else {
      qrContainer.textContent = 'QR generator unavailable. Use the pass token at the gate.';
    }
    document.getElementById('download-gate-pass-qr').addEventListener('click', () => {
      const canvas = qrContainer.querySelector('canvas');
      if (!canvas) return;
      const link = document.createElement('a');
      link.href = canvas.toDataURL('image/png');
      link.download = `gate-pass-${pass.sap_id}.png`;
      link.click();
    });
    document.getElementById('download-student-pdf').addEventListener('click', () => {
      if (!window.jspdf?.jsPDF) return;
      const pdf = new window.jspdf.jsPDF({ unit: 'mm', format: 'a4' });
      const pageWidth = pdf.internal.pageSize.getWidth();
      const pageHeight = pdf.internal.pageSize.getHeight();
      const margin = 14;
      const studentName = state.user?.name || 'Student';

      pdf.setFillColor(248, 250, 252);
      pdf.rect(10, 10, pageWidth - 20, pageHeight - 20, 'F');
      pdf.setDrawColor(30, 64, 175);
      pdf.roundedRect(10, 10, pageWidth - 20, pageHeight - 20, 4, 4, 'S');

      pdf.setTextColor(17, 24, 39);
      pdf.setFont('helvetica', 'bold');
      pdf.setFontSize(20);
      pdf.text('NMIMS Leave - Gate Pass', margin, 24);
      pdf.setFontSize(10);
      pdf.setFont('helvetica', 'normal');
      pdf.text('This is your official digital gate pass.', margin, 31);

      pdf.setDrawColor(148, 163, 184);
      pdf.line(margin, 36, pageWidth - margin, 36);

      pdf.setFont('helvetica', 'bold');
      pdf.setTextColor(31, 41, 55);
      pdf.setFontSize(12);
      pdf.text(`Student Name: ${studentName}`, margin, 48);
      pdf.text(`Student ID: ${pass.sap_id}`, margin, 56);
      pdf.text(`Branch: ${pass.school || 'N/A'}`, margin, 64);
      pdf.text(`Batch: ${pass.hostel || 'N/A'}`, margin, 72);
      pdf.text(`Leave From: ${pass.start_date}`, margin, 84);
      pdf.text(`Leave Till: ${pass.end_date}`, margin, 92);
      pdf.text(`Leave Days: ${pass.leave_days || 1}`, margin, 100);
      pdf.text(`Reason: ${pass.reason || 'Not specified'}`, margin, 112, { maxWidth: pageWidth - (margin * 2) - 40 });
      pdf.text(`Attendance: 85.00%`, margin, 128);

      pdf.setTextColor(16, 185, 129);
      pdf.setFont('helvetica', 'bold');
      pdf.setFontSize(18);
      pdf.text('Approval Status:', margin, 146);
      pdf.setTextColor(37, 99, 235);
      pdf.setFontSize(11);
      pdf.text(`Teacher: ${state.user?.name || 'Approved'}`, margin, 156);
      pdf.text(`HOD: Approved`, margin, 164);
      pdf.text(`Dean: Approved`, margin, 172);

      pdf.setTextColor(17, 24, 39);
      pdf.setFontSize(11);
      pdf.text('Parent Contact Details:', margin, 190);
      pdf.text(`Email: ${state.user?.email || 'parent@example.com'}`, margin, 198);
      pdf.text(`Contact No: ${state.user?.phone || '9876543210'}`, margin, 206);

      pdf.setTextColor(200, 30, 30);
      pdf.saveGraphicsState();
      pdf.translate(150, 120);
      pdf.rotate(-35);
      pdf.setFont('helvetica', 'bold');
      pdf.setFontSize(26);
      pdf.text('APPROVED', 0, 0);
      pdf.restoreGraphicsState();

      pdf.setTextColor(17, 24, 39);
      pdf.setFont('helvetica', 'normal');
      pdf.setFontSize(9);
      pdf.text(`Generated on: ${new Date().toLocaleString()}`, margin, pageHeight - 18);
      pdf.save(`gate-pass-${pass.sap_id}.pdf`);
    });
  }

  function showWardenPage() {
    hideAllPages();
    appContainer.classList.add('portal-wide');
    wardenPage.classList.remove('hidden');
    const hostelGroup = state.user?.warden_gender === 'female' ? 'Girls Hostel' : 'Boys Hostel';
    wardenPage.innerHTML = `
      <div class="max-w-5xl mx-auto">
        <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
          <div><h1 class="text-3xl font-bold text-gray-800">${hostelGroup} Warden</h1><p class="text-sm text-gray-600 mt-1">Signed in as ${state.user?.name || 'Warden'}</p></div>
          <button type="button" data-logout-button class="bg-red-600 text-white px-4 py-2 rounded-lg font-semibold">Logout</button>
        </div>
        <section class="bg-white rounded-xl p-5 border border-gray-200 shadow-sm">
          <h2 class="text-2xl font-semibold text-gray-700 mb-4">Pending Verification</h2>
          <div id="warden-pending-list" class="space-y-4"></div>
        </section>
      </div>
    `;
    refreshPendingRequests();
  }

  async function refreshPendingRequests() {
    const target = document.getElementById('warden-pending-list');
    if (!target) return;
    try {
      const response = await fetch('/api/index.php?endpoint=list_leaves.php&status=Pending%20Verification&limit=20');
      const result = await response.json();
      const items = result.items || [];
      if (!items.length) {
        target.innerHTML = '<p class="text-gray-500">No pending verification requests.</p>';
        return;
      }
      target.innerHTML = items.map(item => `
        <article class="border border-gray-200 rounded-lg p-4 bg-gray-50">
          <p><strong>${item.name}</strong> (${item.sap_id})</p>
          <p>${item.course || ''} | ${item.year || ''} | ${item.gender || ''}</p>
          <p>Dates: ${item.start_date} to ${item.end_date} (${item.leave_days} days)</p>
          <p>School / Hostel: ${item.school || '-'} / ${item.hostel || '-'}</p>
          <p>Reason: ${item.reason}</p>
          <p>Call parent: ${item.parent_contact || 'Contact unavailable'} | ${item.parent_email || ''}</p>
          <div class="mt-3 flex flex-wrap gap-2">
            <button type="button" class="bg-green-600 text-white px-3 py-2 rounded" data-leave-id="${item.id}" data-outcome="Confirmed" data-called-number="${item.parent_contact || ''}">Confirmed</button>
            <button type="button" class="bg-red-600 text-white px-3 py-2 rounded" data-leave-id="${item.id}" data-outcome="Denied" data-called-number="${item.parent_contact || ''}">Denied</button>
            <button type="button" class="bg-yellow-500 text-white px-3 py-2 rounded" data-leave-id="${item.id}" data-outcome="Not Reachable" data-called-number="${item.parent_contact || ''}">Not Reachable</button>
            <button type="button" class="bg-gray-700 text-white px-3 py-2 rounded" data-leave-id="${item.id}" data-outcome="Wrong Person" data-called-number="${item.parent_contact || ''}">Wrong Person</button>
          </div>
          <input type="text" data-remarks="${item.id}" class="mt-3 w-full p-2 border border-gray-300 rounded-lg" placeholder="Call remarks" />
        </article>
      `).join('');
      target.querySelectorAll('[data-outcome]').forEach(button => {
        button.addEventListener('click', async () => {
          const leaveId = button.dataset.leaveId;
          const data = getCsrfFormData({
            leave_id: leaveId,
            outcome: button.dataset.outcome,
            called_number: button.dataset.calledNumber || '',
            remarks: target.querySelector(`[data-remarks="${leaveId}"]`)?.value || ''
          });
          try {
            const response = await fetch('/api/index.php?endpoint=record_call.php', { method: 'POST', body: data });
            const result = await response.json();
            if (!result.success) window.alert(result.message || 'Call result update failed.');
            await refreshPendingRequests();
          } catch (error) {
            console.error(error);
            window.alert('Call result failed.');
          }
        });
      });
    } catch (error) {
      console.error(error);
      target.innerHTML = '<p class="text-red-600">Unable to load pending requests.</p>';
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
            <label class="block text-sm font-medium text-gray-700">Student SAP ID<input id="security-sap-id" type="text" class="mt-2 w-full p-3 border border-gray-300 rounded-lg" placeholder="11-digit SAP ID" /></label>
            <label class="block text-sm font-medium text-gray-700">Pass token<input id="security-pass-token" type="text" class="mt-2 w-full p-3 border border-gray-300 rounded-lg" placeholder="Optional pass token" /></label>
          </div>
          <button id="verify-pass-btn" type="button" class="mt-5 bg-blue-600 text-white px-5 py-3 rounded-lg font-semibold">Verify Pass</button>
          <div id="security-result" class="mt-4 hidden"></div>
        </section>
      </div>
    `;
    document.getElementById('verify-pass-btn').addEventListener('click', async () => {
      const sapId = document.getElementById('security-sap-id').value.trim();
      const token = document.getElementById('security-pass-token').value.trim();
      if (!sapId && !token) {
        showMessage(document.getElementById('security-result'), 'Enter an SAP ID or pass token.', 'error');
        return;
      }
      const params = new URLSearchParams();
      if (sapId) params.set('sap_id', sapId);
      if (token) params.set('token', token);
      try {
        const response = await fetch(`/api/index.php?endpoint=verify_pass.php&${params.toString()}`);
        const result = await response.json();
        if (!result.success) showMessage(document.getElementById('security-result'), result.message || 'Pass verification failed.', 'error');
        else showMessage(document.getElementById('security-result'), `Verified: ${result.student_name} | Status: ${result.status} | Valid QR: ${result.valid_qr ? 'Yes' : 'No'}`, 'success');
      } catch (error) {
        console.error(error);
        showMessage(document.getElementById('security-result'), 'Verification request failed.', 'error');
      }
    });
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
          <p class="text-sm text-gray-600 mb-3"><a href="student_template.csv" class="text-blue-600 underline">Student CSV template</a></p>
          <form id="student-import-form" enctype="multipart/form-data">
            <input type="file" name="students_csv" accept=".csv" required class="block w-full text-sm text-gray-500" />
            <button type="submit" class="mt-4 bg-green-600 text-white px-5 py-3 rounded-lg font-semibold">Import Students</button>
          </form>
          <div id="admin-import-result" class="mt-4 hidden"></div>
        </section>
      </div>
    `;
    document.getElementById('student-import-form').addEventListener('submit', async event => {
      event.preventDefault();
      const formData = new FormData(event.currentTarget);
      formData.append('csrf_token', state.csrfToken);
      try {
        const response = await fetch('/api/index.php?endpoint=import_students.php', { method: 'POST', body: formData });
        const result = await response.json();
        showMessage(document.getElementById('admin-import-result'), result.message || 'Import complete.', result.success ? 'success' : 'error');
      } catch (error) {
        console.error(error);
        showMessage(document.getElementById('admin-import-result'), 'Import failed.', 'error');
      }
    });
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
  roleSelect.addEventListener('change', updateRoleTitle);
  document.addEventListener('click', event => {
    if (event.target.closest('[data-logout-button]')) logoutModal.classList.remove('hidden');
  });
  document.getElementById('cancel-change-password')?.addEventListener('click', closeChangePasswordModal);
  document.getElementById('close-change-password-modal')?.addEventListener('click', closeChangePasswordModal);
  document.getElementById('change-password-form')?.addEventListener('submit', handleChangePassword);
  document.getElementById('confirm-logout').addEventListener('click', logoutUser);
  document.getElementById('cancel-logout').addEventListener('click', () => logoutModal.classList.add('hidden'));
  updateRoleTitle();
  showLogin();
});
