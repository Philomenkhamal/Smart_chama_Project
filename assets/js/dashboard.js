/**
 * ChamaLedger — Dashboard JavaScript
 */

document.addEventListener('DOMContentLoaded', function () {

    // ── Mobile sidebar toggle ──────────────────────────────────────────────────
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebar       = document.getElementById('sidebar');
    if (sidebarToggle && sidebar) {
        sidebarToggle.addEventListener('click', () => sidebar.classList.toggle('open'));
        document.addEventListener('click', e => {
            if (!sidebar.contains(e.target) && !sidebarToggle.contains(e.target)) {
                sidebar.classList.remove('open');
            }
        });
    }

    // ── Notifications ─────────────────────────────────────────────────────────
    const notifDropdown = document.getElementById('notifDropdown');
    const notifList     = document.getElementById('notifList');
    const markAllBtn    = document.getElementById('markAllRead');
    const APP_URL       = document.body.dataset.appUrl || '';

    const typeIcon = {
        info:    '<span style="color:#3b82f6;font-size:1.1rem">🔵</span>',
        success: '<span style="color:#22c55e;font-size:1.1rem">✅</span>',
        warning: '<span style="color:#f59e0b;font-size:1.1rem">⚠️</span>',
        danger:  '<span style="color:#ef4444;font-size:1.1rem">🔴</span>',
    };

    function loadNotifications() {
        if (!notifList) return;
        notifList.innerHTML = '<div class="px-3 py-3 text-muted small text-center"><span class="spinner-border spinner-border-sm me-1"></span> Loading…</div>';

        fetch(APP_URL + '/api/notifications.php', { credentials: 'same-origin' })
            .then(r => r.json())
            .then(data => {
                if (!data.notifications || data.notifications.length === 0) {
                    notifList.innerHTML = '<div class="px-3 py-4 text-muted small text-center"><i class="bi bi-bell-slash d-block mb-2" style="font-size:1.5rem"></i>No notifications yet</div>';
                    return;
                }
                notifList.innerHTML = data.notifications.map(n => {
                    const icon = typeIcon[n.type] || typeIcon.info;
                    const unreadStyle = !n.is_read
                        ? 'background:rgba(59,130,246,0.08);border-left:3px solid #3b82f6;'
                        : 'border-left:3px solid transparent;';
                    // Use data-link and data-id instead of href so Bootstrap dropdown doesn't fight us
                    return `
                    <div class="notif-item px-3 py-2 border-bottom"
                         style="cursor:pointer;${unreadStyle}"
                         data-id="${n.id}"
                         data-link="${escHtml(n.link || '')}">
                        <div class="d-flex gap-2 align-items-start">
                            <div class="flex-shrink-0 pt-1">${icon}</div>
                            <div class="overflow-hidden flex-grow-1">
                                <div class="fw-semibold small ${!n.is_read ? 'text-white' : 'text-muted'}" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                                    ${escHtml(n.title)}
                                </div>
                                <div class="text-muted small" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:.75rem">
                                    ${escHtml(n.message)}
                                </div>
                                <div class="text-muted" style="font-size:.68rem;margin-top:2px">${timeAgo(n.created_at)}</div>
                            </div>
                            ${!n.is_read ? '<div class="flex-shrink-0"><span style="width:7px;height:7px;background:#3b82f6;border-radius:50%;display:inline-block;margin-top:6px"></span></div>' : ''}
                        </div>
                    </div>`;
                }).join('');

                // Attach click handlers to each item
                notifList.querySelectorAll('.notif-item').forEach(item => {
                    item.addEventListener('click', function () {
                        const nid  = this.dataset.id;
                        const link = this.dataset.link;

                        // Mark this notification as read
                        fetch(APP_URL + '/api/notifications.php?mark_one=' + nid, { credentials: 'same-origin' });

                        // Mark as read visually immediately
                        this.style.background = '';
                        this.style.borderLeft = '3px solid transparent';
                        const dot = this.querySelector('[style*="border-radius:50%"]');
                        if (dot) dot.remove();

                        // Navigate if there's a real link
                        if (link && link !== '#' && link !== '') {
                            // Close dropdown first, then navigate
                            const bsDropdown = bootstrap.Dropdown.getInstance(document.getElementById('notifBtn'));
                            if (bsDropdown) bsDropdown.hide();
                            setTimeout(() => { window.location.href = link; }, 100);
                        }
                    });
                });
            })
            .catch(() => {
                if (notifList) notifList.innerHTML = '<div class="px-3 py-2 text-muted small text-center">Could not load notifications.</div>';
            });
    }

    // Load when dropdown opens
    if (notifDropdown) {
        const btn = document.getElementById('notifBtn');
        btn?.addEventListener('click', loadNotifications);
    }

    // Mark ALL read
    if (markAllBtn) {
        markAllBtn.addEventListener('click', function (e) {
            e.preventDefault();
            fetch(APP_URL + '/api/notifications.php?mark_all', { method: 'POST', credentials: 'same-origin' })
                .then(() => {
                    loadNotifications();
                    const badge = document.querySelector('.badge-dot');
                    if (badge) badge.style.display = 'none';
                });
        });
    }

    // ── Confirm dialogs ────────────────────────────────────────────────────────
    document.querySelectorAll('[data-confirm]').forEach(el => {
        el.addEventListener('click', function (e) {
            if (!confirm(this.dataset.confirm)) e.preventDefault();
        });
    });

    // ── Auto-dismiss alerts after 6s ──────────────────────────────────────────
    document.querySelectorAll('.alert:not(.alert-permanent)').forEach(alert => {
        setTimeout(() => {
            const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
            bsAlert?.close();
        }, 6000);
    });

    // ── Utilities ──────────────────────────────────────────────────────────────
    function escHtml(str) {
        const d = document.createElement('div');
        d.textContent = str ?? '';
        return d.innerHTML;
    }

    function timeAgo(dateStr) {
        if (!dateStr) return '';
        const diff = Math.floor((Date.now() - new Date(dateStr)) / 1000);
        if (diff < 60)    return 'Just now';
        if (diff < 3600)  return Math.floor(diff / 60) + 'm ago';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
        return Math.floor(diff / 86400) + 'd ago';
    }

    // ── Money formatter ────────────────────────────────────────────────────────
    window.formatMoney = function (amount, currency = 'KES') {
        return currency + ' ' + Number(amount).toLocaleString('en-KE', { minimumFractionDigits: 2 });
    };

    // ── Loan calculator ────────────────────────────────────────────────────────
    const loanCalc = document.getElementById('loanCalculator');
    if (loanCalc) {
        const amountInput = document.getElementById('calc_amount');
        const rateInput   = document.getElementById('calc_rate');
        const monthsInput = document.getElementById('calc_months');
        const calcResult  = document.getElementById('calcResult');

        function recalculate() {
            const P = parseFloat(amountInput?.value) || 0;
            const r = parseFloat(rateInput?.value)   || 0;
            const n = parseInt(monthsInput?.value)   || 0;
            if (P <= 0 || r <= 0 || n <= 0) return;
            const totalInterest  = P * (r / 100) * n;
            const totalRepayable = P + totalInterest;
            const monthly        = totalRepayable / n;
            if (calcResult) {
                calcResult.innerHTML = `
                    <div class="row g-2 text-center small mt-2">
                        <div class="col-4">
                            <div class="bg-light rounded p-2">
                                <div class="text-muted">Total Interest</div>
                                <strong>${formatMoney(totalInterest)}</strong>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="bg-primary text-white rounded p-2">
                                <div>Total Repayable</div>
                                <strong>${formatMoney(totalRepayable)}</strong>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="bg-light rounded p-2">
                                <div class="text-muted">Monthly Payment</div>
                                <strong>${formatMoney(monthly)}</strong>
                            </div>
                        </div>
                    </div>`;
            }
        }
        [amountInput, rateInput, monthsInput].forEach(el => el?.addEventListener('input', recalculate));
    }

});
