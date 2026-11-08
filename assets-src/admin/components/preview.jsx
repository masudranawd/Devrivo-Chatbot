/**
 * Live preview: the real widget (build/widget.js) rendered from unsaved
 * settings via /preview-config, inside a contained stage.
 */
import { Button, Notice, TextControl } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request, toErrors } from '../lib/api';
import { boot } from '../lib/boot';
import { PostPicker } from './post-picker';

let widgetLoading = null;

/**
 * Loads build/widget.js once.
 *
 * @return {Promise<void>} Resolves when window.devrivoChatbotWidget.preview exists.
 */
export function loadWidget() {
	if ( window.devrivoChatbotWidget && window.devrivoChatbotWidget.preview ) {
		return Promise.resolve();
	}
	if ( ! widgetLoading ) {
		widgetLoading = new Promise( ( resolve, reject ) => {
			const script = document.createElement( 'script' );
			script.src = boot().previewWidgetUrl;
			script.async = true;
			script.onload = () => resolve();
			script.onerror = () => {
				widgetLoading = null;
				reject( new Error( 'widget' ) );
			};
			document.head.append( script );
		} );
	}
	return widgetLoading;
}

/**
 * Runs a flow or shows a form in a rendered preview.
 *
 * @param {Object}      widget Controller from preview().
 * @param {Object|null} play   { type: "flow"|"form", id }.
 */
function run( widget, play ) {
	if ( ! widget || ! play || ! play.id ) {
		return;
	}
	if ( play.type === 'flow' && widget.flow ) {
		widget.flow( play.id );
	} else if ( play.type === 'form' && widget.form ) {
		widget.form( play.id );
	}
}

/**
 * Preview panel.
 *
 * @param {Object}  props
 * @param {Object}  props.changes Unsaved sections ({ display: {…}, appearance: {…} }).
 * @param {Object}  props.play    Optional { type: "flow"|"form", id, n }: run it (again when n changes).
 * @param {boolean} props.devices Show the Desktop / Mobile toggle.
 * @param {boolean} props.pages   Show "Preview as page" (a page and a path).
 * @return {Element} Panel.
 */
export function Preview( {
	changes,
	play = null,
	devices = false,
	pages = false,
} ) {
	const stage = useRef( null );
	const widget = useRef( null );
	const playing = useRef( play );
	const [ error, setError ] = useState( '' );
	const [ mobile, setMobile ] = useState( false );
	// Page to preview: a post ID and/or a path relative to the home.
	const [ page, setPage ] = useState( { postId: 0, path: '/' } );
	const pageKey = `${ page.postId }|${ page.path }`;
	const key = JSON.stringify( changes || {} );
	playing.current = play;

	useEffect( () => {
		let cancelled = false;
		const timer = window.setTimeout( async () => {
			try {
				await loadWidget();
				const config = await request( '/preview-config', {
					method: 'POST',
					data: JSON.parse( key ),
				} );
				if ( cancelled || ! stage.current ) {
					return;
				}
				if ( widget.current ) {
					widget.current.destroy();
				}
				widget.current = window.devrivoChatbotWidget.preview(
					stage.current,
					config,
					{
						note: __( 'Preview only', 'devrivo-chatbot' ),
						mobile,
						fontUrl: boot().previewFontUrl,
						page,
					}
				);
				run( widget.current, playing.current );
				setError( '' );
			} catch ( problem ) {
				if ( ! cancelled ) {
					setError(
						problem instanceof Error && problem.message === 'widget'
							? __(
									'The preview could not be loaded.',
									'devrivo-chatbot'
								)
							: toErrors( problem ).message
					);
				}
			}
		}, 400 );

		return () => {
			cancelled = true;
			window.clearTimeout( timer );
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ key, mobile, pageKey ] );

	// "Try this flow" without other changes: run it in the current preview.
	useEffect( () => {
		run( widget.current, play );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ play && play.type, play && play.id, play && play.n ] );

	useEffect(
		() => () => {
			if ( widget.current ) {
				widget.current.destroy();
			}
		},
		[]
	);

	return (
		<section
			className="devrivo-preview"
			aria-labelledby="devrivo-preview-title"
		>
			<h2 id="devrivo-preview-title">
				{ __( 'Preview', 'devrivo-chatbot' ) }
			</h2>
			<p className="description">
				{ __(
					'Shows your unsaved changes. Buttons and forms do nothing here.',
					'devrivo-chatbot'
				) }
			</p>
			{ pages && (
				<div className="devrivo-preview__page">
					<PostPicker
						label={ __( 'Preview as page', 'devrivo-chatbot' ) }
						ids={ page.postId ? [ page.postId ] : [] }
						onChange={ ( ids ) =>
							setPage( {
								...page,
								postId: ids.length ? ids[ ids.length - 1 ] : 0,
							} )
						}
					/>
					<TextControl
						label={ __( 'Or a path', 'devrivo-chatbot' ) }
						help={ sprintf(
							/* translators: %s: Site home path, e.g. "/". */
							__(
								'Relative to your site home (%s), e.g. /services/implants/',
								'devrivo-chatbot'
							),
							boot().homePath || '/'
						) }
						value={ page.path }
						onChange={ ( path ) => setPage( { ...page, path } ) }
						__next40pxDefaultSize
					/>
				</div>
			) }
			{ devices && (
				<div
					className="devrivo-preview__devices"
					role="group"
					aria-label={ __( 'Preview size', 'devrivo-chatbot' ) }
				>
					<Button
						size="small"
						variant={ mobile ? 'tertiary' : 'secondary' }
						isPressed={ ! mobile }
						onClick={ () => setMobile( false ) }
					>
						{ __( 'Desktop', 'devrivo-chatbot' ) }
					</Button>
					<Button
						size="small"
						variant={ mobile ? 'secondary' : 'tertiary' }
						isPressed={ mobile }
						onClick={ () => setMobile( true ) }
					>
						{ __( 'Mobile', 'devrivo-chatbot' ) }
					</Button>
				</div>
			) }
			{ error && (
				<Notice status="warning" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			<div
				className={
					'devrivo-preview__stage' +
					( mobile ? ' devrivo-preview__stage--mobile' : '' )
				}
				data-device={ mobile ? 'mobile' : 'desktop' }
				ref={ stage }
			/>
		</section>
	);
}
