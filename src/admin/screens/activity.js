/**
 * Activity: everything AI apps did, with one-click undo.
 */
import {
	Button,
	Card,
	Modal,
	Notice,
	SelectControl,
	Spinner,
} from '@wordpress/components';
import { dateI18n, getSettings, humanTimeDiff } from '@wordpress/date';
import { Fragment, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { undo } from '@wordpress/icons';

import { api } from '../api';
import { Badge, ScreenHeader, humanizeTool } from '../components';

export default function Activity() {
	const [ page, setPage ] = useState( 1 );
	const [ status, setStatus ] = useState( 'changes' );
	const [ data, setData ] = useState( null );
	const [ open, setOpen ] = useState( null );
	const [ reverting, setReverting ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ notice, setNotice ] = useState( null );

	const load = () =>
		api
			.listActivity( page, status )
			.then( setData )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: e.message } )
			);

	useEffect( () => {
		setData( null );
		load();
		// eslint-disable-next-line react-hooks/exhaustive-deps -- reload when the page or filter changes.
	}, [ page, status ] );

	const revert = async () => {
		setBusy( true );
		try {
			await api.revert( reverting.id );
			setNotice( {
				status: 'success',
				text: __( 'The change was undone.', 'mcpai' ),
			} );
			await load();
		} catch ( e ) {
			setNotice( { status: 'error', text: e.message } );
		}
		setReverting( null );
		setBusy( false );
	};

	const format = getSettings().formats.datetime;

	return (
		<>
			<ScreenHeader
				title={ __( 'Activity', 'mcpai' ) }
				description={ __(
					'Everything AI apps did on your site. Undo content changes with one click. Entries are kept for 90 days.',
					'mcpai'
				) }
				actions={
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Show', 'mcpai' ) }
						hideLabelFromVision
						value={ status }
						options={ [
							{
								label: __( 'Changes only', 'mcpai' ),
								value: 'changes',
							},
							{ label: __( 'All activity', 'mcpai' ), value: '' },
							{
								label: __( 'Errors only', 'mcpai' ),
								value: 'error',
							},
						] }
						onChange={ ( value ) => {
							setPage( 1 );
							setStatus( value );
						} }
					/>
				}
			/>

			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.text }
				</Notice>
			) }

			{ data === null && <Spinner /> }

			{ data && data.items.length === 0 && (
				<Card className="mcpai-empty">
					<h3>{ __( 'Nothing here yet', 'mcpai' ) }</h3>
					<p>
						{ __(
							'When an AI app reads or changes something on your site, it shows up here.',
							'mcpai'
						) }
					</p>
				</Card>
			) }

			{ data && data.items.length > 0 && (
				<Card className="mcpai-table-card">
					<table className="mcpai-table">
						<thead>
							<tr>
								<th>{ __( 'When', 'mcpai' ) }</th>
								<th>{ __( 'App', 'mcpai' ) }</th>
								<th>{ __( 'Action', 'mcpai' ) }</th>
								<th>{ __( 'Item', 'mcpai' ) }</th>
								<th>{ __( 'Result', 'mcpai' ) }</th>
								<th>
									<span className="screen-reader-text">
										{ __( 'Actions', 'mcpai' ) }
									</span>
								</th>
							</tr>
						</thead>
						<tbody>
							{ data.items.map( ( item ) => (
								<Fragment key={ item.id }>
									<tr>
										<td
											data-label={ __( 'When', 'mcpai' ) }
											title={ dateI18n(
												format,
												item.created_at
											) }
										>
											{ humanTimeDiff( item.created_at ) }
										</td>
										<td data-label={ __( 'App', 'mcpai' ) }>
											{ item.connection }
											{ item.agent && (
												<span className="mcpai-via">
													{ sprintf(
														/* translators: %s: program name, e.g. curl */
														__( 'via %s', 'mcpai' ),
														item.agent
													) }
												</span>
											) }
										</td>
										<td
											data-label={ __(
												'Action',
												'mcpai'
											) }
										>
											<button
												type="button"
												className="mcpai-link-button"
												aria-expanded={
													open === item.id
												}
												onClick={ () =>
													setOpen(
														open === item.id
															? null
															: item.id
													)
												}
											>
												{ humanizeTool( item.tool ) }
												<span
													className="mcpai-link-button__chevron"
													aria-hidden="true"
												>
													{ open === item.id
														? '▾'
														: '▸' }
												</span>
											</button>
										</td>
										<td
											data-label={ __( 'Item', 'mcpai' ) }
										>
											{ item.site && (
												<Badge tone="info">
													{ item.site }
												</Badge>
											) }{ ' ' }
											{ item.object ? (
												<a href={ item.object.url }>
													{ item.object.title }
												</a>
											) : (
												'—'
											) }
										</td>
										<td
											data-label={ __(
												'Result',
												'mcpai'
											) }
										>
											{ item.status === 'ok' ? (
												<Badge tone="success">
													{ __( 'Done', 'mcpai' ) }
												</Badge>
											) : (
												<Badge tone="danger">
													{ __( 'Failed', 'mcpai' ) }
												</Badge>
											) }
											{ item.reverted_at && (
												<Badge>
													{ __( 'Undone', 'mcpai' ) }
												</Badge>
											) }
										</td>
										<td className="mcpai-table__actions">
											{ item.can_revert && (
												<Button
													variant="secondary"
													size="compact"
													icon={ undo }
													onClick={ () =>
														setReverting( item )
													}
												>
													{ __( 'Undo', 'mcpai' ) }
												</Button>
											) }
										</td>
									</tr>
									{ open === item.id && (
										<tr className="mcpai-table__details">
											<td colSpan="6">
												{ item.message && (
													<p className="mcpai-error-text">
														{ item.message }
													</p>
												) }
												<pre>
													{ JSON.stringify(
														item.arguments,
														null,
														2
													) }
												</pre>
												<span className="mcpai-muted">
													{ sprintf(
														/* translators: 1: tool name, 2: duration in ms */
														__(
															'Tool %1$s · %2$d ms',
															'mcpai'
														),
														item.tool,
														item.duration_ms
													) }
												</span>
											</td>
										</tr>
									) }
								</Fragment>
							) ) }
						</tbody>
					</table>
				</Card>
			) }

			{ data && data.total_pages > 1 && (
				<div className="mcpai-pagination">
					<Button
						variant="secondary"
						disabled={ page <= 1 }
						onClick={ () => setPage( page - 1 ) }
					>
						{ __( 'Newer', 'mcpai' ) }
					</Button>
					<span>
						{ sprintf(
							/* translators: 1: page, 2: total pages */
							__( 'Page %1$d of %2$d', 'mcpai' ),
							page,
							data.total_pages
						) }
					</span>
					<Button
						variant="secondary"
						disabled={ page >= data.total_pages }
						onClick={ () => setPage( page + 1 ) }
					>
						{ __( 'Older', 'mcpai' ) }
					</Button>
				</div>
			) }

			{ reverting && (
				<Modal
					title={ __( 'Undo this change?', 'mcpai' ) }
					onRequestClose={ () => setReverting( null ) }
					size="small"
				>
					<p>
						<strong>
							{ humanizeTool( reverting.tool ) }
							{ reverting.object &&
								` · ${ reverting.object.title }` }
						</strong>
					</p>
					<p>{ reverting.undo_label }</p>
					<div className="mcpai-actions">
						<Button
							variant="tertiary"
							onClick={ () => setReverting( null ) }
						>
							{ __( 'Cancel', 'mcpai' ) }
						</Button>
						<Button
							variant="primary"
							isBusy={ busy }
							disabled={ busy }
							onClick={ revert }
						>
							{ __( 'Undo change', 'mcpai' ) }
						</Button>
					</div>
				</Modal>
			) }
		</>
	);
}
