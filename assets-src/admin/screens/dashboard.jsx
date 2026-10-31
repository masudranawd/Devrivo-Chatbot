/**
 * Dashboard: widget on/off, new leads, AI usage, knowledge index and a
 * setup checklist (instead of admin notices).
 */
import {
	Card,
	CardBody,
	CardHeader,
	ToggleControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Loading } from '../components/common';
import { missing } from '../lib/actions';
import { request, requestWithTotals, toErrors } from '../lib/api';
import { boot } from '../lib/boot';
import { publish, useSettings } from '../lib/use-settings';

/**
 * Setup hints from the settings and status data.
 *
 * @param {Object}      all      All settings.
 * @param {Object|null} aiStatus /ai/status.
 * @return {Array<{id: string, text: string, href: string}>} Hints.
 */
export function checklist( all, aiStatus ) {
	const data = boot();
	const items = [];
	const incomplete = ( all.actions || [] ).filter(
		( action ) =>
			missing( action, data.actionTypes ).length &&
			! missing( action, data.actionTypes ).includes( 'type' )
	);
	if ( incomplete.length ) {
		items.push( {
			id: 'actions',
			text: sprintf(
				/* translators: %d: Number of quick actions. */
				__(
					'%d quick action(s) are missing a phone number, link or email, so visitors don’t see them.',
					'devrivo-chatbot'
				),
				incomplete.length
			),
			href: '#/actions',
		} );
	}
	if ( ! ( all.integrations?.notification_emails || [] ).length ) {
		items.push( {
			id: 'emails',
			text: __(
				'No notification email is set, so enquiries go to the site admin email.',
				'devrivo-chatbot'
			),
			href: '',
		} );
	}
	if ( ! data.privacyPolicyUrl ) {
		items.push( {
			id: 'privacy',
			text: __(
				'Your site has no privacy policy page. Forms link to it next to the consent box.',
				'devrivo-chatbot'
			),
			href: data.privacySettingsUrl,
		} );
	}
	if ( aiStatus && ! aiStatus.enabled ) {
		items.push( {
			id: 'ai',
			text: __(
				'AI answers are off. The assistant answers from your pages and FAQs (Guided mode).',
				'devrivo-chatbot'
			),
			href: '#/ai',
		} );
	}
	return items;
}

/**
 * Dashboard screen.
 *
 * @param {Object}                                    props
 * @param {(status: string, message: string) => void} props.notify Notice.
 * @return {Element} Screen.
 */
export default function Dashboard( { notify } ) {
	const settings = useSettings( [ 'display' ] );
	const [ newLeads, setNewLeads ] = useState( null );
	const [ aiStatus, setAiStatus ] = useState( null );
	const [ index, setIndex ] = useState( null );
	const [ busy, setBusy ] = useState( false );

	useEffect( () => {
		requestWithTotals( '/leads?status=new&per_page=1' )
			.then( ( result ) => setNewLeads( result.total ) )
			.catch( () => setNewLeads( null ) );
		request( '/ai/status' )
			.then( setAiStatus )
			.catch( () => setAiStatus( null ) );
		request( '/knowledge/status' )
			.then( setIndex )
			.catch( () => setIndex( null ) );
	}, [] );

	if ( ! settings.ready ) {
		return <Loading settings={ settings } />;
	}

	const enabled = !! settings.saved.display?.enabled;
	const toggle = async ( value ) => {
		setBusy( true );
		try {
			publish(
				await request( '/settings', {
					method: 'POST',
					data: { display: { enabled: value } },
				} )
			);
			notify(
				'success',
				value
					? __( 'The chat widget is now on.', 'devrivo-chatbot' )
					: __( 'The chat widget is now off.', 'devrivo-chatbot' )
			);
		} catch ( error ) {
			notify( 'error', toErrors( error ).message );
		} finally {
			setBusy( false );
		}
	};

	const hints = checklist( settings.all, aiStatus );
	const usage = aiStatus?.usage?.tokens || 0;
	const limit = aiStatus?.limit || 0;

	return (
		<div className="devrivo-grid">
			<Card>
				<CardHeader>
					<h2>{ __( 'Chat widget', 'devrivo-chatbot' ) }</h2>
				</CardHeader>
				<CardBody>
					<ToggleControl
						label={ __(
							'Show the chat widget on my site',
							'devrivo-chatbot'
						) }
						checked={ enabled }
						disabled={ busy }
						onChange={ toggle }
					/>
				</CardBody>
			</Card>

			<Card>
				<CardHeader>
					<h2>{ __( 'New leads', 'devrivo-chatbot' ) }</h2>
				</CardHeader>
				<CardBody>
					<p className="devrivo-stat">
						{ newLeads === null ? '–' : String( newLeads ) }
					</p>
					<a href="#/inbox">
						{ __( 'Open the inbox', 'devrivo-chatbot' ) }
					</a>
				</CardBody>
			</Card>

			<Card>
				<CardHeader>
					<h2>{ __( 'AI answers', 'devrivo-chatbot' ) }</h2>
				</CardHeader>
				<CardBody>
					{ ! aiStatus && (
						<p>{ __( 'Loading…', 'devrivo-chatbot' ) }</p>
					) }
					{ aiStatus && (
						<>
							<p>
								{ aiStatus.enabled
									? __( 'On', 'devrivo-chatbot' )
									: __(
											'Off (Guided mode)',
											'devrivo-chatbot'
										) }
							</p>
							{ limit > 0 && (
								<p>
									{ sprintf(
										/* translators: 1: Tokens used, 2: Monthly limit. */
										__(
											'%1$s of %2$s tokens used this month',
											'devrivo-chatbot'
										),
										usage.toLocaleString(),
										limit.toLocaleString()
									) }
								</p>
							) }
							<a href="#/ai">
								{ __( 'AI settings', 'devrivo-chatbot' ) }
							</a>
						</>
					) }
				</CardBody>
			</Card>

			<Card>
				<CardHeader>
					<h2>{ __( 'Knowledge index', 'devrivo-chatbot' ) }</h2>
				</CardHeader>
				<CardBody>
					{ ! index && (
						<p>{ __( 'Loading…', 'devrivo-chatbot' ) }</p>
					) }
					{ index && (
						<p>
							{ sprintf(
								/* translators: 1: Number of pages, 2: Number of FAQs. */
								__(
									'%1$d pages and posts, %2$d FAQs indexed.',
									'devrivo-chatbot'
								),
								index.posts,
								index.faqs
							) }{ ' ' }
							{ index.rebuild?.status === 'running' &&
								__(
									'Re-indexing in the background…',
									'devrivo-chatbot'
								) }
						</p>
					) }
				</CardBody>
			</Card>

			<Card className="devrivo-grid__wide">
				<CardHeader>
					<h2>{ __( 'Setup checklist', 'devrivo-chatbot' ) }</h2>
				</CardHeader>
				<CardBody>
					{ hints.length === 0 ? (
						<p>
							{ __(
								'All set. Nothing needs your attention.',
								'devrivo-chatbot'
							) }
						</p>
					) : (
						<ul className="devrivo-checklist">
							{ hints.map( ( hint ) => (
								<li key={ hint.id }>
									{ hint.text }{ ' ' }
									{ hint.href && (
										<a href={ hint.href }>
											{ __(
												'Fix it',
												'devrivo-chatbot'
											) }
										</a>
									) }
								</li>
							) ) }
						</ul>
					) }
				</CardBody>
			</Card>
		</div>
	);
}
