/*
 * Custom dropdown used on the staff ATS screens (stage, rating, reviewer,
 * note author, position filter). Rendered by minc_hiring_dropdown() in
 * inc/ats-view.php; a native <select> can't hold the × buttons or the inline
 * "add a name" row, and its menu can't be styled to match the design.
 *
 * Two modes, set by data-mode on the wrapper:
 *  - "choice": a button that opens a list of options.
 *  - "names":  a text box you can type in. Typing filters the list; a name that
 *    isn't listed gets an "Add" row. With data-removable="1" each name has an ×
 *    that asks for confirmation before removing it.
 *
 * Follows the ARIA combobox/listbox pattern. The chosen value lives in a
 * hidden input (data-dd-value), which fires "change" when it changes.
 *
 * MincSelect.init(wrapper) returns the instance (also at wrapper.mincSelect):
 *   getValue(), setValue(value, silent), setDisabled(flag), focus(),
 *   addOption({value, label, disabled}), removeOption(value),
 *   onChange(fn), configure({ onAdd(name) -> Promise<name>, onRemove(name) -> Promise })
 */
(function () {
    var nextId = 0;

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

    function same(a, b) {
        return String(a).toLowerCase() === String(b).toLowerCase();
    }

    function MincSelect(root) {
        this.root = root;
        this.names = root.getAttribute('data-mode') === 'names';
        this.removable = root.getAttribute('data-removable') === '1';
        this.placeholder = root.getAttribute('data-placeholder') || '';
        this.valueInput = root.querySelector('[data-dd-value]');
        this.control = root.querySelector('[data-dd-control]');
        this.textEl = root.querySelector('[data-dd-text]');
        this.list = root.querySelector('[data-dd-list]');
        this.items = JSON.parse(root.getAttribute('data-options') || '[]');
        this.callbacks = {};
        this.id = 'dd' + ++nextId;
        this.isOpen = false;
        this.query = '';
        this.dirty = false;
        this.nav = [];
        this.active = -1;
        this.confirming = null;
        this.message = '';
        this.busy = false;

        this.sortItems();
        this.bind();
        this.syncControl();
        // The server may render the control disabled; mirror that on the wrapper.
        this.root.classList.toggle('is-disabled', !!this.control.disabled);
    }

    MincSelect.prototype.sortItems = function () {
        if (this.names) {
            this.items.sort(function (a, b) {
                return a.label.localeCompare(b.label, undefined, { sensitivity: 'base' });
            });
        }
    };

    /* ---- public API ---- */

    MincSelect.prototype.configure = function (callbacks) {
        this.callbacks = callbacks || {};
    };

    MincSelect.prototype.getValue = function () {
        return this.valueInput.value;
    };

    MincSelect.prototype.setValue = function (value, silent) {
        var changed = this.valueInput.value !== value;
        this.valueInput.value = value;
        this.syncControl();
        if (changed && !silent) {
            this.valueInput.dispatchEvent(new Event('change', { bubbles: true }));
        }
    };

    MincSelect.prototype.onChange = function (fn) {
        this.valueInput.addEventListener('change', fn);
    };

    MincSelect.prototype.setDisabled = function (flag) {
        this.control.disabled = !!flag;
        this.root.classList.toggle('is-disabled', !!flag);
        if (flag) {
            this.close();
        }
    };

    MincSelect.prototype.focus = function () {
        this.control.focus();
    };

    MincSelect.prototype.addOption = function (item) {
        var exists = this.items.some(function (existing) {
            return same(existing.value, item.value);
        });
        if (!exists) {
            this.items.push({ value: item.value, label: item.label, disabled: !!item.disabled });
            this.sortItems();
            if (this.isOpen) {
                this.render();
            }
        }
    };

    MincSelect.prototype.removeOption = function (value) {
        this.items = this.items.filter(function (item) {
            return !same(item.value, value);
        });
        if (this.confirming !== null && same(this.confirming, value)) {
            this.confirming = null;
        }
        if (same(this.getValue(), value)) {
            this.setValue('', false);
        }
        if (this.isOpen) {
            this.render();
        }
    };

    /* ---- display ---- */

    MincSelect.prototype.selectedItem = function () {
        var value = this.getValue();
        return value === '' ? null : this.items.filter(function (item) {
            return item.value === value;
        })[0] || null;
    };

    // Shows the chosen value (or the placeholder) in the closed control.
    MincSelect.prototype.syncControl = function () {
        var selected = this.selectedItem();
        if (this.names) {
            this.control.value = selected ? selected.value : '';
        } else {
            this.textEl.textContent = selected ? selected.label : this.placeholder;
            this.root.classList.toggle('is-placeholder', !selected);
        }
    };

    MincSelect.prototype.visibleItems = function () {
        var query = this.names ? this.query.toLowerCase() : '';
        return this.items.filter(function (item) {
            return !query || item.label.toLowerCase().indexOf(query) !== -1;
        });
    };

    MincSelect.prototype.render = function () {
        var self = this;
        var value = this.getValue();
        var index = 0;
        this.list.textContent = '';
        this.nav = [];

        this.visibleItems().forEach(function (item) {
            var row = el('li', 'massinc-dd-row');
            row.setAttribute('role', 'presentation');

            if (self.confirming !== null && item.value === self.confirming) {
                row.className += ' is-confirming';
                row.appendChild(el('span', 'massinc-dd-confirm-text', 'Remove ' + item.value + '?'));
                var yes = el('button', 'massinc-dd-confirm massinc-dd-confirm-yes', 'Remove');
                var no = el('button', 'massinc-dd-confirm', 'Keep');
                yes.type = no.type = 'button';
                yes.tabIndex = no.tabIndex = -1;
                yes.addEventListener('click', function () {
                    self.confirmRemove(item.value);
                });
                no.addEventListener('click', function () {
                    self.confirming = null;
                    self.render();
                });
                row.appendChild(yes);
                row.appendChild(no);
                self.list.appendChild(row);
                return;
            }

            var option = el('div', 'massinc-dd-option', item.label);
            option.id = self.id + '-o' + index++;
            option.setAttribute('role', 'option');
            option.setAttribute('aria-selected', item.value === value ? 'true' : 'false');
            if (item.value === value) {
                option.className += ' is-selected';
            }
            if (item.disabled) {
                option.className += ' is-disabled';
                option.setAttribute('aria-disabled', 'true');
            } else {
                var entry = { el: option, item: item };
                self.nav.push(entry);
                option.addEventListener('click', function () {
                    self.activate(entry);
                });
                option.addEventListener('mousemove', function () {
                    self.setActive(self.nav.indexOf(entry));
                });
            }
            row.appendChild(option);

            if (self.removable && item.value !== '') {
                var x = el('button', 'massinc-dd-remove', '×');
                x.type = 'button';
                x.tabIndex = -1;
                x.setAttribute('aria-label', 'Remove ' + item.value);
                x.addEventListener('click', function () {
                    self.confirming = item.value;
                    self.message = '';
                    self.render();
                });
                row.appendChild(x);
            }
            self.list.appendChild(row);
        });

        // A typed name that isn't on the list yet can be added right here.
        var typed = this.names ? this.query : '';
        if (typed && !this.items.some(function (item) { return same(item.value, typed); })) {
            var addRow = el('li', 'massinc-dd-row');
            addRow.setAttribute('role', 'presentation');
            var add = el('div', 'massinc-dd-option massinc-dd-add', '+ Add “' + typed + '”');
            add.id = self.id + '-add';
            add.setAttribute('role', 'option');
            var addEntry = { el: add, add: true };
            this.nav.push(addEntry);
            add.addEventListener('click', function () {
                self.activate(addEntry);
            });
            add.addEventListener('mousemove', function () {
                self.setActive(self.nav.indexOf(addEntry));
            });
            addRow.appendChild(add);
            this.list.appendChild(addRow);
        }

        if (!this.list.children.length) {
            var hint = this.names
                ? 'No names yet. Type yours to add it.'
                : 'No options.';
            this.list.appendChild(el('li', 'massinc-dd-empty', hint));
        }

        if (this.message) {
            var error = el('li', 'massinc-dd-error', this.message);
            error.setAttribute('role', 'alert');
            this.list.appendChild(error);
        }
    };

    MincSelect.prototype.setActive = function (index, scroll) {
        if (!this.nav.length) {
            this.active = -1;
            this.control.removeAttribute('aria-activedescendant');
            return;
        }
        this.active = Math.max(0, Math.min(index, this.nav.length - 1));
        this.nav.forEach(function (entry, i) {
            entry.el.classList.toggle('is-active', i === this.active);
        }, this);
        var current = this.nav[this.active].el;
        this.control.setAttribute('aria-activedescendant', current.id);
        if (scroll && current.scrollIntoView) {
            current.scrollIntoView({ block: 'nearest' });
        }
    };

    /* ---- opening and closing ---- */

    MincSelect.prototype.open = function () {
        if (this.isOpen || this.control.disabled) {
            return;
        }
        this.isOpen = true;
        this.query = '';
        this.dirty = false;
        this.confirming = null;
        this.message = '';
        this.list.hidden = false;
        this.control.setAttribute('aria-expanded', 'true');
        this.root.classList.add('is-open');
        this.render();

        var value = this.getValue();
        var start = 0;
        if (!this.names) {
            this.nav.forEach(function (entry, i) {
                if (entry.item && entry.item.value === value) {
                    start = i;
                }
            });
        }
        this.setActive(start, true);
    };

    MincSelect.prototype.close = function () {
        if (!this.isOpen) {
            return;
        }
        this.isOpen = false;
        this.query = '';
        this.confirming = null;
        this.message = '';
        this.list.hidden = true;
        this.control.setAttribute('aria-expanded', 'false');
        this.control.removeAttribute('aria-activedescendant');
        this.root.classList.remove('is-open');
        this.syncControl();
    };

    /* ---- choosing, adding, removing ---- */

    MincSelect.prototype.activate = function (entry) {
        if (entry.add) {
            this.addTyped();
            return;
        }
        this.setValue(entry.item.value, false);
        this.close();
        // The text box keeps focus on its own (and refocusing it would reopen the menu).
        if (!this.names) {
            this.control.focus();
        }
    };

    MincSelect.prototype.fail = function (message) {
        this.message = message;
        this.render();
        this.setActive(this.active);
    };

    MincSelect.prototype.addTyped = function () {
        var self = this;
        var name = this.query.replace(/\s+/g, ' ').trim();
        if (!name || this.busy) {
            return;
        }

        var finish = function (saved) {
            self.addOption({ value: saved, label: saved });
            self.setValue(saved, false);
            self.close();
        };

        this.busy = true;
        this.root.classList.add('is-busy');
        Promise.resolve(this.callbacks.onAdd ? this.callbacks.onAdd(name) : name)
            .then(finish)
            .catch(function (error) {
                self.fail(
                    error && error.fromServer
                        ? error.message
                        : 'Could not save that name. Check your connection and try again.'
                );
            })
            .then(function () {
                self.busy = false;
                self.root.classList.remove('is-busy');
            });
    };

    MincSelect.prototype.confirmRemove = function (value) {
        var self = this;
        if (this.busy) {
            return;
        }

        this.busy = true;
        Promise.resolve(this.callbacks.onRemove ? this.callbacks.onRemove(value) : null)
            .then(function () {
                self.removeOption(value);
            })
            .catch(function (error) {
                self.confirming = null;
                self.fail(
                    error && error.fromServer
                        ? error.message
                        : 'Could not remove that name. Check your connection and try again.'
                );
            })
            .then(function () {
                self.busy = false;
            });
    };

    /* ---- events ---- */

    MincSelect.prototype.bind = function () {
        var self = this;
        var control = this.control;

        // Keep focus in the control while clicking inside the menu.
        this.list.addEventListener('mousedown', function (event) {
            event.preventDefault();
        });

        document.addEventListener('mousedown', function (event) {
            if (self.isOpen && !self.root.contains(event.target)) {
                self.close();
            }
        });

        control.addEventListener('click', function () {
            if (self.names) {
                self.open();
                // Select the chosen name so typing replaces it instead of adding to it,
                // unless the person has already started typing.
                if (!self.dirty) {
                    control.select();
                }
            } else if (self.isOpen) {
                self.close();
            } else {
                self.open();
            }
        });

        if (this.names) {
            // Whether focus came from the mouse (the click handler selects the
            // text then) or the keyboard (select it here). No timers: a timer
            // can fire after the first letters are typed and wipe them.
            var pointer = false;
            control.addEventListener('mousedown', function () {
                pointer = true;
            });
            control.addEventListener('blur', function () {
                pointer = false;
            });
            control.addEventListener('focus', function () {
                self.open();
                if (!pointer) {
                    control.select();
                }
                pointer = false;
            });
            control.addEventListener('input', function () {
                self.dirty = true;
                if (!self.isOpen) {
                    self.open();
                    self.dirty = true;
                }
                self.query = control.value.replace(/\s+/g, ' ').trim();
                self.confirming = null;
                self.message = '';
                self.render();
                self.setActive(0);
            });
        }

        control.addEventListener('keydown', function (event) {
            self.onKeydown(event);
        });
    };

    MincSelect.prototype.onKeydown = function (event) {
        var key = event.key;

        if (this.confirming !== null) {
            if (key === 'Enter') {
                event.preventDefault();
                this.confirmRemove(this.confirming);
            } else if (key === 'Escape') {
                event.preventDefault();
                this.confirming = null;
                this.render();
                this.setActive(this.active);
            }
            return;
        }

        if (key === 'ArrowDown' || key === 'ArrowUp') {
            event.preventDefault();
            if (!this.isOpen) {
                this.open();
            } else {
                this.setActive(this.active + (key === 'ArrowDown' ? 1 : -1), true);
            }
        } else if (key === 'Enter' || (key === ' ' && !this.names)) {
            // Names is a text box inside a form: never let Enter submit it.
            event.preventDefault();
            if (!this.isOpen) {
                this.open();
            } else if (this.nav[this.active]) {
                this.activate(this.nav[this.active]);
            }
        } else if (key === 'Escape') {
            if (this.isOpen) {
                event.preventDefault();
                event.stopPropagation();
                this.close();
            }
        } else if (key === 'Tab') {
            this.close();
        } else if (key === 'Delete' && event.shiftKey && this.removable && this.isOpen) {
            var entry = this.nav[this.active];
            if (entry && entry.item) {
                event.preventDefault();
                this.confirming = entry.item.value;
                this.render();
            }
        } else if (!this.names && (key === 'Home' || key === 'End') && this.isOpen) {
            event.preventDefault();
            this.setActive(key === 'Home' ? 0 : this.nav.length - 1, true);
        } else if (!this.names && key.length === 1 && !event.ctrlKey && !event.metaKey) {
            // Type-ahead: jump to the next option starting with that letter.
            if (!this.isOpen) {
                this.open();
            }
            var letter = key.toLowerCase();
            for (var step = 1; step <= this.nav.length; step++) {
                var index = (this.active + step) % this.nav.length;
                var label = this.nav[index].item ? this.nav[index].item.label : '';
                if (label.toLowerCase().charAt(0) === letter) {
                    this.setActive(index, true);
                    break;
                }
            }
        }
    };

    window.MincSelect = {
        init: function (root) {
            if (!root.mincSelect) {
                root.mincSelect = new MincSelect(root);
            }
            return root.mincSelect;
        }
    };
})();
