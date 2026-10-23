(function () {
    const { registerBlockType } = wp.blocks;
    const { createElement: el } = wp.element;

    registerBlockType('minc-hiring/staff-login', {
        edit: function () {
            return el(
                'div',
                { className: 'minc-hiring-staff-login-editor' },
                el('strong', null, 'Hiring Staff Login'),
                el(
                    'p',
                    null,
                    'Staff sign-in form for the MassINC applicant tracking portal.'
                )
            );
        },
        save: function () {
            return null;
        }
    });
})();
