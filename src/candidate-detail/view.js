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

    function init(root) {
        var input = root.querySelector('.massinc-star-input');
        var buttons = Array.prototype.slice.call(input.querySelectorAll('.massinc-star-button'));
        var hint = root.querySelector('[data-your-hint]');
        var submit = root.querySelector('[data-submit-rating]');
        var avgHeader = root.querySelector('[data-avg-header]');
        var avgOverall = root.querySelector('[data-avg-overall]');
        var reviews = root.querySelector('[data-reviews]');
        var template = root.querySelector('[data-reviews-template]');
        var othersSum = parseFloat(root.getAttribute('data-others-sum')) || 0;
        var othersCount = parseInt(root.getAttribute('data-others-count'), 10) || 0;
        var score = parseInt(input.getAttribute('data-score'), 10) || 0;
        var submittedOn = '';

        function paintStars(n) {
            buttons.forEach(function (button, index) {
                var value = index + 1;
                button.innerHTML = starHtml(value <= n ? 'full' : 'empty');
                button.setAttribute('aria-pressed', value === score ? 'true' : 'false');
            });
        }

        function renderAverages() {
            var total = othersCount + 1;
            var avg = (othersSum + score) / total;
            var value = '<span class="massinc-rating-value">' + avg.toFixed(1) + '</span>';
            avgHeader.innerHTML =
                starsHtml(avg) + value +
                '<span class="massinc-rating-count">(' + total + ' ratings)</span>';
            avgOverall.innerHTML =
                starsHtml(avg) + value +
                '<span class="massinc-rating-count">average of ' + total + ' reviewers</span>';
        }

        function renderHint() {
            if (root.getAttribute('data-state') === 'after') {
                hint.textContent =
                    score + ' of 5 · submitted ' + submittedOn + ' · click a star to change';
            } else if (score) {
                hint.textContent = score + ' of 5 · click Submit rating to save';
            } else {
                hint.textContent = 'Not rated yet · click a star to rate';
            }
        }

        buttons.forEach(function (button, index) {
            var value = index + 1;

            button.addEventListener('mouseenter', function () {
                paintStars(value);
            });
            button.addEventListener('mouseleave', function () {
                paintStars(score);
            });
            button.addEventListener('click', function () {
                score = value;
                paintStars(score);
                if (root.getAttribute('data-state') === 'after') {
                    renderAverages();
                }
                renderHint();
            });
        });

        // Keyboard users never trigger mouseleave, so reset on blur too.
        input.addEventListener('focusout', function () {
            paintStars(score);
        });

        submit.addEventListener('click', function () {
            if (!score) {
                hint.textContent = 'Choose a star rating first.';
                buttons[0].focus();
                return;
            }

            // Reveal the other reviewers' ratings. Not persisted yet: there is
            // no ratings table or endpoint, so this only changes the page.
            submittedOn = today();
            if (template && reviews) {
                reviews.appendChild(template.content.cloneNode(true));
                template.remove();
            }
            root.setAttribute('data-state', 'after');
            renderAverages();
            renderHint();
        });

        // Server-rendered "after" state (?preview=rated) starts with a date already in the hint.
        if (root.getAttribute('data-state') === 'after') {
            submittedOn = '19 Aug 2026';
        }
    }

    // The interview date is a plain text field in the design; switch to a
    // native date picker once it's focused so the placeholder still shows.
    function initDateInput(root) {
        var date = root.querySelector('[data-date-input]');
        if (!date) {
            return;
        }
        date.addEventListener('focus', function () {
            date.type = 'date';
        });
        date.addEventListener('blur', function () {
            if (!date.value) {
                date.type = 'text';
            }
        });
    }

    document.querySelectorAll('.massinc-detail[data-state]').forEach(function (root) {
        init(root);
        initDateInput(root);
    });
})();
