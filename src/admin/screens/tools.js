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

const settings = window.viagentSettings;

function ToolRow( { tool, onToggle } ) {
	return (
		<div className="viagent-tool">
			<div className="viagent-tool__text">
				<strong>{ tool.label }</strong>
				<span>{ tool.summary }</span>
				<span className="viagent-tool__badges">
					<Badge tone={ levelTone( tool.level ) }>
						{ sprintf(
							/* translators: %s: access level */
							__( 'Access: %s', 'viagent' ),
							settings.levels[ tool.level ]
						) }
					</Badge>
					{ ! tool.readonly && (
						<Badge tone="info">
							{ __( 'Makes changes', 'viagent' ) }
						</Badge>
					) }
					{ tool.destructive && (
						<Badge tone="danger">
							{ __( 'Can delete', 'viagent' ) }
						</Badge>
					) }
					{ tool.source && (
						<Badge tone="warning">
							{ sprintf(
								/* translators: %s: plugin namespace */
								__( 'From: %s', 'viagent' ),
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
							__( 'Enable %s', 'viagent' ),
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
		<Card className="viagent-group viagent-group--tasks">
			<button
				type="button"
				className="viagent-group__header"
				aria-expanded={ isOpen }
				onClick={ () => setIsOpen( ! isOpen ) }
			>
				<span className="viagent-group__text">
					<strong>{ __( 'Ready-made tasks', 'viagent' ) }</strong>
					<span className="viagent-muted">
						{ __(
							'One-click tasks AI apps can offer, e.g. in Claude Desktop’s “+” menu or as / commands in Claude Code and VS Code. Each appears only when the connection has the tools it needs.',
							'viagent'
						) }
					</span>
				</span>
				<Badge tone="success">
					{ sprintf(
						/* translators: 1: enabled tasks, 2: total tasks */
						__( '%1$d of %2$d on', 'viagent' ),
						prompts.filter( ( item ) => item.enabled ).length,
						prompts.length
					) }
				</Badge>
				<span className="viagent-group__chevron" aria-hidden="true" />
			</button>
			{ isOpen && (
				<CardBody className="viagent-tool-list">
					{ prompts.map( ( prompt ) => (
						<div className="viagent-tool" key={ prompt.name }>
							<div className="viagent-tool__text">
								<strong>{ prompt.title }</strong>
								<span>{ prompt.description }</span>
								<span className="viagent-tool__badges">
									{ ! prompt.available && (
										<Badge>
											{ __(
												'Needs tools that are off or missing',
												'viagent'
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
											__( 'Enable %s', 'viagent' ),
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
				title={ __( 'Tools & permissions', 'viagent' ) }
				description={ __(
					'Tools are the actions AI apps can take. Each connection only sees the tools its access level allows — switch a tool off here to hide it from every app.',
					'viagent'
				) }
			/>

			{ error && (
				<Notice status="error" onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }

			{ groups === null && <Spinner /> }

			{ groups && (
				<div className="viagent-toolbar">
					<SearchControl
						__nextHasNoMarginBottom
						label={ __( 'Search tools', 'viagent' ) }
						placeholder={ __(
							'Search tools, e.g. “media” or “publish”',
							'viagent'
						) }
						value={ search }
						onChange={ setSearch }
					/>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Show', 'viagent' ) }
						hideLabelFromVision
						value={ show }
						options={ [
							{
								label: __( 'All tools', 'viagent' ),
								value: 'all',
							},
							{
								label: __( 'Switched on', 'viagent' ),
								value: 'on',
							},
							{
								label: __( 'Switched off', 'viagent' ),
								value: 'off',
							},
						] }
						onChange={ setShow }
					/>
					<span className="viagent-toolbar__summary">
						{ sprintf(
							/* translators: 1: enabled tools, 2: total tools */
							__( '%1$d of %2$d tools on', 'viagent' ),
							onCount,
							all.length
						) }
					</span>
				</div>
			) }

			<div className="viagent-tool-groups">
				{ ! filtering && <PromptsCard /> }
				{ groups && visible.length === 0 && (
					<Card className="viagent-empty">
						<p>
							{ __( 'No tools match your search.', 'viagent' ) }
						</p>
					</Card>
				) }
				{ visible.map( ( group ) => {
					const on = group.tools.filter(
						( tool ) => tool.enabled
					).length;
					const isOpen = filtering || !! open[ group.slug ];
					return (
						<Card key={ group.slug } className="viagent-group">
							<button
								type="button"
								className="viagent-group__header"
								aria-expanded={ isOpen }
								onClick={ () =>
									setOpen( {
										...open,
										[ group.slug ]: ! open[ group.slug ],
									} )
								}
							>
								<span className="viagent-group__text">
									<strong>{ group.label }</strong>
									<span className="viagent-muted">
										{ group.description }
									</span>
								</span>
								<Badge tone={ on ? 'success' : 'neutral' }>
									{ sprintf(
										/* translators: 1: enabled tools, 2: total tools */
										__( '%1$d of %2$d on', 'viagent' ),
										on,
										group.tools.length
									) }
								</Badge>
								<span
									className="viagent-group__chevron"
									aria-hidden="true"
								/>
							</button>
							{ isOpen && (
								<CardBody className="viagent-tool-list">
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
