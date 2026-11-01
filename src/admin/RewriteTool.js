import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../core3/api';
import { AiButton, AiDraftReview, Badge, Button, Card, ErrorNotice, FormGrid, PageHeader, ResponsiveTable, Select, Stack, Textarea, TextField, useToast } from '../core3/ui';
import LessonPicker from './LessonPicker';
import { MarkdownPreview } from './markdown';
import { TONES, shortDate } from './util';

const MODES = [
	{ value: 'simplify', label: __( 'Simplify for beginners', 'dev3-ai-course-builder' ) },
	{ value: 'expand', label: __( 'Expand with more detail', 'dev3-ai-course-builder' ) },
	{ value: 'examples', label: __( 'Add examples and an exercise', 'dev3-ai-course-builder' ) },
	{ value: 'shorten', label: __( 'Make it shorter', 'dev3-ai-course-builder' ) },
	{ value: 'clarity', label: __( 'Fix grammar and clarity', 'dev3-ai-course-builder' ) },
	{ value: 'tone', label: __( 'Change the tone', 'dev3-ai-course-builder' ) },
	{ value: 'reading', label: __( 'Change the reading level', 'dev3-ai-course-builder' ) },
	{ value: 'translate', label: __( 'Translate', 'dev3-ai-course-builder' ) },
];
const modeLabel = ( m ) => ( MODES.find( ( x ) => x.value === m ) || { label: __( 'Manual edit', 'dev3-ai-course-builder' ) } ).label;

/**
 * Lesson rewriter with history and undo.
 *
 * @param {Object}   props          Props.
 * @param {number}   props.lessonId Lesson from the route.
 * @param {Object}   props.settings Settings.
 * @param {Object}   props.status   Status.
 * @param {Function} props.onUsage  Usage changed.
 * @param {Function} props.reloadStatus Refresh status.
 * @return {Element} Screen.
 */
export default function RewriteTool( { lessonId, settings, status, onUsage, reloadStatus } ) {
	const toast = useToast();
	const [ lesson, setLesson ] = useState( null );
	const [ mode, setMode ] = useState( 'simplify' );
	const [ tone, setTone ] = useState( settings.tone || 'friendly' );
	const [ reading, setReading ] = useState( 'grade 8 (plain English)' );
	const [ language, setLanguage ] = useState( 'Spanish' );
	const [ extra, setExtra ] = useState( '' );
	const [ draft, setDraft ] = useState( null );
	const [ history, setHistory ] = useState( null );
	const [ busy, setBusy ] = useState( '' );
	const [ error, setError ] = useState( null );
	const ai = ( status && status.ai ) || {};
	const aiReady = ai.enabled && ai.key_set && ! ai.cap_reached;

	const loadLesson = ( id ) => request( `lessons/${ id }` ).then( setLesson ).catch( setError );
	const loadHistory = () => request( 'rewrites' ).then( ( r ) => setHistory( r.items ) ).catch( () => setHistory( [] ) );
	useEffect( () => {
		if ( lessonId ) {
			loadLesson( lessonId );
		}
		loadHistory();
	}, [ lessonId ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const pick = ( row ) => {
		setDraft( null );
		setError( null );
		if ( row ) {
			loadLesson( row.id );
		} else {
			setLesson( null );
		}
	};

	const generate = () => {
		setBusy( 'ai' );
		setError( null );
		request( 'tools/rewrite', { method: 'POST', data: { lesson_id: lesson.id, mode, extra, tone, reading_level: reading, target_language: language } } )
			.then( ( r ) => {
				setDraft( r );
				onUsage();
			} )
			.catch( setError )
			.finally( () => setBusy( '' ) );
	};

	const apply = ( content ) => {
		setBusy( 'apply' );
		request( 'tools/rewrite/apply', { method: 'POST', data: { lesson_id: lesson.id, content, mode } } )
			.then( () => {
				toast.success( __( 'Lesson updated. You can undo it below.', 'dev3-ai-course-builder' ) );
				setDraft( null );
				loadLesson( lesson.id );
				loadHistory();
				reloadStatus();
			} )
			.catch( setError )
			.finally( () => setBusy( '' ) );
	};

	const undo = ( row, force = false ) => {
		setBusy( `undo${ row.id }` );
		request( `rewrites/${ row.id }/undo`, { method: 'POST', data: { force } } )
			.then( ( r ) => {
				setHistory( r.items );
				toast.success( __( 'Rewrite undone.', 'dev3-ai-course-builder' ) );
				if ( lesson && lesson.id === row.post_id ) {
					loadLesson( lesson.id );
				}
				reloadStatus();
			} )
			.catch( ( e ) => {
				// eslint-disable-next-line no-alert
				if ( e.code === 'dev3lm_changed' && window.confirm( e.message ) ) {
					undo( row, true );
					return;
				}
				toast.error( e );
			} )
			.finally( () => setBusy( '' ) );
	};

	const columns = [
		{ key: 'post_title', label: __( 'Lesson', 'dev3-ai-course-builder' ), primary: true, render: ( r ) => r.post_title || `#${ r.post_id }` },
		{ key: 'mode', label: __( 'Change', 'dev3-ai-course-builder' ), render: ( r ) => modeLabel( r.mode ) },
		{ key: 'when', label: __( 'When', 'dev3-ai-course-builder' ), hideOnMobile: true, render: ( r ) => shortDate( r.created_at ) },
		{ key: 'state', label: __( 'State', 'dev3-ai-course-builder' ), render: ( r ) => ( r.undone ? <Badge>{ __( 'Undone', 'dev3-ai-course-builder' ) }</Badge> : <Badge tone="success">{ __( 'Applied', 'dev3-ai-course-builder' ) }</Badge> ) },
	];

	return (
		<Stack gap={ 16 }>
			<PageHeader title={ __( 'Lesson rewriter', 'dev3-ai-course-builder' ) } description={ __( 'Rewrite an existing lesson with DEV3 AI. Compare the changes, edit, then save it as native blocks. Every rewrite can be undone.', 'dev3-ai-course-builder' ) } />
			<LessonPicker title={ __( '1. Lesson', 'dev3-ai-course-builder' ) } lesson={ lesson } onSelect={ pick } />
			{ lesson && (
				<Card tone="ai" title={ __( '2. Rewrite', 'dev3-ai-course-builder' ) }>
					<Stack gap={ 16 }>
						<FormGrid>
							<Select label={ __( 'How', 'dev3-ai-course-builder' ) } value={ mode } onChange={ setMode } options={ MODES } />
							{ mode === 'tone' && <Select label={ __( 'Tone', 'dev3-ai-course-builder' ) } value={ tone } onChange={ setTone } options={ TONES } /> }
							{ mode === 'reading' && <TextField label={ __( 'Reading level', 'dev3-ai-course-builder' ) } value={ reading } onChange={ setReading } maxLength={ 60 } /> }
							{ mode === 'translate' && <TextField label={ __( 'Target language', 'dev3-ai-course-builder' ) } value={ language } onChange={ setLanguage } maxLength={ 40 } /> }
						</FormGrid>
						<Textarea label={ __( 'Extra instructions (optional)', 'dev3-ai-course-builder' ) } value={ extra } onChange={ setExtra } rows={ 2 } maxLength={ 600 } />
						<div className="dev3lm-inline-actions">
							<AiButton onClick={ generate } loading={ busy === 'ai' } loadingText={ __( 'Rewriting…', 'dev3-ai-course-builder' ) } disabled={ ! aiReady || !! busy || lesson.words < 5 }>
								{ __( 'Rewrite with DEV3 AI', 'dev3-ai-course-builder' ) }
							</AiButton>
						</div>
						<ErrorNotice error={ error } onDismiss={ () => setError( null ) } onRetry={ generate } />
						{ draft && (
							<>
								<AiDraftReview
									original={ draft.original }
									draft={ draft.content }
									meta={ { model: draft.model, tokens: draft.tokens, cached: draft.cached } }
									onAccept={ apply }
									onReject={ () => setDraft( null ) }
									onRegenerate={ generate }
									regenerating={ busy === 'ai' }
									accepting={ busy === 'apply' }
									acceptLabel={ __( 'Save to lesson', 'dev3-ai-course-builder' ) }
								/>
								<details className="dev3lm-details">
									<summary>{ __( 'Preview the rewritten lesson', 'dev3-ai-course-builder' ) }</summary>
									<div className="dev3lm-preview-box">
										<MarkdownPreview md={ draft.content } />
									</div>
								</details>
							</>
						) }
					</Stack>
				</Card>
			) }
			<Card title={ __( 'Rewrite history', 'dev3-ai-course-builder' ) } flush>
				<ResponsiveTable
					columns={ columns }
					rows={ history || [] }
					rowKey={ ( r ) => r.id }
					loading={ history === null }
					rowActions={ ( r ) =>
						r.can_undo ? (
							<Button
								size="sm"
								icon="refresh"
								loading={ busy === `undo${ r.id }` }
								onClick={ ( e ) => {
									e.stopPropagation();
									undo( r );
								} }
							>
								{ __( 'Undo', 'dev3-ai-course-builder' ) }
							</Button>
						) : null
					}
					empty={ { icon: 'clock', title: __( 'No rewrites yet', 'dev3-ai-course-builder' ), description: __( 'Saved rewrites appear here and can be undone.', 'dev3-ai-course-builder' ) } }
				/>
			</Card>
		</Stack>
	);
}
