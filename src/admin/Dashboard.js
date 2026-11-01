import { __, sprintf } from '@wordpress/i18n';
import { Badge, Button, Card, Columns, EmptyState, Icon, Notice, PageHeader, ResponsiveTable, Stack, Stat, StatGrid } from '../core3/ui';
import { STATUS, shortDate } from './util';

const STARTS = [
	{ mode: 'topic', icon: 'sparkles', title: __( 'From a topic', 'dev3-ai-course-builder' ), text: __( 'Describe the course and DEV3 AI drafts sections, lessons and quizzes.', 'dev3-ai-course-builder' ) },
	{ mode: 'outline', icon: 'list', title: __( 'From an outline', 'dev3-ai-course-builder' ), text: __( 'Paste headings or bullets. Works without AI, too.', 'dev3-ai-course-builder' ) },
	{ mode: 'file', icon: 'file', title: __( 'From a PDF or DOCX', 'dev3-ai-course-builder' ), text: __( 'Upload notes, a book chapter or slides exported as PDF.', 'dev3-ai-course-builder' ) },
];

/**
 * Dashboard.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.status   Status.
 * @param {Array}    props.adapters Adapters.
 * @param {Function} props.navigate Navigate.
 * @return {Element} Dashboard.
 */
export default function Dashboard( { status, adapters, navigate } ) {
	const loading = ! status;
	const counts = ( status && status.counts ) || {};
	const ai = ( status && status.ai ) || {};
	const auto = status ? adapters.find( ( a ) => a.id === status.auto ) : null;
	const recent = ( status && status.recent ) || [];

	const columns = [
		{ key: 'title', label: __( 'Course', 'dev3-ai-course-builder' ), primary: true, render: ( r ) => r.title || __( '(untitled)', 'dev3-ai-course-builder' ) },
		{ key: 'status', label: __( 'Status', 'dev3-ai-course-builder' ), render: ( r ) => <Badge tone={ ( STATUS[ r.status ] || STATUS.draft ).tone }>{ ( STATUS[ r.status ] || STATUS.draft ).label }</Badge> },
		{ key: 'lessons', label: __( 'Lessons', 'dev3-ai-course-builder' ), align: 'end', render: ( r ) => r.counts.lessons },
		{ key: 'updated', label: __( 'Updated', 'dev3-ai-course-builder' ), hideOnMobile: true, render: ( r ) => shortDate( r.updated_at ) },
	];

	return (
		<Stack gap={ 24 }>
			<PageHeader
				title={ __( 'Dashboard', 'dev3-ai-course-builder' ) }
				description={ __( 'Turn a topic, an outline or a document into a complete course, review it, then create it in your LMS.', 'dev3-ai-course-builder' ) }
				actions={
					<Button variant="primary" icon="plus" onClick={ () => navigate( 'new' ) }>
						{ __( 'New course', 'dev3-ai-course-builder' ) }
					</Button>
				}
			/>
			{ status && ! ai.key_set && (
				<Notice
					tone="ai"
					title={ __( 'Connect DEV3 AI', 'dev3-ai-course-builder' ) }
					actions={
						<Button size="sm" variant="ai" href="#/api">
							{ __( 'Add API key', 'dev3-ai-course-builder' ) }
						</Button>
					}
				>
					{ __( 'Add a Google Gemini API key to draft outlines, lessons and quizzes. Everything else (outline import, the course editor and creating courses) already works without AI.', 'dev3-ai-course-builder' ) }
				</Notice>
			) }
			<StatGrid>
				<Stat icon="edit" label={ __( 'Course drafts', 'dev3-ai-course-builder' ) } value={ counts.draft || 0 } loading={ loading } />
				<Stat icon="success" tone="success" label={ __( 'Courses created', 'dev3-ai-course-builder' ) } value={ status ? status.built : 0 } loading={ loading } />
				<Stat icon="file" tone="brand" label={ __( 'Lessons created', 'dev3-ai-course-builder' ) } value={ status ? status.lessons : 0 } loading={ loading } />
				<Stat icon="refresh" label={ __( 'Lesson rewrites', 'dev3-ai-course-builder' ) } value={ status ? status.rewrites : 0 } loading={ loading } />
			</StatGrid>
			<Card title={ __( 'Start a course', 'dev3-ai-course-builder' ) } description={ __( 'Nothing is created in your LMS until you review the course tree and click Create.', 'dev3-ai-course-builder' ) }>
				<Columns min={ 220 } gap={ 12 }>
					{ STARTS.map( ( s ) => (
						<a key={ s.mode } className="dev3lm-start" href={ `#/new/${ s.mode }` }>
							<span className="dev3lm-start__icon" aria-hidden="true">
								<Icon name={ s.icon } size={ 20 } />
							</span>
							<span className="dev3lm-start__title">{ s.title }</span>
							<span className="dev3lm-start__text">{ s.text }</span>
						</a>
					) ) }
				</Columns>
			</Card>
			<Columns min={ 320 } gap={ 16 }>
				<Card title={ __( 'Your LMS', 'dev3-ai-course-builder' ) } description={ auto ? sprintf( /* translators: %s: LMS name */ __( 'New courses go to %s unless you choose another target.', 'dev3-ai-course-builder' ), auto.label ) : '' }>
					<ul className="dev3lm-lms-list">
						{ adapters.map( ( a ) => (
							<li key={ a.id }>
								<span className="dev3lm-lms-list__name">{ a.label }</span>
								{ a.available ? <Badge tone="success" dot>{ __( 'Ready', 'dev3-ai-course-builder' ) }</Badge> : <Badge>{ __( 'Not active', 'dev3-ai-course-builder' ) }</Badge> }
								<span className="dev3lm-lms-list__meta">
									{ a.supports.topics ? __( 'Sections, lessons, topics, quizzes', 'dev3-ai-course-builder' ) : __( 'Topics, lessons, quizzes', 'dev3-ai-course-builder' ) }
									{ a.supports.certificate ? ` · ${ __( 'certificate', 'dev3-ai-course-builder' ) }` : '' }
								</span>
							</li>
						) ) }
					</ul>
				</Card>
				<Card title={ __( 'Tools for existing lessons', 'dev3-ai-course-builder' ) }>
					<Stack gap={ 12 }>
						<p className="dev3lm-muted">{ __( 'Write a quiz from any lesson you already have, or rewrite a lesson: simpler, longer, with examples, another tone or another language.', 'dev3-ai-course-builder' ) }</p>
						<div className="dev3lm-inline-actions">
							<Button icon="check" onClick={ () => navigate( 'quiz' ) }>
								{ __( 'Quiz generator', 'dev3-ai-course-builder' ) }
							</Button>
							<Button icon="edit" onClick={ () => navigate( 'rewrite' ) }>
								{ __( 'Lesson rewriter', 'dev3-ai-course-builder' ) }
							</Button>
						</div>
					</Stack>
				</Card>
			</Columns>
			<Card
				title={ __( 'Recent courses', 'dev3-ai-course-builder' ) }
				flush
				actions={
					recent.length > 0 && (
						<Button variant="ghost" size="sm" onClick={ () => navigate( 'courses' ) }>
							{ __( 'View all', 'dev3-ai-course-builder' ) }
						</Button>
					)
				}
			>
				<ResponsiveTable
					columns={ columns }
					rows={ recent }
					rowKey={ ( r ) => r.id }
					loading={ loading }
					onRowClick={ ( r ) => navigate( `builder/${ r.id }` ) }
					empty={ <EmptyState compact icon="sparkles" title={ __( 'No courses yet', 'dev3-ai-course-builder' ) } description={ __( 'Start from a topic, an outline or a document.', 'dev3-ai-course-builder' ) } action={ <Button variant="primary" onClick={ () => navigate( 'new' ) }>{ __( 'New course', 'dev3-ai-course-builder' ) }</Button> } /> }
				/>
			</Card>
		</Stack>
	);
}
