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

const settings = window.mcpaiSettings;

function ConnectionStatus( { connection, onHelp } ) {
	if ( connection.last_used_at ) {
		return (
			<span className="mcpai-conn__status is-connected">
				<span className="mcpai-dot is-success" aria-hidden="true" />
				{ sprintf(
					/* translators: %s: relative time */
					__( 'Active %s', 'mcpai' ),
					humanTimeDiff( connection.last_used_at )
				) }
			</span>
		);
	}
	return (
		<span className="mcpai-conn__status is-waiting">
			<span className="mcpai-dot is-warning" aria-hidden="true" />
			{ __( 'Not connected yet', 'mcpai' ) }
			<Button variant="link" onClick={ onHelp }>
				{ __( 'How to finish', 'mcpai' ) }
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
			{ __( 'Connect an app', 'mcpai' ) }
		</Button>
	);

	return (
		<>
			<ScreenHeader
				title={ __( 'Connections', 'mcpai' ) }
				description={ __(
					'AI apps that can access your site. Revoke any of them at any time — they are disconnected immediately.',
					'mcpai'
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
				<Card className="mcpai-empty">
					<span className="mcpai-empty__icon" aria-hidden="true">
						✦
					</span>
					<h3>{ __( 'No AI apps connected yet', 'mcpai' ) }</h3>
					<p>
						{ __(
							'Connect Claude, ChatGPT, Cursor or another AI app in about a minute.',
							'mcpai'
						) }
					</p>
					{ newButton }
				</Card>
			) }

			{ connections && connections.length > 0 && (
				<Card className="mcpai-list">
					{ connections.map( ( connection ) => {
						const client = clientFor( connection.client );
						return (
							<div
								className="mcpai-conn"
								key={ `${ connection.type }-${ connection.id }` }
							>
								<ClientMark client={ client } />
								<div className="mcpai-conn__main">
									<strong>{ connection.name }</strong>
									<span
										className="mcpai-muted"
										title={
											connection.key_prefix
												? `${ connection.key_prefix }…`
												: undefined
										}
									>
										{ connection.type === 'oauth'
											? __(
													'Signed in with WordPress',
													'mcpai'
												)
											: __(
													'Connected with a key',
													'mcpai'
												) }
										{ ' · ' }
										{ sprintf(
											/* translators: %s: user name */
											__( 'acts as %s', 'mcpai' ),
											connection.user
										) }
									</span>
								</div>
								<div className="mcpai-conn__badges">
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
												{ __( 'Drafts only', 'mcpai' ) }
											</Badge>
										) }
									{ connection.compact && (
										<Badge tone="info">
											{ __( 'Compact', 'mcpai' ) }
										</Badge>
									) }
								</div>
								<ConnectionStatus
									connection={ connection }
									onHelp={ () => navigate( 'connect' ) }
								/>
								<Button
									className="mcpai-conn__revoke"
									variant="tertiary"
									isDestructive
									size="compact"
									onClick={ () => setRevoking( connection ) }
								>
									{ __( 'Revoke', 'mcpai' ) }
								</Button>
							</div>
						);
					} ) }
				</Card>
			) }

			{ revoking && (
				<Modal
					title={ __( 'Revoke this connection?', 'mcpai' ) }
					onRequestClose={ () => setRevoking( null ) }
					size="small"
				>
					<p>
						{ sprintf(
							/* translators: %s: connection name */
							__(
								'“%s” will immediately lose access to your site. You can create a new connection later.',
								'mcpai'
							),
							revoking.name
						) }
					</p>
					<div className="mcpai-actions">
						<Button
							variant="tertiary"
							onClick={ () => setRevoking( null ) }
						>
							{ __( 'Cancel', 'mcpai' ) }
						</Button>
						<Button
							variant="primary"
							isDestructive
							isBusy={ busy }
							disabled={ busy }
							onClick={ revoke }
						>
							{ __( 'Revoke access', 'mcpai' ) }
						</Button>
					</div>
				</Modal>
			) }
		</>
	);
}
