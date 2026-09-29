/**
 * Settings: safety switches, connection details and health checks.
 */
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
	Spinner,
	ToggleControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { update } from '@wordpress/icons';

import { api } from '../api';
import { Badge, CopyField, ScreenHeader } from '../components';

const settings = window.mcpaiSettings;

const STATUS_LABELS = {
	good: __( 'OK', 'mcpai' ),
	info: __( 'Note', 'mcpai' ),
	warning: __( 'Check', 'mcpai' ),
	critical: __( 'Problem', 'mcpai' ),
};

/**
 * Checks that need a real HTTP request, run from the browser: an AI app calls
 * the same URL, and WordPress loopback requests hang on single-threaded servers.
 *
 * @return {Promise<Object[]>} Check results.
 */
async function browserChecks() {
	const results = [];
	const request = ( url, options ) =>
		window.fetch( url, { credentials: 'omit', ...options } );

	try {
		const response = await request( settings.endpoint, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: '{"jsonrpc":"2.0","id":1,"method":"ping"}',
		} );
		const ok = [ 401, 503 ].includes( response.status );
		results.push( {
			id: 'endpoint',
			status: ok ? 'good' : 'critical',
			label: __( 'Connection URL', 'mcpai' ),
			message: ok
				? __(
						'The connection URL is online and requires a key.',
						'mcpai'
					)
				: sprintf(
						/* translators: %d: HTTP status code */
						__(
							'The connection URL answered with an unexpected status (%d).',
							'mcpai'
						),
						response.status
					),
			fix: ok
				? ''
				: __(
						'A security plugin, firewall or cache may be blocking /wp-json/. Allow requests to /wp-json/mcpai/.',
						'mcpai'
					),
		} );
	} catch {
		results.push( {
			id: 'endpoint',
			status: 'critical',
			label: __( 'Connection URL', 'mcpai' ),
			message: __( 'The connection URL could not be reached.', 'mcpai' ),
			fix: __(
				'Check that the REST API is not disabled by a security plugin or firewall.',
				'mcpai'
			),
		} );
		return results;
	}

	try {
		const response = await request( settings.probeUrl, {
			headers: { Authorization: 'Bearer mcpai-probe' },
		} );
		const body = await response.json();
		results.push(
			body.authorization
				? {
						id: 'auth_header',
						status: 'good',
						label: __( 'Key header', 'mcpai' ),
						message: __(
							'Your server passes the key header through to WordPress.',
							'mcpai'
						),
					}
				: {
						id: 'auth_header',
						status: 'warning',
						label: __( 'Key header', 'mcpai' ),
						message: __(
							'Your server removes the "Authorization" header, so apps sending the key that way are rejected. Apps can send it as "X-MCPAI-Key" instead.',
							'mcpai'
						),
						fix: __(
							'Add this line to .htaccess above "# BEGIN WordPress": SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1',
							'mcpai'
						),
					}
		);
	} catch {
		// Reachability is already reported above.
	}

	return results;
}

function HealthChecks() {
	const [ health, setHealth ] = useState( null );
	const [ error, setError ] = useState( null );

	const run = () => {
		setHealth( null );
		Promise.all( [ api.getHealth(), browserChecks() ] )
			.then( ( [ server, browser ] ) => {
				const [ first, ...rest ] = server.checks;
				// Keep the pause warning first, then permalinks, then reachability.
				const checks =
					first?.id === 'paused'
						? [ first, ...browser, ...rest ]
						: [ ...browser, ...server.checks ];
				setHealth( { ...server, checks } );
			} )
			.catch( ( e ) => setError( e.message ) );
	};

	useEffect( run, [] );

	return (
		<Card>
			<CardHeader>
				<h3>{ __( 'Connection check', 'mcpai' ) }</h3>
				<Button
					variant="secondary"
					size="compact"
					icon={ update }
					onClick={ run }
					disabled={ ! health }
				>
					{ __( 'Run again', 'mcpai' ) }
				</Button>
			</CardHeader>
			<CardBody>
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }
				{ ! health && ! error && <Spinner /> }
				{ health && (
					<ul className="mcpai-health">
						{ health.checks.map( ( check ) => (
							<li
								key={ check.id }
								className={ `is-${ check.status }` }
							>
								<span className="mcpai-health__status">
									{ STATUS_LABELS[ check.status ] }
								</span>
								<div>
									<strong>{ check.label }</strong>
									<p>{ check.message }</p>
									{ check.fix && (
										<p className="mcpai-health__fix">
											<strong>
												{ __( 'How to fix:', 'mcpai' ) }
											</strong>{ ' ' }
											{ check.fix }
										</p>
									) }
								</div>
							</li>
						) ) }
					</ul>
				) }
			</CardBody>
		</Card>
	);
}

export default function Settings( { state, onStateChange } ) {
	const [ error, setError ] = useState( null );

	const save = async ( data ) => {
		try {
			onStateChange( await api.updateSettings( data ) );
		} catch ( e ) {
			setError( e.message );
		}
	};

	return (
		<>
			<ScreenHeader
				title={ __( 'Settings', 'mcpai' ) }
				description={ __(
					'Safety switches that apply to every connected AI app.',
					'mcpai'
				) }
			/>

			{ error && (
				<Notice status="error" onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }

			<div className="mcpai-settings">
				<Card>
					<CardHeader>
						<h3>{ __( 'Safety', 'mcpai' ) }</h3>
					</CardHeader>
					<CardBody className="mcpai-settings__toggles">
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __( 'Pause all AI access', 'mcpai' ) }
							help={ __(
								'Instantly blocks every connected app. Turn it off to resume — connections are kept.',
								'mcpai'
							) }
							checked={ Boolean( state?.paused ) }
							onChange={ ( paused ) => save( { paused } ) }
						/>
						<div
							className={ `mcpai-danger-toggle ${
								state?.allow_permanent_delete ? 'is-on' : ''
							}` }
						>
							<ToggleControl
								__nextHasNoMarginBottom
								label={ __(
									'Allow permanent deletion',
									'mcpai'
								) }
								help={ __(
									'Off (recommended): deleted posts go to the trash and media cannot be deleted. On: AI apps with delete access can skip the trash and delete media — this cannot be undone.',
									'mcpai'
								) }
								checked={ Boolean(
									state?.allow_permanent_delete
								) }
								onChange={ ( value ) =>
									save( { allow_permanent_delete: value } )
								}
							/>
							{ state?.allow_permanent_delete && (
								<p className="mcpai-danger-toggle__warning">
									{ __(
										'Permanent deletion is on. AI apps with delete access can remove things that cannot be undone.',
										'mcpai'
									) }
								</p>
							) }
						</div>
					</CardBody>
				</Card>

				<Card>
					<CardHeader>
						<h3>{ __( 'Advanced', 'mcpai' ) }</h3>
					</CardHeader>
					<CardBody className="mcpai-settings__toggles">
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __(
								'Compact tool list for all apps',
								'mcpai'
							) }
							help={ __(
								'Shows AI apps 3 tools that look up the others, instead of the full list. Useful for apps or small models that struggle with many tools. Apps with a known tool limit, like Cursor, use this automatically when needed.',
								'mcpai'
							) }
							checked={ Boolean( state?.compact_mode ) }
							onChange={ ( value ) =>
								save( { compact_mode: value } )
							}
						/>
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __(
								'Delete all Mcpai data when the plugin is deleted',
								'mcpai'
							) }
							help={ __(
								'Removes connections, activity history and settings when you delete the plugin from the Plugins screen. Deactivating the plugin never deletes anything.',
								'mcpai'
							) }
							checked={ Boolean( state?.delete_data ) }
							onChange={ ( value ) =>
								save( { delete_data: value } )
							}
						/>
					</CardBody>
				</Card>

				<Card>
					<CardHeader>
						<h3>{ __( 'Connection details', 'mcpai' ) }</h3>
					</CardHeader>
					<CardBody>
						<CopyField
							label={ __(
								'MCP server URL (Streamable HTTP)',
								'mcpai'
							) }
							value={ settings.endpoint }
						/>
						<p className="mcpai-muted">
							{ __(
								'Apps authenticate with a connection key (Authorization: Bearer …), or with a WordPress Application Password.',
								'mcpai'
							) }
						</p>
					</CardBody>
				</Card>

				<Card>
					<CardHeader>
						<h3>{ __( 'Integrations', 'mcpai' ) }</h3>
					</CardHeader>
					<CardBody>
						<p className="mcpai-muted">
							{ __(
								'Extra tools appear automatically when these plugins are active. Manage them under Tools.',
								'mcpai'
							) }
						</p>
						<ul className="mcpai-integrations">
							{ ( state?.integrations || [] ).map( ( item ) => (
								<li key={ item.slug }>
									<span>{ item.name }</span>
									{ item.active ? (
										<Badge tone="success">
											{ __( 'Active', 'mcpai' ) }
										</Badge>
									) : (
										<Badge>
											{ __( 'Not active', 'mcpai' ) }
										</Badge>
									) }
								</li>
							) ) }
						</ul>
					</CardBody>
				</Card>

				<HealthChecks />
			</div>
		</>
	);
}
