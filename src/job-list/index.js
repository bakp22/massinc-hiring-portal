(function () {
    const { registerBlockType } = wp.blocks;
    const { createElement: el } = wp.element;

    registerBlockType('minc-hiring/job-list', {
        edit: function () {
            return el(
                'div',
                { className: 'minc-hiring-job-list-editor' },
                el('strong', null, 'Hiring Job List'),
                el('p', null, 'Open jobs will be displayed here on the public site.')
            );
        },
        save: function () {
            return null;
        }
    });
})();
