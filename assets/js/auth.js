/**
 * CHAMA Financial Management System
 * Auth Page JavaScript
 */

document.addEventListener('DOMContentLoaded', function () {

    // ── Toggle password visibility ─────────────────────────────────────────────
    document.querySelectorAll('.toggle-pw').forEach(btn => {
        btn.addEventListener('click', function () {
            const targetId = this.dataset.target;
            const input    = document.getElementById(targetId);
            const icon     = this.querySelector('i');

            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.replace('bi-eye', 'bi-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.replace('bi-eye-slash', 'bi-eye');
            }
        });
    });

    // ── Password strength indicator ───────────────────────────────────────────
    const pwInput    = document.getElementById('password');
    const strengthBar = document.getElementById('strengthBar');

    if (pwInput && strengthBar) {
        pwInput.addEventListener('input', function () {
            const val = this.value;
            let score = 0;

            if (val.length >= 8)              score++;
            if (val.length >= 12)             score++;
            if (/[A-Z]/.test(val))            score++;
            if (/[0-9]/.test(val))            score++;
            if (/[^A-Za-z0-9]/.test(val))     score++;

            const widths  = ['0%', '20%', '40%', '60%', '80%', '100%'];
            const colors  = ['#e2e8f0', '#ef4444', '#f97316', '#eab308', '#22c55e', '#16a34a'];
            const labels  = ['', 'Very Weak', 'Weak', 'Fair', 'Strong', 'Very Strong'];

            strengthBar.style.setProperty('--strength-width', widths[score]);
            strengthBar.style.setProperty('--strength-color', colors[score]);
            strengthBar.title = labels[score];
        });
    }

    // ── Password match check ──────────────────────────────────────────────────
    const pw2Input = document.getElementById('password_confirm');
    const matchMsg = document.getElementById('matchMsg');

    if (pw2Input && matchMsg && pwInput) {
        pw2Input.addEventListener('input', function () {
            if (this.value === '') {
                matchMsg.className = 'd-none';
                return;
            }
            if (this.value === pwInput.value) {
                matchMsg.textContent = '✓ Passwords match';
                matchMsg.className   = 'small text-success';
            } else {
                matchMsg.textContent = '✗ Passwords do not match';
                matchMsg.className   = 'small text-danger';
            }
        });
    }

    // ── Prevent double submit ─────────────────────────────────────────────────
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        form.addEventListener('submit', function () {
            const btn = this.querySelector('[type=submit]');
            if (btn) {
                btn.disabled    = true;
                btn.innerHTML   = '<span class="spinner-border spinner-border-sm me-2"></span>Please wait…';
            }
        });
    });
});
