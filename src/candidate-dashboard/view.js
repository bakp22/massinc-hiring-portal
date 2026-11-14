(function () {
    function init(root) {
        var search = root.querySelector('#massinc-candidate-search');
        var rows = Array.prototype.slice.call(
            root.querySelectorAll('.massinc-table-row[data-stage]')
        );
        var empty = root.querySelector('.massinc-table-empty');
        var count = root.querySelector('[data-count]');
        var countLabel = root.querySelector('[data-count-label]');
        var position = window.MincSelect.init(root.querySelector('[data-dd="position"]'));
        var state = { query: '', stage: '', position: '', minRating: 0 };

        // The list arrives newest application first. Clicking Rating or Applied
        // sorts by that column; clicking the active column again reverses it.
        var table = root.querySelector('.massinc-table');
        var sortLabel = root.querySelector('[data-sort-label]');
        var sort = { key: 'applied', dir: 'desc' };
        var original = rows.slice();
        var LABELS = {
            'applied desc': 'date applied, newest first',
            'applied asc': 'date applied, oldest first',
            'rating desc': 'rating, high to low',
            'rating asc': 'rating, low to high'
        };

        function number(row, attribute) {
            return parseFloat(row.getAttribute(attribute));
        }

        function compare(a, b) {
            var direction = sort.dir === 'asc' ? 1 : -1;

            if (sort.key === 'rating') {
                var ra = number(a, 'data-rating');
                var rb = number(b, 'data-rating');
                // Candidates nobody has rated yet stay at the bottom either way.
                if (ra < 0 && rb >= 0) {
                    return 1;
                }
                if (rb < 0 && ra >= 0) {
                    return -1;
                }
                if (ra !== rb) {
                    return (ra - rb) * direction;
                }
                // Same rating: newest application first.
                var byDate = number(b, 'data-applied') - number(a, 'data-applied');
                if (byDate) {
                    return byDate;
                }
            } else {
                var da = number(a, 'data-applied');
                var db = number(b, 'data-applied');
                if (da !== db) {
                    return (da - db) * direction;
                }
            }
            return original.indexOf(a) - original.indexOf(b);
        }

        function applySort() {
            rows.sort(compare).forEach(function (row) {
                table.insertBefore(row, empty);
            });

            root.querySelectorAll('[data-sort]').forEach(function (button) {
                var active = button.getAttribute('data-sort') === sort.key;
                var header = button.parentNode;
                header.setAttribute(
                    'aria-sort',
                    active ? (sort.dir === 'asc' ? 'ascending' : 'descending') : 'none'
                );
                button.querySelector('.massinc-sort-arrow').textContent = active
                    ? sort.dir === 'asc' ? '\u25B2' : '\u25BC'
                    : '';
            });
            sortLabel.textContent = LABELS[sort.key + ' ' + sort.dir];
        }

        root.querySelectorAll('[data-sort]').forEach(function (button) {
            button.addEventListener('click', function () {
                var key = button.getAttribute('data-sort');
                // A newly chosen column starts with its most useful order:
                // newest first for dates, highest first for ratings.
                sort = {
                    key: key,
                    dir: sort.key === key ? (sort.dir === 'desc' ? 'asc' : 'desc') : 'desc'
                };
                applySort();
            });
        });

        function apply() {
            var visible = 0;
            rows.forEach(function (row) {
                var rating = parseFloat(row.getAttribute('data-rating'));
                var matches =
                    (!state.query ||
                        row.getAttribute('data-search').indexOf(state.query) !== -1) &&
                    (!state.stage || row.getAttribute('data-stage') === state.stage) &&
                    (!state.position || row.getAttribute('data-position') === state.position) &&
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

        position.onChange(function () {
            state.position = position.getValue();
            apply();
        });

        search.addEventListener('input', function () {
            state.query = search.value.trim().toLowerCase();
            apply();
        });
    }

    document.querySelectorAll('.massinc-dashboard').forEach(init);
})();
