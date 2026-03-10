import { Progress, Alert } from 'antd';
import { WarningOutlined } from '@ant-design/icons';
import { useNavigate } from 'react-router-dom';
import { useState, useEffect, useRef } from 'react';
import { useWizard } from '../WizardContext';
import useSharedDataStore from '../../utils/sharedDataStore';
import ActivityFeed from '../../components/ActivityFeed';
import { Api } from '../../utils/Api';

/* global sdEdiAdminParams, ajaxurl */

const ImportingStep = () => {
	const navigate    = useNavigate();
	const { importOptions, selectedDemo } = useWizard();
	const { activeSessionId, setActiveSessionId } = useSharedDataStore();

	const [ stepLabel,   setStepLabel   ] = useState( 'Starting…' );
	const [ xmlProgress, setXmlProgress ] = useState( { done: 0, total: 0 } );
	const [ overallPct,  setOverallPct  ] = useState( 0 );
	const [ error,       setError       ] = useState( null );
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

		if ( ! json.success ) {
			throw new Error( json.data?.errorMessage ?? 'Unknown error' );
		}

		return json.data;
	};

	// ── XML chunk polling loop ───────────────────────────────────────────────
	const runChunkLoop = async ( totalItems ) => {
		let offset = 0;

		while ( offset < totalItems && ! abortRef.current ) {
			setStepLabel( `Importing content… ${ offset } / ${ totalItems }` );

			const data = await ajaxPost( 'sd_edi_import_xml_chunk', { offset } );

			setXmlProgress( { done: data.done, total: data.total } );
			setOverallPct( Math.round( ( data.done / data.total ) * 60 ) + 20 );

			offset = data.offset ?? data.done;

			if ( data.done >= data.total ) break;
		}
	};

	// ── Main import sequence ─────────────────────────────────────────────────
	useEffect( () => {
		let sid = sessionId;

		const run = async () => {
			try {
				setStepLabel( 'Downloading demo files…' );
				setOverallPct( 5 );
				const initData = await ajaxPost( 'sd_edi_initialize' );
				sid = initData.sessionId ?? sid;
				sessionIdRef.current = sid;
				setSessionId( sid );
				setActiveSessionId( sid );

				setStepLabel( 'Installing plugins…' );
				setOverallPct( 10 );
				await ajaxPost( 'sd_edi_install_plugins' );

				setStepLabel( 'Analysing demo content…' );
				setOverallPct( 18 );
				const queueData = await ajaxPost( 'sd_edi_install_demo' );
				const totalItems = queueData?.total ?? 0;

				await runChunkLoop( totalItems );

				const remaining = [
					{ action: 'sd_edi_import_menus',        label: 'Importing menus…',      pct: 82 },
					{ action: 'sd_edi_import_widgets',       label: 'Importing widgets…',    pct: 85 },
					{ action: 'sd_edi_customizer_import',    label: 'Applying customizer…',  pct: 88 },
					{ action: 'sd_edi_import_settings',      label: 'Importing settings…',   pct: 91 },
					{ action: 'sd_edi_import_fluent_forms',  label: 'Importing forms…',      pct: 93 },
					{ action: 'sd_edi_activate_plugins',     label: 'Activating plugins…',   pct: 95 },
					{ action: 'sd_edi_finalize',             label: 'Finalising…',            pct: 99 },
				];

				for ( const step of remaining ) {
					if ( abortRef.current ) break;
					setStepLabel( step.label );
					setOverallPct( step.pct );
					await ajaxPost( step.action );
				}

				setOverallPct( 100 );
				setDone( true );
				setTimeout( () => navigate( '/wizard/regen' ), 800 );

			} catch ( err ) {
				setError( err.message );
			}
		};

		run();

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
					description={ error }
					style={ { marginBottom: 20 } }
				/>
			) }

			<ActivityFeed sessionId={ sessionId } active={ ! done && ! error } />
		</div>
	);
};

export default ImportingStep;
