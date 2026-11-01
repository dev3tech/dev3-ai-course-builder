import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, Card, ErrorNotice, IconButton, Modal, PageHeader, ResponsiveTable, Select, Stack, TextField, useToast } from '../core3/ui';
import { STATUS, downloadJson, shortDate } from './util';

const FILTERS = [
	{ value: '', label: __( 'All statuses', 'dev3-ai-course-builder' ) },
	{ value: 'draft', label: __( 'Drafts', 'dev3-ai-course-builder' ) },
	{ value: 'building', label: __( 'Creating', 'dev3-ai-course-builder' ) },
	{ value: 'built', label: __( 'Created', 'dev3-ai-course-builder' ) },
	{ value: 'failed', label: __( 'Failed', 'dev3-ai-course-builder' ) },
];

/**
 * Course drafts list.
 *
 * @param {Object}   props          Props.
 * @param {Array}    props.adapters Adapters.
 * @param {Function} props.navigate Navigate.
 * @param {Function} props.reloadStatus Refresh status.
 * @return {Element} List.
 */
export default function CoursesTab( { adapters, navigate, reloadStatus } ) {
	const toast = useToast();
	const [ data, setData ] = useState( null );
	const [ page, setPage ] = useState( 1 );
	const [ filter, setFilter ] = useState( '' );
	const [ search, setSearch ] = useState( '' );
	const [ error, setError ] = useState( null );
	const [ remove, setRemove ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const fileRef = useRef( null );

	const load = () => {
		setError( null );
		const q = new URLSearchParams( { page: String( page ), per_page: '20', status: filter, search } );
		request( `blueprints?${ q.toString() }` ).then( setData ).catch( setError );
	};
	useEffect( () => {
		const t = setTimeout( load, search ? 300 : 0 );
		return () => clearTimeout( t );
	}, [ page, filter, search ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const lmsLabel = ( id ) => ( adapters.find( ( a ) => a.id === id ) || { label: id || '—' } ).label;
	const stop = ( fn ) => ( e ) => {
		e.stopPropagation();
		fn();
	};
	const duplicate = ( row ) =>
		request( `blueprints/${ row.id }/duplicate`, { method: 'POST' } )
			.then( ( bp ) => {
				toast.success( __( 'Course draft duplicated.', 'dev3-ai-course-builder' ) );
				navigate( `builder/${ bp.id }` );
			} )
			.catch( ( e ) => toast.error( e ) );
	const exportRow = ( row ) =>
		request( `blueprints/${ row.id }` )
			.then( ( bp ) => downloadJson( `course-${ row.id }.json`, bp.tree ) )
			.catch( ( e ) => toast.error( e ) );
	const confirmDelete = () => {
		setBusy( true );
		request( `blueprints/${ remove.id }`, { method: 'DELETE' } )
			.then( () => {
				setRemove( null );
				load();
				reloadStatus();
				toast.success( __( 'Course draft deleted.', 'dev3-ai-course-builder' ) );
			} )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => setBusy( false ) );
	};
	const importFile = ( e ) => {
		const file = e.target.files && e.target.files[ 0 ];
		e.target.value = '';
		if ( ! file ) {
			return;
		}
		file.text()
			.then( ( text ) => request( 'blueprints', { method: 'POST', data: { mode: 'import', tree: JSON.parse( text ) } } ) )
			.then( ( bp ) => navigate( `builder/${ bp.id }` ) )
			.catch( ( err ) => toast.error( err && err.message ? err : { message: __( 'That file is not a DEV3 course export.', 'dev3-ai-course-builder' ) } ) );
	};

	const columns = [
		{ key: 'title', label: __( 'Course', 'dev3-ai-course-builder' ), primary: true, render: ( r ) => <strong>{ r.title || __( '(untitled)', 'dev3-ai-course-builder' ) }</strong> },
		{ key: 'status', label: __( 'Status', 'dev3-ai-course-builder' ), render: ( r ) => <Badge tone={ ( STATUS[ r.status ] || STATUS.draft ).tone }>{ ( STATUS[ r.status ] || STATUS.draft ).label }</Badge> },
		{ key: 'lms', label: __( 'Target', 'dev3-ai-course-builder' ), render: ( r ) => lmsLabel( r.lms ) },
		{ key: 'size', label: __( 'Size', 'dev3-ai-course-builder' ), render: ( r ) => sprintf( /* translators: 1: sections, 2: lessons, 3: quizzes */ __( '%1$d sections · %2$d lessons · %3$d quizzes', 'dev3-ai-course-builder' ), r.counts.sections, r.counts.lessons, r.counts.quizzes ) },
		{ key: 'source', label: __( 'Source', 'dev3-ai-course-builder' ), hideOnMobile: true, render: ( r ) => ( r.source && r.source.file_name ) || r.source_type },
		{ key: 'updated', label: __( 'Updated', 'dev3-ai-course-builder' ), hideOnMobile: true, render: ( r ) => shortDate( r.updated_at ) },
	];

	return (
		<Stack gap={ 16 }>
			<PageHeader
				title={ __( 'Courses', 'dev3-ai-course-builder' ) }
				description={ __( 'Course drafts and the courses they created.', 'dev3-ai-course-builder' ) }
				actions={
					<div className="dev3lm-inline-actions">
						<Button variant="ghost" icon="file" onClick={ () => fileRef.current && fileRef.current.click() }>
							{ __( 'Import JSON', 'dev3-ai-course-builder' ) }
						</Button>
						<input ref={ fileRef } type="file" accept="application/json,.json" className="screen-reader-text" onChange={ importFile } tabIndex={ -1 } aria-hidden="true" />
						<Button variant="primary" icon="plus" onClick={ () => navigate( 'new' ) }>
							{ __( 'New course', 'dev3-ai-course-builder' ) }
						</Button>
					</div>
				}
			/>
			<div className="dev3lm-filters">
				<TextField label={ __( 'Search courses', 'dev3-ai-course-builder' ) } hideLabel placeholder={ __( 'Search courses…', 'dev3-ai-course-builder' ) } value={ search } onChange={ ( v ) => { setSearch( v ); setPage( 1 ); } } />
				<Select label={ __( 'Status', 'dev3-ai-course-builder' ) } hideLabel value={ filter } onChange={ ( v ) => { setFilter( v ); setPage( 1 ); } } options={ FILTERS } />
			</div>
			<ErrorNotice error={ error } onRetry={ load } />
			<Card flush>
				<ResponsiveTable
					columns={ columns }
					rows={ data ? data.items : [] }
					rowKey={ ( r ) => r.id }
					loading={ ! data && ! error }
					onRowClick={ ( r ) => navigate( `builder/${ r.id }` ) }
					rowActions={ ( r ) => (
						<span className="dev3lm-row-actions">
							<IconButton icon="copy" label={ sprintf( /* translators: %s: course */ __( 'Duplicate %s', 'dev3-ai-course-builder' ), r.title ) } onClick={ stop( () => duplicate( r ) ) } />
							<IconButton icon="external" label={ sprintf( /* translators: %s: course */ __( 'Export %s as JSON', 'dev3-ai-course-builder' ), r.title ) } onClick={ stop( () => exportRow( r ) ) } />
							<IconButton icon="trash" label={ sprintf( /* translators: %s: course */ __( 'Delete %s', 'dev3-ai-course-builder' ), r.title ) } onClick={ stop( () => setRemove( r ) ) } />
						</span>
					) }
					pagination={ data ? { page, perPage: 20, total: data.total, onPageChange: setPage } : undefined }
					empty={ { icon: 'sparkles', title: __( 'No courses yet', 'dev3-ai-course-builder' ), description: __( 'Start from a topic, an outline or a document.', 'dev3-ai-course-builder' ), action: <Button variant="primary" onClick={ () => navigate( 'new' ) }>{ __( 'New course', 'dev3-ai-course-builder' ) }</Button> } }
				/>
			</Card>
			<Modal
				open={ !! remove }
				onClose={ () => setRemove( null ) }
				size="sm"
				title={ __( 'Delete this course draft?', 'dev3-ai-course-builder' ) }
				description={ remove && remove.status === 'built' ? __( 'The course created in your LMS stays. Only the draft is deleted (undo the build first to remove the course too).', 'dev3-ai-course-builder' ) : __( 'The draft and its lesson texts are deleted.', 'dev3-ai-course-builder' ) }
				footer={
					<>
						<Button variant="ghost" onClick={ () => setRemove( null ) } data-autofocus>
							{ __( 'Cancel', 'dev3-ai-course-builder' ) }
						</Button>
						<Button variant="danger" onClick={ confirmDelete } loading={ busy }>
							{ __( 'Delete draft', 'dev3-ai-course-builder' ) }
						</Button>
					</>
				}
			/>
		</Stack>
	);
}
