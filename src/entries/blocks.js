/**
 * Editor side of the "Course Certificate" block (server-rendered).
 */
import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, TextControl } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import { __ } from '@wordpress/i18n';
import metadata from '../../blocks/certificate/block.json';

registerBlockType( metadata.name, {
	...metadata,
	title: __( 'Course Certificate', 'dev3-ai-course-builder' ),
	description: __( 'Printable certificate for learners who completed a course.', 'dev3-ai-course-builder' ),
	edit( { attributes, setAttributes } ) {
		const props = useBlockProps();
		return (
			<div { ...props }>
				<InspectorControls>
					<PanelBody title={ __( 'Certificate', 'dev3-ai-course-builder' ) }>
						<TextControl
							label={ __( 'Course ID', 'dev3-ai-course-builder' ) }
							help={ __( 'Leave 0 to use the current course (or its parent page).', 'dev3-ai-course-builder' ) }
							type="number"
							value={ attributes.courseId || 0 }
							onChange={ ( v ) => setAttributes( { courseId: parseInt( v, 10 ) || 0 } ) }
						/>
					</PanelBody>
				</InspectorControls>
				<ServerSideRender block={ metadata.name } attributes={ attributes } />
			</div>
		);
	},
	save: () => null,
} );
