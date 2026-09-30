/**
 * Connections: every AI app that can access the site, with status and revoke.
 */
import { Button, Card, Modal, Notice, Spinner } from '@wordpress/components';
import { humanTimeDiff } from '@wordpress/date';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { plus } from '@wordpress/icons';

import { api } from '../api';
import {
	Badge,
	ClientMark,
	ScreenHeader,
	clientFor,
	levelTone,
} from '../components';

const settings = window.viagentSettings;

function ConnectionStatus( { connection, onHelp } ) {
	if ( connection.last_used_at ) {
		return (
			<span className="viagent-conn__status is-connected">
				<span className="viagent-dot is-success" aria-hidden="true" />
				{ sprintf(
					/* translators: %s: relative time */
					__( 'Active %s', 'viagent' ),
					humanTimeDiff( connection.last_used_at )
				) }
			</span>
		);
	}
	return (
		<span className="viagent-conn__status is-waiting">
			<span className="viagent-dot is-warning" aria-hidden="true" />
			{ __( 'Not connected yet', 'viagent' ) }
			<Button variant="link" onClick={ onHelp }>
				{ __( 'How to finish', 'viagent' ) }
			</Button>
		</span>
	);
}

export default function Connections( { navigate } ) {
	const [ connections, setConnections ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ revoking, setRevoking ] = useState( null );
	const [ busy, setBusy ] = useState( false );

	const load = () =>
		api
			.listConnections()
			.then( setConnections )
			.catch( ( e ) => setError( e.message ) );

	useEffect( () => {
		load();
	}, [] );

	const revoke = async () => {
		setBusy( true );
		try {
			await ( revoking.type === 'oauth'
				? api.revokeGrant( revoking.id )
				: api.revokeConnection( revoking.id ) );
			setRevoking( null );
			await load();
		} catch ( e ) {
			setError( e.message );
		}
		setBusy( false );
	};

	const newButton = (
		<Button
			variant="primary"
			icon={ plus }
			onClick={ () => navigate( 'connect' ) }
		>
			{ __( 'Connect an app', 'viagent' ) }
		</Button>
	);

	return (
		<>
			<ScreenHeader
				title={ __( 'Connections', 'viagent' ) }
				description={ __(
					'AI apps that can access your site. Revoke any of them at any time — they are disconnected immediately.',
					'viagent'
				) }
				actions={ newButton }
			/>

			{ error && (
				<Notice status="error" onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }

			{ connections === null && <Spinner /> }

			{ connections && connections.length === 0 && (
				<Card className="viagent-empty">
					<span className="viagent-empty__icon" aria-hidden="true">
						✦
					</span>
					<h3>{ __( 'No AI apps connected yet', 'viagent' ) }</h3>
					<p>
						{ __(
							'Connect Claude, ChatGPT, Cursor or another AI app in about a minute.',
							'viagent'
						) }
					</p>
					{ newButton }
				</Card>
			) }

			{ connections && connections.length > 0 && (
				<Card className="viagent-list">
					{ connections.map( ( connection ) => {
						const client = clientFor( connection.client );
						return (
							<div
								className="viagent-conn"
								key={ `${ connection.type }-${ connection.id }` }
							>
								<ClientMark client={ client } />
								<div className="viagent-conn__main">
									<strong>{ connection.name }</strong>
									<span
										className="viagent-muted"
										title={
											connection.key_prefix
												? `${ connection.key_prefix }…`
												: undefined
										}
									>
										{ connection.type === 'oauth'
											? __(
													'Signed in with WordPress',
													'viagent'
												)
											: __(
													'Connected with a key',
													'viagent'
												) }
										{ ' · ' }
										{ sprintf(
											/* translators: %s: user name */
											__( 'acts as %s', 'viagent' ),
											connection.user
										) }
									</span>
								</div>
								<div className="viagent-conn__badges">
									<Badge
										tone={ levelTone(
											connection.access_level
										) }
									>
										{
											settings.levels[
												connection.access_level
											]
										}
									</Badge>
									{ connection.access_level !== 'read' &&
										connection.draft_only && (
											<Badge>
												{ __(
													'Drafts only',
													'viagent'
												) }
											</Badge>
										) }
									{ connection.compact && (
										<Badge tone="info">
											{ __( 'Compact', 'viagent' ) }
										</Badge>
									) }
								</div>
								<ConnectionStatus
									connection={ connection }
									onHelp={ () => navigate( 'connect' ) }
								/>
								<Button
									className="viagent-conn__revoke"
									variant="tertiary"
									isDestructive
									size="compact"
									onClick={ () => setRevoking( connection ) }
								>
									{ __( 'Revoke', 'viagent' ) }
								</Button>
							</div>
						);
					} ) }
				</Card>
			) }

			{ revoking && (
				<Modal
					title={ __( 'Revoke this connection?', 'viagent' ) }
					onRequestClose={ () => setRevoking( null ) }
					size="small"
				>
					<p>
						{ sprintf(
							/* translators: %s: connection name */
							__(
								'“%s” will immediately lose access to your site. You can create a new connection later.',
								'viagent'
							),
							revoking.name
						) }
					</p>
					<div className="viagent-actions">
						<Button
							variant="tertiary"
							onClick={ () => setRevoking( null ) }
						>
							{ __( 'Cancel', 'viagent' ) }
						</Button>
						<Button
							variant="primary"
							isDestructive
							isBusy={ busy }
							disabled={ busy }
							onClick={ revoke }
						>
							{ __( 'Revoke access', 'viagent' ) }
						</Button>
					</div>
				</Modal>
			) }
		</>
	);
}
