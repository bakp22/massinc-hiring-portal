(function () {
    var STAR_POINTS =
        '11,1.5 13.9,7.9 20.8,8.5 15.6,13.1 17.1,20 11,16.5 4.9,20 6.4,13.1 1.2,8.5 8.1,7.9';

    // Same markup as minc_hiring_star() in inc/ats-view.php.
    function starHtml(fill) {
        var svg =
            '<svg viewBox="0 0 22 22" width="22" height="22" aria-hidden="true" focusable="false"><polygon points="' +
            STAR_POINTS +
            '"/></svg>';
        return (
            '<span class="massinc-star is-' + fill + '">' + svg +
            '<span class="massinc-star-fill">' + svg + '</span></span>'
        );
    }

    // Five stars for an average, rounded to the nearest half.
    function starsHtml(value) {
        var rounded = Math.round(value * 2) / 2;
        var html = '<span class="massinc-stars" aria-hidden="true">';
        for (var i = 1; i <= 5; i++) {
            html += starHtml(rounded >= i ? 'full' : rounded >= i - 0.5 ? 'half' : 'empty');
        }
        return html + '</span>';
    }

    function today() {
        return new Date().toLocaleDateString('en-GB', {
            day: 'numeric',
            month: 'short',
            year: 'numeric'
        });
    }

    function initials(name) {
        return name
            .trim()
            .split(/\s+/)
            .slice(0, 2)
            .map(function (part) {
                return part.charAt(0).toUpperCase();
            })
            .join('');
    }

    function plural(count, one, many) {
        return count + ' ' + (count === 1 ? one : many);
    }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text !== undefined) {
            node.textContent = text;
        }
        return node;
    }

    // Calls the plugin's REST API as the signed-in user. Resolves with the JSON
    // body; rejects with an error whose message came from the server
    // (fromServer) or, if the request never got an answer, a generic one.
    function request(root, method, url, body) {
        var options = {
            method: method,
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': root.getAttribute('data-rest-nonce') || '' }
        };
        if (body !== undefined) {
            options.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(body);
        }

        return fetch(url, options).then(function (response) {
            return response
                .json()
                .catch(function () {
                    return {};
                })
                .then(function (data) {
                    if (!response.ok) {
                        // WordPress words this "Cookie check failed", which says nothing useful.
                        var message =
                            data.code === 'rest_cookie_invalid_nonce'
                                ? 'This page is out of date. Reload it (and sign in again if asked), then try again.'
                                : data.message || 'That did not save. Please try again.';
                        var error = new Error(message);
                        error.fromServer = true;
                        throw error;
                    }
                    return data;
                });
        });
    }

    function messageFor(error) {
        return error && error.fromServer
            ? error.message
            : 'That did not save. Check your connection and try again.';
    }

    // Changes the shared staff list on the server: POST adds a name, DELETE
    // takes one off the list. Removing only edits the list; ratings and notes
    // already saved under the name stay. Resolves with the name as stored (a
    // name that was already listed keeps its spelling). Without an endpoint
    // (?demo=1) the change only lives on the page.
    function changeName(root, method, name) {
        var endpoint = root.getAttribute('data-staff-endpoint');
        if (!endpoint) {
            return Promise.resolve(name);
        }

        if (method === 'POST') {
            return request(root, 'POST', endpoint, { name: name }).then(function (data) {
                return data.name;
            });
        }

        var url = endpoint + (endpoint.indexOf('?') === -1 ? '?' : '&') + 'name=' + encodeURIComponent(name);
        return request(root, 'DELETE', url).then(function () {
            return name;
        });
    }

    // Builds the page's dropdowns (see src/shared/select.js). The reviewer and
    // note-author dropdowns share one staff list, so adding or removing a name
    // in either one updates both.
    function initDropdowns(root) {
        var dd = {};
        root.querySelectorAll('[data-dd]').forEach(function (node) {
            dd[node.getAttribute('data-dd')] = window.MincSelect.init(node);
        });

        var staff = [dd.reviewer, dd.author].filter(Boolean);
        var callbacks = {
            onAdd: function (name) {
                return changeName(root, 'POST', name).then(function (saved) {
                    staff.forEach(function (select) {
                        select.addOption({ value: saved, label: saved });
                    });
                    return saved;
                });
            },
            onRemove: function (name) {
                return changeName(root, 'DELETE', name).then(function () {
                    staff.forEach(function (select) {
                        select.removeOption(name);
                    });
                });
            }
        };
        staff.forEach(function (select) {
            select.configure(callbacks);
        });

        return dd;
    }

    // Which reviewer is picked decides what the page shows. Someone who hasn't
    // rated sees no scores (so they aren't swayed by them); once the picked
    // reviewer has a rating, everyone's ratings show and theirs can be changed.
    function initRatings(root, dd) {
        var reviewer = dd.reviewer;
        var rating = dd.rating;
        var submit = root.querySelector('[data-submit-rating]');
        var submittedNote = root.querySelector('[data-submitted-note]');
        var errorBox = root.querySelector('[data-rating-error]');
        var avgHeader = root.querySelector('[data-avg-header]');
        var avgOverall = root.querySelector('[data-avg-overall]');
        var rows = root.querySelector('[data-reviews]');
        var viewerName = root.querySelector('[data-viewer-name]');
        var hiddenTitle = root.querySelector('[data-hidden-title]');
        var hiddenBody = root.querySelector('[data-hidden-body]');
        var firstName = root.getAttribute('data-first-name') || 'this candidate';
        var endpoint = root.getAttribute('data-ratings-endpoint');
        var baseSum = parseFloat(root.getAttribute('data-base-sum')) || 0;
        var baseCount = parseInt(root.getAttribute('data-base-count'), 10) || 0;
        var ratings = JSON.parse(root.getAttribute('data-ratings') || '[]');
        var busy = false;

        function find(name) {
            return ratings.filter(function (item) {
                return item.reviewer.toLowerCase() === name.toLowerCase();
            })[0];
        }

        function score() {
            return parseInt(rating.getValue(), 10) || 0;
        }

        function showError(message) {
            errorBox.textContent = message || '';
            errorBox.hidden = !message;
        }

        function renderAverages() {
            var total = ratings.length + baseCount;
            if (!total) {
                avgHeader.innerHTML = '';
                avgOverall.innerHTML = '';
                return;
            }
            var sum = ratings.reduce(function (carry, item) {
                return carry + item.score;
            }, baseSum);
            var avg = sum / total;
            var number = '<span class="massinc-rating-value">' + avg.toFixed(1) + '</span>';
            avgHeader.innerHTML =
                starsHtml(avg) + number +
                '<span class="massinc-rating-count">(' + plural(total, 'rating', 'ratings') + ')</span>';
            avgOverall.innerHTML =
                starsHtml(avg) + number +
                '<span class="massinc-rating-count">average of ' + plural(total, 'reviewer', 'reviewers') + '</span>';
        }

        function renderRows() {
            rows.textContent = '';
            ratings.forEach(function (item) {
                var row = el('div', 'massinc-review');

                var avatar = el('span', 'massinc-avatar massinc-avatar-sm', initials(item.reviewer));
                avatar.setAttribute('aria-hidden', 'true');

                var info = el('div', 'massinc-review-info');
                info.appendChild(el('p', 'massinc-review-name', item.reviewer));

                var stars = el('span', 'massinc-rating');
                stars.innerHTML =
                    starsHtml(item.score) +
                    '<span class="massinc-rating-value">' + item.score.toFixed(1) + '</span>';
                var scoreBox = el('div', 'massinc-review-score');
                scoreBox.appendChild(stars);
                scoreBox.appendChild(el('span', 'massinc-rating-count', item.date));

                row.appendChild(avatar);
                row.appendChild(info);
                row.appendChild(scoreBox);
                rows.appendChild(row);
            });
        }

        // "N reviewers have already rated Maya" (shown to anyone who hasn't rated yet).
        function renderHiddenBox() {
            var total = ratings.length + baseCount;
            if (total) {
                hiddenTitle.textContent =
                    total + (total === 1 ? ' reviewer has' : ' reviewers have') +
                    ' already rated ' + firstName;
                hiddenBody.textContent =
                    'Their scores and the overall average stay hidden until you pick your name and submit a rating, so nobody anchors on someone else’s score.';
            } else {
                hiddenTitle.textContent = 'No one has rated ' + firstName + ' yet';
                hiddenBody.textContent = 'Pick your name and submit a rating to be the first.';
            }
        }

        function syncSubmit() {
            submit.disabled = busy || !(reviewer.getValue() && score());
        }

        function applyState() {
            var name = reviewer.getValue();
            var mine = name ? find(name) : null;
            showError('');

            root.setAttribute('data-state', mine ? 'after' : 'before');
            if (mine) {
                rating.setDisabled(false);
                rating.setValue(String(mine.score), true);
                submittedNote.textContent = 'Submitted ' + mine.date;
                viewerName.textContent = mine.reviewer;
                renderRows();
                renderAverages();
            } else {
                rating.setValue('', true);
                rating.setDisabled(!name);
                renderHiddenBox();
            }
            syncSubmit();
        }

        // Saves a reviewer's rating. A second rating from the same reviewer
        // replaces their first (the server does the same).
        function save(name, value) {
            if (!endpoint) {
                return Promise.resolve({ reviewer: name, score: value, date: today() });
            }
            return request(root, 'POST', endpoint, { reviewer: name, score: value });
        }

        function remember(saved) {
            ratings = ratings.filter(function (item) {
                return item.reviewer.toLowerCase() !== saved.reviewer.toLowerCase();
            });
            ratings.unshift({ reviewer: saved.reviewer, score: saved.score, date: saved.date });
        }

        function run(name, value, onFail) {
            busy = true;
            syncSubmit();
            showError('');
            save(name, value)
                .then(function (saved) {
                    remember(saved);
                    busy = false;
                    applyState();
                })
                .catch(function (error) {
                    busy = false;
                    showError(messageFor(error));
                    onFail();
                    syncSubmit();
                });
        }

        reviewer.onChange(applyState);

        rating.onChange(function () {
            var name = reviewer.getValue();
            var mine = name ? find(name) : null;

            if (!mine) {
                syncSubmit();
                return;
            }
            // Already rated: picking another score replaces the old one.
            // (The "none" option isn't a rating, so put the saved score back.)
            if (!score()) {
                rating.setValue(String(mine.score), true);
            } else if (score() !== mine.score) {
                run(name, score(), function () {
                    rating.setValue(String(mine.score), true);
                });
            }
        });

        submit.addEventListener('click', function () {
            if (submit.disabled) {
                return;
            }
            run(reviewer.getValue(), score(), function () {});
        });

        applyState();
    }

    function initNotes(root, dd) {
        var form = root.querySelector('[data-note-form]');
        var author = dd.author;
        var text = root.querySelector('[data-note-text]');
        var error = root.querySelector('[data-note-error]');
        var list = root.querySelector('[data-notes]');
        var button = form.querySelector('[type="submit"]');
        var endpoint = root.getAttribute('data-notes-endpoint');
        var saving = false;

        function fail(message) {
            error.textContent = message;
            error.hidden = false;
        }

        // Newest notes go on top, matching the saved order.
        function show(note) {
            var empty = list.querySelector('[data-notes-empty]');
            if (empty) {
                empty.remove();
            }

            var avatar = el('span', 'massinc-avatar massinc-avatar-sm', initials(note.author));
            avatar.setAttribute('aria-hidden', 'true');

            var info = el('div', 'massinc-note-info');
            info.appendChild(el('p', 'massinc-note-byline', note.author + '  ·  ' + note.date));
            info.appendChild(el('p', 'massinc-note-text', note.text));

            var item = el('div', 'massinc-note');
            item.appendChild(avatar);
            item.appendChild(info);
            list.insertBefore(item, list.firstChild);
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            if (saving) {
                return;
            }

            var name = author.getValue();
            var body = text.value.trim();
            if (!name) {
                fail('Select your name first.');
                author.focus();
                return;
            }
            if (!body) {
                fail('Write a note first.');
                text.focus();
                return;
            }
            error.hidden = true;

            saving = true;
            button.disabled = true;
            var saved = endpoint
                ? request(root, 'POST', endpoint, { author: name, text: body })
                : Promise.resolve({ author: name, text: body, date: today() });

            saved
                .then(function (note) {
                    show(note);
                    text.value = '';
                })
                .catch(function (err) {
                    fail(messageFor(err));
                })
                .then(function () {
                    saving = false;
                    button.disabled = false;
                });
        });
    }

    // Admins only: pick a stage, then Confirm to save it. Staff don't get these controls.
    function initStage(root, dd) {
        var confirm = root.querySelector('[data-confirm-stage]');
        if (!confirm || !dd.stage) {
            return;
        }

        var select = dd.stage;
        var status = root.querySelector('[data-stage-status]');
        var errorBox = root.querySelector('[data-stage-error]');
        var endpoint = root.getAttribute('data-stage-endpoint');
        var labels = JSON.parse(root.getAttribute('data-stages') || '{}');
        var current = root.getAttribute('data-stage');
        var busy = false;

        function sync() {
            confirm.disabled = busy || select.getValue() === current;
        }

        function paint(stage) {
            current = stage;
            root.querySelectorAll('[data-stage-badge]').forEach(function (badge) {
                badge.className = 'massinc-stage massinc-stage-' + stage;
                badge.textContent = labels[stage] || stage;
            });
        }

        select.onChange(function () {
            status.hidden = true;
            errorBox.hidden = true;
            sync();
        });

        confirm.addEventListener('click', function () {
            var stage = select.getValue();
            if (busy || stage === current) {
                return;
            }

            busy = true;
            sync();
            status.hidden = true;
            errorBox.hidden = true;

            // Without an endpoint (?demo=1, built-in sample candidates) the change only lives on the page.
            var saved = endpoint
                ? request(root, 'POST', endpoint, { stage: stage })
                : Promise.resolve({ stage: stage });

            saved
                .then(function (data) {
                    paint(data.stage);
                    status.textContent = 'Stage changed to ' + (labels[data.stage] || data.stage) + '.';
                    status.hidden = false;
                })
                .catch(function (error) {
                    errorBox.textContent = messageFor(error);
                    errorBox.hidden = false;
                })
                .then(function () {
                    busy = false;
                    sync();
                });
        });

        sync();
    }

    document.querySelectorAll('.massinc-detail[data-state]').forEach(function (root) {
        var dd = initDropdowns(root);
        initRatings(root, dd);
        initNotes(root, dd);
        initStage(root, dd);
    });
})();
