import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, Card, ErrorNotice, ResponsiveTable, Select, TextField } from '../core3/ui';

/**
 * Picks an existing lesson (Tutor lesson, LearnDash lesson/topic, page or post).
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.lesson   Selected lesson row (or null).
 * @param {Function} props.onSelect ( row|null ) => void.
 * @param {string}   props.title    Card title.
 * @return {Element} Picker.
 */
export default function LessonPicker( { lesson, onSelect, title } ) {
	const [ search, setSearch ] = useState( '' );
	const [ type, setType ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		if ( lesson ) {
			return undefined;
		}
		const t = setTimeout(
			() => {
				const q = new URLSearchParams( { search, type, page: String( page ) } );
				request( `lessons?${ q.toString() }` ).then( setData ).catch( setError );
			},
			search ? 300 : 0
		);
		return () => clearTimeout( t );
	}, [ search, type, page, lesson ] );

	if ( lesson ) {
		return (
			<Card title={ title } actions={ <Button size="sm" variant="ghost" onClick={ () => onSelect( null ) }>{ __( 'Change', 'dev3-ai-course-builder' ) }</Button> }>
				<div className="dev3lm-picked">
					<p className="dev3lm-picked__title">{ lesson.title }</p>
					<p className="dev3lm-muted dev3lm-small">
						<Badge>{ lesson.type_label }</Badge> { lesson.course && sprintf( /* translators: %s: course title */ __( 'in %s', 'dev3-ai-course-builder' ), lesson.course ) } · { sprintf( /* translators: %d: words */ __( '%d words', 'dev3-ai-course-builder' ), lesson.words ) }
						{ lesson.edit && (
							<>
								{ ' · ' }
								<a href={ lesson.edit }>{ __( 'Edit lesson', 'dev3-ai-course-builder' ) }</a>
							</>
						) }
					</p>
				</div>
			</Card>
		);
	}
	const columns = [
		{ key: 'title', label: __( 'Lesson', 'dev3-ai-course-builder' ), primary: true, render: ( r ) => r.title || __( '(untitled)', 'dev3-ai-course-builder' ) },
		{ key: 'type', label: __( 'Type', 'dev3-ai-course-builder' ), render: ( r ) => r.type_label },
		{ key: 'course', label: __( 'Course', 'dev3-ai-course-builder' ), hideOnMobile: true, render: ( r ) => r.course || '—' },
		{ key: 'words', label: __( 'Words', 'dev3-ai-course-builder' ), align: 'end', hideOnMobile: true, render: ( r ) => r.words },
	];
	return (
		<Card title={ title } flush>
			<div className="dev3lm-filters dev3lm-filters--card">
				<TextField label={ __( 'Search lessons', 'dev3-ai-course-builder' ) } hideLabel placeholder={ __( 'Search lessons…', 'dev3-ai-course-builder' ) } value={ search } onChange={ ( v ) => { setSearch( v ); setPage( 1 ); } } />
				<Select
					label={ __( 'Lesson type', 'dev3-ai-course-builder' ) }
					hideLabel
					value={ type }
					onChange={ ( v ) => { setType( v ); setPage( 1 ); } }
					options={ [ { value: '', label: __( 'All lesson types', 'dev3-ai-course-builder' ) }, ...( ( data && data.types ) || [] ) ] }
				/>
			</div>
			<ErrorNotice error={ error } />
			<ResponsiveTable
				columns={ columns }
				rows={ data ? data.items : [] }
				rowKey={ ( r ) => r.id }
				loading={ ! data && ! error }
				onRowClick={ onSelect }
				rowActions={ ( r ) => (
					<Button
						size="sm"
						onClick={ ( e ) => {
							e.stopPropagation();
							onSelect( r );
						} }
					>
						{ __( 'Select', 'dev3-ai-course-builder' ) }
					</Button>
				) }
				pagination={ data ? { page, perPage: 20, total: data.total, onPageChange: setPage } : undefined }
				empty={ { icon: 'search', title: __( 'No lessons found', 'dev3-ai-course-builder' ), description: __( 'Create a course first, or search for another title.', 'dev3-ai-course-builder' ) } }
			/>
		</Card>
	);
}
