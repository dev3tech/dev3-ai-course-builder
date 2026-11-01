import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { AiButton, AiDraftReview, Badge, Button, Card, ErrorNotice, FormGrid, Notice, NumberField, Select, Stack, Tabs, Textarea, TextField, Toggle } from '../core3/ui';
import { MarkdownPreview, words } from './markdown';
import QuestionEditor from './QuestionEditor';
import { LENGTHS, LEVELS, emptyQuiz, emptyTopic, typeLabel } from './util';

const DETAIL_FIELDS = [
	{ key: 'description', label: __( 'Description', 'dev3-ai-course-builder' ), multiline: true, maxLength: 5000 },
	{ key: 'excerpt', label: __( 'Excerpt', 'dev3-ai-course-builder' ), multiline: true, maxLength: 600 },
	{ key: 'outcomes', label: __( 'Learning outcomes (one per line)', 'dev3-ai-course-builder' ), multiline: true, maxLength: 2000 },
	{ key: 'certificate_title', label: __( 'Certificate title', 'dev3-ai-course-builder' ), maxLength: 160 },
	{ key: 'certificate_body', label: __( 'Certificate text', 'dev3-ai-course-builder' ), multiline: true, maxLength: 1200 },
];

const lines = ( v ) => String( v || '' ).split( '\n' ).map( ( s ) => s.trim() ).filter( Boolean );

/**
 * AI draft meta.
 *
 * @param {Object} r Response.
 * @return {Object} Meta.
 */
const meta = ( r ) => ( { model: r.model, tokens: r.tokens, cached: r.cached } );

/**
 * Course details editor.
 *
 * @param {Object} props Props.
 * @return {Element} Editor.
 */
function CourseForm( { course, onChange, ai, adapter } ) {
	const [ draft, setDraft ] = useState( null );
	const set = ( key ) => ( value ) => onChange( { ...course, [ key ]: value } );
	const setCert = ( key ) => ( value ) => onChange( { ...course, certificate: { ...course.certificate, [ key ]: value } } );
	const current = {
		description: course.description,
		excerpt: course.excerpt,
		outcomes: course.outcomes.join( '\n' ),
		certificate_title: course.certificate.title,
		certificate_body: course.certificate.body,
	};
	const run = ( mode ) => ai.run( 'details', { mode }, ( r ) => setDraft( r ) );
	const accept = ( v ) => {
		onChange( { ...course, description: v.description, excerpt: v.excerpt, outcomes: lines( v.outcomes ), certificate: { title: v.certificate_title, body: v.certificate_body } } );
		setDraft( null );
	};
	return (
		<Stack gap={ 16 }>
			<Card title={ __( 'Course details', 'dev3-ai-course-builder' ) }>
				<Stack gap={ 16 }>
					<TextField label={ __( 'Course title', 'dev3-ai-course-builder' ) } value={ course.title } onChange={ set( 'title' ) } required maxLength={ 200 } />
					<Textarea label={ __( 'Description', 'dev3-ai-course-builder' ) } value={ course.description } onChange={ set( 'description' ) } rows={ 6 } maxLength={ 5000 } help={ __( 'Shown on the course page. Blank lines start new paragraphs.', 'dev3-ai-course-builder' ) } />
					<Textarea label={ __( 'Excerpt', 'dev3-ai-course-builder' ) } value={ course.excerpt } onChange={ set( 'excerpt' ) } rows={ 2 } maxLength={ 600 } />
					<FormGrid>
						<Select label={ __( 'Level', 'dev3-ai-course-builder' ) } value={ course.level } onChange={ set( 'level' ) } options={ LEVELS } />
						<TextField label={ __( 'Audience', 'dev3-ai-course-builder' ) } value={ course.audience } onChange={ set( 'audience' ) } maxLength={ 300 } />
						<NumberField label={ __( 'Duration (minutes)', 'dev3-ai-course-builder' ) } value={ course.duration } onChange={ ( v ) => set( 'duration' )( v === '' ? 0 : v ) } min={ 0 } max={ 100000 } />
					</FormGrid>
					<Textarea label={ __( 'Learning outcomes (one per line)', 'dev3-ai-course-builder' ) } value={ course.outcomes.join( '\n' ) } onChange={ ( v ) => set( 'outcomes' )( v.split( '\n' ) ) } rows={ 4 } />
					<Textarea label={ __( 'Requirements (one per line)', 'dev3-ai-course-builder' ) } value={ course.requirements.join( '\n' ) } onChange={ ( v ) => set( 'requirements' )( v.split( '\n' ) ) } rows={ 3 } />
				</Stack>
			</Card>
			<Card
				title={ __( 'Certificate', 'dev3-ai-course-builder' ) }
				description={
					adapter && adapter.id === 'learndash'
						? __( 'Created as a LearnDash certificate and linked to the course.', 'dev3-ai-course-builder' )
						: __( 'Printed by the Course Certificate block for learners who completed the course.', 'dev3-ai-course-builder' )
				}
			>
				<Stack gap={ 16 }>
					<TextField label={ __( 'Certificate title', 'dev3-ai-course-builder' ) } value={ course.certificate.title } onChange={ setCert( 'title' ) } maxLength={ 160 } />
					<Textarea label={ __( 'Certificate text', 'dev3-ai-course-builder' ) } value={ course.certificate.body } onChange={ setCert( 'body' ) } rows={ 3 } maxLength={ 1200 } help={ __( 'Placeholders: {learner}, {course}, {date}, {site}.', 'dev3-ai-course-builder' ) } />
				</Stack>
			</Card>
			<Card tone="ai" title={ __( 'Write with DEV3 AI', 'dev3-ai-course-builder' ) } description={ __( 'Description, excerpt, outcomes and certificate text from the course structure.', 'dev3-ai-course-builder' ) }>
				<Stack gap={ 12 }>
					<div className="dev3lm-inline-actions">
						<AiButton onClick={ () => run( 'ai' ) } loading={ ai.busy === 'details' } disabled={ ! ai.ready || !! ai.busy }>
							{ __( 'Write description & certificate', 'dev3-ai-course-builder' ) }
						</AiButton>
						<Button onClick={ () => run( 'basic' ) } disabled={ !! ai.busy }>
							{ __( 'Use a template (no AI)', 'dev3-ai-course-builder' ) }
						</Button>
					</div>
					<ErrorNotice error={ ai.error } onDismiss={ ai.clear } />
					{ draft && (
						<AiDraftReview
							title={ draft.basic ? __( 'Template draft', 'dev3-ai-course-builder' ) : __( 'DEV3 AI draft', 'dev3-ai-course-builder' ) }
							fields={ DETAIL_FIELDS }
							original={ current }
							draft={ draft.draft }
							meta={ draft.basic ? undefined : meta( draft ) }
							onAccept={ accept }
							onReject={ () => setDraft( null ) }
							onRegenerate={ draft.basic ? undefined : () => run( 'ai' ) }
							regenerating={ ai.busy === 'details' }
						/>
					) }
				</Stack>
			</Card>
		</Stack>
	);
}

/**
 * Section editor.
 *
 * @param {Object} props Props.
 * @return {Element} Editor.
 */
function SectionForm( { section, onChange, onAddLesson, adapter } ) {
	const set = ( key ) => ( value ) => onChange( { ...section, [ key ]: value } );
	return (
		<Card title={ __( 'Section', 'dev3-ai-course-builder' ) } description={ adapter && adapter.id === 'tutor' ? __( 'Becomes a Tutor LMS topic.', 'dev3-ai-course-builder' ) : __( 'Becomes a section heading in the course.', 'dev3-ai-course-builder' ) }>
			<Stack gap={ 16 }>
				<TextField label={ __( 'Section title', 'dev3-ai-course-builder' ) } value={ section.title } onChange={ set( 'title' ) } required maxLength={ 200 } />
				<Textarea label={ __( 'Summary', 'dev3-ai-course-builder' ) } value={ section.summary } onChange={ set( 'summary' ) } rows={ 3 } maxLength={ 1000 } />
				<Toggle
					label={ __( 'Quiz at the end of this section', 'dev3-ai-course-builder' ) }
					checked={ !! section.quiz }
					onChange={ ( on ) => onChange( { ...section, quiz: on ? emptyQuiz( sprintf( /* translators: %s: section */ __( 'Quiz: %s', 'dev3-ai-course-builder' ), section.title ) ) : null } ) }
					help={ adapter && adapter.id === 'learndash' ? __( 'LearnDash has no section quizzes: it is added after the last lesson of the section.', 'dev3-ai-course-builder' ) : undefined }
				/>
				<div>
					<Button icon="plus" onClick={ () => onAddLesson( section.id ) }>
						{ __( 'Add lesson', 'dev3-ai-course-builder' ) }
					</Button>
				</div>
			</Stack>
		</Card>
	);
}

/**
 * Lesson / topic editor with Markdown-lite content and the DEV3 AI lesson writer.
 *
 * @param {Object} props Props.
 * @return {Element} Editor.
 */
function LessonForm( { node, kind, onChange, ai, adapter, settings } ) {
	const [ tab, setTab ] = useState( 'write' );
	const [ draft, setDraft ] = useState( null );
	const [ length, setLength ] = useState( settings.lesson_length || 'medium' );
	const set = ( key ) => ( value ) => onChange( { ...node, [ key ]: value } );
	const run = () => ai.run( 'lesson', { node: node.id, length }, setDraft );
	const supportsTopics = ! adapter || adapter.supports.topics;
	return (
		<Stack gap={ 16 }>
			<Card title={ kind === 'topic' ? __( 'Topic', 'dev3-ai-course-builder' ) : __( 'Lesson', 'dev3-ai-course-builder' ) }>
				<Stack gap={ 16 }>
					<TextField label={ kind === 'topic' ? __( 'Topic title', 'dev3-ai-course-builder' ) : __( 'Lesson title', 'dev3-ai-course-builder' ) } value={ node.title } onChange={ set( 'title' ) } required maxLength={ 200 } />
					<Textarea label={ __( 'Summary / key points', 'dev3-ai-course-builder' ) } value={ node.summary } onChange={ set( 'summary' ) } rows={ 3 } maxLength={ 1000 } help={ __( 'Guides DEV3 AI when it writes the content.', 'dev3-ai-course-builder' ) } />
					{ node.ai_draft && (
						<Notice
							tone="ai"
							title={ __( 'Written by DEV3 AI', 'dev3-ai-course-builder' ) }
							actions={
								<Button size="sm" icon="check" onClick={ () => onChange( { ...node, ai_draft: false } ) }>
									{ __( 'Mark as reviewed', 'dev3-ai-course-builder' ) }
								</Button>
							}
						>
							{ __( 'Read and edit this lesson before you create the course.', 'dev3-ai-course-builder' ) }
						</Notice>
					) }
					<div>
						<div className="dev3lm-content-head">
							<span className="dev3lm-label" id={ `dev3lm-content-${ node.id }` }>
								{ __( 'Content', 'dev3-ai-course-builder' ) }
							</span>
							<span className="dev3lm-muted dev3lm-small">{ sprintf( /* translators: %d: words */ __( '%d words', 'dev3-ai-course-builder' ), words( node.content ) ) }</span>
						</div>
						<div className="dev3lm-tabs-scroll">
							<Tabs
								variant="pills"
								label={ __( 'Content view', 'dev3-ai-course-builder' ) }
								value={ tab }
								onChange={ setTab }
								tabs={ [
									{ id: 'write', label: __( 'Write', 'dev3-ai-course-builder' ) },
									{ id: 'preview', label: __( 'Preview', 'dev3-ai-course-builder' ) },
								] }
							/>
						</div>
						{ tab === 'write' ? (
							<Textarea
								label={ __( 'Lesson content', 'dev3-ai-course-builder' ) }
								hideLabel
								value={ node.content }
								onChange={ set( 'content' ) }
								rows={ 16 }
								className="dev3lm-mono"
								help={ __( '## heading, - list, 1. numbered list, > quote, "> Tip: …" callout, ``` code, **bold**, *italic*. Becomes native blocks.', 'dev3-ai-course-builder' ) }
							/>
						) : (
							<div className="dev3lm-preview-box">{ node.content.trim() ? <MarkdownPreview md={ node.content } /> : <p className="dev3lm-muted">{ __( 'Nothing to preview yet.', 'dev3-ai-course-builder' ) }</p> }</div>
						) }
					</div>
					{ kind === 'lesson' && (
						<div className="dev3lm-inline-actions">
							{ supportsTopics && (
								<Button size="sm" icon="plus" onClick={ () => onChange( { ...node, topics: [ ...node.topics, emptyTopic( __( 'New topic', 'dev3-ai-course-builder' ) ) ] } ) }>
									{ __( 'Add topic', 'dev3-ai-course-builder' ) }
								</Button>
							) }
							<Toggle
								label={ __( 'Lesson quiz', 'dev3-ai-course-builder' ) }
								checked={ !! node.quiz }
								onChange={ ( on ) => onChange( { ...node, quiz: on ? emptyQuiz( sprintf( /* translators: %s: lesson */ __( 'Check: %s', 'dev3-ai-course-builder' ), node.title ) ) : null } ) }
							/>
						</div>
					) }
				</Stack>
			</Card>
			<Card tone="ai" title={ __( 'Write this lesson with DEV3 AI', 'dev3-ai-course-builder' ) } description={ __( 'Uses the course outline, this summary and your source material.', 'dev3-ai-course-builder' ) }>
				<Stack gap={ 12 }>
					<div className="dev3lm-ai-row">
						<Select label={ __( 'Length', 'dev3-ai-course-builder' ) } value={ length } onChange={ setLength } options={ LENGTHS } />
						<AiButton onClick={ run } loading={ ai.busy === 'lesson' } loadingText={ __( 'Writing…', 'dev3-ai-course-builder' ) } disabled={ ! ai.ready || !! ai.busy || ! node.title.trim() }>
							{ node.content.trim() ? __( 'Rewrite with DEV3 AI', 'dev3-ai-course-builder' ) : __( 'Write with DEV3 AI', 'dev3-ai-course-builder' ) }
						</AiButton>
					</div>
					<ErrorNotice error={ ai.error } onDismiss={ ai.clear } />
					{ draft && (
						<>
							<AiDraftReview
								original={ node.content }
								draft={ draft.content }
								meta={ meta( draft ) }
								onAccept={ ( v ) => {
									onChange( { ...node, content: v, ai_draft: false } );
									setDraft( null );
								} }
								onReject={ () => setDraft( null ) }
								onRegenerate={ run }
								regenerating={ ai.busy === 'lesson' }
								acceptLabel={ __( 'Use this lesson', 'dev3-ai-course-builder' ) }
							/>
							<details className="dev3lm-details">
								<summary>{ __( 'Preview the draft', 'dev3-ai-course-builder' ) }</summary>
								<div className="dev3lm-preview-box">
									<MarkdownPreview md={ draft.content } />
								</div>
							</details>
						</>
					) }
				</Stack>
			</Card>
		</Stack>
	);
}

/**
 * Quiz editor with the DEV3 AI question generator.
 *
 * @param {Object} props Props.
 * @return {Element} Editor.
 */
function QuizForm( { quiz, onChange, ai, adapter, settings } ) {
	const types = adapter ? adapter.supports.types : undefined;
	const [ count, setCount ] = useState( settings.questions_per_quiz || 5 );
	const [ draft, setDraft ] = useState( null );
	const set = ( key ) => ( value ) => onChange( { ...quiz, [ key ]: value } );
	const wanted = ( settings.question_types || [ 'single' ] ).filter( ( t ) => ! types || types.includes( t ) );
	const run = ( mode ) => ai.run( 'quiz', { node: quiz.id, count, mode, types: wanted }, setDraft );
	return (
		<Stack gap={ 16 }>
			<Card title={ __( 'Quiz', 'dev3-ai-course-builder' ) }>
				<Stack gap={ 16 }>
					<FormGrid>
						<TextField label={ __( 'Quiz title', 'dev3-ai-course-builder' ) } value={ quiz.title } onChange={ set( 'title' ) } maxLength={ 200 } />
						<NumberField label={ __( 'Passing grade (%)', 'dev3-ai-course-builder' ) } value={ quiz.passing } onChange={ ( v ) => set( 'passing' )( v === '' ? 0 : v ) } min={ 0 } max={ 100 } />
					</FormGrid>
					<Textarea label={ __( 'Instructions', 'dev3-ai-course-builder' ) } value={ quiz.description } onChange={ set( 'description' ) } rows={ 2 } maxLength={ 1000 } />
					<QuestionEditor questions={ quiz.questions } onChange={ set( 'questions' ) } types={ types } />
				</Stack>
			</Card>
			<Card tone="ai" title={ __( 'Generate questions with DEV3 AI', 'dev3-ai-course-builder' ) } description={ sprintf( /* translators: %s: list of question types */ __( 'From the lesson content. Types: %s.', 'dev3-ai-course-builder' ), wanted.map( typeLabel ).join( ', ' ) ) }>
				<Stack gap={ 12 }>
					<div className="dev3lm-ai-row">
						<NumberField label={ __( 'Questions', 'dev3-ai-course-builder' ) } value={ count } onChange={ ( v ) => setCount( v === '' ? 5 : v ) } min={ 1 } max={ 30 } />
						<AiButton onClick={ () => run( 'ai' ) } loading={ ai.busy === 'quiz' } disabled={ ! ai.ready || !! ai.busy }>
							{ __( 'Generate with DEV3 AI', 'dev3-ai-course-builder' ) }
						</AiButton>
						<Button onClick={ () => run( 'basic' ) } disabled={ !! ai.busy }>
							{ __( 'Basic questions (no AI)', 'dev3-ai-course-builder' ) }
						</Button>
					</div>
					<ErrorNotice error={ ai.error } onDismiss={ ai.clear } />
					{ draft && <QuestionDraft draft={ draft } onAppend={ ( qs ) => { onChange( { ...quiz, questions: [ ...quiz.questions, ...qs ] } ); setDraft( null ); } } onReplace={ ( qs ) => { onChange( { ...quiz, questions: qs } ); setDraft( null ); } } onReject={ () => setDraft( null ) } onRegenerate={ () => run( draft.basic ? 'basic' : 'ai' ) } busy={ ai.busy === 'quiz' } types={ types } hasExisting={ quiz.questions.length > 0 } /> }
				</Stack>
			</Card>
		</Stack>
	);
}

/**
 * Review step for generated questions (editable before they are added).
 *
 * @param {Object} props Props.
 * @return {Element} Review.
 */
export function QuestionDraft( { draft, onAppend, onReplace, onReject, onRegenerate, busy, types, hasExisting, acceptLabel } ) {
	const [ questions, setQuestions ] = useState( draft.questions );
	return (
		<div className="dev3lm-draft">
			<div className="dev3lm-draft__head">
				<p className="dev3lm-draft__title">
					{ draft.basic ? __( 'Basic questions draft', 'dev3-ai-course-builder' ) : __( 'DEV3 AI · Questions draft', 'dev3-ai-course-builder' ) }
				</p>
				<Badge tone="warning">{ __( 'Not saved yet', 'dev3-ai-course-builder' ) }</Badge>
			</div>
			<p className="dev3lm-muted dev3lm-small">{ __( 'Check every answer. Edit or delete questions before you add them.', 'dev3-ai-course-builder' ) }</p>
			<QuestionEditor questions={ questions } onChange={ setQuestions } types={ types } />
			{ ! draft.basic && (
				<p className="dev3lm-muted dev3lm-small">
					{ draft.model } · { sprintf( /* translators: %d: tokens */ __( '%d tokens', 'dev3-ai-course-builder' ), draft.tokens ) }
					{ draft.cached ? ` · ${ __( 'cached', 'dev3-ai-course-builder' ) }` : '' }
				</p>
			) }
			<div className="dev3lm-inline-actions">
				<Button variant="primary" icon="check" onClick={ () => onAppend( questions ) } disabled={ ! questions.length }>
					{ acceptLabel || ( hasExisting ? __( 'Add to quiz', 'dev3-ai-course-builder' ) : __( 'Use these questions', 'dev3-ai-course-builder' ) ) }
				</Button>
				{ hasExisting && onReplace && (
					<Button onClick={ () => onReplace( questions ) } disabled={ ! questions.length }>
						{ __( 'Replace existing', 'dev3-ai-course-builder' ) }
					</Button>
				) }
				{ onRegenerate && (
					<Button icon="refresh" onClick={ onRegenerate } loading={ busy }>
						{ __( 'Regenerate', 'dev3-ai-course-builder' ) }
					</Button>
				) }
				<Button variant="ghost" onClick={ onReject }>
					{ __( 'Reject', 'dev3-ai-course-builder' ) }
				</Button>
			</div>
		</div>
	);
}

/**
 * Editor for the selected node.
 *
 * @param {Object} props Props.
 * @return {Element} Editor.
 */
export default function NodeEditor( { found, onChange, onAddLesson, blueprintId, tree, aiReady, adapter, settings, onUsage, disabled } ) {
	const [ busy, setBusy ] = useState( '' );
	const [ error, setError ] = useState( null );
	const ai = {
		ready: aiReady && ! disabled,
		busy,
		error,
		clear: () => setError( null ),
		run: ( kind, data, done ) => {
			setBusy( kind );
			setError( null );
			request( `blueprints/${ blueprintId }/${ kind }`, { method: 'POST', data: { ...data, tree } } )
				.then( ( r ) => {
					done( r );
					if ( r && ! r.basic ) {
						onUsage();
					}
				} )
				.catch( setError )
				.finally( () => setBusy( '' ) );
		},
	};
	if ( ! found ) {
		return <Notice tone="info">{ __( 'Select an item in the course structure to edit it.', 'dev3-ai-course-builder' ) }</Notice>;
	}
	const props = { onChange, ai, adapter, settings };
	return (
		<fieldset className="dev3lm-editor" disabled={ disabled }>
			<legend className="screen-reader-text">{ __( 'Edit item', 'dev3-ai-course-builder' ) }</legend>
			{ found.kind === 'course' && <CourseForm course={ found.node } { ...props } /> }
			{ found.kind === 'section' && <SectionForm section={ found.node } onAddLesson={ onAddLesson } { ...props } /> }
			{ ( found.kind === 'lesson' || found.kind === 'topic' ) && <LessonForm key={ found.node.id } node={ found.node } kind={ found.kind } { ...props } /> }
			{ found.kind === 'quiz' && <QuizForm key={ found.node.id } quiz={ found.node } { ...props } /> }
		</fieldset>
	);
}
