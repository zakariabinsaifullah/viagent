/**
 * Mcpai admin app.
 */
import { Button } from '@wordpress/components';
import { createRoot, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { api } from './api';
import Activity from './screens/activity';
import Connect from './screens/connect';
import Connections from './screens/connections';
import Overview from './screens/overview';
import Settings from './screens/settings';
import Tools from './screens/tools';
import './admin.scss';

const settings = window.mcpaiSettings;

const TABS = [
	{ id: 'overview', label: __( 'Overview', 'mcpai' ) },
	{ id: 'connect', label: __( 'Connect an app', 'mcpai' ) },
	{ id: 'connections', label: __( 'Connections', 'mcpai' ) },
	{ id: 'tools', label: __( 'Tools', 'mcpai' ) },
	{ id: 'activity', label: __( 'Activity', 'mcpai' ) },
	{ id: 'settings', label: __( 'Settings', 'mcpai' ) },
];

function currentTab() {
	const id = window.location.hash.replace( /^#\/?/, '' );
	return TABS.some( ( tab ) => tab.id === id ) ? id : null;
}

function App() {
	const [ tab, setTab ] = useState( currentTab() );
	const [ state, setState ] = useState( null );

	useEffect( () => {
		const onHash = () => setTab( currentTab() );
		window.addEventListener( 'hashchange', onHash );
		return () => window.removeEventListener( 'hashchange', onHash );
	}, [] );

	useEffect( () => {
		api.getState().then( ( result ) => {
			setState( result );
			// First visit: send new users straight to the wizard.
			setTab(
				( current ) =>
					current || ( result.connections ? 'overview' : 'connect' )
			);
		} );
	}, [ tab ] );

	const navigate = ( id ) => {
		window.location.hash = `/${ id }`;
		window.scrollTo( 0, 0 );
	};

	const resume = async () =>
		setState( await api.updateSettings( { paused: false } ) );

	return (
		<div className="mcpai-app">
			<header className="mcpai-header">
				<span className="mcpai-header__logo" aria-hidden="true">
					<svg
						viewBox="0 0 24 24"
						width="22"
						height="22"
						fill="currentColor"
					>
						<path d="M12 2l1.8 4.7L18.5 8.5l-4.7 1.8L12 15l-1.8-4.7L5.5 8.5l4.7-1.8zM18 14l.9 2.1L21 17l-2.1.9L18 20l-.9-2.1L15 17l2.1-.9zM6 15l.7 1.8L8.5 17.5l-1.8.7L6 20l-.7-1.8-1.8-.7 1.8-.7z" />
					</svg>
				</span>
				<div className="mcpai-header__title">
					<h1>{ __( 'Mcpai', 'mcpai' ) }</h1>
					<p>
						{ __(
							'Let AI apps like Claude, ChatGPT and Cursor work on',
							'mcpai'
						) }{ ' ' }
						<strong>{ settings.siteName }</strong>
					</p>
				</div>
				{ state && tab !== 'overview' && (
					<div
						className={ `mcpai-status ${ state.paused ? 'is-paused' : 'is-active' }` }
					>
						<span className="mcpai-status__dot" />
						{ state.paused
							? __( 'AI access paused', 'mcpai' )
							: __( 'AI access on', 'mcpai' ) }
						{ state.paused && (
							<Button variant="link" onClick={ resume }>
								{ __( 'Resume', 'mcpai' ) }
							</Button>
						) }
					</div>
				) }
			</header>

			<nav
				className="mcpai-nav"
				aria-label={ __( 'Mcpai sections', 'mcpai' ) }
			>
				{ TABS.map( ( item ) => (
					<a
						key={ item.id }
						href={ `#/${ item.id }` }
						className={ item.id === tab ? 'is-active' : '' }
						aria-current={ item.id === tab ? 'page' : undefined }
					>
						{ item.label }
						{ item.id === 'connections' &&
							state?.connections > 0 && (
								<span className="mcpai-nav__count">
									{ state.connections }
								</span>
							) }
					</a>
				) ) }
			</nav>

			<main className="mcpai-main">
				{ tab === 'overview' && (
					<Overview
						state={ state }
						navigate={ navigate }
						onStateChange={ setState }
					/>
				) }
				{ tab === 'connect' && <Connect navigate={ navigate } /> }
				{ tab === 'connections' && (
					<Connections navigate={ navigate } />
				) }
				{ tab === 'tools' && <Tools /> }
				{ tab === 'activity' && <Activity /> }
				{ tab === 'settings' && (
					<Settings state={ state } onStateChange={ setState } />
				) }
			</main>
		</div>
	);
}

const root = document.getElementById( 'mcpai-root' );
if ( root ) {
	createRoot( root ).render( <App /> );
}
