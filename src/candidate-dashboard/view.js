(function () {
    function init(root) {
        var search = root.querySelector('#massinc-candidate-search');
        var rows = Array.prototype.slice.call(
            root.querySelectorAll('.massinc-table-row[data-stage]')
        );
        var empty = root.querySelector('.massinc-table-empty');
        var count = root.querySelector('[data-count]');
        var countLabel = root.querySelector('[data-count-label]');
        var state = { query: '', stage: '', minRating: 0 };

        function apply() {
            var visible = 0;
            rows.forEach(function (row) {
                var rating = parseFloat(row.getAttribute('data-rating'));
                var matches =
                    (!state.query ||
                        row.getAttribute('data-search').indexOf(state.query) !== -1) &&
                    (!state.stage || row.getAttribute('data-stage') === state.stage) &&
                    (!state.minRating || rating >= state.minRating);

                row.hidden = !matches;
                if (matches) {
                    visible++;
                }
            });

            count.textContent = visible;
            countLabel.textContent = visible === 1 ? 'candidate' : 'candidates';
            empty.hidden = visible !== 0;
        }

        // Pill groups behave as single-select toggle buttons.
        function bindPills(filter, onChange) {
            var group = root.querySelector('[data-filter="' + filter + '"]');
            group.addEventListener('click', function (event) {
                var pill = event.target.closest('.massinc-pill');
                if (!pill) {
                    return;
                }
                group.querySelectorAll('.massinc-pill').forEach(function (other) {
                    var active = other === pill;
                    other.classList.toggle('is-active', active);
                    other.setAttribute('aria-pressed', active ? 'true' : 'false');
                });
                onChange(pill.getAttribute('data-value'));
                apply();
            });
        }

        bindPills('stage', function (value) {
            state.stage = value;
        });
        bindPills('rating', function (value) {
            state.minRating = parseFloat(value) || 0;
        });

        search.addEventListener('input', function () {
            state.query = search.value.trim().toLowerCase();
            apply();
        });
    }

    document.querySelectorAll('.massinc-dashboard').forEach(init);
})();
