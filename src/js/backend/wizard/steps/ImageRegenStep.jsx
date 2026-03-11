import { useState, useEffect, useRef } from 'react';
import { useNavigate } from 'react-router-dom';
import { Progress, Collapse, Alert, Typography } from 'antd';
import { WarningOutlined } from '@ant-design/icons';
import useSharedDataStore from '../../utils/sharedDataStore';

/* global sdEdiAdminParams, ajaxurl */

const { Text } = Typography;

const ImageRegenStep = () => {
	const navigate            = useNavigate();
	const { activeSessionId } = useSharedDataStore();
	const sessionId           = activeSessionId || '';

	const [ phase,           setPhase           ] = useState( 'checking' );
	const [ total,           setTotal           ] = useState( 0 );
	const [ done,            setDone            ] = useState( 0 );
	const [ currentFilename, setCurrentFilename ] = useState( '' );
	const [ totalSizes,      setTotalSizes      ] = useState( 0 );
	const [ failures,        setFailures        ] = useState( [] );
	const [ error,           setError           ] = useState( null );

	const abortRef = useRef( false );

	// ── AJAX helper ──────────────────────────────────────────────────────────
	const ajaxPost = async ( action, extra = {} ) => {
		const body = new URLSearchParams( {
			action,
			sd_edi_nonce: sdEdiAdminParams.sd_edi_nonce,
			sessionId,
			...extra,
		} );

		const res  = await fetch( ajaxurl, { method: 'POST', body } );
		const json = await res.json();

		if ( ! json.success ) {
			throw new Error( json.data?.errorMessage ?? 'Request failed' );
		}

		return json.data;
	};

	// On mount: check count then auto-start regen without prompting.
	useEffect( () => {
		if ( ! sessionId ) {
			navigate( '/wizard/complete' );
			return;
		}

		( async () => {
			try {
				const checkData = await ajaxPost( 'sd_edi_regen_check' );
				if ( abortRef.current ) return;

				if ( checkData.total === 0 ) {
					setTimeout( () => navigate( '/wizard/complete' ), 500 );
					return;
				}

				setTotal( checkData.total );
				setPhase( 'running' );

				let offset   = 0;
				let allFails = [];

				while ( ! abortRef.current ) {
					const data = await ajaxPost( 'sd_edi_regenerate_images', { offset } );

					setDone( data.done );
					setCurrentFilename( data.current_filename || '' );
					setTotalSizes( ( prev ) => prev + ( data.sizes_generated?.length ?? 0 ) );

					if ( data.failed?.length ) {
						allFails = [ ...allFails, ...data.failed ];
						setFailures( [ ...allFails ] );
					}

					if ( data.completed ) break;

					offset = data.done;
				}

				setPhase( 'done' );
				setTimeout( () => navigate( '/wizard/complete' ), 1200 );
			} catch ( err ) {
				if ( abortRef.current ) return;
				setError( err.message );
				setPhase( 'done' );
			}
		} )();

		return () => { abortRef.current = true; };
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	const pct = total > 0 ? Math.round( ( done / total ) * 100 ) : 0;

	if ( 'checking' === phase ) {
		return (
			<div style={ { textAlign: 'center', padding: '40px 0' } }>
				<div style={ { marginTop: 16, color: '#595959' } }>Checking images…</div>
			</div>
		);
	}

	return (
		<div>
			<div style={ { marginBottom: 24 } }>
				<div style={ { fontSize: 14, color: '#595959', marginBottom: 8 } }>
					{ 'done' === phase
						? `Regeneration complete — ${ done } / ${ total } images`
						: `Regenerating images — ${ currentFilename || '…' }` }
				</div>
				<Progress
					percent={ pct }
					status={ error ? 'exception' : 'done' === phase ? 'success' : 'active' }
					strokeColor={ { '0%': '#6366f1', '100%': '#818cf8' } }
				/>
				<div style={ { display: 'flex', gap: 20, marginTop: 4, fontSize: 12, color: '#8c8c8c' } }>
					<span>{ done } / { total } images</span>
					{ totalSizes > 0 && <span>{ totalSizes } sizes generated</span> }
				</div>
			</div>

			{ failures.length > 0 && (
				<Collapse
					size="small"
					items={ [ {
						key:      '1',
						label:    <><WarningOutlined style={ { color: '#faad14' } } /> { failures.length } image{ failures.length !== 1 ? 's' : '' } failed</>,
						children: (
							<ul style={ { margin: 0, paddingLeft: 16 } }>
								{ failures.map( ( f, i ) => (
									<li key={ i }>
										<Text code>{ f.filename }</Text> — { f.error }
									</li>
								) ) }
							</ul>
						),
					} ] }
				/>
			) }

			{ error && (
				<Alert type="error" showIcon message="Regeneration Error" description={ error } style={ { marginTop: 16 } } />
			) }
		</div>
	);
};

export default ImageRegenStep;
