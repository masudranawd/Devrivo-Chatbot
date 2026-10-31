/**
 * Live preview: the real widget (build/widget.js) rendered from unsaved
 * settings via /preview-config, inside a contained stage.
 */
import { Notice } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request, toErrors } from '../lib/api';
import { boot } from '../lib/boot';

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
 * Preview panel.
 *
 * @param {Object} props
 * @param {Object} props.changes Unsaved sections ({ display: {…}, appearance: {…} }).
 * @return {Element} Panel.
 */
export function Preview( { changes } ) {
	const stage = useRef( null );
	const widget = useRef( null );
	const [ error, setError ] = useState( '' );
	const key = JSON.stringify( changes || {} );

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
					{ note: __( 'Preview only', 'devrivo-chatbot' ) }
				);
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
	}, [ key ] );

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
			{ error && (
				<Notice status="warning" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			<div className="devrivo-preview__stage" ref={ stage } />
		</section>
	);
}
