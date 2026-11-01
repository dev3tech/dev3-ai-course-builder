import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Card, Checkbox, FormGrid, Modal, NumberField, PageHeader, Select, Stack, TextField, Toggle, useToast } from '../core3/ui';
import { LENGTHS, LEVELS, LMS_OPTIONS, QUESTION_TYPES, QUIZ_PLACES, TONES } from './util';

const KEYS = [ 'default_lms', 'post_status', 'language', 'tone', 'audience', 'level', 'sections', 'lessons_per_section', 'topics_per_lesson', 'quiz_placement', 'questions_per_quiz', 'question_types', 'lesson_length', 'passing_grade', 'certificate', 'max_source_chars', 'outline_model', 'delete_on_uninstall' ];
const DEFAULTS = {
	default_lms: 'auto',
	post_status: 'draft',
	language: 'English',
	tone: 'friendly',
	audience: '',
	level: 'beginner',
	sections: 4,
	lessons_per_section: 3,
	topics_per_lesson: 0,
	quiz_placement: 'section',
	questions_per_quiz: 5,
	question_types: [ 'single', 'multiple', 'true_false' ],
	lesson_length: 'medium',
	passing_grade: 70,
	certificate: true,
	max_source_chars: 30000,
	outline_model: 'pro',
};

/**
 * Plugin settings.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.settings Settings.
 * @param {Function} props.save     Save.
 * @param {Array}    props.adapters Adapters.
 * @return {Element} Settings.
 */
export default function SettingsTab( { settings, save, adapters } ) {
	const toast = useToast();
	const [ form, setForm ] = useState( settings );
	const [ busy, setBusy ] = useState( false );
	const [ confirm, setConfirm ] = useState( false );
	const set = ( key ) => ( value ) => setForm( { ...form, [ key ]: value } );
	const dirty = KEYS.some( ( k ) => JSON.stringify( form[ k ] ) !== JSON.stringify( settings[ k ] ) );
	const num = ( key, fallback ) => ( form[ key ] === '' ? fallback : form[ key ] );

	const persist = ( values, message ) => {
		setBusy( true );
		return save( values )
			.then( ( next ) => {
				setForm( next );
				toast.success( message );
			} )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setBusy( false ) );
	};
	const submit = ( e ) => {
		e.preventDefault();
		const values = {};
		KEYS.forEach( ( k ) => ( values[ k ] = form[ k ] ) );
		values.sections = num( 'sections', 4 );
		values.lessons_per_section = num( 'lessons_per_section', 3 );
		values.topics_per_lesson = num( 'topics_per_lesson', 0 );
		values.questions_per_quiz = num( 'questions_per_quiz', 5 );
		values.passing_grade = num( 'passing_grade', 70 );
		values.max_source_chars = num( 'max_source_chars', 30000 );
		persist( values, __( 'Settings saved.', 'dev3-ai-course-builder' ) );
	};
	const toggleType = ( type ) => ( on ) => {
		const list = form.question_types || [];
		const next = on ? [ ...list, type ] : list.filter( ( t ) => t !== type );
		set( 'question_types' )( next.length ? next : [ type ] );
	};
	const footer = (
		<>
			<Button variant="ghost" onClick={ () => setForm( settings ) } disabled={ ! dirty || busy }>
				{ __( 'Discard changes', 'dev3-ai-course-builder' ) }
			</Button>
			<Button variant="primary" type="submit" loading={ busy } disabled={ ! dirty }>
				{ __( 'Save settings', 'dev3-ai-course-builder' ) }
			</Button>
		</>
	);

	return (
		<form onSubmit={ submit }>
			<Stack gap={ 16 }>
				<PageHeader
					title={ __( 'Settings', 'dev3-ai-course-builder' ) }
					description={ __( 'Defaults for new courses, quizzes and the LMS target.', 'dev3-ai-course-builder' ) }
					actions={
						<Button variant="ghost" icon="refresh" onClick={ () => setConfirm( true ) }>
							{ __( 'Reset to defaults', 'dev3-ai-course-builder' ) }
						</Button>
					}
				/>
				<Modal
					open={ confirm }
					onClose={ () => setConfirm( false ) }
					size="sm"
					title={ __( 'Reset settings?', 'dev3-ai-course-builder' ) }
					description={ __( 'All options on this page go back to their defaults. Your API key, course drafts and usage history are kept.', 'dev3-ai-course-builder' ) }
					footer={
						<>
							<Button variant="ghost" onClick={ () => setConfirm( false ) } data-autofocus>
								{ __( 'Cancel', 'dev3-ai-course-builder' ) }
							</Button>
							<Button variant="danger" loading={ busy } onClick={ () => persist( DEFAULTS, __( 'Settings reset to defaults.', 'dev3-ai-course-builder' ) ).then( () => setConfirm( false ) ) }>
								{ __( 'Reset settings', 'dev3-ai-course-builder' ) }
							</Button>
						</>
					}
				/>
				<Card title={ __( 'LMS', 'dev3-ai-course-builder' ) } footer={ footer }>
					<FormGrid>
						<Select label={ __( 'Default target', 'dev3-ai-course-builder' ) } value={ form.default_lms } onChange={ set( 'default_lms' ) } options={ LMS_OPTIONS( adapters ) } help={ __( 'Auto-detect uses Tutor LMS, then LearnDash, else WordPress pages.', 'dev3-ai-course-builder' ) } />
						<Select
							label={ __( 'Status of created items', 'dev3-ai-course-builder' ) }
							value={ form.post_status }
							onChange={ set( 'post_status' ) }
							options={ [
								{ value: 'draft', label: __( 'Draft', 'dev3-ai-course-builder' ) },
								{ value: 'publish', label: __( 'Published', 'dev3-ai-course-builder' ) },
								{ value: 'pending', label: __( 'Pending review', 'dev3-ai-course-builder' ) },
								{ value: 'private', label: __( 'Private', 'dev3-ai-course-builder' ) },
							] }
						/>
						<NumberField label={ __( 'Quiz passing grade (%)', 'dev3-ai-course-builder' ) } value={ form.passing_grade } onChange={ set( 'passing_grade' ) } min={ 0 } max={ 100 } />
					</FormGrid>
					<Toggle label={ __( 'Create certificate text', 'dev3-ai-course-builder' ) } help={ __( 'LearnDash: a certificate post linked to the course. Tutor LMS and pages: shown by the Course Certificate block.', 'dev3-ai-course-builder' ) } checked={ !! form.certificate } onChange={ set( 'certificate' ) } />
				</Card>
				<Card title={ __( 'Course defaults', 'dev3-ai-course-builder' ) } footer={ footer }>
					<Stack gap={ 16 }>
						<FormGrid>
							<TextField label={ __( 'Language', 'dev3-ai-course-builder' ) } value={ form.language } onChange={ set( 'language' ) } maxLength={ 40 } />
							<Select label={ __( 'Tone', 'dev3-ai-course-builder' ) } value={ form.tone } onChange={ set( 'tone' ) } options={ TONES } />
							<TextField label={ __( 'Audience', 'dev3-ai-course-builder' ) } value={ form.audience } onChange={ set( 'audience' ) } maxLength={ 200 } />
							<Select label={ __( 'Level', 'dev3-ai-course-builder' ) } value={ form.level } onChange={ set( 'level' ) } options={ LEVELS } />
							<NumberField label={ __( 'Sections', 'dev3-ai-course-builder' ) } value={ form.sections } onChange={ set( 'sections' ) } min={ 1 } max={ 30 } />
							<NumberField label={ __( 'Lessons per section', 'dev3-ai-course-builder' ) } value={ form.lessons_per_section } onChange={ set( 'lessons_per_section' ) } min={ 1 } max={ 20 } />
							<NumberField label={ __( 'Topics per lesson', 'dev3-ai-course-builder' ) } value={ form.topics_per_lesson } onChange={ set( 'topics_per_lesson' ) } min={ 0 } max={ 10 } help={ __( 'LearnDash topics or child pages.', 'dev3-ai-course-builder' ) } />
							<Select label={ __( 'Lesson length', 'dev3-ai-course-builder' ) } value={ form.lesson_length } onChange={ set( 'lesson_length' ) } options={ LENGTHS } />
							<Select label={ __( 'Quizzes', 'dev3-ai-course-builder' ) } value={ form.quiz_placement } onChange={ set( 'quiz_placement' ) } options={ QUIZ_PLACES } />
							<NumberField label={ __( 'Questions per quiz', 'dev3-ai-course-builder' ) } value={ form.questions_per_quiz } onChange={ set( 'questions_per_quiz' ) } min={ 1 } max={ 30 } />
						</FormGrid>
						<fieldset className="dev3lm-fieldset">
							<legend>{ __( 'Default question types', 'dev3-ai-course-builder' ) }</legend>
							<div className="dev3lm-checks">
								{ QUESTION_TYPES.map( ( t ) => (
									<Checkbox key={ t.value } label={ t.label } checked={ ( form.question_types || [] ).includes( t.value ) } onChange={ toggleType( t.value ) } />
								) ) }
							</div>
						</fieldset>
					</Stack>
				</Card>
				<Card title={ __( 'DEV3 AI', 'dev3-ai-course-builder' ) } footer={ footer }>
					<FormGrid>
						<Select
							label={ __( 'Outline model', 'dev3-ai-course-builder' ) }
							value={ form.outline_model }
							onChange={ set( 'outline_model' ) }
							options={ [
								{ value: 'pro', label: __( 'Pro model (best structure)', 'dev3-ai-course-builder' ) },
								{ value: 'default', label: __( 'Default model (faster, cheaper)', 'dev3-ai-course-builder' ) },
							] }
							help={ __( 'Lessons and quizzes use the default model from API Config.', 'dev3-ai-course-builder' ) }
						/>
						<NumberField label={ __( 'Max source characters sent', 'dev3-ai-course-builder' ) } value={ form.max_source_chars } onChange={ set( 'max_source_chars' ) } min={ 2000 } max={ 120000 } step={ 1000 } help={ __( 'Longer documents are trimmed to the most relevant parts.', 'dev3-ai-course-builder' ) } />
					</FormGrid>
					<Toggle label={ __( 'Delete plugin data on uninstall', 'dev3-ai-course-builder' ) } help={ __( 'Removes course drafts, history and settings. Courses created in your LMS are always kept.', 'dev3-ai-course-builder' ) } checked={ !! form.delete_on_uninstall } onChange={ set( 'delete_on_uninstall' ) } />
				</Card>
			</Stack>
		</form>
	);
}
