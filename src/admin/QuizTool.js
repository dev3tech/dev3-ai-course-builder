import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { AiButton, Button, Card, Checkbox, ErrorNotice, FormGrid, Notice, NumberField, PageHeader, Select, Stack, TextField, useToast } from '../core3/ui';
import LessonPicker from './LessonPicker';
import { QuestionDraft } from './NodeEditor';
import { QUESTION_TYPES } from './util';

/**
 * Quiz generator for existing lessons.
 *
 * @param {Object}   props          Props.
 * @param {number}   props.lessonId Lesson preselected by the route (row action).
 * @param {Object}   props.settings Settings.
 * @param {Object}   props.status   Status.
 * @param {Array}    props.adapters Adapters.
 * @param {Function} props.onUsage  Usage changed.
 * @return {Element} Screen.
 */
export default function QuizTool( { lessonId, settings, status, adapters, onUsage } ) {
	const toast = useToast();
	const [ lesson, setLesson ] = useState( null );
	const [ count, setCount ] = useState( settings.questions_per_quiz || 5 );
	const [ types, setTypes ] = useState( settings.question_types || [ 'single' ] );
	const [ title, setTitle ] = useState( '' );
	const [ postStatus, setPostStatus ] = useState( settings.post_status || 'draft' );
	const [ draft, setDraft ] = useState( null );
	const [ created, setCreated ] = useState( null );
	const [ busy, setBusy ] = useState( '' );
	const [ error, setError ] = useState( null );
	const ai = ( status && status.ai ) || {};
	const aiReady = ai.enabled && ai.key_set && ! ai.cap_reached;

	useEffect( () => {
		if ( lessonId ) {
			request( `lessons/${ lessonId }` ).then( setLesson ).catch( setError );
		}
	}, [ lessonId ] );

	const adapter = lesson ? adapters.find( ( a ) => a.id === lesson.lms ) : null;
	const allowed = adapter ? adapter.supports.types : QUESTION_TYPES.map( ( t ) => t.value );
	const chosen = types.filter( ( t ) => allowed.includes( t ) );

	const pick = ( row ) => {
		setLesson( row );
		setDraft( null );
		setCreated( null );
		setTitle( row ? sprintf( /* translators: %s: lesson */ __( 'Quiz: %s', 'dev3-ai-course-builder' ), row.title ) : '' );
	};
	useEffect( () => {
		if ( lesson && ! title ) {
			setTitle( sprintf( /* translators: %s: lesson */ __( 'Quiz: %s', 'dev3-ai-course-builder' ), lesson.title ) );
		}
	}, [ lesson ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const generate = ( mode ) => {
		setBusy( mode );
		setError( null );
		setCreated( null );
		request( 'tools/quiz', { method: 'POST', data: { lesson_id: lesson.id, count, types: chosen, mode } } )
			.then( ( r ) => {
				setDraft( r );
				if ( ! r.basic ) {
					onUsage();
				}
			} )
			.catch( setError )
			.finally( () => setBusy( '' ) );
	};

	const create = ( questions ) => {
		setBusy( 'create' );
		request( 'tools/quiz/create', { method: 'POST', data: { lesson_id: lesson.id, title, questions, status: postStatus } } )
			.then( ( r ) => {
				setCreated( r );
				setDraft( null );
				toast.success( __( 'Quiz created.', 'dev3-ai-course-builder' ) );
			} )
			.catch( setError )
			.finally( () => setBusy( '' ) );
	};

	const toggle = ( type ) => ( on ) => {
		const next = on ? [ ...types, type ] : types.filter( ( t ) => t !== type );
		setTypes( next.length ? next : [ type ] );
	};

	return (
		<Stack gap={ 16 }>
			<PageHeader title={ __( 'Quiz generator', 'dev3-ai-course-builder' ) } description={ __( 'Write a quiz from a lesson you already have. Review every question, then create the quiz next to the lesson.', 'dev3-ai-course-builder' ) } />
			<LessonPicker title={ __( '1. Lesson', 'dev3-ai-course-builder' ) } lesson={ lesson } onSelect={ pick } />
			{ lesson && (
				<Card tone="ai" title={ __( '2. Questions', 'dev3-ai-course-builder' ) }>
					<Stack gap={ 16 }>
						<FormGrid>
							<NumberField label={ __( 'Number of questions', 'dev3-ai-course-builder' ) } value={ count } onChange={ ( v ) => setCount( v === '' ? 5 : v ) } min={ 1 } max={ 30 } />
						</FormGrid>
						<fieldset className="dev3lm-fieldset">
							<legend>{ __( 'Question types', 'dev3-ai-course-builder' ) }</legend>
							<div className="dev3lm-checks">
								{ QUESTION_TYPES.filter( ( t ) => allowed.includes( t.value ) ).map( ( t ) => (
									<Checkbox key={ t.value } label={ t.label } checked={ types.includes( t.value ) } onChange={ toggle( t.value ) } />
								) ) }
							</div>
						</fieldset>
						{ lesson.words < 40 && <Notice tone="warning">{ __( 'This lesson has very little text. Questions work best from a full lesson.', 'dev3-ai-course-builder' ) }</Notice> }
						<div className="dev3lm-inline-actions">
							<AiButton onClick={ () => generate( 'ai' ) } loading={ busy === 'ai' } disabled={ ! aiReady || !! busy }>
								{ __( 'Generate quiz with DEV3 AI', 'dev3-ai-course-builder' ) }
							</AiButton>
							<Button onClick={ () => generate( 'basic' ) } loading={ busy === 'basic' } disabled={ !! busy }>
								{ __( 'Basic questions (no AI)', 'dev3-ai-course-builder' ) }
							</Button>
						</div>
						<ErrorNotice error={ error } onDismiss={ () => setError( null ) } />
						{ draft && (
							<>
								<FormGrid>
									<TextField label={ __( 'Quiz title', 'dev3-ai-course-builder' ) } value={ title } onChange={ setTitle } maxLength={ 200 } />
									<Select
										label={ __( 'Status', 'dev3-ai-course-builder' ) }
										value={ postStatus }
										onChange={ setPostStatus }
										options={ [
											{ value: 'draft', label: __( 'Draft', 'dev3-ai-course-builder' ) },
											{ value: 'publish', label: __( 'Published', 'dev3-ai-course-builder' ) },
										] }
										help={ adapter && adapter.id === 'tutor' ? __( 'Tutor LMS quizzes follow the course status.', 'dev3-ai-course-builder' ) : undefined }
									/>
								</FormGrid>
								<QuestionDraft
									draft={ draft }
									types={ allowed }
									onAppend={ create }
									onReject={ () => setDraft( null ) }
									onRegenerate={ () => generate( draft.basic ? 'basic' : 'ai' ) }
									busy={ !! busy }
									acceptLabel={ sprintf( /* translators: %s: LMS */ __( 'Create quiz in %s', 'dev3-ai-course-builder' ), lesson.lms_label || __( 'WordPress', 'dev3-ai-course-builder' ) ) }
								/>
							</>
						) }
						{ created && (
							<Notice
								tone="success"
								title={ sprintf( /* translators: 1: quiz title, 2: number of questions */ __( '"%1$s" created with %2$d questions', 'dev3-ai-course-builder' ), created.title, created.questions ) }
								actions={
									<div className="dev3lm-inline-actions">
										{ created.edit && (
											<Button size="sm" href={ created.edit } icon="edit">
												{ __( 'Edit quiz', 'dev3-ai-course-builder' ) }
											</Button>
										) }
										{ created.view && (
											<Button size="sm" variant="ghost" href={ created.view } icon="external" target="_blank" rel="noreferrer">
												{ __( 'View', 'dev3-ai-course-builder' ) }
											</Button>
										) }
									</div>
								}
							/>
						) }
					</Stack>
				</Card>
			) }
		</Stack>
	);
}
