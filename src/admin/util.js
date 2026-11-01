import { __ } from '@wordpress/i18n';

export const T = 'dev3-ai-course-builder';

export const QUESTION_TYPES = [
	{ value: 'single', label: __( 'Single choice', 'dev3-ai-course-builder' ) },
	{ value: 'multiple', label: __( 'Multiple choice', 'dev3-ai-course-builder' ) },
	{ value: 'true_false', label: __( 'True / false', 'dev3-ai-course-builder' ) },
	{ value: 'fill_blank', label: __( 'Fill in the blank', 'dev3-ai-course-builder' ) },
	{ value: 'short_answer', label: __( 'Short answer', 'dev3-ai-course-builder' ) },
	{ value: 'essay', label: __( 'Essay / open ended', 'dev3-ai-course-builder' ) },
	{ value: 'ordering', label: __( 'Ordering', 'dev3-ai-course-builder' ) },
	{ value: 'matching', label: __( 'Matching', 'dev3-ai-course-builder' ) },
];

export const typeLabel = ( type ) => ( QUESTION_TYPES.find( ( t ) => t.value === type ) || { label: type } ).label;

export const LEVELS = [
	{ value: 'all_levels', label: __( 'All levels', 'dev3-ai-course-builder' ) },
	{ value: 'beginner', label: __( 'Beginner', 'dev3-ai-course-builder' ) },
	{ value: 'intermediate', label: __( 'Intermediate', 'dev3-ai-course-builder' ) },
	{ value: 'expert', label: __( 'Expert', 'dev3-ai-course-builder' ) },
];

export const TONES = [
	{ value: 'friendly', label: __( 'Friendly', 'dev3-ai-course-builder' ) },
	{ value: 'professional', label: __( 'Professional', 'dev3-ai-course-builder' ) },
	{ value: 'academic', label: __( 'Academic', 'dev3-ai-course-builder' ) },
	{ value: 'conversational', label: __( 'Conversational', 'dev3-ai-course-builder' ) },
	{ value: 'motivational', label: __( 'Motivational', 'dev3-ai-course-builder' ) },
];

export const LENGTHS = [
	{ value: 'short', label: __( 'Short (300-450 words)', 'dev3-ai-course-builder' ) },
	{ value: 'medium', label: __( 'Medium (600-900 words)', 'dev3-ai-course-builder' ) },
	{ value: 'long', label: __( 'Long (1,200-1,600 words)', 'dev3-ai-course-builder' ) },
];

export const QUIZ_PLACES = [
	{ value: 'none', label: __( 'No quizzes', 'dev3-ai-course-builder' ) },
	{ value: 'section', label: __( 'One quiz per section', 'dev3-ai-course-builder' ) },
	{ value: 'lesson', label: __( 'One quiz per lesson', 'dev3-ai-course-builder' ) },
	{ value: 'course', label: __( 'One final quiz', 'dev3-ai-course-builder' ) },
];

export const STATUS = {
	draft: { label: __( 'Draft', 'dev3-ai-course-builder' ), tone: 'neutral' },
	building: { label: __( 'Creating…', 'dev3-ai-course-builder' ), tone: 'info' },
	built: { label: __( 'Created', 'dev3-ai-course-builder' ), tone: 'success' },
	failed: { label: __( 'Failed', 'dev3-ai-course-builder' ), tone: 'danger' },
};

export const LMS_OPTIONS = ( adapters = [] ) => [
	{ value: 'auto', label: __( 'Auto-detect', 'dev3-ai-course-builder' ) },
	...adapters.map( ( a ) => ( {
		value: a.id,
		label: a.available ? a.label : `${ a.label } (${ __( 'not active', 'dev3-ai-course-builder' ) })`,
		disabled: ! a.available,
	} ) ),
];

/**
 * New node id (same shape the server keeps).
 *
 * @param {string} prefix One letter.
 * @return {string} Id.
 */
export function newId( prefix = 'n' ) {
	return prefix + Math.random().toString( 36 ).slice( 2, 10 ).padEnd( 8, '0' );
}

export const emptyQuiz = ( title = '' ) => ( { id: newId( 'q' ), title, description: '', passing: 70, questions: [] } );
export const emptyLesson = ( title = '' ) => ( { id: newId( 'l' ), title, summary: '', content: '', topics: [], quiz: null, ai_draft: false } );
export const emptyTopic = ( title = '' ) => ( { id: newId( 't' ), title, summary: '', content: '', ai_draft: false } );
export const emptySection = ( title = '' ) => ( { id: newId( 's' ), title, summary: '', lessons: [], quiz: null } );

/**
 * Empty question of a type.
 *
 * @param {string} type Type.
 * @return {Object} Question.
 */
export function emptyQuestion( type = 'single' ) {
	const q = { id: newId( 'k' ), type, text: '', explanation: '', points: 1, answers: [] };
	if ( type === 'single' || type === 'multiple' ) {
		q.answers = [ { text: '', correct: true, match: '' }, { text: '', correct: false, match: '' } ];
	} else if ( type === 'true_false' ) {
		q.answers = [ { text: 'True', correct: true, match: '' }, { text: 'False', correct: false, match: '' } ];
	} else if ( type === 'fill_blank' ) {
		q.text = '';
		q.answers = [ { text: '', correct: true, match: '' } ];
	} else if ( type === 'ordering' || type === 'matching' ) {
		q.answers = [ { text: '', correct: true, match: '' }, { text: '', correct: true, match: '' } ];
	}
	return q;
}

/**
 * Moves an array item up (-1) or down (+1); returns a new array.
 *
 * @param {Array}  list  List.
 * @param {number} index Index.
 * @param {number} dir   -1 | 1.
 * @return {Array} New list.
 */
export function move( list, index, dir ) {
	const to = index + dir;
	if ( to < 0 || to >= list.length ) {
		return list;
	}
	const next = list.slice();
	const [ item ] = next.splice( index, 1 );
	next.splice( to, 0, item );
	return next;
}

/**
 * Finds a node by id: { kind, node, section, lesson }.
 *
 * @param {Object} tree Tree.
 * @param {string} id   Node id.
 * @return {Object|null} Match.
 */
export function findNode( tree, id ) {
	if ( ! tree ) {
		return null;
	}
	if ( id === 'course' ) {
		return { kind: 'course', node: tree.course, section: null, lesson: null };
	}
	if ( tree.quiz && tree.quiz.id === id ) {
		return { kind: 'quiz', node: tree.quiz, section: null, lesson: null };
	}
	for ( const s of tree.sections ) {
		if ( s.id === id ) {
			return { kind: 'section', node: s, section: s, lesson: null };
		}
		if ( s.quiz && s.quiz.id === id ) {
			return { kind: 'quiz', node: s.quiz, section: s, lesson: null };
		}
		for ( const l of s.lessons ) {
			if ( l.id === id ) {
				return { kind: 'lesson', node: l, section: s, lesson: null };
			}
			if ( l.quiz && l.quiz.id === id ) {
				return { kind: 'quiz', node: l.quiz, section: s, lesson: l };
			}
			for ( const t of l.topics ) {
				if ( t.id === id ) {
					return { kind: 'topic', node: t, section: s, lesson: l };
				}
			}
		}
	}
	return null;
}

/**
 * Immutable update of one node by id.
 *
 * @param {Object}   tree Tree.
 * @param {string}   id   Node id ('course' for course details).
 * @param {Function} fn   ( node ) => node | null (null removes it).
 * @return {Object} New tree.
 */
export function updateNode( tree, id, fn ) {
	if ( id === 'course' ) {
		return { ...tree, course: fn( tree.course ) };
	}
	const quiz = ( q ) => ( q && q.id === id ? fn( q ) : q );
	const sections = [];
	tree.sections.forEach( ( s ) => {
		let next = s.id === id ? fn( s ) : s;
		if ( ! next ) {
			return;
		}
		const lessons = [];
		next.lessons.forEach( ( l ) => {
			let ln = l.id === id ? fn( l ) : l;
			if ( ! ln ) {
				return;
			}
			const topics = [];
			ln.topics.forEach( ( t ) => {
				const tn = t.id === id ? fn( t ) : t;
				if ( tn ) {
					topics.push( tn );
				}
			} );
			ln = { ...ln, topics, quiz: quiz( ln.quiz ) || null };
			lessons.push( ln );
		} );
		next = { ...next, lessons, quiz: quiz( next.quiz ) || null };
		sections.push( next );
	} );
	return { ...tree, sections, quiz: quiz( tree.quiz ) || null };
}

/**
 * Moves a section/lesson/topic within its parent list.
 *
 * @param {Object} tree Tree.
 * @param {string} id   Node id.
 * @param {number} dir  -1 | 1.
 * @return {Object} New tree.
 */
export function moveNode( tree, id, dir ) {
	const si = tree.sections.findIndex( ( s ) => s.id === id );
	if ( si >= 0 ) {
		return { ...tree, sections: move( tree.sections, si, dir ) };
	}
	const sections = tree.sections.map( ( s ) => {
		const li = s.lessons.findIndex( ( l ) => l.id === id );
		if ( li >= 0 ) {
			return { ...s, lessons: move( s.lessons, li, dir ) };
		}
		return {
			...s,
			lessons: s.lessons.map( ( l ) => {
				const ti = l.topics.findIndex( ( t ) => t.id === id );
				return ti >= 0 ? { ...l, topics: move( l.topics, ti, dir ) } : l;
			} ),
		};
	} );
	return { ...tree, sections };
}

/**
 * Moves a lesson to the previous/next section (when it is first/last in its own).
 *
 * @param {Object} tree Tree.
 * @param {string} id   Lesson id.
 * @param {number} dir  -1 | 1.
 * @return {Object} New tree.
 */
export function moveLessonAcross( tree, id, dir ) {
	const si = tree.sections.findIndex( ( s ) => s.lessons.some( ( l ) => l.id === id ) );
	const to = si + dir;
	if ( si < 0 || to < 0 || to >= tree.sections.length ) {
		return tree;
	}
	const lesson = tree.sections[ si ].lessons.find( ( l ) => l.id === id );
	const sections = tree.sections.map( ( s, i ) => {
		if ( i === si ) {
			return { ...s, lessons: s.lessons.filter( ( l ) => l.id !== id ) };
		}
		if ( i === to ) {
			return { ...s, lessons: dir > 0 ? [ lesson, ...s.lessons ] : [ ...s.lessons, lesson ] };
		}
		return s;
	} );
	return { ...tree, sections };
}

/**
 * Counts like the server's Tree::counts().
 *
 * @param {Object} tree Tree.
 * @return {Object} Counts.
 */
export function counts( tree ) {
	const out = { sections: 0, lessons: 0, topics: 0, quizzes: 0, questions: 0, written: 0 };
	if ( ! tree ) {
		return out;
	}
	const q = ( quiz ) => {
		if ( quiz ) {
			out.quizzes++;
			out.questions += quiz.questions.length;
		}
	};
	out.sections = tree.sections.length;
	tree.sections.forEach( ( s ) => {
		out.lessons += s.lessons.length;
		s.lessons.forEach( ( l ) => {
			out.topics += l.topics.length;
			out.written += l.content && l.content.trim() ? 1 : 0;
			q( l.quiz );
		} );
		q( s.quiz );
	} );
	q( tree.quiz );
	return out;
}

/**
 * Client-side issues (mirrors Tree::issues() for instant feedback).
 *
 * @param {Object} tree Tree.
 * @return {Array} [ { node, level, message } ].
 */
export function issues( tree ) {
	const out = [];
	if ( ! tree ) {
		return out;
	}
	if ( ! tree.course.title.trim() ) {
		out.push( { node: 'course', level: 'error', message: __( 'Give the course a title.', 'dev3-ai-course-builder' ) } );
	}
	if ( ! tree.sections.length ) {
		out.push( { node: 'course', level: 'error', message: __( 'Add at least one section with a lesson.', 'dev3-ai-course-builder' ) } );
	}
	const quizIssues = ( quiz ) => {
		quiz.questions.forEach( ( qq ) => {
			const correct = qq.answers.filter( ( a ) => a.correct ).length;
			if ( ! qq.text.trim() ) {
				out.push( { node: quiz.id, level: 'error', message: __( 'A question has no text.', 'dev3-ai-course-builder' ) } );
			} else if ( ( qq.type === 'single' || qq.type === 'multiple' ) && ( qq.answers.filter( ( a ) => a.text.trim() ).length < 2 || ! correct ) ) {
				out.push( { node: quiz.id, level: 'error', message: __( 'A choice question needs two answers and a correct one.', 'dev3-ai-course-builder' ) } );
			}
		} );
		if ( ! quiz.questions.length ) {
			out.push( { node: quiz.id, level: 'warning', message: __( 'Quiz has no questions (it will be skipped).', 'dev3-ai-course-builder' ) } );
		}
	};
	tree.sections.forEach( ( s ) => {
		if ( ! s.title.trim() ) {
			out.push( { node: s.id, level: 'error', message: __( 'A section has no title.', 'dev3-ai-course-builder' ) } );
		}
		s.lessons.forEach( ( l ) => {
			if ( ! l.title.trim() ) {
				out.push( { node: l.id, level: 'error', message: __( 'A lesson has no title.', 'dev3-ai-course-builder' ) } );
			} else if ( ! ( l.content || '' ).trim() ) {
				out.push( { node: l.id, level: 'warning', message: __( 'No content yet.', 'dev3-ai-course-builder' ) } );
			}
			if ( l.ai_draft ) {
				out.push( { node: l.id, level: 'warning', message: __( 'DEV3 AI draft not reviewed yet.', 'dev3-ai-course-builder' ) } );
			}
			if ( l.quiz ) {
				quizIssues( l.quiz );
			}
		} );
		if ( s.quiz ) {
			quizIssues( s.quiz );
		}
	} );
	if ( tree.quiz ) {
		quizIssues( tree.quiz );
	}
	return out;
}

/**
 * Formats an ISO date for lists.
 *
 * @param {string} iso ISO date.
 * @return {string} Local date.
 */
export function shortDate( iso ) {
	if ( ! iso ) {
		return '';
	}
	const d = new Date( iso );
	return Number.isNaN( d.getTime() ) ? '' : d.toLocaleString( undefined, { dateStyle: 'medium', timeStyle: 'short' } );
}

/**
 * Downloads JSON as a file.
 *
 * @param {string} name File name.
 * @param {*}      data Data.
 */
export function downloadJson( name, data ) {
	const blob = new Blob( [ JSON.stringify( data, null, 2 ) ], { type: 'application/json' } );
	const url = URL.createObjectURL( blob );
	const a = document.createElement( 'a' );
	a.href = url;
	a.download = name;
	document.body.appendChild( a );
	a.click();
	a.remove();
	setTimeout( () => URL.revokeObjectURL( url ), 1000 );
}
