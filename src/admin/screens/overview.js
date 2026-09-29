/**
 * Overview: the home screen for returning users — status at a glance, what
 * needs attention, connected apps, recent changes and things to try.
 */
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Spinner,
} from '@wordpress/components';
import { humanTimeDiff } from '@wordpress/date';
import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { arrowRight, plus } from '@wordpress/icons';

import { api } from '../api';
import { Badge, ClientMark, clientFor, humanizeTool } from '../components';

function StatusDot( { tone } ) {
	return <span className={ `mcpai-dot is-${ tone }` } aria-hidden="true" />;
}

export default function Overview( { state, navigate, onStateChange } ) {
	const [ connections, setConnections ] = useState( null );
	const [ activity, setActivity ] = useState( null );
	const [ health, setHealth ] = useState( null );
	const [ prompts, setPrompts ] = useState( null );

	useEffect( () => {
		api.listConnections()
			.then( setConnections )
			.catch( () => setConnections( [] ) );
		api.listActivity( 1, 'changes' )
			.then( ( data ) => setActivity( data.items.slice( 0, 5 ) ) )
			.catch( () => setActivity( [] ) );
		api.getHealth()
			.then( setHealth )
			.catch( () => setHealth( { checks: [] } ) );
		api.listPrompts()
			.then( ( list ) =>
				setPrompts( list.filter( ( p ) => p.enabled && p.available ) )
			)
			.catch( () => setPrompts( [] ) );
	}, [] );

	const connected = ( connections || [] ).filter( ( c ) => c.last_used_at );
	const waiting = ( connections || [] ).filter( ( c ) => ! c.last_used_at );

	const attention = [];
	if ( state?.paused ) {
		attention.push( {
			tone: 'warning',
			text: __(
				'AI access is paused. Connected apps can’t reach your site.',
				'mcpai'
			),
			action: __( 'Resume', 'mcpai' ),
			onClick: async () =>
				onStateChange( await api.updateSettings( { paused: false } ) ),
		} );
	}
	waiting.forEach( ( connection ) =>
		attention.push( {
			tone: 'warning',
			text: sprintf(
				/* translators: %s: connection name */
				__( '%s is set up but hasn’t connected yet.', 'mcpai' ),
				connection.name
			),
			action: __( 'Show me how', 'mcpai' ),
			onClick: () => navigate( 'connect' ),
		} )
	);
	( health?.checks || [] )
		.filter(
			( check ) =>
				[ 'warning', 'critical' ].includes( check.status ) &&
				check.id !== 'paused'
		)
		.forEach( ( check ) =>
			attention.push( {
				tone: check.status === 'critical' ? 'danger' : 'warning',
				text: `${ check.label }: ${ check.message }`,
				action: __( 'Fix it', 'mcpai' ),
				onClick: () => navigate( 'settings' ),
			} )
		);
	if ( state?.allow_permanent_delete ) {
		attention.push( {
			tone: 'warning',
			text: __(
				'Permanent deletion is allowed — AI apps can delete things that can’t be undone.',
				'mcpai'
			),
			action: __( 'Review', 'mcpai' ),
			onClick: () => navigate( 'settings' ),
		} );
	}

	const loading = connections === null;
	let headline = __( 'No AI apps are connected yet', 'mcpai' );
	if ( connected.length ) {
		headline = sprintf(
			/* translators: %d: number of connected apps */
			_n(
				'%d AI app is working on your site',
				'%d AI apps are working on your site',
				connected.length,
				'mcpai'
			),
			connected.length
		);
	}

	return (
		<div className="mcpai-overview">
			<section
				className={ `mcpai-hero ${ state?.paused ? 'is-paused' : '' }` }
			>
				<div className="mcpai-hero__text">
					<span className="mcpai-hero__eyebrow">
						<StatusDot
							tone={ state?.paused ? 'warning' : 'success' }
						/>
						{ state?.paused
							? __( 'Paused', 'mcpai' )
							: __( 'AI access on', 'mcpai' ) }
					</span>
					<h2>{ loading ? __( 'Loading…', 'mcpai' ) : headline }</h2>
					<p>
						{ state?.last_activity
							? sprintf(
									/* translators: %s: relative time */
									__(
										'Last activity %s. Every change is logged and can be undone.',
										'mcpai'
									),
									humanTimeDiff( state.last_activity )
								)
							: __(
									'Connect Claude, ChatGPT, Cursor or another AI app in about a minute. Every change it makes is logged and can be undone.',
									'mcpai'
								) }
					</p>
				</div>
				<div className="mcpai-hero__actions">
					<Button
						variant="primary"
						icon={ plus }
						onClick={ () => navigate( 'connect' ) }
					>
						{ __( 'Connect an app', 'mcpai' ) }
					</Button>
					<Button
						variant="secondary"
						onClick={ async () =>
							onStateChange(
								await api.updateSettings( {
									paused: ! state?.paused,
								} )
							)
						}
					>
						{ state?.paused
							? __( 'Resume AI access', 'mcpai' )
							: __( 'Pause AI access', 'mcpai' ) }
					</Button>
				</div>
			</section>

			{ attention.length > 0 && (
				<Card className="mcpai-attention">
					<CardHeader>
						<h3>{ __( 'Needs your attention', 'mcpai' ) }</h3>
						<Badge tone="warning">{ attention.length }</Badge>
					</CardHeader>
					<CardBody className="mcpai-attention__list">
						{ attention.map( ( item ) => (
							<div
								className="mcpai-attention__item"
								key={ item.text }
							>
								<StatusDot tone={ item.tone } />
								<span>{ item.text }</span>
								<Button
									variant="secondary"
									size="compact"
									onClick={ item.onClick }
								>
									{ item.action }
								</Button>
							</div>
						) ) }
					</CardBody>
				</Card>
			) }

			<div className="mcpai-overview__grid">
				<Card>
					<CardHeader>
						<h3>{ __( 'Connected apps', 'mcpai' ) }</h3>
						<Button
							variant="link"
							onClick={ () => navigate( 'connections' ) }
						>
							{ __( 'Manage', 'mcpai' ) }
						</Button>
					</CardHeader>
					<CardBody className="mcpai-mini-list">
						{ loading && <Spinner /> }
						{ ! loading && ! connections.length && (
							<p className="mcpai-muted">
								{ __( 'Nothing connected yet.', 'mcpai' ) }
							</p>
						) }
						{ ( connections || [] )
							.slice( 0, 5 )
							.map( ( connection ) => (
								<div
									className="mcpai-mini-list__row"
									key={ `${ connection.type }-${ connection.id }` }
								>
									<ClientMark
										client={ clientFor(
											connection.client
										) }
										size="small"
									/>
									<div className="mcpai-mini-list__main">
										<strong>{ connection.name }</strong>
										<span className="mcpai-muted">
											{ connection.last_used_at
												? sprintf(
														/* translators: %s: relative time */
														__(
															'Active %s',
															'mcpai'
														),
														humanTimeDiff(
															connection.last_used_at
														)
													)
												: __(
														'Not connected yet',
														'mcpai'
													) }
										</span>
									</div>
									<StatusDot
										tone={
											connection.last_used_at
												? 'success'
												: 'warning'
										}
									/>
								</div>
							) ) }
					</CardBody>
				</Card>

				<Card>
					<CardHeader>
						<h3>{ __( 'Recent changes', 'mcpai' ) }</h3>
						<Button
							variant="link"
							onClick={ () => navigate( 'activity' ) }
						>
							{ __( 'View all', 'mcpai' ) }
						</Button>
					</CardHeader>
					<CardBody className="mcpai-mini-list">
						{ activity === null && <Spinner /> }
						{ activity?.length === 0 && (
							<p className="mcpai-muted">
								{ __(
									'When an AI app changes something, it shows up here — with an Undo button.',
									'mcpai'
								) }
							</p>
						) }
						{ ( activity || [] ).map( ( item ) => (
							<div
								className="mcpai-mini-list__row"
								key={ item.id }
							>
								<div className="mcpai-mini-list__main">
									<strong>
										{ humanizeTool( item.tool ) }
										{ item.object && (
											<span className="mcpai-muted">
												{ ' ' }
												· { item.object.title }
											</span>
										) }
									</strong>
									<span className="mcpai-muted">
										{ item.connection }
										{ item.agent
											? ` (${ sprintf(
													/* translators: %s: program name, e.g. curl */
													__( 'via %s', 'mcpai' ),
													item.agent
												) })`
											: '' }{ ' ' }
										· { humanTimeDiff( item.created_at ) }
									</span>
								</div>
								{ item.status !== 'ok' && (
									<Badge tone="danger">
										{ __( 'Failed', 'mcpai' ) }
									</Badge>
								) }
								{ item.reverted_at && (
									<Badge>{ __( 'Undone', 'mcpai' ) }</Badge>
								) }
							</div>
						) ) }
					</CardBody>
				</Card>
			</div>

			{ prompts?.length > 0 && (
				<Card>
					<CardHeader>
						<div>
							<h3>
								{ __(
									'Ready-made tasks your AI can do',
									'mcpai'
								) }
							</h3>
							<p className="mcpai-muted">
								{ __(
									'Find them in your AI app’s “+” or / menu — or just ask in your own words.',
									'mcpai'
								) }
							</p>
						</div>
						<Button
							variant="link"
							icon={ arrowRight }
							iconPosition="right"
							onClick={ () => navigate( 'tools' ) }
						>
							{ __( 'All tools', 'mcpai' ) }
						</Button>
					</CardHeader>
					<CardBody className="mcpai-task-grid">
						{ prompts.map( ( prompt ) => (
							<div className="mcpai-task" key={ prompt.name }>
								<strong>{ prompt.title }</strong>
								<span>{ prompt.description }</span>
							</div>
						) ) }
					</CardBody>
				</Card>
			) }
		</div>
	);
}
