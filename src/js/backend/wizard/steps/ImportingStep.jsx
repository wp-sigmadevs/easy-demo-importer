import { Progress, Alert, Button } from 'antd';
import { WarningOutlined, ReloadOutlined } from '@ant-design/icons';
import { useNavigate } from 'react-router-dom';
import React, { useState, useEffect, useRef } from 'react';
import { useWizard } from '../WizardContext';
import useSharedDataStore from '../../utils/sharedDataStore';
import ActivityFeed from '../../components/ActivityFeed';

/* global sdEdiAdminParams, ajaxurl */

const phaseWeights = {
	'sd_edi_install_demo':        { label: 'Initialising import…',  weight: 5 },
	'sd_edi_install_plugins':     { label: 'Installing plugins…',   weight: 10 },
	'sd_edi_activate_plugins':    { label: 'Activating plugins…',    weight: 12 },
	'sd_edi_download_demo_files': { label: 'Downloading demo files…', weight: 15 },
	'sd_edi_import_xml':          { label: 'Analysing demo content…', weight: 18 },
	'sd_edi_import_xml_chunk':    { label: 'Importing content…',     weight: 20 },
	'sd_edi_import_customizer':   { label: 'Applying customizer…',   weight: 80 },
	'sd_edi_import_menus':        { label: 'Importing menus…',       weight: 82 },
	'sd_edi_import_widgets':       { label: 'Importing widgets…',     weight: 85 },
	'sd_edi_import_rev_slider':    { label: 'Importing Revolution sliders…', weight: 88 },
	'sd_edi_import_layer_slider':  { label: 'Importing LayerSliders…', weight: 90 },
	'sd_edi_import_settings':      { label: 'Importing settings…',    weight: 92 },
	'sd_edi_import_fluent_forms':  { label: 'Importing forms…',       weight: 94 },
	'sd_edi_finalize_demo':        { label: 'Finalising…',            weight: 98 },
};

const ImportingStep = () => {
	const navigate    = useNavigate();
	const { importOptions, selectedDemo } = useWizard();
	const { activeSessionId, setActiveSessionId } = useSharedDataStore();

	const [ stepLabel,   setStepLabel   ] = useState( 'Starting…' );
	const [ xmlProgress, setXmlProgress ] = useState( { done: 0, total: 0 } );
	const [ overallPct,  setOverallPct  ] = useState( 0 );
	const [ error,       setError       ] = useState( null );
	const [ isLocked,    setIsLocked    ] = useState( false );
	const [ done,        setDone        ] = useState( false );
	const [ sessionId,   setSessionId   ] = useState( activeSessionId || '' );

	const abortRef     = useRef( false );
	const sessionIdRef = useRef( activeSessionId || '' );

	// ── AJAX helper ─────────────────────────────────────────────────────────
	const ajaxPost = async ( action, extra = {} ) => {
		const body = new URLSearchParams( {
			action,
			sd_edi_nonce: sdEdiAdminParams.sd_edi_nonce,
			sessionId:    sessionIdRef.current,
			demo:         selectedDemo?.slug ?? '',
			excludeImages:  importOptions.media   ? '' : '1',
			reset:          importOptions.resetDb ? 'true' : 'false',
			...extra,
		} );

		const res  = await fetch( ajaxurl, { method: 'POST', body } );
		const json = await res.json();

		if ( json.error === true ) {
			throw new Error( json.errorMessage || 'Unknown server error' );
		}

		if ( json.success === false ) {
			throw new Error( json.data?.errorMessage || json.data || 'Unknown error' );
		}

		return json.data !== undefined ? json.data : json;
	};

	// ── XML chunk polling loop ───────────────────────────────────────────────
	const runChunkLoop = async ( totalItems ) => {
		let offset = 0;

		while ( offset < totalItems && ! abortRef.current ) {
			setStepLabel( `Importing content… ${ offset } / ${ totalItems }` );

			const data = await ajaxPost( 'sd_edi_import_xml_chunk', { offset } );

			// Safeguard: if data doesn't have progress info, we might be done or failed.
			if ( data.done === undefined || data.total === undefined ) {
				setOverallPct( 80 );
				break;
			}

			setXmlProgress( { done: data.done, total: data.total } );
			// Chunked loop takes up 60% of total progress (from 20% to 80%)
			setOverallPct( Math.round( ( data.done / data.total ) * 60 ) + 20 );

			offset = data.offset ?? data.done;

			if ( data.done >= data.total ) break;
		}
	};

	const runImport = async ( forceReset = false ) => {
		setError( null );
		setIsLocked( false );
		setOverallPct( 0 );
		setStepLabel( 'Initialising import…' );
		
		let sid = sessionId;
		let nextAction = 'sd_edi_install_demo';

		try {
			while ( nextAction && ! abortRef.current ) {
				const phaseInfo = phaseWeights[ nextAction ];
				if ( phaseInfo ) {
					setStepLabel( phaseInfo.label );
					// For non-chunked steps, set the weight directly
					if ( nextAction !== 'sd_edi_import_xml_chunk' ) {
						setOverallPct( phaseInfo.weight );
					}
				}

				if ( nextAction === 'sd_edi_import_xml_chunk' ) {
					// We need the total count from the previous sd_edi_import_xml step
					// This should have been stored in a transient on the server,
					// but the frontend needs it to run the loop.
					// If we don't have it, we'll try to get it from the last response if available.
					// But usually sd_edi_import_xml returns { total: X }
					// Handled inside the previous step's response.
					nextAction = null; // stop the while loop, runChunkLoop will take over
					break;
				}

				const response = await ajaxPost( nextAction, ( nextAction === 'sd_edi_install_demo' && forceReset ) ? { forceReset: 'true' } : {} );
				
				// Update session ID if returned (usually on first step)
				if ( response.sessionId ) {
					sid = response.sessionId;
					sessionIdRef.current = sid;
					setSessionId( sid );
					setActiveSessionId( sid );
				}

				// Special handling for XML Analysis step
				if ( nextAction === 'sd_edi_import_xml' ) {
					const totalItems = response.total ?? 0;
					await runChunkLoop( totalItems );
					nextAction = 'sd_edi_import_customizer'; // Standard flow
					continue;
				}

				nextAction = response.nextPhase || null;

				// If the server tells us to retry (mutex lock)
				if ( response.retry ) {
					setStepLabel( response.nextPhaseMessage || 'Waiting…' );
					await new Promise( resolve => setTimeout( resolve, ( response.retryAfter || 5 ) * 1000 ) );
					nextAction = response.nextPhase; 
				}
			}

			if ( ! abortRef.current ) {
				setOverallPct( 100 );
				setDone( true );
				setTimeout( () => navigate( '/wizard/regen' ), 800 );
			}

		} catch ( err ) {
			setError( err.message );
			if ( err.message.includes( 'already in progress' ) ) {
				setIsLocked( true );
			}
		}
	};

	// ── Main import sequence ─────────────────────────────────────────────────
	useEffect( () => {
		runImport();
		return () => { abortRef.current = true; };
	}, [] );

	const pct = xmlProgress.total > 0
		? Math.round( ( xmlProgress.done / xmlProgress.total ) * 100 )
		: null;

	return (
		<div>
			<div style={ {
				background: '#fffbe6', border: '1px solid #ffe58f',
				borderRadius: 8, padding: '10px 14px',
				fontSize: 13, color: '#ad6800', marginBottom: 20,
				display: 'flex', alignItems: 'center', gap: 8,
			} }>
				<WarningOutlined />
				Do not close this tab while the import is running.
			</div>

			<div style={ { marginBottom: 24 } }>
				<div style={ { fontSize: 14, color: '#595959', marginBottom: 8 } }>{ stepLabel }</div>
				<Progress
					percent={ overallPct }
					status={ error ? 'exception' : done ? 'success' : 'active' }
					strokeColor={ { '0%': '#6366f1', '100%': '#818cf8' } }
				/>

				{ pct !== null && (
					<div style={ { fontSize: 12, color: '#8c8c8c', marginTop: 4 } }>
						XML content: { xmlProgress.done } / { xmlProgress.total } items ({ pct }%)
					</div>
				) }
			</div>

			{ error && (
				<Alert
					type="error"
					showIcon
					message="Import Error"
					description={ 
						<div>
							<div style={{ marginBottom: 12 }}>{ error }</div>
							{ isLocked && (
								<Button 
									danger 
									icon={<ReloadOutlined />} 
									onClick={() => runImport(true)}
								>
									Force Start Over
								</Button>
							)}
						</div>
					}
					style={ { marginBottom: 20 } }
				/>
			) }

			<ActivityFeed sessionId={ sessionId } active={ ! done && ! error } />
		</div>
	);
};

export default ImportingStep;
