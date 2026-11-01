import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { AiButton, Badge, Button, Card, Checkbox, ErrorNotice, FormGrid, Notice, NumberField, PageHeader, Select, Stack, Tabs, Textarea, TextField, useToast } from '../core3/ui';
import OutlinePreview from './OutlinePreview';
import { LENGTHS, LEVELS, LMS_OPTIONS, QUESTION_TYPES, QUIZ_PLACES, TONES } from './util';

const MODES = [
	{ id: 'topic', label: __( 'Topic', 'dev3-ai-course-builder' ) },
	{ id: 'outline', label: __( 'Outline', 'dev3-ai-course-builder' ) },
	{ id: 'file', label: __( 'PDF / DOCX', 'dev3-ai-course-builder' ) },
];

const SAMPLE_OUTLINE = `# Home Coffee Brewing

## Module 1: Beans and grind
### Choosing fresh beans
### Grind size explained

## Module 2: Brewing methods
### Pour-over step by step
### French press basics

## Quiz
What grind size suits a French press?
- [x] Coarse
- [ ] Extra fine`;

/**
 * Opens the WordPress media library for one course document.
 *
 * @param {Function} onSelect ( { id, filename, url, mime } ) => void.
 */
function pickFile( onSelect ) {
	if ( ! window.wp || ! window.wp.media ) {
		return;
	}
	const frame = window.wp.media( {
		title: __( 'Choose course material', 'dev3-ai-course-builder' ),
		button: { text: __( 'Use this file', 'dev3-ai-course-builder' ) },
		library: { type: [ 'application/pdf', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'text/plain' ] },
		multiple: false,
	} );
	frame.on( 'select', () => {
		const a = frame.state().get( 'selection' ).first().toJSON();
		onSelect( { id: a.id, filename: a.filename, url: a.url, mime: a.mime, size: a.filesizeHumanReadable || '' } );
	} );
	frame.open();
}

/**
 * New course: source + options → AI outline draft or non-AI tree → builder.
 *
 * @param {Object}   props          Props.
 * @param {string}   props.mode     Initial mode from the route.
 * @param {Object}   props.settings Settings.
 * @param {Object}   props.status   Status.
 * @param {Array}    props.adapters Adapters.
 * @param {Function} props.navigate Navigate.
 * @param {Function} props.onUsage  Usage changed.
 * @return {Element} Screen.
 */
export default function NewCourse( { mode: initialMode, settings, status, adapters, navigate, onUsage } ) {
	const toast = useToast();
	const [ mode, setMode ] = useState( MODES.some( ( m ) => m.id === initialMode ) ? initialMode : 'topic' );
	const [ title, setTitle ] = useState( '' );
	const [ topic, setTopic ] = useState( '' );
	const [ goals, setGoals ] = useState( '' );
	const [ outline, setOutline ] = useState( '' );
	const [ file, setFile ] = useState( null );
	const [ lms, setLms ] = useState( settings.default_lms || 'auto' );
	const [ opts, setOpts ] = useState( {
		audience: settings.audience || '',
		level: settings.level,
		language: settings.language,
		tone: settings.tone,
		sections: settings.sections,
		lessons_per_section: settings.lessons_per_section,
		topics_per_lesson: settings.topics_per_lesson,
		quiz_placement: settings.quiz_placement,
		questions_per_quiz: settings.questions_per_quiz,
		question_types: settings.question_types || [ 'single' ],
		lesson_length: settings.lesson_length,
	} );
	const [ preview, setPreview ] = useState( null );
	const [ blueprint, setBlueprint ] = useState( null );
	const [ draft, setDraft ] = useState( null );
	const [ busy, setBusy ] = useState( '' );
	const [ error, setError ] = useState( null );

	const ai = ( status && status.ai ) || {};
	const aiReady = ai.enabled && ai.key_set && ! ai.cap_reached;
	const set = ( key ) => ( value ) => setOpts( { ...opts, [ key ]: value } );
	const targetId = lms === 'auto' ? ( status && status.auto ) || 'pages' : lms;
	const target = adapters.find( ( a ) => a.id === targetId );
	const supportsTopics = target ? target.supports.topics : true;
	const allowedTypes = target ? target.supports.types : QUESTION_TYPES.map( ( t ) => t.value );

	const hasSource = ( mode === 'topic' && ( topic.trim() || title.trim() ) ) || ( mode === 'outline' && outline.trim() ) || ( mode === 'file' && file );

	const body = ( extra = {} ) => ( {
		mode,
		title,
		topic: mode === 'topic' ? topic : title,
		outline: mode === 'outline' ? outline : '',
		attachment_id: mode === 'file' && file ? file.id : 0,
		lms: lms === 'auto' ? '' : lms,
		options: { ...opts, goals, topics_per_lesson: supportsTopics ? opts.topics_per_lesson : 0 },
		...extra,
	} );

	const createBlueprint = ( extra ) => request( 'blueprints', { method: 'POST', data: body( extra ) } );

	const runPreview = () => {
		setBusy( 'preview' );
		setError( null );
		request( 'source/parse', { method: 'POST', data: mode === 'file' ? { attachment_id: file.id } : { text: outline } } )
			.then( setPreview )
			.catch( setError )
			.finally( () => setBusy( '' ) );
	};

	const generate = () => {
		setBusy( 'ai' );
		setError( null );
		const ensure = blueprint ? Promise.resolve( blueprint ) : createBlueprint();
		ensure
			.then( ( bp ) => {
				setBlueprint( bp );
				return request( `blueprints/${ bp.id }/outline`, { method: 'POST', data: { options: body().options, topic: mode === 'topic' ? topic : '' } } );
			} )
			.then( ( res ) => {
				setDraft( res );
				onUsage();
			} )
			.catch( setError )
			.finally( () => setBusy( '' ) );
	};

	const acceptDraft = () => {
		setBusy( 'accept' );
		request( `blueprints/${ blueprint.id }`, { method: 'PUT', data: { tree: draft.tree } } )
			.then( ( bp ) => {
				toast.success( __( 'Outline saved. Review and write the lessons next.', 'dev3-ai-course-builder' ) );
				navigate( `builder/${ bp.id }` );
			} )
			.catch( setError )
			.finally( () => setBusy( '' ) );
	};

	const withoutAi = ( extra ) => {
		setBusy( 'basic' );
		setError( null );
		createBlueprint( extra )
			.then( ( bp ) => navigate( `builder/${ bp.id }` ) )
			.catch( setError )
			.finally( () => setBusy( '' ) );
	};

	const toggleType = ( type ) => ( on ) => {
		const next = on ? [ ...opts.question_types, type ] : opts.question_types.filter( ( t ) => t !== type );
		set( 'question_types' )( next.length ? next : [ type ] );
	};

	return (
		<Stack gap={ 24 }>
			<PageHeader
				title={ __( 'New course', 'dev3-ai-course-builder' ) }
				description={ __( 'Pick a source and the shape of the course. You review the whole course tree before anything is created in your LMS.', 'dev3-ai-course-builder' ) }
				back={ { href: '#/dashboard', label: __( 'Dashboard', 'dev3-ai-course-builder' ) } }
			/>
			<Card title={ __( '1. Source', 'dev3-ai-course-builder' ) }>
				<Stack gap={ 16 }>
					<div className="dev3lm-tabs-scroll">
						<Tabs
							variant="pills"
							label={ __( 'Course source', 'dev3-ai-course-builder' ) }
							tabs={ MODES }
							value={ mode }
							onChange={ ( m ) => {
								setMode( m );
								setPreview( null );
								setDraft( null );
								setBlueprint( null );
							} }
						/>
					</div>
					<TextField label={ __( 'Course title', 'dev3-ai-course-builder' ) } value={ title } onChange={ setTitle } help={ __( 'Optional. DEV3 AI suggests one when it is empty.', 'dev3-ai-course-builder' ) } maxLength={ 200 } />
					{ mode === 'topic' && (
						<>
							<Textarea
								label={ __( 'What is the course about?', 'dev3-ai-course-builder' ) }
								value={ topic }
								onChange={ ( v ) => {
									setTopic( v );
									setBlueprint( null );
								} }
								rows={ 4 }
								maxLength={ 2000 }
								placeholder={ __( 'e.g. A practical beginner course on brewing great coffee at home: beans, grinding, pour-over, espresso basics and milk.', 'dev3-ai-course-builder' ) }
							/>
							<Textarea label={ __( 'Learning goals (optional)', 'dev3-ai-course-builder' ) } value={ goals } onChange={ setGoals } rows={ 2 } maxLength={ 1000 } />
						</>
					) }
					{ mode === 'outline' && (
						<>
							<Textarea
								label={ __( 'Outline', 'dev3-ai-course-builder' ) }
								value={ outline }
								onChange={ ( v ) => {
									setOutline( v );
									setBlueprint( null );
								} }
								rows={ 12 }
								className="dev3lm-mono"
								help={ __( 'Use # for the course title, ## for sections and ### for lessons, or "Module 1:" / "Lesson 1:" lines, or indented bullets. Text under a heading becomes lesson content. A "Quiz" heading with questions ending in "?" becomes a quiz.', 'dev3-ai-course-builder' ) }
							/>
							<div className="dev3lm-inline-actions">
								<Button size="sm" variant="ghost" onClick={ () => setOutline( SAMPLE_OUTLINE ) }>
									{ __( 'Insert an example', 'dev3-ai-course-builder' ) }
								</Button>
							</div>
						</>
					) }
					{ mode === 'file' && (
						<div className="dev3lm-file">
							<Button icon="file" onClick={ () => pickFile( ( f ) => { setFile( f ); setPreview( null ); setDraft( null ); setBlueprint( null ); } ) }>
								{ file ? __( 'Choose another file', 'dev3-ai-course-builder' ) : __( 'Upload or choose a file', 'dev3-ai-course-builder' ) }
							</Button>
							{ file ? (
								<p className="dev3lm-file__name">
									<strong>{ file.filename }</strong> { file.size && <span className="dev3lm-muted">({ file.size })</span> }
								</p>
							) : (
								<p className="dev3lm-muted">{ __( 'PDF, DOCX, TXT or Markdown, up to 15 MB. Scanned PDFs need DEV3 AI (it reads the pages).', 'dev3-ai-course-builder' ) }</p>
							) }
						</div>
					) }
					{ ( mode === 'outline' || mode === 'file' ) && (
						<div className="dev3lm-inline-actions">
							<Button variant="secondary" icon="search" onClick={ runPreview } loading={ busy === 'preview' } disabled={ ! hasSource }>
								{ __( 'Preview structure', 'dev3-ai-course-builder' ) }
							</Button>
						</div>
					) }
					{ preview && (
						<div className="dev3lm-preview">
							{ preview.scanned ? (
								<Notice tone="warning" title={ __( 'No text found in this file', 'dev3-ai-course-builder' ) }>
									{ __( 'It is probably a scanned PDF. Generate the outline with DEV3 AI, which reads the pages, or paste the outline instead.', 'dev3-ai-course-builder' ) }
								</Notice>
							) : (
								<>
									<p className="dev3lm-muted">{ sprintf( /* translators: %d: words */ __( 'Found %d words. This is the structure without AI:', 'dev3-ai-course-builder' ), preview.words ) }</p>
									<OutlinePreview tree={ preview.tree } max={ 6 } />
								</>
							) }
						</div>
					) }
				</Stack>
			</Card>
			<Card title={ __( '2. Course shape', 'dev3-ai-course-builder' ) } description={ __( 'Defaults come from Settings.', 'dev3-ai-course-builder' ) }>
				<Stack gap={ 16 }>
					<FormGrid>
						<Select label={ __( 'Create in', 'dev3-ai-course-builder' ) } value={ lms } onChange={ setLms } options={ LMS_OPTIONS( adapters ) } help={ target ? sprintf( /* translators: %s: LMS */ __( 'Target: %s', 'dev3-ai-course-builder' ), target.label ) : '' } />
						<TextField label={ __( 'Audience', 'dev3-ai-course-builder' ) } value={ opts.audience } onChange={ set( 'audience' ) } placeholder={ __( 'e.g. busy beginners', 'dev3-ai-course-builder' ) } />
						<Select label={ __( 'Level', 'dev3-ai-course-builder' ) } value={ opts.level } onChange={ set( 'level' ) } options={ LEVELS } />
						<TextField label={ __( 'Language', 'dev3-ai-course-builder' ) } value={ opts.language } onChange={ set( 'language' ) } />
						<Select label={ __( 'Tone', 'dev3-ai-course-builder' ) } value={ opts.tone } onChange={ set( 'tone' ) } options={ TONES } />
						<Select label={ __( 'Lesson length', 'dev3-ai-course-builder' ) } value={ opts.lesson_length } onChange={ set( 'lesson_length' ) } options={ LENGTHS } />
						<NumberField label={ __( 'Sections', 'dev3-ai-course-builder' ) } value={ opts.sections } onChange={ set( 'sections' ) } min={ 1 } max={ 30 } />
						<NumberField label={ __( 'Lessons per section', 'dev3-ai-course-builder' ) } value={ opts.lessons_per_section } onChange={ set( 'lessons_per_section' ) } min={ 1 } max={ 20 } />
						{ supportsTopics && <NumberField label={ __( 'Topics per lesson', 'dev3-ai-course-builder' ) } value={ opts.topics_per_lesson } onChange={ set( 'topics_per_lesson' ) } min={ 0 } max={ 10 } help={ __( 'Sub-lessons (LearnDash topics, child pages).', 'dev3-ai-course-builder' ) } /> }
						<Select label={ __( 'Quizzes', 'dev3-ai-course-builder' ) } value={ opts.quiz_placement } onChange={ set( 'quiz_placement' ) } options={ QUIZ_PLACES } />
						<NumberField label={ __( 'Questions per quiz', 'dev3-ai-course-builder' ) } value={ opts.questions_per_quiz } onChange={ set( 'questions_per_quiz' ) } min={ 1 } max={ 30 } />
					</FormGrid>
					<fieldset className="dev3lm-fieldset">
						<legend>{ __( 'Question types', 'dev3-ai-course-builder' ) }</legend>
						<div className="dev3lm-checks">
							{ QUESTION_TYPES.filter( ( t ) => allowedTypes.includes( t.value ) ).map( ( t ) => (
								<Checkbox key={ t.value } label={ t.label } checked={ opts.question_types.includes( t.value ) } onChange={ toggleType( t.value ) } />
							) ) }
						</div>
					</fieldset>
				</Stack>
			</Card>
			<Card tone="ai" title={ __( '3. Draft the course', 'dev3-ai-course-builder' ) }>
				<Stack gap={ 16 }>
					<ErrorNotice error={ error } onRetry={ busy ? undefined : generate } onDismiss={ () => setError( null ) } />
					{ ! aiReady && (
						<Notice tone="info">
							{ ai.key_set ? __( 'DEV3 AI is paused (switched off or the monthly budget is reached). You can still build the course without AI.', 'dev3-ai-course-builder' ) : __( 'Add a Google Gemini API key in API Config to generate with DEV3 AI. You can still build the course without AI.', 'dev3-ai-course-builder' ) }
						</Notice>
					) }
					<div className="dev3lm-inline-actions">
						<AiButton onClick={ generate } loading={ busy === 'ai' } loadingText={ __( 'Designing the course… (up to a minute)', 'dev3-ai-course-builder' ) } disabled={ ! hasSource || ! aiReady || !! busy }>
							{ __( 'Generate outline with DEV3 AI', 'dev3-ai-course-builder' ) }
						</AiButton>
						{ mode === 'topic' ? (
							<Button onClick={ () => withoutAi( { template: true } ) } loading={ busy === 'basic' } disabled={ ! hasSource || !! busy }>
								{ __( 'Start from a template', 'dev3-ai-course-builder' ) }
							</Button>
						) : (
							<Button onClick={ () => withoutAi() } loading={ busy === 'basic' } disabled={ ! hasSource || !! busy }>
								{ __( 'Build from this source (no AI)', 'dev3-ai-course-builder' ) }
							</Button>
						) }
						<Button variant="ghost" onClick={ () => withoutAi( { mode: 'blank', title: title || topic.split( '\n' )[ 0 ] } ) } disabled={ !! busy }>
							{ __( 'Blank course', 'dev3-ai-course-builder' ) }
						</Button>
					</div>
					{ draft && (
						<div className="dev3lm-draft" aria-live="polite">
							<div className="dev3lm-draft__head">
								<p className="dev3lm-draft__title">{ __( 'DEV3 AI · Course outline draft', 'dev3-ai-course-builder' ) }</p>
								<Badge tone="warning">{ __( 'Not saved yet', 'dev3-ai-course-builder' ) }</Badge>
							</div>
							<OutlinePreview tree={ draft.tree } />
							<p className="dev3lm-muted dev3lm-small">
								{ draft.model } · { sprintf( /* translators: %d: tokens */ __( '%d tokens', 'dev3-ai-course-builder' ), draft.tokens ) }
								{ draft.cached ? ` · ${ __( 'cached', 'dev3-ai-course-builder' ) }` : '' }
							</p>
							<div className="dev3lm-inline-actions">
								<Button variant="primary" icon="check" onClick={ acceptDraft } loading={ busy === 'accept' }>
									{ __( 'Use this outline', 'dev3-ai-course-builder' ) }
								</Button>
								<Button icon="refresh" onClick={ generate } loading={ busy === 'ai' } disabled={ !! busy }>
									{ __( 'Regenerate', 'dev3-ai-course-builder' ) }
								</Button>
								<Button variant="ghost" onClick={ () => setDraft( null ) }>
									{ __( 'Discard', 'dev3-ai-course-builder' ) }
								</Button>
							</div>
						</div>
					) }
				</Stack>
			</Card>
		</Stack>
	);
}
