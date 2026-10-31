/**
 * Inbox: leads table with status and form filters, pagination, a detail
 * view, status changes, delete and CSV export.
 *
 * Lead data comes from visitors: it is only ever rendered as React text
 * (never as HTML), and the page link is shown only for http(s) URLs.
 */
import { Button, Notice, SelectControl, Spinner } from '@wordpress/components';
import {
	isValidElement,
	useCallback,
	useEffect,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';
import { ConfirmModal } from '../components/common';
import { request, requestWithTotals, toErrors } from '../lib/api';
import { boot } from '../lib/boot';
import { useSettings } from '../lib/use-settings';

export const PER_PAGE = 20;

/**
 * Translated status names.
 *
 * @return {Object<string, string>} Names.
 */
export function statusNames() {
	return {
		new: __( 'New', 'devrivo-chatbot' ),
		contacted: __( 'Contacted', 'devrivo-chatbot' ),
		closed: __( 'Closed', 'devrivo-chatbot' ),
		spam: __( 'Spam', 'devrivo-chatbot' ),
	};
}

/**
 * A URL safe to link to (http or https), or ''.
 *
 * @param {unknown} value URL.
 * @return {string} URL.
 */
export function safeLink( value ) {
	if ( typeof value !== 'string' || ! value ) {
		return '';
	}
	try {
		const url = new URL( value );
		return url.protocol === 'http:' || url.protocol === 'https:'
			? url.href
			: '';
	} catch {
		return '';
	}
}

/**
 * Localised date and time.
 *
 * @param {string} value RFC 3339 date.
 * @return {string} Date.
 */
function date( value ) {
	const parsed = new Date( value );
	return Number.isNaN( parsed.getTime() )
		? String( value || '' )
		: parsed.toLocaleString();
}

/**
 * A label and value pair (strings are rendered as text, never HTML).
 *
 * @param {Object}  props
 * @param {unknown} props.label Label.
 * @param {unknown} props.value Value (string or element).
 * @return {Element} Row.
 */
function Row( { label, value } ) {
	return (
		<div className="devrivo-lead__row">
			<dt>{ String( label ?? '' ) }</dt>
			<dd>{ isValidElement( value ) ? value : String( value ?? '' ) }</dd>
		</div>
	);
}

/**
 * One lead in detail.
 *
 * @param {Object}                          props
 * @param {Object}                          props.lead     Lead.
 * @param {Object}                          props.forms    Forms by ID.
 * @param {(...args: unknown[]) => unknown} props.onStatus New status.
 * @param {(...args: unknown[]) => unknown} props.onDelete Delete.
 * @param {(...args: unknown[]) => unknown} props.onClose  Back to the list.
 * @param {boolean}                         props.busy     Request in progress.
 * @return {Element} Detail.
 */
export function LeadDetail( {
	lead,
	forms,
	onStatus,
	onDelete,
	onClose,
	busy,
} ) {
	const names = statusNames();
	const link = safeLink( lead.page_url );

	return (
		<section className="devrivo-lead" aria-labelledby="devrivo-lead-title">
			<h2 id="devrivo-lead-title">
				{ lead.name ||
					lead.email ||
					sprintf(
						/* translators: %d: Lead ID. */ __(
							'Lead #%d',
							'devrivo-chatbot'
						),
						lead.id
					) }
			</h2>
			<dl className="devrivo-lead__fields">
				<Row
					label={ __( 'Received', 'devrivo-chatbot' ) }
					value={ date( lead.created_at ) }
				/>
				<Row
					label={ __( 'Form', 'devrivo-chatbot' ) }
					value={ forms[ lead.form_id ]?.title || lead.form_id }
				/>
				{ ( lead.data || [] ).map( ( field, index ) => (
					<Row
						key={ index }
						label={ field.label ?? field.id }
						value={ field.value }
					/>
				) ) }
				<Row
					label={ __( 'Page', 'devrivo-chatbot' ) }
					value={
						link ? (
							<a
								href={ link }
								target="_blank"
								rel="noopener noreferrer"
							>
								{ link }
							</a>
						) : (
							lead.page_url || '–'
						)
					}
				/>
				<Row
					label={ __( 'Consent', 'devrivo-chatbot' ) }
					value={ `${ lead.consent_text || '' }${ lead.consent_at ? ` (${ date( lead.consent_at ) })` : '' }` }
				/>
			</dl>

			<SelectControl
				label={ __( 'Status', 'devrivo-chatbot' ) }
				value={ lead.status }
				options={ Object.entries( names ).map(
					( [ value, label ] ) => ( { value, label } )
				) }
				onChange={ onStatus }
				disabled={ busy }
				__next40pxDefaultSize
			/>
			<div className="devrivo-modal-actions">
				<Button
					variant="secondary"
					onClick={ onClose }
					__next40pxDefaultSize
				>
					{ __( 'Back to the list', 'devrivo-chatbot' ) }
				</Button>
				<Button
					variant="tertiary"
					isDestructive
					onClick={ onDelete }
					disabled={ busy }
					__next40pxDefaultSize
				>
					{ __( 'Delete lead', 'devrivo-chatbot' ) }
				</Button>
			</div>
		</section>
	);
}

/**
 * Inbox screen.
 *
 * @param {Object}                                    props
 * @param {(status: string, message: string) => void} props.notify Notice.
 * @return {Element} Screen.
 */
export default function Inbox( { notify } ) {
	const settings = useSettings( 'forms' );
	const [ status, setStatus ] = useState( '' );
	const [ form, setForm ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ result, setResult ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ selected, setSelected ] = useState( null );
	const [ confirm, setConfirm ] = useState( false );
	const [ busy, setBusy ] = useState( false );

	const load = useCallback( () => {
		setError( '' );
		requestWithTotals(
			addQueryArgs( '/leads', {
				page,
				per_page: PER_PAGE,
				status: status || undefined,
				form_id: form || undefined,
			} )
		)
			.then( setResult )
			.catch( ( problem ) => setError( toErrors( problem ).message ) );
	}, [ page, status, form ] );

	useEffect( load, [ load ] );

	const forms = settings.values.forms || {};
	const names = statusNames();

	const changeStatus = async ( value ) => {
		setBusy( true );
		try {
			const lead = await request( `/leads/${ selected.id }`, {
				method: 'PATCH',
				data: { status: value },
			} );
			setSelected( lead );
			load();
			notify( 'success', __( 'Status updated.', 'devrivo-chatbot' ) );
		} catch ( problem ) {
			notify( 'error', toErrors( problem ).message );
		} finally {
			setBusy( false );
		}
	};

	const remove = async () => {
		setConfirm( false );
		setBusy( true );
		try {
			await request( `/leads/${ selected.id }`, { method: 'DELETE' } );
			setSelected( null );
			load();
			notify( 'success', __( 'Lead deleted.', 'devrivo-chatbot' ) );
		} catch ( problem ) {
			notify( 'error', toErrors( problem ).message );
		} finally {
			setBusy( false );
		}
	};

	const exportUrl = boot().leadsExportUrl
		? addQueryArgs( boot().leadsExportUrl, {
				status: status || undefined,
				form_id: form || undefined,
			} )
		: '';

	if ( selected ) {
		return (
			<>
				<LeadDetail
					lead={ selected }
					forms={ forms }
					busy={ busy }
					onStatus={ changeStatus }
					onDelete={ () => setConfirm( true ) }
					onClose={ () => setSelected( null ) }
				/>
				{ confirm && (
					<ConfirmModal
						title={ __( 'Delete this lead?', 'devrivo-chatbot' ) }
						message={ __(
							'The lead and all its details are deleted for good.',
							'devrivo-chatbot'
						) }
						confirm={ __( 'Delete', 'devrivo-chatbot' ) }
						onCancel={ () => setConfirm( false ) }
						onConfirm={ remove }
					/>
				) }
			</>
		);
	}

	return (
		<div className="devrivo-inbox">
			<div className="devrivo-inbox__filters">
				<SelectControl
					label={ __( 'Status', 'devrivo-chatbot' ) }
					value={ status }
					options={ [
						{ label: __( 'All', 'devrivo-chatbot' ), value: '' },
						...Object.entries( names ).map(
							( [ value, label ] ) => ( { value, label } )
						),
					] }
					onChange={ ( value ) => {
						setStatus( value );
						setPage( 1 );
					} }
					__next40pxDefaultSize
				/>
				<SelectControl
					label={ __( 'Form', 'devrivo-chatbot' ) }
					value={ form }
					options={ [
						{ label: __( 'All', 'devrivo-chatbot' ), value: '' },
						...Object.entries( forms ).map( ( [ id, item ] ) => ( {
							value: id,
							label: item.title || id,
						} ) ),
					] }
					onChange={ ( value ) => {
						setForm( value );
						setPage( 1 );
					} }
					__next40pxDefaultSize
				/>
				{ exportUrl && (
					<Button
						variant="secondary"
						href={ exportUrl }
						__next40pxDefaultSize
					>
						{ __( 'Export CSV', 'devrivo-chatbot' ) }
					</Button>
				) }
			</div>

			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			{ ! result && ! error && <Spinner /> }

			{ result && result.data.length === 0 && (
				<p>{ __( 'No leads yet.', 'devrivo-chatbot' ) }</p>
			) }

			{ result && result.data.length > 0 && (
				<>
					<table className="wp-list-table widefat striped devrivo-inbox__table">
						<caption className="screen-reader-text">
							{ __( 'Leads', 'devrivo-chatbot' ) }
						</caption>
						<thead>
							<tr>
								<th scope="col">
									{ __( 'Date', 'devrivo-chatbot' ) }
								</th>
								<th scope="col">
									{ __( 'Name', 'devrivo-chatbot' ) }
								</th>
								<th scope="col">
									{ __( 'Email', 'devrivo-chatbot' ) }
								</th>
								<th scope="col">
									{ __( 'Form', 'devrivo-chatbot' ) }
								</th>
								<th scope="col">
									{ __( 'Status', 'devrivo-chatbot' ) }
								</th>
								<th scope="col">
									<span className="screen-reader-text">
										{ __( 'Actions', 'devrivo-chatbot' ) }
									</span>
								</th>
							</tr>
						</thead>
						<tbody>
							{ result.data.map( ( lead ) => (
								<tr key={ lead.id }>
									<td>{ date( lead.created_at ) }</td>
									<td>{ String( lead.name || '' ) }</td>
									<td>{ String( lead.email || '' ) }</td>
									<td>
										{ forms[ lead.form_id ]?.title ||
											String( lead.form_id ) }
									</td>
									<td>
										{ names[ lead.status ] ||
											String( lead.status ) }
									</td>
									<td>
										<Button
											variant="link"
											onClick={ () =>
												setSelected( lead )
											}
											aria-label={ sprintf(
												/* translators: %s: Lead name or email. */
												__(
													'View lead from %s',
													'devrivo-chatbot'
												),
												String(
													lead.name ||
														lead.email ||
														lead.id
												)
											) }
										>
											{ __( 'View', 'devrivo-chatbot' ) }
										</Button>
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
					<div className="devrivo-inbox__pages">
						<Button
							variant="secondary"
							disabled={ page <= 1 }
							accessibleWhenDisabled
							onClick={ () => setPage( page - 1 ) }
							__next40pxDefaultSize
						>
							{ __( 'Previous', 'devrivo-chatbot' ) }
						</Button>
						<span>
							{ sprintf(
								/* translators: 1: Page, 2: Pages, 3: Total leads. */
								__(
									'Page %1$d of %2$d (%3$d leads)',
									'devrivo-chatbot'
								),
								page,
								Math.max( 1, result.totalPages ),
								result.total
							) }
						</span>
						<Button
							variant="secondary"
							disabled={ page >= result.totalPages }
							accessibleWhenDisabled
							onClick={ () => setPage( page + 1 ) }
							__next40pxDefaultSize
						>
							{ __( 'Next', 'devrivo-chatbot' ) }
						</Button>
					</div>
				</>
			) }
		</div>
	);
}
