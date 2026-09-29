/**
 * Connect wizard: pick an app → choose what it may do → follow tailored steps.
 */
import {
	Button,
	Card,
	CardBody,
	Modal,
	Notice,
	Spinner,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { arrowLeft, check, external } from '@wordpress/icons';

import { api } from '../api';
import {
	Badge,
	ClientMark,
	CodeBlock,
	CopyButton,
	CopyField,
} from '../components';
import { deeplink, fillTemplate } from '../snippets';

const settings = window.mcpaiSettings;

const GROUPS = [
	{ id: 'app', label: __( 'Chat apps', 'mcpai' ) },
	{ id: 'editor', label: __( 'Code editors', 'mcpai' ) },
	{ id: 'cli', label: __( 'Terminal agents', 'mcpai' ) },
	{ id: 'other', label: __( 'Something else', 'mcpai' ) },
];

const ACCESS = [
	{
		id: 'read',
		title: __( 'Read only', 'mcpai' ),
		description: __(
			'Can look at your posts, pages, media and settings. Cannot change anything.',
			'mcpai'
		),
		example: __( '“Summarize my latest posts”', 'mcpai' ),
	},
	{
		id: 'content',
		title: __( 'Write content', 'mcpai' ),
		description: __(
			'Can write and edit posts and pages, upload images, and manage categories and comments.',
			'mcpai'
		),
		example: __( '“Draft a blog post about our spring sale”', 'mcpai' ),
		recommended: true,
	},
	{
		id: 'admin',
		title: __( 'Full control', 'mcpai' ),
		description: __(
			'Everything above plus settings, menus, plugins, themes and users. It can never create or edit administrators.',
			'mcpai'
		),
		example: __( '“Install an SEO plugin and set the homepage”', 'mcpai' ),
	},
];

const EXAMPLE_PROMPTS = [
	__( 'What is my WordPress site about? Give me a quick overview.', 'mcpai' ),
	__(
		'Write a 600-word blog post about why our customers love us and save it as a draft.',
		'mcpai'
	),
	__( 'Find my posts that have no featured image.', 'mcpai' ),
];

function stepClass( index, step ) {
	if ( index + 1 === step ) {
		return 'is-current';
	}
	return index + 1 < step ? 'is-done' : '';
}

function Steps( { step } ) {
	const labels = [
		__( 'Choose your AI app', 'mcpai' ),
		__( 'Choose what it can do', 'mcpai' ),
		__( 'Connect', 'mcpai' ),
	];
	return (
		<ol className="mcpai-steps">
			{ labels.map( ( label, index ) => (
				<li key={ label } className={ stepClass( index, step ) }>
					<span className="mcpai-steps__number">{ index + 1 }</span>
					{ label }
				</li>
			) ) }
		</ol>
	);
}

/**
 * Connection status per catalog slug: "connected" once an app has made a
 * request, "waiting" when it was set up but never connected.
 *
 * @param {Object[]} connections Connections from the API.
 * @return {Object} Map of slug → { status, connection }.
 */
function statusByClient( connections ) {
	const map = {};
	( connections || [] ).forEach( ( connection ) => {
		const status = connection.last_used_at ? 'connected' : 'waiting';
		const current = map[ connection.client ];
		if (
			! current ||
			( status === 'connected' && current.status !== 'connected' )
		) {
			map[ connection.client ] = { status, connection };
		}
	} );
	return map;
}

function ClientStatus( { status } ) {
	if ( ! status ) {
		return null;
	}
	return status.status === 'connected' ? (
		<span className="mcpai-client__status is-connected">
			{ __( 'Connected', 'mcpai' ) }
		</span>
	) : (
		<span className="mcpai-client__status is-waiting">
			{ __( 'Set up · not connected yet', 'mcpai' ) }
		</span>
	);
}

function ChooseClient( { onSelect, statuses, onHelp } ) {
	const waiting = settings.clients.filter(
		( client ) => statuses[ client.slug ]?.status === 'waiting'
	);

	return (
		<div className="mcpai-client-groups">
			{ waiting.map( ( client ) => (
				<Notice
					key={ client.slug }
					status="warning"
					isDismissible={ false }
					className="mcpai-waiting-notice"
					actions={ [
						{
							label: __( 'Show me how to finish', 'mcpai' ),
							onClick: () => onHelp( client ),
							variant: 'primary',
						},
					] }
				>
					{ sprintf(
						/* translators: %s: app name */
						__(
							'%s is set up but hasn’t connected to your site yet.',
							'mcpai'
						),
						client.name
					) }
				</Notice>
			) ) }

			{ GROUPS.map( ( group ) => {
				const clients = settings.clients.filter(
					( client ) => client.group === group.id
				);
				if ( ! clients.length ) {
					return null;
				}
				return (
					<section key={ group.id }>
						<h3 className="mcpai-group-title">{ group.label }</h3>
						<div className="mcpai-client-grid">
							{ clients.map( ( client ) => {
								const soon =
									client.auth === 'oauth' && ! settings.oauth;
								let badge = null;
								if ( soon ) {
									badge = (
										<Badge>
											{ __( 'Coming soon', 'mcpai' ) }
										</Badge>
									);
								} else if ( client.auth === 'oauth' ) {
									badge = (
										<Badge tone="success">
											{ __( 'Sign in', 'mcpai' ) }
										</Badge>
									);
								} else if ( client.deeplink ) {
									badge = (
										<Badge tone="success">
											{ __( 'One-click', 'mcpai' ) }
										</Badge>
									);
								}
								return (
									<button
										key={ client.slug }
										type="button"
										className={ `mcpai-client ${
											statuses[ client.slug ]
												? `has-${ statuses[ client.slug ].status }`
												: ''
										}` }
										disabled={ soon }
										onClick={ () => onSelect( client ) }
									>
										<span className="mcpai-client__top">
											<ClientMark client={ client } />
											{ badge }
										</span>
										<strong className="mcpai-client__name">
											{ client.name }
										</strong>
										<span className="mcpai-client__tagline">
											{ client.tagline }
										</span>
										<span className="mcpai-client__footer">
											<ClientStatus
												status={
													statuses[ client.slug ]
												}
											/>
											<span
												className="mcpai-client__arrow"
												aria-hidden="true"
											>
												→
											</span>
										</span>
									</button>
								);
							} ) }
						</div>
					</section>
				);
			} ) }
		</div>
	);
}

/**
 * First message to send, naming the tools so the AI uses them.
 *
 * @param {Object} client Catalog entry.
 * @return {string} Prompt.
 */
function firstPrompt( client ) {
	const source =
		client.auth === 'oauth'
			? sprintf(
					/* translators: %s: site name */
					__( 'my “%s” connector', 'mcpai' ),
					settings.siteName
				)
			: sprintf(
					/* translators: %s: server name */
					__( 'the “%s” tools', 'mcpai' ),
					settings.serverName
				);
	return sprintf(
		/* translators: %s: connector or tools name */
		__(
			'Use %s to look at my WordPress site and give me a short overview: the site name, how many posts and pages it has, and which theme it uses.',
			'mcpai'
		),
		source
	);
}

/**
 * A numbered step section.
 *
 * @param {Object}  props
 * @param {number}  props.number   Step number.
 * @param {string}  props.title    Title.
 * @param {Element} props.children Content.
 */
function Section( { number, title, children } ) {
	return (
		<section className="mcpai-section">
			<h3 className="mcpai-section__title">
				<span className="mcpai-section__number">{ number }</span>
				{ title }
			</h3>
			<div className="mcpai-section__body">{ children }</div>
		</section>
	);
}

/**
 * "Check it's connected" + "Send your first message" steps.
 *
 * @param {Object}  props
 * @param {Object}  props.client  Catalog entry.
 * @param {number}  props.start   Number of the first section.
 * @param {boolean} props.inModal Shown in the help dialog (no live status).
 */
function FinishSteps( { client, start, inModal = false } ) {
	const prompt = firstPrompt( client );
	return (
		<>
			{ client.verify && (
				<Section
					number={ start }
					title={ sprintf(
						/* translators: %s: app name */
						__( 'Check that %s can see your site', 'mcpai' ),
						client.name
					) }
				>
					<p>{ client.verify }</p>
				</Section>
			) }
			<Section
				number={ client.verify ? start + 1 : start }
				title={ sprintf(
					/* translators: %s: app name */
					__( 'Send this first message in %s', 'mcpai' ),
					client.name
				) }
			>
				<div className="mcpai-first-prompt">
					<p>{ prompt }</p>
					<CopyButton
						text={ prompt }
						label={ __( 'Copy message', 'mcpai' ) }
					/>
				</div>
				<p className="mcpai-muted">
					{ inModal
						? __(
								'The first time, your AI asks for permission to use your site’s tools — choose “Allow”. Then come back here: the app will show as Connected.',
								'mcpai'
							)
						: __(
								'The first time, your AI asks for permission to use your site’s tools — choose “Allow”. The box below turns green as soon as it connects.',
								'mcpai'
							) }
				</p>
			</Section>
		</>
	);
}

/**
 * Help for an app that was set up but never connected (key not shown again).
 *
 * @param {Object}     props
 * @param {Object}     props.client  Catalog entry.
 * @param {() => void} props.onClose Close handler.
 * @param {() => void} props.onRedo  Start a fresh setup.
 */
function HelpModal( { client, onClose, onRedo } ) {
	const bridge = ( client.template || '' ).includes( 'mcp-remote' );
	return (
		<Modal
			title={ sprintf(
				/* translators: %s: app name */
				__( 'Finish connecting %s', 'mcpai' ),
				client.name
			) }
			onRequestClose={ onClose }
			className="mcpai-help-modal"
		>
			<div className="mcpai-connect-steps">
				{ settings.isLocal && bridge && (
					<Notice status="warning" isDismissible={ false }>
						{ __(
							'This site runs on your computer and uses a development security certificate. Make sure the "env" section of your configuration also contains this line, then restart the app:',
							'mcpai'
						) }
						<code className="mcpai-inline-code">
							&quot;NODE_TLS_REJECT_UNAUTHORIZED&quot;:
							&quot;0&quot;
						</code>
					</Notice>
				) }
				<FinishSteps client={ client } start={ 1 } inModal />
				<p className="mcpai-muted">
					{ __(
						'Still not working? Create a fresh connection and follow the steps again — the old one can be revoked under Connections.',
						'mcpai'
					) }
				</p>
				<div className="mcpai-actions">
					<Button variant="tertiary" onClick={ onClose }>
						{ __( 'Close', 'mcpai' ) }
					</Button>
					<Button variant="secondary" onClick={ onRedo }>
						{ __( 'Set it up again', 'mcpai' ) }
					</Button>
				</div>
			</div>
		</Modal>
	);
}

function ChooseAccess( { client, onBack, onCreated } ) {
	const [ level, setLevel ] = useState( 'content' );
	const [ draftOnly, setDraftOnly ] = useState( true );
	const [ name, setName ] = useState( client.name );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );

	const selectLevel = ( id ) => {
		setLevel( id );
		setDraftOnly( id !== 'admin' );
	};

	const create = async () => {
		setBusy( true );
		setError( null );
		try {
			const connection = await api.createConnection( {
				name: name || client.name,
				client: client.slug,
				access_level: level,
				draft_only: level === 'read' ? true : draftOnly,
			} );
			onCreated( connection );
		} catch ( e ) {
			setError( e.message );
			setBusy( false );
		}
	};

	return (
		<div className="mcpai-access">
			<div
				className="mcpai-access__cards"
				role="group"
				aria-label={ __( 'Access level', 'mcpai' ) }
			>
				{ ACCESS.map( ( option ) => (
					<button
						key={ option.id }
						type="button"
						aria-pressed={ level === option.id }
						className={ `mcpai-access-card ${
							level === option.id ? 'is-selected' : ''
						}` }
						onClick={ () => selectLevel( option.id ) }
					>
						<span className="mcpai-access-card__title">
							{ option.title }
							{ option.recommended && (
								<Badge tone="success">
									{ __( 'Recommended', 'mcpai' ) }
								</Badge>
							) }
						</span>
						<span className="mcpai-access-card__description">
							{ option.description }
						</span>
						<span className="mcpai-access-card__example">
							{ option.example }
						</span>
					</button>
				) ) }
			</div>

			{ level !== 'read' && (
				<Card className="mcpai-access__options">
					<CardBody>
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __( 'Drafts only (safest)', 'mcpai' ) }
							help={
								draftOnly
									? __(
											'The AI can create and edit drafts, but cannot publish, delete, or change anything visitors already see. You review and publish.',
											'mcpai'
										)
									: __(
											'The AI can publish and delete directly. Deleted items go to the trash, and you can undo changes from the Activity screen.',
											'mcpai'
										)
							}
							checked={ draftOnly }
							onChange={ setDraftOnly }
						/>
					</CardBody>
				</Card>
			) }

			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Connection name', 'mcpai' ) }
				help={ __(
					'Helps you recognise it later, e.g. “Claude on my laptop”.',
					'mcpai'
				) }
				value={ name }
				onChange={ setName }
			/>

			<p className="mcpai-muted">
				{ sprintf(
					/* translators: %s: user display name */
					__(
						'The AI will act as you (%s), so it can never do more than your own account can.',
						'mcpai'
					),
					settings.user
				) }
			</p>

			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			<div className="mcpai-actions">
				<Button
					variant="tertiary"
					icon={ arrowLeft }
					onClick={ onBack }
				>
					{ __( 'Back', 'mcpai' ) }
				</Button>
				<Button
					variant="primary"
					isBusy={ busy }
					disabled={ busy }
					onClick={ create }
				>
					{ __( 'Create connection', 'mcpai' ) }
				</Button>
			</div>
		</div>
	);
}

/**
 * Polls until the app has connected, then celebrates.
 *
 * @param {Object}                 props
 * @param {() => Promise<boolean>} props.isConnected Async check.
 * @param {() => void}             props.onDone      Done handler.
 * @param {() => void}             props.onAnother   "Connect another app" handler.
 */
function WaitForConnection( { isConnected, onDone, onAnother } ) {
	const [ connected, setConnected ] = useState( false );

	useEffect( () => {
		if ( connected ) {
			return;
		}
		const timer = setInterval( async () => {
			try {
				if ( await isConnected() ) {
					setConnected( true );
				}
			} catch {
				// Keep waiting; the connection may have been revoked elsewhere.
			}
		}, 3000 );
		return () => clearInterval( timer );
	}, [ connected, isConnected ] );

	if ( ! connected ) {
		return (
			<div className="mcpai-waiting">
				<Spinner />
				<div>
					<strong>
						{ __( 'Waiting for your AI app to connect…', 'mcpai' ) }
					</strong>
					<p>
						{ __(
							'Finish the steps above and send the first message. This page updates by itself.',
							'mcpai'
						) }
					</p>
				</div>
			</div>
		);
	}

	return (
		<div className="mcpai-success">
			<span className="mcpai-success__icon" aria-hidden="true">
				✓
			</span>
			<h3>
				{ __(
					'Connected! Your AI can now work on your site.',
					'mcpai'
				) }
			</h3>
			<p>{ __( 'Try asking it:', 'mcpai' ) }</p>
			<ul className="mcpai-prompts">
				{ EXAMPLE_PROMPTS.map( ( prompt ) => (
					<li key={ prompt }>
						<span>{ prompt }</span>
						<CopyButton text={ prompt } />
					</li>
				) ) }
			</ul>
			<p className="mcpai-muted">
				{ __(
					'Tip: many apps also offer ready-made tasks from your site, such as “Write a blog post” or “SEO check-up” — look in the “+” or / menu.',
					'mcpai'
				) }
			</p>
			<div className="mcpai-actions">
				<Button variant="secondary" onClick={ onAnother }>
					{ __( 'Connect another app', 'mcpai' ) }
				</Button>
				<Button variant="primary" onClick={ onDone }>
					{ __( 'Done', 'mcpai' ) }
				</Button>
			</div>
		</div>
	);
}

function ConnectSteps( { client, connection, onDone, onAnother } ) {
	const isConnected = useCallback(
		async () =>
			Boolean(
				( await api.getConnection( connection.id ) ).last_used_at
			),
		[ connection.id ]
	);
	const bridge = ( client.template || '' ).includes( 'mcp-remote' );
	const values = {
		url: settings.endpoint,
		key: connection.key,
		name: settings.serverName,
		// Node.js does not trust local development certificates.
		localEnv:
			settings.isLocal && bridge
				? ',\n        "NODE_TLS_REJECT_UNAUTHORIZED": "0"'
				: '',
	};
	const snippet = fillTemplate( client.template, values );
	const link = client.deeplink ? deeplink( client.deeplink, values ) : null;
	const formatLabel = {
		command: __( 'Command', 'mcpai' ),
		json: 'JSON',
		toml: 'TOML',
	}[ client.format ];

	return (
		<div className="mcpai-connect-steps">
			<Notice status="warning" isDismissible={ false }>
				{ __(
					'Your connection key is shown only once. It is already included below — copy it now if you need it elsewhere. Treat it like a password.',
					'mcpai'
				) }
			</Notice>

			<CopyField
				label={ __( 'Connection key', 'mcpai' ) }
				value={ connection.key }
				secret
			/>

			{ client.needs && (
				<Notice status="info" isDismissible={ false }>
					{ client.needs }
				</Notice>
			) }

			<Section
				number={ 1 }
				title={ sprintf(
					/* translators: %s: app name */
					__( 'Add your site to %s', 'mcpai' ),
					client.name
				) }
			>
				{ link && (
					<div className="mcpai-deeplink">
						<Button variant="primary" href={ link } icon={ check }>
							{ sprintf(
								/* translators: %s: app name */
								__( 'Add to %s', 'mcpai' ),
								client.name
							) }
						</Button>
						<span className="mcpai-muted">
							{ __(
								'Opens the app and asks you to confirm.',
								'mcpai'
							) }
						</span>
					</div>
				) }

				<ol className="mcpai-instructions">
					{ client.steps.map( ( step ) => (
						<li key={ step }>{ step }</li>
					) ) }
				</ol>

				<CodeBlock
					code={ snippet }
					caption={
						client.file
							? `${ formatLabel } · ${ client.file }`
							: formatLabel
					}
				/>

				<details className="mcpai-details">
					<summary>{ __( 'Manual setup details', 'mcpai' ) }</summary>
					<CopyField
						label={ __( 'Server URL (Streamable HTTP)', 'mcpai' ) }
						value={ settings.endpoint }
					/>
					<CopyField
						label={ __( 'Header', 'mcpai' ) }
						value={ `Authorization: Bearer ${ connection.key }` }
					/>
					<p className="mcpai-muted">
						{ __(
							'If your server strips the Authorization header, send the key as “X-MCPAI-Key” instead.',
							'mcpai'
						) }
					</p>
					{ client.docs && (
						<Button
							variant="link"
							href={ client.docs }
							target="_blank"
							icon={ external }
							iconPosition="right"
						>
							{ sprintf(
								/* translators: %s: app name */
								__( '%s MCP documentation', 'mcpai' ),
								client.name
							) }
						</Button>
					) }
				</details>
				{ settings.isLocal && bridge && (
					<p className="mcpai-muted">
						{ __(
							'Because this site runs on your computer, the configuration includes a line that lets the bridge accept its local security certificate. Live sites don’t need it.',
							'mcpai'
						) }
					</p>
				) }
				{ settings.isLocal && client.local_tip && (
					<Notice status="info" isDismissible={ false }>
						{ client.local_tip }
					</Notice>
				) }
			</Section>

			<FinishSteps client={ client } start={ 2 } />

			<WaitForConnection
				isConnected={ isConnected }
				onDone={ onDone }
				onAnother={ onAnother }
			/>
		</div>
	);
}

function OAuthSteps( { client, onBack, onDone, onAnother } ) {
	// Allow for a little clock difference between browser and server.
	const [ startedAt ] = useState( () =>
		new Date( Date.now() - 30000 ).toISOString()
	);

	// Connected once a new sign-in (OAuth grant) appears after this screen opened.
	const isConnected = useCallback(
		async () =>
			( await api.listConnections() ).some(
				( item ) =>
					item.type === 'oauth' &&
					item.created_at &&
					item.created_at >= startedAt.slice( 0, 19 )
			),
		[ startedAt ]
	);

	return (
		<div className="mcpai-connect-steps">
			{ settings.isLocal && (
				<Notice status="warning" isDismissible={ false }>
					{ sprintf(
						/* translators: %s: app name */
						__(
							'This site runs on your computer, so %s (which runs in the cloud) cannot reach it. Publish the site or use a Studio preview or tunnel first — or connect an app on this computer instead, such as Claude Code, Claude Desktop or Cursor.',
							'mcpai'
						),
						client.name
					) }
				</Notice>
			) }

			<Section
				number={ 1 }
				title={ sprintf(
					/* translators: %s: app name */
					__( 'Add your site to %s', 'mcpai' ),
					client.name
				) }
			>
				<CopyField
					label={ __( 'Your connector URL', 'mcpai' ) }
					value={ settings.endpoint }
				/>

				<ol className="mcpai-instructions">
					{ client.steps.map( ( step ) => (
						<li key={ step }>{ step }</li>
					) ) }
				</ol>

				<p className="mcpai-muted">
					{ __(
						'No key needed: you’ll sign in to WordPress and choose what the app may do. You can disconnect it any time.',
						'mcpai'
					) }
				</p>

				{ client.needs && (
					<Notice status="info" isDismissible={ false }>
						{ client.needs }
					</Notice>
				) }
				{ client.docs && (
					<Button
						variant="link"
						href={ client.docs }
						target="_blank"
						icon={ external }
						iconPosition="right"
					>
						{ sprintf(
							/* translators: %s: app name */
							__( '%s’s official setup guide', 'mcpai' ),
							client.name
						) }
					</Button>
				) }
			</Section>

			<FinishSteps client={ client } start={ 2 } />

			<WaitForConnection
				isConnected={ isConnected }
				onDone={ onDone }
				onAnother={ onAnother }
			/>

			<div className="mcpai-actions">
				<Button
					variant="tertiary"
					icon={ arrowLeft }
					onClick={ onBack }
				>
					{ __( 'Back', 'mcpai' ) }
				</Button>
			</div>
		</div>
	);
}

export default function Connect( { navigate } ) {
	const [ client, setClient ] = useState( null );
	const [ connection, setConnection ] = useState( null );
	const [ statuses, setStatuses ] = useState( {} );
	const [ helpFor, setHelpFor ] = useState( null );

	useEffect( () => {
		if ( ! client ) {
			api.listConnections()
				.then( ( list ) => setStatuses( statusByClient( list ) ) )
				.catch( () => {} );
		}
	}, [ client ] );

	const isOAuth = client?.auth === 'oauth';
	let step = 1;
	if ( client ) {
		step = connection || isOAuth ? 3 : 2;
	}

	const reset = () => {
		setClient( null );
		setConnection( null );
	};

	const titles = {
		1: __( 'Which AI app do you want to connect?', 'mcpai' ),
		2: sprintf(
			/* translators: %s: app name */
			__( 'What should %s be allowed to do?', 'mcpai' ),
			client?.name
		),
		3: sprintf(
			/* translators: %s: app name */
			__( 'Connect %s', 'mcpai' ),
			client?.name
		),
	};

	return (
		<div className="mcpai-connect">
			<Steps step={ step } />
			<Card>
				<CardBody className="mcpai-connect__body">
					<div className="mcpai-connect__title">
						{ client && <ClientMark client={ client } /> }
						<h2>{ titles[ step ] }</h2>
					</div>

					{ step === 1 && (
						<ChooseClient
							onSelect={ setClient }
							statuses={ statuses }
							onHelp={ setHelpFor }
						/>
					) }
					{ step === 2 && (
						<ChooseAccess
							client={ client }
							onBack={ reset }
							onCreated={ setConnection }
						/>
					) }
					{ step === 3 && isOAuth && (
						<OAuthSteps
							client={ client }
							onBack={ reset }
							onDone={ () => navigate( 'connections' ) }
							onAnother={ reset }
						/>
					) }
					{ step === 3 && ! isOAuth && (
						<ConnectSteps
							client={ client }
							connection={ connection }
							onDone={ () => navigate( 'connections' ) }
							onAnother={ reset }
						/>
					) }
				</CardBody>
			</Card>
			{ helpFor && (
				<HelpModal
					client={ helpFor }
					onClose={ () => setHelpFor( null ) }
					onRedo={ () => {
						setHelpFor( null );
						setClient( helpFor );
					} }
				/>
			) }
		</div>
	);
}
