import { __, sprintf } from '@wordpress/i18n';
import { Button, IconButton, cx } from '../core3/ui';

/**
 * Status dot for a lesson/topic.
 *
 * @param {Object} props      Props.
 * @param {Object} props.node Node.
 * @return {Element} Dot.
 */
function Dot( { node } ) {
	let tone = 'empty';
	let label = __( 'No content yet', 'dev3-ai-course-builder' );
	if ( node.ai_draft ) {
		tone = 'ai';
		label = __( 'DEV3 AI draft, not reviewed', 'dev3-ai-course-builder' );
	} else if ( node.content && node.content.trim() ) {
		tone = 'done';
		label = __( 'Has content', 'dev3-ai-course-builder' );
	}
	return (
		<span className={ `dev3lm-dot dev3lm-dot--${ tone }` } title={ label }>
			<span className="screen-reader-text">{ label }</span>
		</span>
	);
}

/**
 * One row in the tree.
 *
 * @param {Object} props Props.
 * @return {Element} Row.
 */
function Row( { id, depth, active, onSelect, label, kind, dot, first, last, onMove, onRemove, extra, errors } ) {
	const kindLabel = {
		section: __( 'Section', 'dev3-ai-course-builder' ),
		lesson: __( 'Lesson', 'dev3-ai-course-builder' ),
		topic: __( 'Topic', 'dev3-ai-course-builder' ),
		quiz: __( 'Quiz', 'dev3-ai-course-builder' ),
	}[ kind ];
	const name = label || sprintf( /* translators: %s: item kind */ __( '(untitled %s)', 'dev3-ai-course-builder' ), kindLabel.toLowerCase() );
	return (
		<li className={ cx( 'dev3lm-tree__row', `dev3lm-tree__row--${ kind }`, `is-depth-${ depth }`, active && 'is-active', errors && 'has-error' ) }>
			<button type="button" className="dev3lm-tree__select" aria-current={ active ? 'true' : undefined } onClick={ () => onSelect( id ) }>
				{ dot }
				<span className="dev3lm-tree__kind">{ kindLabel }</span>
				<span className="dev3lm-tree__label">{ name }</span>
			</button>
			<span className="dev3lm-tree__tools">
				{ extra }
				{ onMove && (
					<>
						<IconButton size="sm" icon="chevronLeft" className="dev3lm-rot-up" label={ sprintf( /* translators: %s: item title */ __( 'Move up: %s', 'dev3-ai-course-builder' ), name ) } onClick={ () => onMove( id, -1 ) } disabled={ first } />
						<IconButton size="sm" icon="chevronRight" className="dev3lm-rot-down" label={ sprintf( /* translators: %s: item title */ __( 'Move down: %s', 'dev3-ai-course-builder' ), name ) } onClick={ () => onMove( id, 1 ) } disabled={ last } />
					</>
				) }
				{ onRemove && <IconButton size="sm" icon="trash" label={ sprintf( /* translators: %s: item title */ __( 'Delete: %s', 'dev3-ai-course-builder' ), name ) } onClick={ () => onRemove( id ) } /> }
			</span>
		</li>
	);
}

/**
 * Course tree navigator with add / move / delete.
 *
 * @param {Object}   props               Props.
 * @param {Object}   props.tree          Tree.
 * @param {string}   props.selected      Selected node id.
 * @param {Function} props.onSelect      ( id ) => void.
 * @param {Function} props.onMove        ( id, dir ) => void.
 * @param {Function} props.onMoveAcross  ( id, dir ) => void (lesson to previous/next section).
 * @param {Function} props.onRemove      ( id ) => void.
 * @param {Function} props.onAddSection  () => void.
 * @param {Function} props.onAddLesson   ( sectionId ) => void.
 * @param {Function} props.onAddFinalQuiz () => void.
 * @param {Set}      props.errorNodes    Node ids with errors.
 * @param {boolean}  props.disabled      Read-only.
 * @return {Element} Tree.
 */
export default function TreeNav( { tree, selected, onSelect, onMove, onMoveAcross, onRemove, onAddSection, onAddLesson, onAddFinalQuiz, errorNodes, disabled } ) {
	const mv = disabled ? undefined : onMove;
	const rm = disabled ? undefined : onRemove;
	return (
		<nav className="dev3lm-tree" aria-label={ __( 'Course structure', 'dev3-ai-course-builder' ) }>
			<ul className="dev3lm-tree__list">
				<li className={ cx( 'dev3lm-tree__row', 'dev3lm-tree__row--course', selected === 'course' && 'is-active', errorNodes.has( 'course' ) && 'has-error' ) }>
					<button type="button" className="dev3lm-tree__select" aria-current={ selected === 'course' ? 'true' : undefined } onClick={ () => onSelect( 'course' ) }>
						<span className="dev3lm-tree__kind">{ __( 'Course', 'dev3-ai-course-builder' ) }</span>
						<span className="dev3lm-tree__label">{ tree.course.title || __( '(untitled course)', 'dev3-ai-course-builder' ) }</span>
					</button>
				</li>
				{ tree.sections.map( ( s, si ) => (
					<li key={ s.id } className="dev3lm-tree__group">
						<ul className="dev3lm-tree__list">
							<Row id={ s.id } depth={ 0 } kind="section" label={ s.title } active={ selected === s.id } onSelect={ onSelect } first={ si === 0 } last={ si === tree.sections.length - 1 } onMove={ mv } onRemove={ rm } errors={ errorNodes.has( s.id ) } />
							{ s.lessons.map( ( l, li ) => (
								<li key={ l.id } className="dev3lm-tree__group">
									<ul className="dev3lm-tree__list">
										<Row
											id={ l.id }
											depth={ 1 }
											kind="lesson"
											label={ l.title }
											dot={ <Dot node={ l } /> }
											active={ selected === l.id }
											onSelect={ onSelect }
											first={ li === 0 && si === 0 }
											last={ li === s.lessons.length - 1 && si === tree.sections.length - 1 }
											onMove={ mv ? ( id, dir ) => ( ( dir < 0 && li === 0 ) || ( dir > 0 && li === s.lessons.length - 1 ) ? onMoveAcross( id, dir ) : onMove( id, dir ) ) : undefined }
											onRemove={ rm }
											errors={ errorNodes.has( l.id ) }
										/>
										{ l.topics.map( ( t, ti ) => (
											<Row key={ t.id } id={ t.id } depth={ 2 } kind="topic" label={ t.title } dot={ <Dot node={ t } /> } active={ selected === t.id } onSelect={ onSelect } first={ ti === 0 } last={ ti === l.topics.length - 1 } onMove={ mv } onRemove={ rm } errors={ errorNodes.has( t.id ) } />
										) ) }
										{ l.quiz && <Row id={ l.quiz.id } depth={ 2 } kind="quiz" label={ l.quiz.title } active={ selected === l.quiz.id } onSelect={ onSelect } onRemove={ rm } errors={ errorNodes.has( l.quiz.id ) } /> }
									</ul>
								</li>
							) ) }
							{ s.quiz && <Row id={ s.quiz.id } depth={ 1 } kind="quiz" label={ s.quiz.title } active={ selected === s.quiz.id } onSelect={ onSelect } onRemove={ rm } errors={ errorNodes.has( s.quiz.id ) } /> }
							{ ! disabled && (
								<li className="dev3lm-tree__add is-depth-1">
									<Button size="sm" variant="ghost" icon="plus" onClick={ () => onAddLesson( s.id ) }>
										{ __( 'Add lesson', 'dev3-ai-course-builder' ) }
									</Button>
								</li>
							) }
						</ul>
					</li>
				) ) }
				{ tree.quiz && <Row id={ tree.quiz.id } depth={ 0 } kind="quiz" label={ tree.quiz.title } active={ selected === tree.quiz.id } onSelect={ onSelect } onRemove={ rm } errors={ errorNodes.has( tree.quiz.id ) } /> }
			</ul>
			{ ! disabled && (
				<div className="dev3lm-tree__footer">
					<Button size="sm" icon="plus" onClick={ onAddSection }>
						{ __( 'Add section', 'dev3-ai-course-builder' ) }
					</Button>
					{ ! tree.quiz && (
						<Button size="sm" variant="ghost" icon="check" onClick={ onAddFinalQuiz }>
							{ __( 'Add final quiz', 'dev3-ai-course-builder' ) }
						</Button>
					) }
				</div>
			) }
		</nav>
	);
}
