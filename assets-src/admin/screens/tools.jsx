/**
 * Tools: industry presets (merge or replace, with undo), settings export and
 * import (JSON), and resetting one section to its defaults.
 */
import {
	Button,
	Card,
	CardBody,
	CheckboxControl,
	Modal,
	Notice,
	RadioControl,
	SelectControl,
} from '@wordpress/components';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { ConfirmModal, ErrorSummary } from '../components/common';
import { request, toErrors } from '../lib/api';
import {
	downloadJson,
	importError,
	parseImport,
	replacedSections,
	sectionLabels,
} from '../lib/transfer';
import { publish } from '../lib/use-settings';

/**
 * Apply-preset dialog: Merge (recommended) or Replace, and for Replace the
 * exact sections that will be overwritten.
 *
 * @param {Object}                 props
 * @param {Object}                 props.preset   Preset from GET /presets/{id} (null while loading).
 * @param {string}                 props.title    Preset title.
 * @param {boolean}                props.busy     Applying.
 * @param {(mode: string) => void} props.onApply  Apply with "merge" or "replace".
 * @param {() => void}             props.onCancel Cancelled.
 * @return {Element} Modal.
 */
export function ApplyPresetModal( { preset, title, busy, onApply, onCancel } ) {
	const [ mode, setMode ] = useState( 'merge' );
	const labels = sectionLabels();
	const sections = replacedSections( preset );

	return (
		<Modal
			title={ sprintf(
				/* translators: %s: Preset title. */
				__( 'Apply “%s”', 'devrivo-chatbot' ),
				title
			) }
			onRequestClose={ onCancel }
		>
			<RadioControl
				label={ __( 'How should it be applied?', 'devrivo-chatbot' ) }
				selected={ mode }
				options={ [
					{
						label: __( 'Merge (recommended)', 'devrivo-chatbot' ),
						value: 'merge',
						description: __(
							'Only fills sections you have not changed yet. Your own items stay as they are.',
							'devrivo-chatbot'
						),
					},
					{
						label: __( 'Replace', 'devrivo-chatbot' ),
						value: 'replace',
						description: __(
							'Uses the preset’s content for every section it includes.',
							'devrivo-chatbot'
						),
					},
				] }
				onChange={ setMode }
			/>
			{ mode === 'replace' && (
				<Notice status="warning" isDismissible={ false }>
					{ ! preset ? (
						<p>{ __( 'Loading…', 'devrivo-chatbot' ) }</p>
					) : (
						<>
							<p>
								{ __(
									'These sections will be replaced. Your own items in them will be overwritten:',
									'devrivo-chatbot'
								) }
							</p>
							<ul
								className="devrivo-error-list"
								data-testid="replaced-sections"
							>
								{ sections.map( ( name ) => (
									<li key={ name }>
										{ labels[ name ] || name }
									</li>
								) ) }
							</ul>
							<p>
								{ __(
									'You can undo this afterwards with “Undo last change”.',
									'devrivo-chatbot'
								) }
							</p>
						</>
					) }
				</Notice>
			) }
			<div className="devrivo-modal-actions">
				<Button
					variant="tertiary"
					onClick={ onCancel }
					__next40pxDefaultSize
				>
					{ __( 'Cancel', 'devrivo-chatbot' ) }
				</Button>
				<Button
					variant="primary"
					isDestructive={ mode === 'replace' }
					isBusy={ busy }
					disabled={ busy || ( mode === 'replace' && ! preset ) }
					accessibleWhenDisabled
					onClick={ () => onApply( mode ) }
					__next40pxDefaultSize
				>
					{ mode === 'replace'
						? __( 'Replace sections', 'devrivo-chatbot' )
						: __( 'Merge', 'devrivo-chatbot' ) }
				</Button>
			</div>
		</Modal>
	);
}

/**
 * Reads a chosen file as text.
 *
 * @param {File} file File.
 * @return {Promise<string>} Contents.
 */
function readFile( file ) {
	if ( typeof file.text === 'function' ) {
		return file.text();
	}
	return new Promise( ( resolve, reject ) => {
		const reader = new window.FileReader();
		reader.onload = () => resolve( String( reader.result ) );
		reader.onerror = () => reject( reader.error );
		reader.readAsText( file );
	} );
}

/**
 * Tools screen.
 *
 * @param {Object}                                    props
 * @param {(status: string, message: string) => void} props.notify Notice.
 * @return {Element} Screen.
 */
export default function Tools( { notify } ) {
	const labels = sectionLabels();
	const [ presets, setPresets ] = useState( null );
	const [ backup, setBackup ] = useState( null );
	const [ applying, setApplying ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ confirmUndo, setConfirmUndo ] = useState( false );
	const [ file, setFile ] = useState( null );
	const [ fileError, setFileError ] = useState( '' );
	const [ chosen, setChosen ] = useState( [] );
	const [ skip, setSkip ] = useState( true );
	const [ importErrors, setImportErrors ] = useState( {
		message: '',
		fields: {},
	} );
	const [ reset, setReset ] = useState( '' );
	const [ confirmReset, setConfirmReset ] = useState( false );

	const loadPresets = useCallback( () => {
		request( '/presets' )
			.then( setPresets )
			.catch( ( error ) => notify( 'error', toErrors( error ).message ) );
		request( '/presets/backup' )
			.then( setBackup )
			.catch( () => setBackup( null ) );
	}, [ notify ] );

	useEffect( () => {
		loadPresets();
	}, [ loadPresets ] );

	const startApply = ( item ) => {
		setApplying( { item, preset: null } );
		request( `/presets/${ item.id }` )
			.then( ( preset ) =>
				setApplying( ( current ) =>
					current && current.item.id === item.id
						? { ...current, preset }
						: current
				)
			)
			.catch( ( error ) => {
				setApplying( null );
				notify( 'error', toErrors( error ).message );
			} );
	};

	const apply = async ( mode ) => {
		setBusy( true );
		try {
			const result = await request(
				`/presets/${ applying.item.id }/apply`,
				{
					method: 'POST',
					data: { mode },
				}
			);
			publish( result.settings );
			const names = ( result.applied || [] ).map(
				( name ) => labels[ name ] || name
			);
			notify(
				'success',
				names.length
					? sprintf(
							/* translators: %s: Section names. */
							__(
								'Preset applied. Updated: %s.',
								'devrivo-chatbot'
							),
							names.join( ', ' )
						)
					: __(
							'Preset applied. Nothing needed to change: you have already edited every section it includes.',
							'devrivo-chatbot'
						)
			);
			setApplying( null );
			loadPresets();
		} catch ( error ) {
			notify( 'error', toErrors( error ).message );
		} finally {
			setBusy( false );
		}
	};

	const undo = async () => {
		setConfirmUndo( false );
		setBusy( true );
		try {
			const result = await request( '/presets/undo', {
				method: 'POST',
			} );
			publish( result.settings );
			notify(
				'success',
				__( 'Your previous settings are back.', 'devrivo-chatbot' )
			);
			loadPresets();
		} catch ( error ) {
			notify( 'error', toErrors( error ).message );
		} finally {
			setBusy( false );
		}
	};

	const exportSettings = async () => {
		try {
			const data = await request( '/settings/export' );
			const date = String( data?.meta?.exported_at || '' ).slice( 0, 10 );
			downloadJson( data, `devrivo-chatbot-settings-${ date }.json` );
		} catch ( error ) {
			notify( 'error', toErrors( error ).message );
		}
	};

	const chooseFile = async ( event ) => {
		const picked = event.target.files && event.target.files[ 0 ];
		setFile( null );
		setFileError( '' );
		setImportErrors( { message: '', fields: {} } );
		if ( ! picked ) {
			return;
		}
		let text = '';
		if ( picked.size <= 1048576 ) {
			try {
				text = await readFile( picked );
			} catch {
				setFileError( importError( 'read' ) );
				return;
			}
		}
		const parsed = parseImport( text, picked.size );
		if ( ! parsed.ok ) {
			setFileError( importError( parsed.error ) );
			return;
		}
		setFile( { name: picked.name, ...parsed } );
		setChosen( parsed.sections );
	};

	const runImport = async () => {
		setBusy( true );
		setImportErrors( { message: '', fields: {} } );
		try {
			const result = await request( '/settings/import', {
				method: 'POST',
				data: {
					data: file.data,
					sections: chosen,
					skip_site_specific: skip,
				},
			} );
			publish( result.settings );
			notify(
				'success',
				sprintf(
					/* translators: %s: Section names. */
					__( 'Settings imported: %s.', 'devrivo-chatbot' ),
					( result.sections || [] )
						.map( ( name ) => labels[ name ] || name )
						.join( ', ' )
				)
			);
			setFile( null );
			loadPresets();
		} catch ( error ) {
			setImportErrors( toErrors( error ) );
		} finally {
			setBusy( false );
		}
	};

	const runReset = async () => {
		setConfirmReset( false );
		setBusy( true );
		try {
			publish(
				await request(
					`/settings?section=${ encodeURIComponent( reset ) }`,
					{
						method: 'DELETE',
					}
				)
			);
			notify(
				'success',
				sprintf(
					/* translators: %s: Section name. */
					__( '“%s” is back to its defaults.', 'devrivo-chatbot' ),
					labels[ reset ] || reset
				)
			);
			setReset( '' );
		} catch ( error ) {
			notify( 'error', toErrors( error ).message );
		} finally {
			setBusy( false );
		}
	};

	const backupText = () => {
		const when = backup.created
			? new Date( backup.created ).toLocaleString()
			: '';
		const preset = ( presets || [] ).find(
			( item ) => item.id === backup.preset
		);
		return backup.preset
			? sprintf(
					/* translators: 1: Preset title, 2: Date and time. */
					__(
						'Restores your settings from before the preset “%1$s” was applied (%2$s).',
						'devrivo-chatbot'
					),
					preset ? preset.title : backup.preset,
					when
				)
			: sprintf(
					/* translators: %s: Date and time. */
					__(
						'Restores your settings from before the last import (%s).',
						'devrivo-chatbot'
					),
					when
				);
	};

	const resettable = Object.keys( labels ).filter(
		( name ) => name !== 'general'
	);

	return (
		<div className="devrivo-tools">
			<section aria-labelledby="devrivo-presets">
				<h2 id="devrivo-presets">
					{ __( 'Industry presets', 'devrivo-chatbot' ) }
				</h2>
				<p className="description">
					{ __(
						'Ready-made quick actions, conversations and texts for your type of business. Everything stays editable.',
						'devrivo-chatbot'
					) }
				</p>
				{ ! presets && <p>{ __( 'Loading…', 'devrivo-chatbot' ) }</p> }
				{ presets && (
					<div className="devrivo-presets">
						{ presets.map( ( item ) => (
							<Card key={ item.id } size="small">
								<CardBody>
									<h3>
										{ item.title }{ ' ' }
										{ item.active && (
											<span className="devrivo-badge">
												{ __(
													'Active',
													'devrivo-chatbot'
												) }
											</span>
										) }
									</h3>
									<p>{ item.description }</p>
									<Button
										variant="secondary"
										onClick={ () => startApply( item ) }
										disabled={ busy }
										__next40pxDefaultSize
									>
										{ __( 'Apply…', 'devrivo-chatbot' ) }
									</Button>
								</CardBody>
							</Card>
						) ) }
					</div>
				) }
				{ backup && backup.exists && (
					<div>
						<Button
							variant="secondary"
							onClick={ () => setConfirmUndo( true ) }
							disabled={ busy }
							aria-describedby="devrivo-undo-help"
							__next40pxDefaultSize
						>
							{ __( 'Undo last change', 'devrivo-chatbot' ) }
						</Button>
						<p className="description" id="devrivo-undo-help">
							{ __(
								'Restores your settings to how they were before the most recent preset or import. Only that one change can be undone.',
								'devrivo-chatbot'
							) }{ ' ' }
							{ backupText() }
						</p>
					</div>
				) }
			</section>

			<section aria-labelledby="devrivo-transfer">
				<h2 id="devrivo-transfer">
					{ __( 'Export and import', 'devrivo-chatbot' ) }
				</h2>
				<p className="description">
					{ __(
						'Download all settings as a JSON file, for a backup or to copy them to another site. Leads are not included.',
						'devrivo-chatbot'
					) }
				</p>
				<p className="description">
					{ __(
						'The file includes your notification email addresses, so share it carefully.',
						'devrivo-chatbot'
					) }
				</p>
				<p>
					<Button
						variant="secondary"
						onClick={ exportSettings }
						__next40pxDefaultSize
					>
						{ __( 'Export settings', 'devrivo-chatbot' ) }
					</Button>
				</p>

				<div className="devrivo-form">
					<div>
						<label htmlFor="devrivo-import-file">
							{ __(
								'Import a settings file (.json, up to 1 MB)',
								'devrivo-chatbot'
							) }
						</label>
						<br />
						<input
							id="devrivo-import-file"
							type="file"
							accept=".json,application/json"
							onChange={ chooseFile }
							aria-describedby={
								fileError ? 'devrivo-import-error' : undefined
							}
						/>
					</div>
					{ fileError && (
						<p
							className="devrivo-field-error"
							id="devrivo-import-error"
							role="alert"
						>
							{ fileError }
						</p>
					) }
					{ file && (
						<>
							<ErrorSummary errors={ importErrors } />
							<p>
								{ sprintf(
									/* translators: %s: Site address. */
									__(
										'Exported from %s.',
										'devrivo-chatbot'
									),
									String( file.data.meta.site || '' )
								) }
							</p>
							<fieldset className="devrivo-fieldset">
								<legend>
									{ __(
										'Sections to import',
										'devrivo-chatbot'
									) }
								</legend>
								{ file.sections.map( ( name ) => (
									<CheckboxControl
										key={ name }
										label={ labels[ name ] || name }
										checked={ chosen.includes( name ) }
										onChange={ ( on ) =>
											setChosen(
												on
													? [ ...chosen, name ]
													: chosen.filter(
															( n ) => n !== name
														)
											)
										}
									/>
								) ) }
							</fieldset>
							<CheckboxControl
								label={ __(
									'Keep this site’s page selections, avatar and AI connection',
									'devrivo-chatbot'
								) }
								help={ __(
									'Recommended when the file comes from another site: page IDs, the avatar image and the AI provider, model and limit are kept as they are here.',
									'devrivo-chatbot'
								) }
								checked={ skip }
								onChange={ setSkip }
							/>
							<p className="description">
								{ __(
									'The chosen sections replace yours. Everything is checked first: if anything is invalid, nothing changes. You can undo the import with “Undo last change”.',
									'devrivo-chatbot'
								) }
							</p>
							<div>
								<Button
									variant="primary"
									onClick={ runImport }
									isBusy={ busy }
									disabled={ busy || ! chosen.length }
									accessibleWhenDisabled
									__next40pxDefaultSize
								>
									{ __( 'Import', 'devrivo-chatbot' ) }
								</Button>
							</div>
						</>
					) }
				</div>
			</section>

			<section aria-labelledby="devrivo-reset">
				<h2 id="devrivo-reset">
					{ __( 'Reset a section', 'devrivo-chatbot' ) }
				</h2>
				<div className="devrivo-flowbar">
					<SelectControl
						label={ __( 'Section', 'devrivo-chatbot' ) }
						value={ reset }
						options={ [
							{
								label: __( 'Choose…', 'devrivo-chatbot' ),
								value: '',
							},
							...resettable.map( ( name ) => ( {
								label: labels[ name ],
								value: name,
							} ) ),
						] }
						onChange={ setReset }
						__next40pxDefaultSize
					/>
					<Button
						variant="secondary"
						isDestructive
						disabled={ ! reset || busy }
						accessibleWhenDisabled
						onClick={ () => setConfirmReset( true ) }
						__next40pxDefaultSize
					>
						{ __( 'Reset to defaults', 'devrivo-chatbot' ) }
					</Button>
				</div>
			</section>

			{ applying && (
				<ApplyPresetModal
					preset={ applying.preset }
					title={ applying.item.title }
					busy={ busy }
					onApply={ apply }
					onCancel={ () => setApplying( null ) }
				/>
			) }
			{ confirmUndo && backup && (
				<ConfirmModal
					title={ __( 'Undo last change?', 'devrivo-chatbot' ) }
					message={ backupText() }
					confirm={ __( 'Undo', 'devrivo-chatbot' ) }
					onCancel={ () => setConfirmUndo( false ) }
					onConfirm={ undo }
				/>
			) }
			{ confirmReset && reset && (
				<ConfirmModal
					title={ __( 'Reset to defaults?', 'devrivo-chatbot' ) }
					message={ sprintf(
						/* translators: %s: Section name. */
						__(
							'All settings in “%s” go back to their defaults. This cannot be undone.',
							'devrivo-chatbot'
						),
						labels[ reset ]
					) }
					confirm={ __( 'Reset', 'devrivo-chatbot' ) }
					onCancel={ () => setConfirmReset( false ) }
					onConfirm={ runReset }
				/>
			) }
		</div>
	);
}
