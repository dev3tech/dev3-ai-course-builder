import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { AiButton, Badge, Button, Card, Checkbox, ErrorNotice, JobProgress, Modal, Notice, PageHeader, Select, SkeletonCard, Stack, useToast } from '../core3/ui';
import NodeEditor from './NodeEditor';
import TreeNav from './TreeNav';
import { LMS_OPTIONS, STATUS, counts as countTree, downloadJson, emptyLesson, emptyQuiz, emptySection, findNode, issues as treeIssues, moveLessonAcross, moveNode, updateNode } from './util';

const POST_STATUSES = [
	{ value: 'draft', label: __( 'Draft (review in the LMS first)', 'dev3-ai-course-builder' ) },
	{ value: 'publish', label: __( 'Published', 'dev3-ai-course-builder' ) },
	{ value: 'pending', label: __( 'Pending review', 'dev3-ai-course-builder' ) },
	{ value: 'private', label: __( 'Private', 'dev3-ai-course-builder' ) },
];

/**
 * Course builder: tree + editor, DEV3 AI drafting, background creation and undo.
 *
 * @param {Object}   props          Props.
 * @param {number}   props.id       Blueprint id.
 * @param {Object}   props.settings Settings.
 * @param {Object}   props.status   Status.
 * @param {Array}    props.adapters Adapters.
 * @param {Function} props.navigate Navigate.
 * @param {Function} props.onUsage  Usage changed.
 * @param {Function} props.reloadStatus Refresh dashboard data.
 * @return {Element} Builder.
 */
export default function Builder( { id, settings, status, adapters, navigate, onUsage, reloadStatus } ) {
	const toast = useToast();
	const [ bp, setBp ] = useState( null );
	const [ tree, setTree ] = useState( null );
	const [ selected, setSelected ] = useState( 'course' );
	const [ dirty, setDirty ] = useState( false );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ confirm, setConfirm ] = useState( false );
	const [ undo, setUndo ] = useState( false );
	const [ force, setForce ] = useState( false );
	const [ target, setTarget ] = useState( '' );
	const [ postStatus, setPostStatus ] = useState( settings.post_status || 'draft' );
	const [ busy, setBusy ] = useState( '' );
	const [ contentJob, setContentJob ] = useState( null );
	const editorRef = useRef( null );
	const latest = useRef( null );

	const ai = ( status && status.ai ) || {};
	const aiReady = ai.enabled && ai.key_set && ! ai.cap_reached;

	const applyBp = ( next, keepTree = false ) => {
		setBp( next );
		if ( ! keepTree ) {
			setTree( next.tree );
			setDirty( false );
		}
		setTarget( ( t ) => t || next.lms || settings.default_lms || 'auto' );
		const cj = next.content_job;
		setContentJob( cj && [ 'queued', 'running' ].includes( cj.status ) ? cj : null );
	};
	const load = () =>
		request( `blueprints/${ id }` )
			.then( ( next ) => applyBp( next ) )
			.catch( setError );
	useEffect( () => {
		load();
	}, [ id ] ); // eslint-disable-line react-hooks/exhaustive-deps

	latest.current = tree;
	const building = bp && bp.status === 'building';
	const locked = building || !! contentJob;

	const save = ( quiet = false ) => {
		if ( ! latest.current ) {
			return Promise.resolve( bp );
		}
		setSaving( true );
		const sent = latest.current;
		return request( `blueprints/${ id }`, { method: 'PUT', data: { tree: sent, lms: target === 'auto' ? '' : target } } )
			.then( ( next ) => {
				setBp( next );
				if ( latest.current === sent ) {
					setDirty( false );
				}
				if ( ! quiet ) {
					toast.success( __( 'Course draft saved.', 'dev3-ai-course-builder' ) );
				}
				return next;
			} )
			.catch( ( e ) => {
				toast.error( e );
				throw e;
			} )
			.finally( () => setSaving( false ) );
	};

	// Autosave 2.5 s after the last change (not while a job writes into the tree).
	useEffect( () => {
		if ( ! dirty || locked ) {
			return undefined;
		}
		const t = setTimeout( () => save( true ).catch( () => {} ), 2500 );
		return () => clearTimeout( t );
	}, [ tree, dirty, locked ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const change = ( next ) => {
		setTree( next );
		setDirty( true );
	};

	const found = useMemo( () => findNode( tree, selected ), [ tree, selected ] );
	const localIssues = useMemo( () => treeIssues( tree ), [ tree ] );
	const errorNodes = useMemo( () => new Set( localIssues.filter( ( i ) => i.level === 'error' ).map( ( i ) => i.node ) ), [ localIssues ] );
	const c = countTree( tree );
	const targetId = target === 'auto' || ! target ? ( status && status.auto ) || 'pages' : target;
	const adapter = adapters.find( ( a ) => a.id === targetId );

	const select = ( nodeId ) => {
		setSelected( nodeId );
		if ( editorRef.current && window.matchMedia && window.matchMedia( '(max-width: 959px)' ).matches ) {
			editorRef.current.scrollIntoView( { behavior: 'smooth', block: 'start' } );
		}
	};
	const addSection = () => {
		const s = emptySection( __( 'New section', 'dev3-ai-course-builder' ) );
		s.lessons = [ emptyLesson( __( 'New lesson', 'dev3-ai-course-builder' ) ) ];
		change( { ...tree, sections: [ ...tree.sections, s ] } );
		select( s.id );
	};
	const addLesson = ( sectionId ) => {
		const l = emptyLesson( __( 'New lesson', 'dev3-ai-course-builder' ) );
		change( updateNode( tree, sectionId, ( s ) => ( { ...s, lessons: [ ...s.lessons, l ] } ) ) );
		select( l.id );
	};
	const remove = ( nodeId ) => {
		const f = findNode( tree, nodeId );
		const label = f && f.node.title ? f.node.title : __( 'this item', 'dev3-ai-course-builder' );
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( sprintf( /* translators: %s: item title */ __( 'Delete "%s" from the course draft?', 'dev3-ai-course-builder' ), label ) ) ) {
			return;
		}
		change( updateNode( tree, nodeId, () => null ) );
		if ( selected === nodeId ) {
			setSelected( 'course' );
		}
	};

	const draftAll = () => {
		setBusy( 'draft' );
		save( true )
			.then( () => request( `blueprints/${ id }/draft-all`, { method: 'POST' } ) )
			.then( ( job ) => setContentJob( job ) )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => setBusy( '' ) );
	};

	const build = () => {
		setBusy( 'build' );
		save( true )
			.then( () => request( `blueprints/${ id }/build`, { method: 'POST', data: { lms: target === 'auto' ? '' : target, status: postStatus } } ) )
			.then( () => {
				setConfirm( false );
				return load();
			} )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => setBusy( '' ) );
	};

	const unbuild = () => {
		setBusy( 'undo' );
		request( `blueprints/${ id }/unbuild`, { method: 'POST', data: { force } } )
			.then( ( r ) => {
				setUndo( false );
				applyBp( r.blueprint );
				reloadStatus();
				toast.success( sprintf( /* translators: %d: number of items */ __( 'Build undone: %d items removed.', 'dev3-ai-course-builder' ), r.removed ) );
			} )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => setBusy( '' ) );
	};

	if ( error ) {
		return <ErrorNotice error={ error } onRetry={ load } />;
	}
	if ( ! bp || ! tree ) {
		return <SkeletonCard lines={ 8 } />;
	}
	const st = STATUS[ bp.status ] || STATUS.draft;
	const result = bp.result || {};
	const errors = localIssues.filter( ( i ) => i.level === 'error' );
	const warnings = localIssues.filter( ( i ) => i.level === 'warning' );
	const empty = tree.sections.reduce( ( n, s ) => n + s.lessons.filter( ( l ) => ! l.content.trim() ).length, 0 );

	return (
		<Stack gap={ 16 }>
			<PageHeader
				eyebrow={ __( 'Course builder', 'dev3-ai-course-builder' ) }
				title={ tree.course.title || __( 'Untitled course', 'dev3-ai-course-builder' ) }
				back={ { href: '#/courses', label: __( 'Courses', 'dev3-ai-course-builder' ) } }
				description={ sprintf(
					/* translators: 1: sections, 2: lessons, 3: written lessons, 4: quizzes, 5: questions */
					__( '%1$d sections · %2$d lessons (%3$d written) · %4$d quizzes · %5$d questions', 'dev3-ai-course-builder' ),
					c.sections,
					c.lessons,
					c.written,
					c.quizzes,
					c.questions
				) }
				actions={
					<div className="dev3lm-header-actions">
						<Badge tone={ st.tone }>{ st.label }</Badge>
						<span className="dev3lm-save-state" aria-live="polite">
							{ saving ? __( 'Saving…', 'dev3-ai-course-builder' ) : dirty ? __( 'Unsaved changes', 'dev3-ai-course-builder' ) : __( 'All changes saved', 'dev3-ai-course-builder' ) }
						</span>
						<Button onClick={ () => save() } loading={ saving } disabled={ locked || ! dirty }>
							{ __( 'Save', 'dev3-ai-course-builder' ) }
						</Button>
						<Button variant="ghost" icon="copy" onClick={ () => downloadJson( `course-${ id }.json`, tree ) }>
							{ __( 'Export', 'dev3-ai-course-builder' ) }
						</Button>
						{ bp.status !== 'built' && (
							<Button variant="primary" icon="check" onClick={ () => setConfirm( true ) } disabled={ locked || errors.length > 0 }>
								{ __( 'Create course', 'dev3-ai-course-builder' ) }
							</Button>
						) }
					</div>
				}
			/>

			{ building && bp.job_id > 0 && (
				<Card title={ __( 'Creating the course', 'dev3-ai-course-builder' ) }>
					<JobProgress
						jobId={ bp.job_id }
						job={ bp.job }
						title={ sprintf( /* translators: %s: LMS */ __( 'Creating in %s', 'dev3-ai-course-builder' ), adapter ? adapter.label : '' ) }
						onDone={ () => {
							load();
							reloadStatus();
						} }
					/>
				</Card>
			) }

			{ bp.status === 'built' && (
				<Notice
					tone="success"
					title={ __( 'Course created', 'dev3-ai-course-builder' ) }
					actions={
						<div className="dev3lm-inline-actions">
							{ result.links && result.links.edit && (
								<Button size="sm" variant="primary" href={ result.links.edit } icon="edit">
									{ __( 'Edit in LMS', 'dev3-ai-course-builder' ) }
								</Button>
							) }
							{ result.links && result.links.view && (
								<Button size="sm" href={ result.links.view } icon="external" target="_blank" rel="noreferrer">
									{ __( 'View course', 'dev3-ai-course-builder' ) }
								</Button>
							) }
							<Button size="sm" variant="ghost" icon="trash" onClick={ () => setUndo( true ) }>
								{ __( 'Undo build', 'dev3-ai-course-builder' ) }
							</Button>
						</div>
					}
				>
					{ result.failed > 0
						? sprintf( /* translators: %d: failed items */ __( 'Created with %d skipped items (see the job details). Later changes here are not synced: undo the build to create it again.', 'dev3-ai-course-builder' ), result.failed )
						: __( 'Everything was created. Later changes here are not synced: undo the build to create it again.', 'dev3-ai-course-builder' ) }
				</Notice>
			) }
			{ bp.status === 'failed' && (
				<Notice tone="error" title={ __( 'The course could not be created', 'dev3-ai-course-builder' ) } actions={ <Button size="sm" onClick={ () => setUndo( true ) }>{ __( 'Clean up and try again', 'dev3-ai-course-builder' ) }</Button> }>
					{ ( result.errors || [] ).slice( 0, 3 ).map( ( e ) => e.message ).join( ' ' ) || __( 'See the job details.', 'dev3-ai-course-builder' ) }
				</Notice>
			) }

			{ contentJob && (
				<Card tone="ai" title={ __( 'DEV3 AI is writing the lessons', 'dev3-ai-course-builder' ) } description={ __( 'Editing is paused until it finishes. Every lesson stays a draft for you to review.', 'dev3-ai-course-builder' ) }>
					<JobProgress
						jobId={ contentJob.id }
						job={ contentJob }
						title={ __( 'Drafting lessons', 'dev3-ai-course-builder' ) }
						onChange={ () => request( `blueprints/${ id }` ).then( ( next ) => setTree( next.tree ) ).catch( () => {} ) }
						onDone={ () => {
							setContentJob( null );
							load();
							onUsage();
						} }
					/>
				</Card>
			) }

			{ ( errors.length > 0 || warnings.length > 0 ) && (
				<details className="dev3lm-issues">
					<summary>
						{ errors.length > 0 && <Badge tone="danger">{ sprintf( /* translators: %d: errors */ __( '%d to fix', 'dev3-ai-course-builder' ), errors.length ) }</Badge> }
						{ warnings.length > 0 && <Badge tone="warning">{ sprintf( /* translators: %d: warnings */ __( '%d to review', 'dev3-ai-course-builder' ), warnings.length ) }</Badge> }
						<span>{ __( 'Checks before creating the course', 'dev3-ai-course-builder' ) }</span>
					</summary>
					<ul>
						{ [ ...errors, ...warnings ].slice( 0, 40 ).map( ( i, n ) => (
							<li key={ n }>
								<button type="button" className="dev3lm-linkbtn" onClick={ () => select( i.node ) }>
									{ i.message }
								</button>
							</li>
						) ) }
					</ul>
				</details>
			) }

			<div className="dev3lm-builder">
				<aside className="dev3lm-builder__tree">
					<Card
						title={ __( 'Structure', 'dev3-ai-course-builder' ) }
						actions={
							empty > 0 && ! locked ? (
								<AiButton size="sm" onClick={ draftAll } loading={ busy === 'draft' } disabled={ ! aiReady || !! busy }>
									{ sprintf( /* translators: %d: empty lessons */ __( 'Write %d lessons', 'dev3-ai-course-builder' ), empty ) }
								</AiButton>
							) : null
						}
					>
						<TreeNav
							tree={ tree }
							selected={ selected }
							onSelect={ select }
							onMove={ ( nid, dir ) => change( moveNode( tree, nid, dir ) ) }
							onMoveAcross={ ( nid, dir ) => change( moveLessonAcross( tree, nid, dir ) ) }
							onRemove={ remove }
							onAddSection={ addSection }
							onAddLesson={ addLesson }
							onAddFinalQuiz={ () => {
								const q = emptyQuiz( __( 'Final assessment', 'dev3-ai-course-builder' ) );
								change( { ...tree, quiz: q } );
								select( q.id );
							} }
							errorNodes={ errorNodes }
							disabled={ locked }
						/>
					</Card>
				</aside>
				<section className="dev3lm-builder__editor" ref={ editorRef } aria-label={ __( 'Editor', 'dev3-ai-course-builder' ) }>
					<NodeEditor
						found={ found }
						onChange={ ( node ) => change( updateNode( tree, selected, () => node ) ) }
						onAddLesson={ addLesson }
						blueprintId={ id }
						tree={ tree }
						aiReady={ aiReady }
						adapter={ adapter }
						settings={ settings }
						onUsage={ onUsage }
						disabled={ locked }
					/>
				</section>
			</div>

			<Modal
				open={ confirm }
				onClose={ () => setConfirm( false ) }
				title={ __( 'Create this course?', 'dev3-ai-course-builder' ) }
				description={ __( 'A background job creates everything in order. You can undo the build afterwards.', 'dev3-ai-course-builder' ) }
				footer={
					<>
						<Button variant="ghost" onClick={ () => setConfirm( false ) } data-autofocus>
							{ __( 'Cancel', 'dev3-ai-course-builder' ) }
						</Button>
						<Button variant="primary" onClick={ build } loading={ busy === 'build' } disabled={ adapter && ! adapter.available }>
							{ __( 'Create course', 'dev3-ai-course-builder' ) }
						</Button>
					</>
				}
			>
				<Stack gap={ 16 }>
					<Select label={ __( 'Create in', 'dev3-ai-course-builder' ) } value={ target || 'auto' } onChange={ setTarget } options={ LMS_OPTIONS( adapters ) } />
					<Select label={ __( 'Status of created items', 'dev3-ai-course-builder' ) } value={ postStatus } onChange={ setPostStatus } options={ POST_STATUSES } />
					<ul className="dev3lm-summary">
						<li>{ sprintf( /* translators: %d: sections */ __( '%d sections', 'dev3-ai-course-builder' ), c.sections ) }</li>
						<li>{ sprintf( /* translators: %d: lessons */ __( '%d lessons', 'dev3-ai-course-builder' ), c.lessons ) }</li>
						{ c.topics > 0 && <li>{ adapter && ! adapter.supports.topics ? sprintf( /* translators: %d: topics */ __( '%d topics (created as lessons)', 'dev3-ai-course-builder' ), c.topics ) : sprintf( /* translators: %d: topics */ __( '%d topics', 'dev3-ai-course-builder' ), c.topics ) }</li> }
						<li>{ sprintf( /* translators: 1: quizzes, 2: questions */ __( '%1$d quizzes with %2$d questions', 'dev3-ai-course-builder' ), c.quizzes, c.questions ) }</li>
					</ul>
					{ warnings.length > 0 && <Notice tone="warning">{ sprintf( /* translators: %d: warnings */ __( '%d items still need review (empty lessons get their summary as content; empty quizzes are skipped).', 'dev3-ai-course-builder' ), warnings.length ) }</Notice> }
				</Stack>
			</Modal>

			<Modal
				open={ undo }
				onClose={ () => setUndo( false ) }
				size="sm"
				title={ __( 'Undo the build?', 'dev3-ai-course-builder' ) }
				description={ __( 'Everything this course draft created (course, sections, lessons, quizzes, certificate) is moved to the trash. The draft stays, so you can create it again.', 'dev3-ai-course-builder' ) }
				footer={
					<>
						<Button variant="ghost" onClick={ () => setUndo( false ) } data-autofocus>
							{ __( 'Cancel', 'dev3-ai-course-builder' ) }
						</Button>
						<Button variant="danger" onClick={ unbuild } loading={ busy === 'undo' }>
							{ force ? __( 'Delete permanently', 'dev3-ai-course-builder' ) : __( 'Move to trash', 'dev3-ai-course-builder' ) }
						</Button>
					</>
				}
			>
				<Checkbox label={ __( 'Delete permanently instead (also removes quiz questions)', 'dev3-ai-course-builder' ) } checked={ force } onChange={ setForce } />
			</Modal>
		</Stack>
	);
}
