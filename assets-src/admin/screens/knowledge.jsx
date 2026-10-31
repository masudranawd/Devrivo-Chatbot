/**
 * Knowledge: which content the assistant answers from (post types, excluded
 * pages), FAQs, and the index status with a Rebuild button.
 */
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
	Notice,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	ConfirmModal,
	ErrorSummary,
	FieldError,
	Loading,
	SaveBar,
	useDirty,
} from '../components/common';
import { PostPicker } from '../components/post-picker';
import { uniqueId } from '../lib/actions';
import { request, toErrors } from '../lib/api';
import { boot } from '../lib/boot';
import { useSettings } from '../lib/use-settings';

// Poll the index status this often while a rebuild runs.
export const POLL_MS = 3000;
// A rebuild still at 0 after this long probably waits for WP-Cron.
export const STUCK_MS = 60000;
export const MAX_FAQS = 100;

/**
 * Index status, polled every POLL_MS while a rebuild runs. `stuck` is true
 * when a running rebuild has processed nothing for STUCK_MS.
 *
 * @return {{status: Object|null, error: string, stuck: boolean, busy: boolean, rebuild: () => Promise<void>, refresh: () => Promise<void>}} State.
 */
export function useIndexStatus() {
	const [ status, setStatus ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ stuck, setStuck ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const since = useRef( 0 );
	const mounted = useRef( true );

	const apply = useCallback( ( result ) => {
		if ( ! mounted.current ) {
			return;
		}
		const running = result?.rebuild?.status === 'running';
		if ( running && ! result.rebuild.processed ) {
			if ( ! since.current ) {
				since.current = Date.now();
			}
			setStuck( Date.now() - since.current >= STUCK_MS );
		} else {
			since.current = 0;
			setStuck( false );
		}
		setStatus( result );
		setError( '' );
	}, [] );

	const load = useCallback(
		async ( options ) => {
			try {
				apply( await request( '/knowledge/' + options.path, options ) );
			} catch ( problem ) {
				if ( mounted.current ) {
					setError( toErrors( problem ).message );
				}
			}
		},
		[ apply ]
	);

	const refresh = useCallback( () => load( { path: 'status' } ), [ load ] );

	const rebuild = async () => {
		setBusy( true );
		since.current = 0;
		await load( { path: 'rebuild', method: 'POST' } );
		if ( mounted.current ) {
			setBusy( false );
		}
	};

	useEffect( () => {
		mounted.current = true;
		refresh();
		return () => {
			mounted.current = false;
		};
	}, [ refresh ] );

	const running = status?.rebuild?.status === 'running';
	useEffect( () => {
		if ( ! running ) {
			return undefined;
		}
		const timer = window.setTimeout( refresh, POLL_MS );
		return () => window.clearTimeout( timer );
	}, [ running, status, refresh ] );

	return { status, error, stuck, busy, rebuild, refresh };
}

/**
 * Index status card.
 *
 * @param {Object} props
 * @param {Object} props.index useIndexStatus() result.
 * @return {Element} Card.
 */
export function IndexStatus( { index } ) {
	const { status } = index;
	const rebuild = status?.rebuild || {};
	const running = rebuild.status === 'running';
	const percent =
		running && rebuild.total > 0
			? Math.min(
					100,
					Math.round( ( rebuild.processed / rebuild.total ) * 100 )
				)
			: 0;

	return (
		<Card>
			<CardHeader>
				<h2>{ __( 'Index status', 'devrivo-chatbot' ) }</h2>
			</CardHeader>
			<CardBody>
				{ index.error && (
					<Notice status="error" isDismissible={ false }>
						{ index.error }
					</Notice>
				) }
				{ ! status && ! index.error && (
					<p>{ __( 'Loading…', 'devrivo-chatbot' ) }</p>
				) }
				{ status && (
					<>
						<p>
							{ sprintf(
								/* translators: 1: Number of pages, 2: Number of FAQs. */
								__(
									'%1$d pages and posts, %2$d FAQs indexed.',
									'devrivo-chatbot'
								),
								status.posts || 0,
								status.faqs || 0
							) }
						</p>
						<div aria-live="polite">
							{ running && (
								<>
									<p>
										{ rebuild.total > 0
											? sprintf(
													/* translators: 1: Items done, 2: Total items. */
													__(
														'Rebuilding: %1$d of %2$d done…',
														'devrivo-chatbot'
													),
													rebuild.processed,
													rebuild.total
												)
											: __(
													'Rebuilding: starting…',
													'devrivo-chatbot'
												) }
									</p>
									<div
										className="devrivo-progress"
										role="progressbar"
										aria-label={ __(
											'Rebuild progress',
											'devrivo-chatbot'
										) }
										aria-valuemin={ 0 }
										aria-valuemax={ 100 }
										aria-valuenow={ percent }
									>
										<span
											style={ { width: `${ percent }%` } }
										/>
									</div>
								</>
							) }
							{ index.stuck && (
								<Notice
									status="warning"
									isDismissible={ false }
								>
									{ __(
										'The rebuild has not started after a minute. It runs in the background with WP-Cron, which may be blocked or turned off on this site (DISABLE_WP_CRON), or only runs when someone visits. Ask your host to set up a real cron job, or check again later.',
										'devrivo-chatbot'
									) }
								</Notice>
							) }
							{ rebuild.status === 'done' && (
								<p className="description">
									{ __(
										'The last rebuild finished.',
										'devrivo-chatbot'
									) }
								</p>
							) }
						</div>
						<Button
							variant="secondary"
							onClick={ index.rebuild }
							isBusy={ index.busy }
							disabled={ index.busy }
							__next40pxDefaultSize
						>
							{ __( 'Rebuild the index', 'devrivo-chatbot' ) }
						</Button>
					</>
				) }
			</CardBody>
		</Card>
	);
}

/**
 * Knowledge screen.
 *
 * @param {Object}                                    props
 * @param {(status: string, message: string) => void} props.notify   Notice.
 * @param {(dirty: boolean) => void}                  props.setDirty Unsaved state.
 * @return {Element} Screen.
 */
export default function Knowledge( { notify, setDirty } ) {
	const settings = useSettings( 'knowledge' );
	const index = useIndexStatus();
	const [ question, setQuestion ] = useState( '' );
	const [ answer, setAnswer ] = useState( '' );
	const [ confirm, setConfirm ] = useState( null );
	useDirty( setDirty, settings.dirty );

	// Saving post types, excluded pages or FAQs starts re-indexing on the server.
	const savedKey = JSON.stringify( settings.saved.knowledge || null );
	const first = useRef( true );
	const { refresh } = index;
	useEffect( () => {
		if ( first.current ) {
			first.current = false;
			return;
		}
		refresh();
	}, [ savedKey, refresh ] );

	if ( ! settings.ready ) {
		return <Loading settings={ settings } />;
	}

	const knowledge = settings.values.knowledge;
	const fields = settings.errors.fields;
	const set = ( key ) => ( value ) =>
		settings.setField( 'knowledge', key, value );
	const faqs = knowledge.faqs || [];
	const setFaq = ( i, key ) => ( value ) =>
		set( 'faqs' )(
			faqs.map( ( faq, n ) =>
				n === i ? { ...faq, [ key ]: value } : faq
			)
		);

	const addFaq = () => {
		if ( ! question.trim() || ! answer.trim() || faqs.length >= MAX_FAQS ) {
			return;
		}
		set( 'faqs' )( [
			...faqs,
			{
				id: uniqueId(
					question,
					faqs.map( ( faq ) => faq.id ),
					'faq'
				),
				question: question.trim(),
				answer: answer.trim(),
			},
		] );
		setQuestion( '' );
		setAnswer( '' );
	};

	return (
		<div className="devrivo-form">
			<ErrorSummary errors={ settings.errors } />
			<IndexStatus index={ index } />

			<fieldset className="devrivo-fieldset">
				<legend>
					{ __( 'Answer from these post types', 'devrivo-chatbot' ) }
				</legend>
				{ boot().postTypes.map( ( type ) => (
					<CheckboxControl
						key={ type.slug }
						label={ type.label }
						checked={ knowledge.post_types.includes( type.slug ) }
						onChange={ ( on ) =>
							set( 'post_types' )(
								on
									? [
											...new Set( [
												...knowledge.post_types,
												type.slug,
											] ),
										]
									: knowledge.post_types.filter(
											( slug ) => slug !== type.slug
										)
							)
						}
					/>
				) ) }
				<FieldError error={ fields.post_types } />
			</fieldset>

			<PostPicker
				label={ __(
					'Never answer from these pages',
					'devrivo-chatbot'
				) }
				ids={ knowledge.exclude_ids || [] }
				onChange={ set( 'exclude_ids' ) }
			/>
			<FieldError error={ fields.exclude_ids } />

			<h2>{ __( 'Frequently asked questions', 'devrivo-chatbot' ) }</h2>
			<p className="description">
				{ __(
					'The assistant answers from these before your pages.',
					'devrivo-chatbot'
				) }
			</p>
			<ol className="devrivo-list">
				{ faqs.map( ( faq, i ) => (
					<li key={ faq.id + i } className="devrivo-subcard">
						<TextControl
							label={ sprintf(
								/* translators: %d: FAQ number. */
								__( 'Question %d', 'devrivo-chatbot' ),
								i + 1
							) }
							value={ faq.question }
							maxLength={ 200 }
							onChange={ setFaq( i, 'question' ) }
							__next40pxDefaultSize
						/>
						<FieldError
							error={
								fields[ `faqs.${ i }.question` ] ||
								fields[ `faqs.${ i }.id` ]
							}
						/>
						<TextareaControl
							label={ sprintf(
								/* translators: %d: FAQ number. */
								__( 'Answer %d', 'devrivo-chatbot' ),
								i + 1
							) }
							value={ faq.answer }
							maxLength={ 2000 }
							rows={ 3 }
							onChange={ setFaq( i, 'answer' ) }
						/>
						<FieldError error={ fields[ `faqs.${ i }.answer` ] } />
						<div>
							<Button
								size="small"
								variant="tertiary"
								isDestructive
								onClick={ () => setConfirm( i ) }
							>
								{ __( 'Delete', 'devrivo-chatbot' ) }
							</Button>
						</div>
					</li>
				) ) }
			</ol>

			<fieldset className="devrivo-fieldset">
				<legend>{ __( 'Add an FAQ', 'devrivo-chatbot' ) }</legend>
				<TextControl
					label={ __( 'Question', 'devrivo-chatbot' ) }
					value={ question }
					maxLength={ 200 }
					onChange={ setQuestion }
					__next40pxDefaultSize
				/>
				<TextareaControl
					label={ __( 'Answer', 'devrivo-chatbot' ) }
					value={ answer }
					maxLength={ 2000 }
					rows={ 3 }
					onChange={ setAnswer }
				/>
				<div>
					<Button
						variant="secondary"
						onClick={ addFaq }
						disabled={
							! question.trim() ||
							! answer.trim() ||
							faqs.length >= MAX_FAQS
						}
						accessibleWhenDisabled
						__next40pxDefaultSize
					>
						{ __( 'Add FAQ', 'devrivo-chatbot' ) }
					</Button>
				</div>
			</fieldset>

			<SaveBar settings={ settings } notify={ notify } />

			{ confirm !== null && faqs[ confirm ] && (
				<ConfirmModal
					title={ __( 'Delete FAQ?', 'devrivo-chatbot' ) }
					message={ sprintf(
						/* translators: %s: Question. */
						__(
							'“%s” will be removed when you save.',
							'devrivo-chatbot'
						),
						faqs[ confirm ].question
					) }
					confirm={ __( 'Delete', 'devrivo-chatbot' ) }
					onCancel={ () => setConfirm( null ) }
					onConfirm={ () => {
						set( 'faqs' )(
							faqs.filter( ( _, n ) => n !== confirm )
						);
						setConfirm( null );
					} }
				/>
			) }
		</div>
	);
}
