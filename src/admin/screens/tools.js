/**
 * Tools & permissions: switch individual AI tools on or off.
 */
import {
	Card,
	CardBody,
	Notice,
	SearchControl,
	SelectControl,
	Spinner,
	ToggleControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { api } from '../api';
import { Badge, ScreenHeader, levelTone } from '../components';

const settings = window.mcpaiSettings;

function ToolRow( { tool, onToggle } ) {
	return (
		<div className="mcpai-tool">
			<div className="mcpai-tool__text">
				<strong>{ tool.label }</strong>
				<span>{ tool.summary }</span>
				<span className="mcpai-tool__badges">
					<Badge tone={ levelTone( tool.level ) }>
						{ sprintf(
							/* translators: %s: access level */
							__( 'Access: %s', 'mcpai' ),
							settings.levels[ tool.level ]
						) }
					</Badge>
					{ ! tool.readonly && (
						<Badge tone="info">
							{ __( 'Makes changes', 'mcpai' ) }
						</Badge>
					) }
					{ tool.destructive && (
						<Badge tone="danger">
							{ __( 'Can delete', 'mcpai' ) }
						</Badge>
					) }
					{ tool.source && (
						<Badge tone="warning">
							{ sprintf(
								/* translators: %s: plugin namespace */
								__( 'From: %s', 'mcpai' ),
								tool.source
							) }
						</Badge>
					) }
					<code title={ tool.description }>{ tool.tool }</code>
				</span>
			</div>
			<ToggleControl
				__nextHasNoMarginBottom
				label={
					<span className="screen-reader-text">
						{ sprintf(
							/* translators: %s: tool name */
							__( 'Enable %s', 'mcpai' ),
							tool.label
						) }
					</span>
				}
				checked={ tool.enabled }
				onChange={ ( enabled ) => onToggle( tool, enabled ) }
			/>
		</div>
	);
}

function PromptsCard() {
	const [ prompts, setPrompts ] = useState( null );
	const [ isOpen, setIsOpen ] = useState( false );

	useEffect( () => {
		api.listPrompts()
			.then( setPrompts )
			.catch( () => setPrompts( [] ) );
	}, [] );

	const toggle = async ( prompt, enabled ) => {
		const update = ( value ) =>
			setPrompts( ( current ) =>
				current.map( ( item ) =>
					item.name === prompt.name
						? { ...item, enabled: value }
						: item
				)
			);
		update( enabled );
		try {
			await api.togglePrompt( prompt.name, enabled );
		} catch {
			update( ! enabled );
		}
	};

	if ( ! prompts?.length ) {
		return null;
	}

	return (
		<Card className="mcpai-group mcpai-group--tasks">
			<button
				type="button"
				className="mcpai-group__header"
				aria-expanded={ isOpen }
				onClick={ () => setIsOpen( ! isOpen ) }
			>
				<span className="mcpai-group__text">
					<strong>{ __( 'Ready-made tasks', 'mcpai' ) }</strong>
					<span className="mcpai-muted">
						{ __(
							'One-click tasks AI apps can offer, e.g. in Claude Desktop’s “+” menu or as / commands in Claude Code and VS Code. Each appears only when the connection has the tools it needs.',
							'mcpai'
						) }
					</span>
				</span>
				<Badge tone="success">
					{ sprintf(
						/* translators: 1: enabled tasks, 2: total tasks */
						__( '%1$d of %2$d on', 'mcpai' ),
						prompts.filter( ( item ) => item.enabled ).length,
						prompts.length
					) }
				</Badge>
				<span className="mcpai-group__chevron" aria-hidden="true" />
			</button>
			{ isOpen && (
				<CardBody className="mcpai-tool-list">
					{ prompts.map( ( prompt ) => (
						<div className="mcpai-tool" key={ prompt.name }>
							<div className="mcpai-tool__text">
								<strong>{ prompt.title }</strong>
								<span>{ prompt.description }</span>
								<span className="mcpai-tool__badges">
									{ ! prompt.available && (
										<Badge>
											{ __(
												'Needs tools that are off or missing',
												'mcpai'
											) }
										</Badge>
									) }
									<code>{ prompt.name }</code>
								</span>
							</div>
							<ToggleControl
								__nextHasNoMarginBottom
								label={
									<span className="screen-reader-text">
										{ sprintf(
											/* translators: %s: task name */
											__( 'Enable %s', 'mcpai' ),
											prompt.title
										) }
									</span>
								}
								checked={ prompt.enabled }
								onChange={ ( enabled ) =>
									toggle( prompt, enabled )
								}
							/>
						</div>
					) ) }
				</CardBody>
			) }
		</Card>
	);
}

export default function Tools() {
	const [ groups, setGroups ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ search, setSearch ] = useState( '' );
	const [ show, setShow ] = useState( 'all' );
	const [ open, setOpen ] = useState( {} );

	useEffect( () => {
		api.listTools()
			.then( setGroups )
			.catch( ( e ) => setError( e.message ) );
	}, [] );

	const setEnabled = ( ability, enabled ) =>
		setGroups( ( current ) =>
			current.map( ( group ) => ( {
				...group,
				tools: group.tools.map( ( tool ) =>
					tool.ability === ability ? { ...tool, enabled } : tool
				),
			} ) )
		);

	const toggle = async ( tool, enabled ) => {
		setEnabled( tool.ability, enabled );
		try {
			await api.toggleTool( tool.ability, enabled );
		} catch ( e ) {
			setEnabled( tool.ability, ! enabled );
			setError( e.message );
		}
	};

	const needle = search.trim().toLowerCase();
	const visible = ( groups || [] )
		.map( ( group ) => ( {
			...group,
			tools: group.tools.filter( ( tool ) => {
				if ( show === 'on' && ! tool.enabled ) {
					return false;
				}
				if ( show === 'off' && tool.enabled ) {
					return false;
				}
				return (
					! needle ||
					`${ tool.label } ${ tool.summary } ${ tool.tool }`
						.toLowerCase()
						.includes( needle )
				);
			} ),
		} ) )
		.filter( ( group ) => group.tools.length > 0 );

	const all = ( groups || [] ).flatMap( ( group ) => group.tools );
	const onCount = all.filter( ( tool ) => tool.enabled ).length;
	const filtering = needle !== '' || show !== 'all';

	return (
		<>
			<ScreenHeader
				title={ __( 'Tools & permissions', 'mcpai' ) }
				description={ __(
					'Tools are the actions AI apps can take. Each connection only sees the tools its access level allows — switch a tool off here to hide it from every app.',
					'mcpai'
				) }
			/>

			{ error && (
				<Notice status="error" onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }

			{ groups === null && <Spinner /> }

			{ groups && (
				<div className="mcpai-toolbar">
					<SearchControl
						__nextHasNoMarginBottom
						label={ __( 'Search tools', 'mcpai' ) }
						placeholder={ __(
							'Search tools, e.g. “media” or “publish”',
							'mcpai'
						) }
						value={ search }
						onChange={ setSearch }
					/>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Show', 'mcpai' ) }
						hideLabelFromVision
						value={ show }
						options={ [
							{ label: __( 'All tools', 'mcpai' ), value: 'all' },
							{
								label: __( 'Switched on', 'mcpai' ),
								value: 'on',
							},
							{
								label: __( 'Switched off', 'mcpai' ),
								value: 'off',
							},
						] }
						onChange={ setShow }
					/>
					<span className="mcpai-toolbar__summary">
						{ sprintf(
							/* translators: 1: enabled tools, 2: total tools */
							__( '%1$d of %2$d tools on', 'mcpai' ),
							onCount,
							all.length
						) }
					</span>
				</div>
			) }

			<div className="mcpai-tool-groups">
				{ ! filtering && <PromptsCard /> }
				{ groups && visible.length === 0 && (
					<Card className="mcpai-empty">
						<p>{ __( 'No tools match your search.', 'mcpai' ) }</p>
					</Card>
				) }
				{ visible.map( ( group ) => {
					const on = group.tools.filter(
						( tool ) => tool.enabled
					).length;
					const isOpen = filtering || !! open[ group.slug ];
					return (
						<Card key={ group.slug } className="mcpai-group">
							<button
								type="button"
								className="mcpai-group__header"
								aria-expanded={ isOpen }
								onClick={ () =>
									setOpen( {
										...open,
										[ group.slug ]: ! open[ group.slug ],
									} )
								}
							>
								<span className="mcpai-group__text">
									<strong>{ group.label }</strong>
									<span className="mcpai-muted">
										{ group.description }
									</span>
								</span>
								<Badge tone={ on ? 'success' : 'neutral' }>
									{ sprintf(
										/* translators: 1: enabled tools, 2: total tools */
										__( '%1$d of %2$d on', 'mcpai' ),
										on,
										group.tools.length
									) }
								</Badge>
								<span
									className="mcpai-group__chevron"
									aria-hidden="true"
								/>
							</button>
							{ isOpen && (
								<CardBody className="mcpai-tool-list">
									{ group.tools.map( ( tool ) => (
										<ToolRow
											key={ tool.ability }
											tool={ tool }
											onToggle={ toggle }
										/>
									) ) }
								</CardBody>
							) }
						</Card>
					);
				} ) }
			</div>
		</>
	);
}
