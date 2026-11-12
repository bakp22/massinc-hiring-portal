(function () {
    const { registerBlockType } = wp.blocks;
    const { createElement: el } = wp.element;
    const { InspectorControls } = wp.blockEditor;
    const { PanelBody, TextControl } = wp.components;

    registerBlockType('minc-hiring/candidate-dashboard', {
        edit: function (props) {
            const detailUrl = props.attributes.detailUrl || '';

            return el(
                wp.element.Fragment,
                null,
                el(
                    InspectorControls,
                    null,
                    el(
                        PanelBody,
                        { title: 'Dashboard Settings', initialOpen: true },
                        el(TextControl, {
                            label: 'Candidate detail page URL',
                            help: 'Where each "View" button links. The candidate is added as ?application=ID.',
                            type: 'url',
                            value: detailUrl,
                            onChange: function (value) {
                                props.setAttributes({ detailUrl: value });
                            }
                        })
                    )
                ),
                el(
                    'div',
                    { className: 'minc-hiring-candidate-dashboard-editor' },
                    el('strong', null, 'Hiring Candidate Dashboard'),
                    el('p', null, 'Staff-only. The candidate table renders on the front end.')
                )
            );
        },
        save: function () {
            return null;
        }
    });
})();
