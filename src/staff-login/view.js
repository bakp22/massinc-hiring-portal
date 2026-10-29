(function () {
    var EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

    function setFieldError(field, message) {
        var el = field.querySelector('.massinc-field-error');
        el.textContent = message || '';
        el.hidden = !message;
        field.classList.toggle('has-error', !!message);
        var input = field.querySelector('input');
        if (input) {
            input.setAttribute('aria-invalid', message ? 'true' : 'false');
        }
    }

    function init(block) {
        var form = block.querySelector('#massinc-login-form');
        if (!form) {
            return;
        }

        var summary = form.querySelector('.massinc-form-error-summary');
        var submit = form.querySelector('.massinc-submit');
        var emailField = form.querySelector('[data-field="email"]');
        var passwordField = form.querySelector('[data-field="password"]');
        var emailInput = emailField.querySelector('input');
        var passwordInput = passwordField.querySelector('input');

        emailInput.addEventListener('input', function () {
            if (emailField.classList.contains('has-error')) {
                setFieldError(emailField, '');
            }
        });

        passwordInput.addEventListener('input', function () {
            if (passwordField.classList.contains('has-error')) {
                setFieldError(passwordField, '');
            }
        });

        function validate() {
            var firstInvalid = null;

            var email = emailInput.value.trim();
            if (!email) {
                setFieldError(emailField, 'Please enter your email address.');
                firstInvalid = firstInvalid || emailInput;
            } else if (!EMAIL_PATTERN.test(email)) {
                setFieldError(
                    emailField,
                    'Please enter a valid email address, like name@massinc.org.'
                );
                firstInvalid = firstInvalid || emailInput;
            } else {
                setFieldError(emailField, '');
            }

            if (!passwordInput.value) {
                setFieldError(passwordField, 'Please enter your password.');
                firstInvalid = firstInvalid || passwordInput;
            } else {
                setFieldError(passwordField, '');
            }

            if (firstInvalid) {
                firstInvalid.focus();
            }

            return !firstInvalid;
        }

        form.addEventListener('submit', function (event) {
            summary.hidden = true;
            if (!validate()) {
                event.preventDefault();
                return;
            }

            submit.disabled = true;
            submit.textContent = 'Signing in…';
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document
            .querySelectorAll('.massinc-staff-login-block')
            .forEach(init);
    });
})();
