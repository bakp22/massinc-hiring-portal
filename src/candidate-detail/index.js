(function () {
    const { registerBlockType } = wp.blocks;
    const { createElement: el } = wp.element;
    const { InspectorControls } = wp.blockEditor;
    const { PanelBody, TextControl } = wp.components;

    registerBlockType('minc-hiring/candidate-detail', {
        edit: function (props) {
            const dashboardUrl = props.attributes.dashboardUrl || '';

            return el(
                wp.element.Fragment,
                null,
                el(
                    InspectorControls,
                    null,
                    el(
                        PanelBody,
                        { title: 'Candidate Detail Settings', initialOpen: true },
                        el(TextControl, {
                            label: 'Dashboard page URL',
                            help: 'Where the "All candidates" link goes.',
                            type: 'url',
                            value: dashboardUrl,
                            onChange: function (value) {
                                props.setAttributes({ dashboardUrl: value });
                            }
                        })
                    )
                ),
                el(
                    'div',
                    { className: 'minc-hiring-candidate-detail-editor' },
                    el('strong', null, 'Hiring Candidate Detail'),
                    el('p', null, 'Staff-only. Shows the candidate named by ?application=ID on the front end.')
                )
            );
        },
        save: function () {
            return null;
        }
    });
})();
