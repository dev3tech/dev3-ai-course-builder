import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Badge, Button, Checkbox, EmptyState, IconButton, NumberField, Select, Textarea, TextField } from '../core3/ui';
import { QUESTION_TYPES, emptyQuestion, move, typeLabel } from './util';

/**
 * Answers editor for one question.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.q        Question.
 * @param {Function} props.onChange ( question ) => void.
 * @param {string}   props.prefix   Label prefix.
 * @return {Element|null} Editor.
 */
function Answers( { q, onChange, prefix } ) {
	const setAnswer = ( i, patch ) => {
		let answers = q.answers.map( ( a, j ) => ( j === i ? { ...a, ...patch } : a ) );
		if ( q.type === 'single' && patch.correct ) {
			answers = answers.map( ( a, j ) => ( { ...a, correct: j === i } ) );
		}
		onChange( { ...q, answers } );
	};
	const add = () => onChange( { ...q, answers: [ ...q.answers, { text: '', correct: q.type !== 'single' && q.type !== 'multiple', match: '' } ] } );
	const remove = ( i ) => onChange( { ...q, answers: q.answers.filter( ( a, j ) => j !== i ) } );

	if ( q.type === 'essay' ) {
		return <p className="dev3lm-muted dev3lm-small">{ __( 'Learners write a free answer; it is graded by hand. Put grading guidance in the explanation.', 'dev3-ai-course-builder' ) }</p>;
	}
	if ( q.type === 'true_false' ) {
		const isTrue = q.answers.length ? q.answers[ 0 ].correct : true;
		return (
			<Select
				label={ __( 'Correct answer', 'dev3-ai-course-builder' ) }
				value={ isTrue ? 'true' : 'false' }
				onChange={ ( v ) => onChange( { ...q, answers: [ { text: 'True', correct: v === 'true', match: '' }, { text: 'False', correct: v !== 'true', match: '' } ] } ) }
				options={ [
					{ value: 'true', label: __( 'True', 'dev3-ai-course-builder' ) },
					{ value: 'false', label: __( 'False', 'dev3-ai-course-builder' ) },
				] }
			/>
		);
	}
	const label = {
		single: __( 'Answers (choose the correct one)', 'dev3-ai-course-builder' ),
		multiple: __( 'Answers (tick every correct one)', 'dev3-ai-course-builder' ),
		fill_blank: __( 'Missing words, in order', 'dev3-ai-course-builder' ),
		short_answer: __( 'Accepted answers', 'dev3-ai-course-builder' ),
		ordering: __( 'Items in the correct order', 'dev3-ai-course-builder' ),
		matching: __( 'Pairs', 'dev3-ai-course-builder' ),
	}[ q.type ];
	return (
		<fieldset className="dev3lm-fieldset">
			<legend>{ label }</legend>
			<ol className="dev3lm-answers">
				{ q.answers.map( ( a, i ) => (
					<li key={ i } className="dev3lm-answers__row">
						{ ( q.type === 'single' || q.type === 'multiple' ) && (
							<Checkbox label={ __( 'Correct', 'dev3-ai-course-builder' ) } checked={ !! a.correct } onChange={ ( v ) => setAnswer( i, { correct: v } ) } />
						) }
						<TextField label={ sprintf( /* translators: 1: question label, 2: answer number */ __( '%1$s answer %2$d', 'dev3-ai-course-builder' ), prefix, i + 1 ) } hideLabel value={ a.text } onChange={ ( v ) => setAnswer( i, { text: v } ) } placeholder={ q.type === 'matching' ? __( 'Term', 'dev3-ai-course-builder' ) : __( 'Answer', 'dev3-ai-course-builder' ) } />
						{ q.type === 'matching' && <TextField label={ sprintf( /* translators: 1: question label, 2: pair number */ __( '%1$s match %2$d', 'dev3-ai-course-builder' ), prefix, i + 1 ) } hideLabel value={ a.match } onChange={ ( v ) => setAnswer( i, { match: v } ) } placeholder={ __( 'Matches…', 'dev3-ai-course-builder' ) } /> }
						<IconButton icon="trash" label={ sprintf( /* translators: %d: answer number */ __( 'Remove answer %d', 'dev3-ai-course-builder' ), i + 1 ) } onClick={ () => remove( i ) } />
					</li>
				) ) }
			</ol>
			<Button size="sm" variant="ghost" icon="plus" onClick={ add }>
				{ __( 'Add answer', 'dev3-ai-course-builder' ) }
			</Button>
		</fieldset>
	);
}

/**
 * Editable question list.
 *
 * @param {Object}   props           Props.
 * @param {Array}    props.questions Questions.
 * @param {Function} props.onChange  ( questions ) => void.
 * @param {Array}    props.types     Allowed type ids.
 * @return {Element} Editor.
 */
export default function QuestionEditor( { questions, onChange, types } ) {
	const [ open, setOpen ] = useState( () => ( questions.length ? questions[ 0 ].id : null ) );
	const [ newType, setNewType ] = useState( types && types.length ? types[ 0 ] : 'single' );
	const options = QUESTION_TYPES.filter( ( t ) => ! types || types.includes( t.value ) );

	const update = ( i, q ) => onChange( questions.map( ( x, j ) => ( j === i ? q : x ) ) );
	const changeType = ( i, type ) => {
		const base = emptyQuestion( type );
		const old = questions[ i ];
		const keep = [ 'single', 'multiple' ].includes( type ) && [ 'single', 'multiple' ].includes( old.type );
		update( i, { ...base, id: old.id, text: old.text, explanation: old.explanation, points: old.points, answers: keep ? old.answers : base.answers } );
	};
	const add = () => {
		const q = emptyQuestion( newType );
		onChange( [ ...questions, q ] );
		setOpen( q.id );
	};

	return (
		<div className="dev3lm-questions">
			{ questions.length === 0 && <EmptyState compact icon="check" title={ __( 'No questions yet', 'dev3-ai-course-builder' ) } description={ __( 'Generate them with DEV3 AI or add them by hand.', 'dev3-ai-course-builder' ) } /> }
			{ questions.map( ( q, i ) => {
				const prefix = sprintf( /* translators: %d: question number */ __( 'Question %d', 'dev3-ai-course-builder' ), i + 1 );
				const isOpen = open === q.id;
				return (
					<div key={ q.id } className={ `dev3lm-q${ isOpen ? ' is-open' : '' }` }>
						<div className="dev3lm-q__head">
							<button type="button" className="dev3lm-q__toggle" aria-expanded={ isOpen } onClick={ () => setOpen( isOpen ? null : q.id ) }>
								<span className="dev3lm-q__num">{ i + 1 }</span>
								<span className="dev3lm-q__text">{ q.text || __( '(no question text)', 'dev3-ai-course-builder' ) }</span>
							</button>
							<Badge>{ typeLabel( q.type ) }</Badge>
							<span className="dev3lm-q__tools">
								<IconButton icon="chevronLeft" className="dev3lm-rot-up" label={ sprintf( /* translators: %s: question label */ __( 'Move %s up', 'dev3-ai-course-builder' ), prefix ) } onClick={ () => onChange( move( questions, i, -1 ) ) } disabled={ i === 0 } />
								<IconButton icon="chevronRight" className="dev3lm-rot-down" label={ sprintf( /* translators: %s: question label */ __( 'Move %s down', 'dev3-ai-course-builder' ), prefix ) } onClick={ () => onChange( move( questions, i, 1 ) ) } disabled={ i === questions.length - 1 } />
								<IconButton icon="trash" label={ sprintf( /* translators: %s: question label */ __( 'Delete %s', 'dev3-ai-course-builder' ), prefix ) } onClick={ () => onChange( questions.filter( ( x ) => x.id !== q.id ) ) } />
							</span>
						</div>
						{ isOpen && (
							<div className="dev3lm-q__body">
								<div className="dev3lm-q__row">
									<Select label={ __( 'Type', 'dev3-ai-course-builder' ) } value={ q.type } onChange={ ( v ) => changeType( i, v ) } options={ options } />
									<NumberField label={ __( 'Points', 'dev3-ai-course-builder' ) } value={ q.points } onChange={ ( v ) => update( i, { ...q, points: v === '' ? 1 : v } ) } min={ 1 } max={ 100 } />
								</div>
								<Textarea
									label={ __( 'Question', 'dev3-ai-course-builder' ) }
									value={ q.text }
									onChange={ ( v ) => update( i, { ...q, text: v } ) }
									rows={ 2 }
									maxLength={ 1000 }
									help={ q.type === 'fill_blank' ? __( 'Write {blank} where each missing word goes, e.g. "Water boils at {blank} degrees Celsius."', 'dev3-ai-course-builder' ) : undefined }
								/>
								<Answers q={ q } onChange={ ( next ) => update( i, next ) } prefix={ prefix } />
								<Textarea label={ __( 'Explanation (shown after answering)', 'dev3-ai-course-builder' ) } value={ q.explanation } onChange={ ( v ) => update( i, { ...q, explanation: v } ) } rows={ 2 } maxLength={ 1000 } />
							</div>
						) }
					</div>
				);
			} ) }
			<div className="dev3lm-questions__add">
				<Select label={ __( 'New question type', 'dev3-ai-course-builder' ) } hideLabel value={ newType } onChange={ setNewType } options={ options } />
				<Button icon="plus" onClick={ add }>
					{ __( 'Add question', 'dev3-ai-course-builder' ) }
				</Button>
			</div>
		</div>
	);
}
