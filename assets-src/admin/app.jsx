/**
 * Admin app shell: left navigation with hash routes (#/dashboard …), page
 * heading that receives focus on navigation, one notice area, and a warning
 * before leaving a screen with unsaved changes.
 */
import { Notice } from '@wordpress/components';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import Actions from './screens/actions';
import Ai from './screens/ai';
import Appearance from './screens/appearance';
import Behaviour from './screens/behaviour';
import Dashboard from './screens/dashboard';
import Display from './screens/display';
import Flows from './screens/flows';
import Forms from './screens/forms';
import Inbox from './screens/inbox';
import Integrations from './screens/integrations';
import Knowledge from './screens/knowledge';
import Offers from './screens/offers';
import Privacy from './screens/privacy';
import Setup from './screens/setup';
import Tools from './screens/tools';

export const SCREENS = [
	{
		id: 'dashboard',
		label: () => __( 'Dashboard', 'devrivo-chatbot' ),
		Screen: Dashboard,
	},
	{
		id: 'appearance',
		label: () => __( 'Appearance', 'devrivo-chatbot' ),
		Screen: Appearance,
	},
	{
		id: 'display',
		label: () => __( 'Display rules', 'devrivo-chatbot' ),
		Screen: Display,
	},
	{
		id: 'behaviour',
		label: () => __( 'Behaviour', 'devrivo-chatbot' ),
		Screen: Behaviour,
	},
	{
		id: 'actions',
		label: () => __( 'Quick actions', 'devrivo-chatbot' ),
		Screen: Actions,
	},
	{
		id: 'flows',
		label: () => __( 'Guided conversations', 'devrivo-chatbot' ),
		Screen: Flows,
	},
	{
		id: 'forms',
		label: () => __( 'Forms', 'devrivo-chatbot' ),
		Screen: Forms,
	},
	{
		id: 'offers',
		label: () => __( 'Offers', 'devrivo-chatbot' ),
		Screen: Offers,
	},
	{
		id: 'knowledge',
		label: () => __( 'Knowledge', 'devrivo-chatbot' ),
		Screen: Knowledge,
	},
	{ id: 'ai', label: () => __( 'AI', 'devrivo-chatbot' ), Screen: Ai },
	{
		id: 'integrations',
		label: () => __( 'Integrations', 'devrivo-chatbot' ),
		Screen: Integrations,
	},
	{
		id: 'privacy',
		label: () => __( 'Privacy', 'devrivo-chatbot' ),
		Screen: Privacy,
	},
	{
		id: 'inbox',
		label: () => __( 'Inbox', 'devrivo-chatbot' ),
		Screen: Inbox,
	},
	{
		id: 'tools',
		label: () => __( 'Tools', 'devrivo-chatbot' ),
		Screen: Tools,
	},
	// Opened from the Dashboard card, the Plugins screen notice and Tools.
	{
		id: 'setup',
		label: () => __( 'Setup', 'devrivo-chatbot' ),
		Screen: Setup,
		hidden: true,
	},
];

/**
 * Route from the URL hash.
 *
 * @return {string} Screen ID.
 */
export function currentRoute() {
	const id = window.location.hash.replace( /^#\/?/, '' ).split( '?' )[ 0 ];
	return SCREENS.some( ( screen ) => screen.id === id ) ? id : 'dashboard';
}

/**
 * The app.
 *
 * @return {Element} App.
 */
export default function App() {
	const [ route, setRoute ] = useState( currentRoute );
	const [ notice, setNotice ] = useState( null );
	const [ dirty, setDirtyState ] = useState( false );
	const heading = useRef( null );
	const first = useRef( true );

	const setDirty = useCallback( ( value ) => setDirtyState( !! value ), [] );
	const notify = useCallback(
		( status, message ) =>
			setNotice( message ? { status, message } : null ),
		[]
	);

	useEffect( () => {
		const onHash = () => {
			setRoute( currentRoute() );
			setNotice( null );
		};
		window.addEventListener( 'hashchange', onHash );
		return () => window.removeEventListener( 'hashchange', onHash );
	}, [] );

	useEffect( () => {
		if ( ! dirty ) {
			return undefined;
		}
		const onLeave = ( event ) => {
			event.preventDefault();
			event.returnValue = '';
		};
		window.addEventListener( 'beforeunload', onLeave );
		return () => window.removeEventListener( 'beforeunload', onLeave );
	}, [ dirty ] );

	useEffect( () => {
		if ( first.current ) {
			first.current = false;
			return;
		}
		if ( heading.current ) {
			heading.current.focus();
		}
	}, [ route ] );

	const onNavigate = ( event ) => {
		if (
			dirty &&
			// eslint-disable-next-line no-alert -- Native confirm keeps navigation simple and accessible.
			! window.confirm(
				__(
					'You have unsaved changes. Leave this screen without saving?',
					'devrivo-chatbot'
				)
			)
		) {
			event.preventDefault();
		}
	};

	const current =
		SCREENS.find( ( screen ) => screen.id === route ) || SCREENS[ 0 ];
	const { Screen } = current;

	return (
		<div className="devrivo-admin">
			<nav
				className="devrivo-admin__nav"
				aria-label={ __(
					'DevRivo Chatbot settings',
					'devrivo-chatbot'
				) }
			>
				<p className="devrivo-admin__brand">
					{ __( 'DevRivo Chatbot', 'devrivo-chatbot' ) }
				</p>
				<ul>
					{ SCREENS.filter( ( screen ) => ! screen.hidden ).map(
						( screen ) => (
							<li key={ screen.id }>
								<a
									href={ `#/${ screen.id }` }
									onClick={ onNavigate }
									aria-current={
										screen.id === route ? 'page' : undefined
									}
								>
									{ screen.label() }
								</a>
							</li>
						)
					) }
				</ul>
			</nav>
			<main className="devrivo-admin__main">
				<h1
					ref={ heading }
					tabIndex={ -1 }
					className="devrivo-admin__title"
				>
					{ current.label() }
				</h1>
				<div aria-live="polite">
					{ notice && (
						<Notice
							status={ notice.status }
							onRemove={ () => setNotice( null ) }
						>
							{ notice.message }
						</Notice>
					) }
				</div>
				<Screen key={ route } notify={ notify } setDirty={ setDirty } />
			</main>
		</div>
	);
}
