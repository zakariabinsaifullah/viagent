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
				text: __( 'The change was undone.', 'viagent' ),
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
				title={ __( 'Activity', 'viagent' ) }
				description={ __(
					'Everything AI apps did on your site. Undo content changes with one click. Entries are kept for 90 days.',
					'viagent'
				) }
				actions={
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Show', 'viagent' ) }
						hideLabelFromVision
						value={ status }
						options={ [
							{
								label: __( 'Changes only', 'viagent' ),
								value: 'changes',
							},
							{
								label: __( 'All activity', 'viagent' ),
								value: '',
							},
							{
								label: __( 'Errors only', 'viagent' ),
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
				<Card className="viagent-empty">
					<h3>{ __( 'Nothing here yet', 'viagent' ) }</h3>
					<p>
						{ __(
							'When an AI app reads or changes something on your site, it shows up here.',
							'viagent'
						) }
					</p>
				</Card>
			) }

			{ data && data.items.length > 0 && (
				<Card className="viagent-table-card">
					<table className="viagent-table">
						<thead>
							<tr>
								<th>{ __( 'When', 'viagent' ) }</th>
								<th>{ __( 'App', 'viagent' ) }</th>
								<th>{ __( 'Action', 'viagent' ) }</th>
								<th>{ __( 'Item', 'viagent' ) }</th>
								<th>{ __( 'Result', 'viagent' ) }</th>
								<th>
									<span className="screen-reader-text">
										{ __( 'Actions', 'viagent' ) }
									</span>
								</th>
							</tr>
						</thead>
						<tbody>
							{ data.items.map( ( item ) => (
								<Fragment key={ item.id }>
									<tr>
										<td
											data-label={ __(
												'When',
												'viagent'
											) }
											title={ dateI18n(
												format,
												item.created_at
											) }
										>
											{ humanTimeDiff( item.created_at ) }
										</td>
										<td
											data-label={ __(
												'App',
												'viagent'
											) }
										>
											{ item.connection }
											{ item.agent && (
												<span className="viagent-via">
													{ sprintf(
														/* translators: %s: program name, e.g. curl */
														__(
															'via %s',
															'viagent'
														),
														item.agent
													) }
												</span>
											) }
										</td>
										<td
											data-label={ __(
												'Action',
												'viagent'
											) }
										>
											<button
												type="button"
												className="viagent-link-button"
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
													className="viagent-link-button__chevron"
													aria-hidden="true"
												>
													{ open === item.id
														? '▾'
														: '▸' }
												</span>
											</button>
										</td>
										<td
											data-label={ __(
												'Item',
												'viagent'
											) }
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
												'viagent'
											) }
										>
											{ item.status === 'ok' ? (
												<Badge tone="success">
													{ __( 'Done', 'viagent' ) }
												</Badge>
											) : (
												<Badge tone="danger">
													{ __(
														'Failed',
														'viagent'
													) }
												</Badge>
											) }
											{ item.reverted_at && (
												<Badge>
													{ __(
														'Undone',
														'viagent'
													) }
												</Badge>
											) }
										</td>
										<td className="viagent-table__actions">
											{ item.can_revert && (
												<Button
													variant="secondary"
													size="compact"
													icon={ undo }
													onClick={ () =>
														setReverting( item )
													}
												>
													{ __( 'Undo', 'viagent' ) }
												</Button>
											) }
										</td>
									</tr>
									{ open === item.id && (
										<tr className="viagent-table__details">
											<td colSpan="6">
												{ item.message && (
													<p className="viagent-error-text">
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
												<span className="viagent-muted">
													{ sprintf(
														/* translators: 1: tool name, 2: duration in ms */
														__(
															'Tool %1$s · %2$d ms',
															'viagent'
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
				<div className="viagent-pagination">
					<Button
						variant="secondary"
						disabled={ page <= 1 }
						onClick={ () => setPage( page - 1 ) }
					>
						{ __( 'Newer', 'viagent' ) }
					</Button>
					<span>
						{ sprintf(
							/* translators: 1: page, 2: total pages */
							__( 'Page %1$d of %2$d', 'viagent' ),
							page,
							data.total_pages
						) }
					</span>
					<Button
						variant="secondary"
						disabled={ page >= data.total_pages }
						onClick={ () => setPage( page + 1 ) }
					>
						{ __( 'Older', 'viagent' ) }
					</Button>
				</div>
			) }

			{ reverting && (
				<Modal
					title={ __( 'Undo this change?', 'viagent' ) }
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
					<div className="viagent-actions">
						<Button
							variant="tertiary"
							onClick={ () => setReverting( null ) }
						>
							{ __( 'Cancel', 'viagent' ) }
						</Button>
						<Button
							variant="primary"
							isBusy={ busy }
							disabled={ busy }
							onClick={ revert }
						>
							{ __( 'Undo change', 'viagent' ) }
						</Button>
					</div>
				</Modal>
			) }
		</>
	);
}
