import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../core3/api';
import config from '../core3/config';
import ApiTab from '../core3/ApiTab';
import UsageTab from '../core3/UsageTab';
import { AppShell, ErrorNotice, PageHeader, SkeletonCard, UsageMeter, useHashRoute } from '../core3/ui';
import Dashboard from './Dashboard';
import NewCourse from './NewCourse';
import Builder from './Builder';
import CoursesTab from './CoursesTab';
import QuizTool from './QuizTool';
import RewriteTool from './RewriteTool';
import SettingsTab from './SettingsTab';

const NAV = [
	{ id: 'dashboard', label: __( 'Dashboard', 'dev3-ai-course-builder' ), icon: 'dashboard' },
	{ id: 'new', label: __( 'New course', 'dev3-ai-course-builder' ), icon: 'plus' },
	{ id: 'courses', label: __( 'Courses', 'dev3-ai-course-builder' ), icon: 'list' },
	{ id: 'quiz', label: __( 'Quiz generator', 'dev3-ai-course-builder' ), icon: 'check' },
	{ id: 'rewrite', label: __( 'Lesson rewriter', 'dev3-ai-course-builder' ), icon: 'edit' },
	{ id: 'settings', label: __( 'Settings', 'dev3-ai-course-builder' ), icon: 'settings' },
	{ id: 'api', label: __( 'API Config', 'dev3-ai-course-builder' ), icon: 'key' },
	{ id: 'usage', label: __( 'Usage Logs', 'dev3-ai-course-builder' ), icon: 'chart' },
];
const ROUTES = [ ...NAV.map( ( n ) => n.id ), 'builder' ];

export default function App() {
	const [ route, navigate ] = useHashRoute( ROUTES, 'dashboard' );
	const [ settings, setSettings ] = useState( null );
	const [ status, setStatus ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ meter, setMeter ] = useState( 0 );

	const loadStatus = () => request( 'status' ).then( setStatus ).catch( () => {} );
	const load = () => {
		setError( null );
		request( 'settings' ).then( setSettings ).catch( setError );
		loadStatus();
	};
	useEffect( load, [] );

	const bump = () => setMeter( ( n ) => n + 1 );
	const save = ( patch ) =>
		request( 'settings', { method: 'POST', data: patch } ).then( ( next ) => {
			setSettings( next );
			bump();
			loadStatus();
			return next;
		} );

	const adapters = ( status && status.adapters ) || config.adapters || [];
	const shared = { settings, status, adapters, navigate, onUsage: bump, reloadStatus: loadStatus };
	const screens = {
		dashboard: () => <Dashboard { ...shared } />,
		new: () => <NewCourse key={ route.params[ 0 ] || 'new' } mode={ route.params[ 0 ] } { ...shared } />,
		builder: () => <Builder key={ route.params[ 0 ] } id={ parseInt( route.params[ 0 ], 10 ) } { ...shared } />,
		courses: () => <CoursesTab { ...shared } />,
		quiz: () => <QuizTool key={ route.params[ 0 ] || 'quiz' } lessonId={ parseInt( route.params[ 0 ] || '0', 10 ) } { ...shared } />,
		rewrite: () => <RewriteTool key={ route.params[ 0 ] || 'rewrite' } lessonId={ parseInt( route.params[ 0 ] || '0', 10 ) } { ...shared } />,
		settings: () => <SettingsTab settings={ settings } save={ save } adapters={ adapters } />,
		api: () => (
			<>
				<PageHeader title={ __( 'API Config', 'dev3-ai-course-builder' ) } description={ __( 'Connect Google Gemini, pick models and set a monthly AI budget.', 'dev3-ai-course-builder' ) } />
				<ApiTab settings={ settings } save={ save } />
			</>
		),
		usage: () => (
			<>
				<PageHeader title={ __( 'Usage Logs', 'dev3-ai-course-builder' ) } description={ __( 'Requests, tokens and estimated cost of every DEV3 AI feature.', 'dev3-ai-course-builder' ) } />
				<UsageTab />
			</>
		),
	};

	return (
		<AppShell
			name={ config.name || 'DEV3 AI Course Builder for LearnDash & Tutor LMS' }
			version={ config.version }
			icon={ config.assetsUrl ? `${ config.assetsUrl }img/icon.svg` : undefined }
			nav={ NAV }
			active={ route.id === 'builder' ? 'courses' : route.id }
			actions={ <UsageMeter compact refreshKey={ meter } /> }
			footer={ __( 'Made by DEV3 Technologies · AI by Google Gemini, using your own API key.', 'dev3-ai-course-builder' ) }
		>
			{ error && <ErrorNotice error={ error } onRetry={ load } /> }
			{ ! settings && ! error && <SkeletonCard lines={ 5 } /> }
			{ settings && screens[ route.id ]() }
		</AppShell>
	);
}
