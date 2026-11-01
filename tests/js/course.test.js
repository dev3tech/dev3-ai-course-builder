import apiFetch from '@wordpress/api-fetch';
import { parse, words, MarkdownPreview } from '../../src/admin/markdown';
import { counts, emptyLesson, emptyQuestion, emptyQuiz, emptySection, findNode, issues, move, moveLessonAcross, moveNode, updateNode } from '../../src/admin/util';
import QuestionEditor from '../../src/admin/QuestionEditor';
import OutlinePreview from '../../src/admin/OutlinePreview';
import Dashboard from '../../src/admin/Dashboard';
import CoursesTab from '../../src/admin/CoursesTab';
import { change, click, render } from './render';

afterEach( () => {
	document.body.innerHTML = '';
	apiFetch.mockReset();
	apiFetch.mockImplementation( () => new Promise( () => {} ) );
} );

const tree = () => {
	const s1 = { ...emptySection( 'Basics' ), id: 's1' };
	const s2 = { ...emptySection( 'Practice' ), id: 's2' };
	s1.lessons = [ { ...emptyLesson( 'Intro' ), id: 'l1', content: 'Hello **world**' }, { ...emptyLesson( 'Tools' ), id: 'l2', topics: [ { id: 't1', title: 'Grinder', summary: '', content: '', ai_draft: false } ] } ];
	s2.lessons = [ { ...emptyLesson( 'Brew' ), id: 'l3', quiz: { ...emptyQuiz( 'Check' ), id: 'q1' } } ];
	s1.quiz = { ...emptyQuiz( 'Quiz 1' ), id: 'q2', questions: [ { ...emptyQuestion( 'single' ), id: 'k1', text: 'Pick one', answers: [ { text: 'A', correct: true, match: '' }, { text: 'B', correct: false, match: '' } ] } ] };
	return { course: { title: 'Coffee', description: '', excerpt: '', level: 'beginner', audience: '', outcomes: [], requirements: [], duration: 0, certificate: { title: '', body: '' } }, sections: [ s1, s2 ], quiz: null };
};

describe( 'markdown-lite', () => {
	it( 'parses headings, lists, quotes, callouts and code like the PHP converter', () => {
		const blocks = parse( '## Title\n\nIntro line one\nline two\n\n- a\n- b\n\n1. first\n2. second\n\n> Tip: grind fresh\n\n> a quote\n\n```\nx < y\n```\n\n---' );
		expect( blocks.map( ( b ) => b.type ) ).toEqual( [ 'heading', 'paragraph', 'list', 'list', 'callout', 'quote', 'code', 'separator' ] );
		expect( blocks[ 1 ].text ).toBe( 'Intro line one line two' );
		expect( blocks[ 2 ].ordered ).toBe( false );
		expect( blocks[ 3 ] ).toMatchObject( { ordered: true, items: [ 'first', 'second' ] } );
		expect( blocks[ 6 ].text ).toBe( 'x < y' );
	} );

	it( 'counts words without markup', () => {
		expect( words( '## Two words\n\n- three more here' ) ).toBe( 5 );
		expect( words( '' ) ).toBe( 0 );
	} );

	it( 'renders a preview with inline formatting and never raw HTML', async () => {
		const view = await render( <MarkdownPreview md={ '## Head\n\nUse **bold** and `code` <script>x</script> [site](https://example.com) [bad](javascript:alert(1))' } /> );
		expect( view.$( '.dev3lm-md h3' ).textContent ).toBe( 'Head' );
		expect( view.$( '.dev3lm-md strong' ).textContent ).toBe( 'bold' );
		expect( view.$( '.dev3lm-md code' ).textContent ).toBe( 'code' );
		expect( view.$( '.dev3lm-md script' ) ).toBeNull();
		const links = view.$$( '.dev3lm-md a' );
		expect( links ).toHaveLength( 1 );
		expect( links[ 0 ].getAttribute( 'href' ) ).toBe( 'https://example.com' );
		view.unmount();
	} );
} );

describe( 'course tree helpers', () => {
	it( 'finds nodes of every kind', () => {
		const t = tree();
		expect( findNode( t, 'course' ).kind ).toBe( 'course' );
		expect( findNode( t, 's2' ).kind ).toBe( 'section' );
		expect( findNode( t, 't1' ) ).toMatchObject( { kind: 'topic', lesson: expect.objectContaining( { id: 'l2' } ) } );
		expect( findNode( t, 'q1' ) ).toMatchObject( { kind: 'quiz', lesson: expect.objectContaining( { id: 'l3' } ) } );
		expect( findNode( t, 'q2' ) ).toMatchObject( { kind: 'quiz', lesson: null } );
		expect( findNode( t, 'nope' ) ).toBeNull();
	} );

	it( 'updates and removes nodes immutably', () => {
		const t = tree();
		const renamed = updateNode( t, 'l2', ( l ) => ( { ...l, title: 'Gear' } ) );
		expect( renamed.sections[ 0 ].lessons[ 1 ].title ).toBe( 'Gear' );
		expect( t.sections[ 0 ].lessons[ 1 ].title ).toBe( 'Tools' );
		const removed = updateNode( t, 't1', () => null );
		expect( removed.sections[ 0 ].lessons[ 1 ].topics ).toHaveLength( 0 );
		const noQuiz = updateNode( t, 'q2', () => null );
		expect( noQuiz.sections[ 0 ].quiz ).toBeNull();
	} );

	it( 'moves sections, lessons and lessons across sections', () => {
		const t = tree();
		expect( moveNode( t, 's2', -1 ).sections.map( ( s ) => s.id ) ).toEqual( [ 's2', 's1' ] );
		expect( moveNode( t, 'l1', 1 ).sections[ 0 ].lessons.map( ( l ) => l.id ) ).toEqual( [ 'l2', 'l1' ] );
		const across = moveLessonAcross( t, 'l2', 1 );
		expect( across.sections[ 0 ].lessons.map( ( l ) => l.id ) ).toEqual( [ 'l1' ] );
		expect( across.sections[ 1 ].lessons.map( ( l ) => l.id ) ).toEqual( [ 'l2', 'l3' ] );
		expect( move( [ 1, 2 ], 0, -1 ) ).toEqual( [ 1, 2 ] );
	} );

	it( 'counts and validates', () => {
		const t = tree();
		expect( counts( t ) ).toMatchObject( { sections: 2, lessons: 3, topics: 1, quizzes: 2, questions: 1, written: 1 } );
		const list = issues( t );
		expect( list.filter( ( i ) => i.level === 'error' ) ).toHaveLength( 0 );
		expect( list.some( ( i ) => i.node === 'q1' && i.level === 'warning' ) ).toBe( true );
		const broken = updateNode( t, 'q2', ( q ) => ( { ...q, questions: [ { ...q.questions[ 0 ], answers: [ { text: 'A', correct: false, match: '' } ] } ] } ) );
		expect( issues( broken ).some( ( i ) => i.node === 'q2' && i.level === 'error' ) ).toBe( true );
		expect( issues( { ...t, course: { ...t.course, title: ' ' } } )[ 0 ] ).toMatchObject( { node: 'course', level: 'error' } );
	} );

	it( 'creates valid empty questions per type', () => {
		expect( emptyQuestion( 'true_false' ).answers.map( ( a ) => a.correct ) ).toEqual( [ true, false ] );
		expect( emptyQuestion( 'essay' ).answers ).toEqual( [] );
		expect( emptyQuestion( 'matching' ).answers ).toHaveLength( 2 );
	} );
} );

describe( 'components', () => {
	it( 'QuestionEditor adds, edits and keeps one correct answer for single choice', async () => {
		let questions = [];
		const onChange = jest.fn( ( next ) => ( questions = next ) );
		const view = await render( <QuestionEditor questions={ questions } onChange={ onChange } types={ [ 'single', 'true_false' ] } /> );
		expect( view.getByText( 'No questions yet' ) ).toBeTruthy();
		// Only allowed types are offered.
		expect( view.$$( 'select option' ).map( ( o ) => o.value ) ).toEqual( [ 'single', 'true_false' ] );
		await click( view.getByText( 'Add question' ) );
		expect( questions ).toHaveLength( 1 );
		await view.rerender( <QuestionEditor questions={ questions } onChange={ onChange } types={ [ 'single', 'true_false' ] } /> );
		await change( view.$( '.dev3lm-q__body textarea' ), 'Which grind for espresso?' );
		expect( questions[ 0 ].text ).toBe( 'Which grind for espresso?' );
		await view.rerender( <QuestionEditor questions={ questions } onChange={ onChange } types={ [ 'single', 'true_false' ] } /> );
		const boxes = view.$$( '.dev3lm-answers input[type="checkbox"]' );
		await click( boxes[ 1 ] );
		expect( questions[ 0 ].answers.map( ( a ) => a.correct ) ).toEqual( [ false, true ] );
		view.unmount();
	} );

	it( 'OutlinePreview shows counts and titles', async () => {
		const view = await render( <OutlinePreview tree={ tree() } /> );
		expect( view.getByText( 'Coffee' ) ).toBeTruthy();
		expect( view.getByText( '2 sections' ) ).toBeTruthy();
		expect( view.getByText( '3 lessons' ) ).toBeTruthy();
		expect( view.getByText( 'Practice' ) ).toBeTruthy();
		view.unmount();
	} );

	it( 'Dashboard asks to connect DEV3 AI and lists LMS targets', async () => {
		const status = { counts: { draft: 2 }, built: 1, lessons: 9, rewrites: 0, auto: 'tutor', ai: { key_set: false }, recent: [] };
		const adapters = [
			{ id: 'tutor', label: 'Tutor LMS', available: true, supports: { topics: false, certificate: false, types: [] } },
			{ id: 'learndash', label: 'LearnDash', available: false, supports: { topics: true, certificate: true, types: [] } },
		];
		const view = await render( <Dashboard status={ status } adapters={ adapters } navigate={ () => {} } /> );
		expect( view.getByText( 'Connect DEV3 AI' ) ).toBeTruthy();
		expect( view.getByText( 'Tutor LMS' ) ).toBeTruthy();
		expect( view.getByText( 'Not active' ) ).toBeTruthy();
		expect( view.getByText( 'No courses yet' ) ).toBeTruthy();
		view.unmount();
	} );

	it( 'CoursesTab loads drafts from the REST API and opens one', async () => {
		apiFetch.mockImplementation( ( { url } ) => {
			expect( url ).toContain( 'blueprints' );
			return Promise.resolve( { items: [ { id: 7, title: 'Coffee 101', status: 'built', lms: 'tutor', counts: { sections: 2, lessons: 5, quizzes: 1 }, source_type: 'topic', source: {}, updated_at: '' } ], total: 1 } );
		} );
		const navigate = jest.fn();
		const view = await render( <CoursesTab adapters={ [ { id: 'tutor', label: 'Tutor LMS', available: true, supports: {} } ] } navigate={ navigate } reloadStatus={ () => {} } /> );
		await view.flush();
		expect( view.getByText( 'Coffee 101' ) ).toBeTruthy();
		expect( view.getByText( 'Created' ) ).toBeTruthy();
		await click( view.getByText( 'Coffee 101' ) );
		expect( navigate ).toHaveBeenCalledWith( 'builder/7' );
		view.unmount();
	} );
} );
