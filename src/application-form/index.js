(function () {
    const { registerBlockType } = wp.blocks;
    const { createElement: el } = wp.element;
    const { InspectorControls } = wp.blockEditor;
    const { PanelBody, TextControl } = wp.components;

    registerBlockType('minc-hiring/application-form', {
        edit: function (props) {
            const jobId = props.attributes.jobId || 0;

            return el(
                wp.element.Fragment,
                null,
                el(
                    InspectorControls,
                    null,
                    el(
                        PanelBody,
                        { title: 'Application Settings', initialOpen: true },
                        el(TextControl, {
                            label: 'Job ID',
                            type: 'number',
                            value: jobId,
                            onChange: function (value) {
                                props.setAttributes({ jobId: parseInt(value || 0, 10) });
                            }
                        })
                    )
                ),
                el(
                    'div',
                    { className: 'minc-hiring-application-editor' },
                    el('strong', null, 'Hiring Application Form'),
                    el('p', null, jobId ? 'Configured for Job #' + jobId : 'Set a Job ID in the block settings.')
                )
            );
        },
        save: function () {
            return null;
        }
    });
})();
