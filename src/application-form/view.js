(function () {
    var MAX_BYTES = 10 * 1024 * 1024;
    var MAX_LINKS = 5;
    var ALLOWED_EXTENSIONS = ['pdf', 'doc', 'docx'];
    var EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

    function formatSize(bytes) {
        if (bytes >= 1024 * 1024) {
            return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
        }
        return Math.max(1, Math.round(bytes / 1024)) + ' KB';
    }

    function extensionOf(name) {
        var dot = name.lastIndexOf('.');
        return dot === -1 ? '' : name.slice(dot + 1).toLowerCase();
    }

    // Returns an error message, or '' if the file is acceptable.
    function validateFile(file) {
        if (ALLOWED_EXTENSIONS.indexOf(extensionOf(file.name)) === -1) {
            return (
                '"' +
                file.name +
                '" isn\'t a supported file type. Please upload a PDF, DOC, or DOCX.'
            );
        }
        if (file.size === 0) {
            return (
                '"' + file.name + '" is empty. Please choose a different file.'
            );
        }
        if (file.size > MAX_BYTES) {
            return (
                '"' +
                file.name +
                '" is ' +
                formatSize(file.size) +
                ', which is over the 10 MB limit.'
            );
        }
        return '';
    }

    function isValidUrl(value) {
        try {
            var url = new URL(value);
            return url.protocol === 'http:' || url.protocol === 'https:';
        } catch (e) {
            return false;
        }
    }

    function setFieldError(field, message) {
        var el = field.querySelector('.massinc-field-error');
        el.textContent = message || '';
        el.hidden = !message;
        field.classList.toggle('has-error', !!message);
    }

    function initUpload(field, state) {
        var name = field.dataset.field;
        var max = parseInt(field.dataset.maxFiles, 10);
        var input = field.querySelector('.massinc-file-input');
        var dropzone = field.querySelector('.massinc-dropzone');
        var list = field.querySelector('.massinc-file-list');
        state[name] = [];

        function render() {
            list.innerHTML = '';
            state[name].forEach(function (file, index) {
                var item = document.createElement('li');
                item.className = 'massinc-file';

                var badge = document.createElement('span');
                badge.className = 'massinc-file-badge';
                badge.textContent = extensionOf(file.name).toUpperCase();

                var details = document.createElement('span');
                details.className = 'massinc-file-details';
                var title = document.createElement('span');
                title.className = 'massinc-file-name';
                title.textContent = file.name;
                var meta = document.createElement('span');
                meta.className = 'massinc-file-meta';
                meta.textContent = formatSize(file.size);
                details.appendChild(title);
                details.appendChild(meta);

                var remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'massinc-file-remove';
                remove.textContent = 'Remove';
                remove.setAttribute('aria-label', 'Remove ' + file.name);
                remove.addEventListener('click', function () {
                    state[name].splice(index, 1);
                    setFieldError(field, '');
                    render();
                });

                item.appendChild(badge);
                item.appendChild(details);
                item.appendChild(remove);
                list.appendChild(item);
            });

            // A single-file field hides the picker once it has its file.
            dropzone.hidden = max === 1 && state[name].length >= 1;
        }

        function addFiles(fileList) {
            var errors = [];
            Array.prototype.forEach.call(fileList, function (file) {
                var problem = validateFile(file);
                if (!problem && state[name].length >= max) {
                    problem =
                        max === 1
                            ? 'Only one file can be attached here. Remove the current one first.'
                            : 'You can attach up to ' + max + ' files here.';
                }
                var duplicate = state[name].some(function (existing) {
                    return (
                        existing.name === file.name &&
                        existing.size === file.size
                    );
                });
                if (!problem && duplicate) {
                    problem = '"' + file.name + '" is already attached.';
                }
                if (problem) {
                    errors.push(problem);
                } else {
                    state[name].push(file);
                }
            });
            setFieldError(field, errors.join(' '));
            input.value = '';
            render();
        }

        input.addEventListener('change', function () {
            addFiles(input.files);
        });

        ['dragenter', 'dragover'].forEach(function (type) {
            dropzone.addEventListener(type, function (event) {
                event.preventDefault();
                dropzone.classList.add('is-dragging');
            });
        });
        ['dragleave', 'drop'].forEach(function (type) {
            dropzone.addEventListener(type, function (event) {
                event.preventDefault();
                dropzone.classList.remove('is-dragging');
            });
        });
        dropzone.addEventListener('drop', function (event) {
            addFiles(event.dataTransfer.files);
        });

        render();
    }

    function init(block) {
        var form = block.querySelector('#massinc-app-form');
        var success = block.querySelector('.massinc-form-success');
        var summary = block.querySelector('.massinc-form-error-summary');
        var submit = block.querySelector('.massinc-submit');
        var emailInput = form.elements.applicant_email;
        var confirmation = block.querySelector('.massinc-confirmation-note');
        var files = {};

        function fieldFor(name) {
            return form.querySelector('[data-field="' + name + '"]');
        }

        block.querySelectorAll('.massinc-upload').forEach(function (field) {
            initUpload(field, files);
        });

        var linkField = fieldFor('sample_url');
        var linkList = linkField.querySelector('.massinc-link-list');
        var addLink = linkField.querySelector('.massinc-add-link');

        function sampleLinks() {
            return Array.prototype.slice.call(
                linkList.querySelectorAll('input')
            );
        }

        addLink.addEventListener('click', function () {
            var row = document.createElement('div');
            row.className = 'massinc-link-row';

            var input = document.createElement('input');
            input.type = 'url';
            input.name = 'sample_url';
            input.placeholder = 'https://';
            input.setAttribute(
                'aria-label',
                'Additional link ' + sampleLinks().length
            );

            var remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'massinc-file-remove';
            remove.textContent = 'Remove';
            remove.setAttribute('aria-label', 'Remove link');
            remove.addEventListener('click', function () {
                row.remove();
                addLink.hidden = false;
                addLink.focus();
            });

            row.appendChild(input);
            row.appendChild(remove);
            linkList.appendChild(row);
            input.focus();
            addLink.hidden = sampleLinks().length >= MAX_LINKS;
        });

        emailInput.addEventListener('input', function () {
            var value = emailInput.value.trim();
            confirmation.hidden = !EMAIL_PATTERN.test(value);
            confirmation.querySelector(
                '.massinc-confirmation-email'
            ).textContent = value;
        });

        // Returns true if everything is valid; marks each failing field.
        function validate() {
            var firstInvalid = null;
            function fail(name, message) {
                var field = fieldFor(name);
                setFieldError(field, message);
                firstInvalid = firstInvalid || field;
            }

            ['first_name', 'last_name'].forEach(function (name) {
                var label = name === 'first_name' ? 'first name' : 'last name';
                if (!form.elements[name].value.trim()) {
                    fail(name, 'Please enter your ' + label + '.');
                } else {
                    setFieldError(fieldFor(name), '');
                }
            });

            var email = emailInput.value.trim();
            if (!email) {
                fail('applicant_email', 'Please enter your email address.');
            } else if (!EMAIL_PATTERN.test(email)) {
                fail(
                    'applicant_email',
                    'Please enter a valid email address, like name@example.com.'
                );
            } else {
                setFieldError(fieldFor('applicant_email'), '');
            }

            if (files.resume.length === 0) {
                fail('resume', 'Please attach your resume.');
            }

            var badLink = sampleLinks().filter(function (input) {
                var value = input.value.trim();
                var invalid = value !== '' && !isValidUrl(value);
                input.setAttribute('aria-invalid', invalid ? 'true' : 'false');
                return invalid;
            })[0];
            if (badLink) {
                fail(
                    'sample_url',
                    'Please enter a full link starting with http:// or https://.'
                );
            } else {
                setFieldError(fieldFor('sample_url'), '');
            }

            if (firstInvalid) {
                var focusable = firstInvalid.querySelector(
                    'input:not([type="hidden"])'
                );
                if (focusable) {
                    focusable.focus();
                }
            }
            return !firstInvalid;
        }

        function setConfirm(name, text) {
            success
                .querySelectorAll('[data-confirm="' + name + '"]')
                .forEach(function (el) {
                    el.textContent = text;
                });
        }

        function showConfirmation(body) {
            var now = new Date();
            var attached = []
                .concat(files.resume, files.cover_letter, files.writing_samples)
                .map(function (file) {
                    return file.name;
                });

            setConfirm('first_name', form.elements.first_name.value.trim());
            setConfirm('email', emailInput.value.trim());
            setConfirm('reference', String(body.application_id));
            setConfirm(
                'submitted',
                now.toLocaleDateString('en-GB', {
                    day: 'numeric',
                    month: 'short',
                    year: 'numeric'
                }) +
                    ' at ' +
                    now.toLocaleTimeString('en-US', {
                        hour: 'numeric',
                        minute: '2-digit',
                        timeZoneName: 'short'
                    })
            );
            setConfirm('attached', attached.join(', '));
            success.querySelector('[data-confirm-row="attached"]').hidden =
                attached.length === 0;

            block.classList.add('is-submitted');
            form.hidden = true;
            success.hidden = false;
            window.scrollTo({
                top: block.getBoundingClientRect().top + window.scrollY - 24,
                behavior: 'smooth'
            });
        }

        function showServerError(body, status) {
            var params = body && body.data && body.data.params;
            var shown = false;
            if (params) {
                Object.keys(params).forEach(function (name) {
                    var field = fieldFor(name);
                    if (field) {
                        setFieldError(field, 'Please check this field.');
                        shown = true;
                    }
                });
            }
            summary.textContent =
                status === 409 || !shown
                    ? (body && body.message) ||
                      'Something went wrong. Please try again.'
                    : 'Please fix the highlighted fields and try again.';
            summary.hidden = false;
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            summary.hidden = true;
            if (!validate()) {
                return;
            }

            // NOTE: files are validated and held above, but not sent yet. They
            // go in the request once POST /applications accepts multipart
            // (attachment model + server-side upload work).
            var payload = {
                first_name: form.elements.first_name.value.trim(),
                last_name: form.elements.last_name.value.trim(),
                applicant_email: emailInput.value.trim(),
                phone: form.elements.phone.value.trim(),
                job_id: parseInt(form.elements.job_id.value, 10)
            };

            submit.disabled = true;
            submit.textContent = 'Submitting…';

            fetch(block.dataset.endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
                .then(function (response) {
                    return response
                        .json()
                        .catch(function () {
                            return {};
                        })
                        .then(function (body) {
                            return {
                                ok: response.ok,
                                status: response.status,
                                body: body
                            };
                        });
                })
                .then(function (result) {
                    if (result.ok) {
                        showConfirmation(result.body);
                        return;
                    }
                    showServerError(result.body, result.status);
                })
                .catch(function () {
                    summary.textContent =
                        "We couldn't reach the server. Check your connection and try again.";
                    summary.hidden = false;
                })
                .finally(function () {
                    submit.disabled = false;
                    submit.textContent = 'Submit application';
                });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document
            .querySelectorAll('.massinc-application-form-block[data-endpoint]')
            .forEach(init);
    });
})();
