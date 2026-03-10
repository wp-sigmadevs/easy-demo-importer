import { useState, useEffect, useRef } from 'react';
import { useNavigate } from 'react-router-dom';
import { Button, Progress, Space, Collapse, Alert, Typography, Spin } from 'antd';
import { PictureOutlined, WarningOutlined } from '@ant-design/icons';
import useSharedDataStore from '../../utils/sharedDataStore';

/* global sdEdiAdminParams, ajaxurl */

const { Text } = Typography;

const ImageRegenStep = () => {
	const navigate            = useNavigate();
	const { activeSessionId } = useSharedDataStore();
	const sessionId           = activeSessionId || '';

	const [ phase,           setPhase           ] = useState( 'checking' );
	const [ total,           setTotal           ] = useState( 0 );
	const [ firstFilename,   setFirstFilename   ] = useState( '' );
	const [ done,            setDone            ] = useState( 0 );
	const [ currentFilename, setCurrentFilename ] = useState( '' );
	const [ totalSizes,      setTotalSizes      ] = useState( 0 );  // cumulative sizes generated
	const [ failures,        setFailures        ] = useState( [] );
	const [ error,           setError           ] = useState( null );

	const abortRef = useRef( false );

	// ── AJAX helper ──────────────────────────────────────────────────────────
	// Nonce key is 'sd_edi_nonce' (Helpers::nonceId()), value at sdEdiAdminParams.sd_edi_nonce.
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

	// On mount: check how many attachments need regen.
	useEffect( () => {
		if ( ! sessionId ) {
			navigate( '/wizard/complete' );
			return;
		}

		( async () => {
			try {
				const data = await ajaxPost( 'sd_edi_regen_check' );

				if ( data.total === 0 ) {
					setTimeout( () => navigate( '/wizard/complete' ), 500 );
					return;
				}

				setTotal( data.total );
				setFirstFilename( data.first_filename || '' );
				setPhase( 'prompt' );
			} catch ( err ) {
				setError( err.message );
				setPhase( 'prompt' );
			}
		} )();

		return () => { abortRef.current = true; };
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	const handleSkip = () => navigate( '/wizard/complete' );

	const handleBackground = async () => {
		try {
			await ajaxPost( 'sd_edi_background_regen' );
		} catch {
			// Non-fatal — still proceed to complete.
		}
		navigate( '/wizard/complete' );
	};

	const handleRegenNow = async () => {
		setPhase( 'running' );
		let offset   = 0;
		let allFails = [];

		try {
			while ( ! abortRef.current ) {
				const data = await ajaxPost( 'sd_edi_regenerate_images', { offset } );

				setDone( data.done );
				setCurrentFilename( data.current_filename || '' );
				// Accumulate total sizes generated (cumulative counter, not per-image pills).
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
			setError( err.message );
			setPhase( 'done' );
		}
	};

	const pct = total > 0 ? Math.round( ( done / total ) * 100 ) : 0;

	if ( 'checking' === phase ) {
		return (
			<div style={ { textAlign: 'center', padding: '40px 0' } }>
				<Spin size="large" />
				<div style={ { marginTop: 16, color: '#595959' } }>Checking images…</div>
			</div>
		);
	}

	if ( 'prompt' === phase ) {
		return (
			<div style={ { textAlign: 'center', padding: '24px 0' } }>
				<PictureOutlined style={ { fontSize: 48, color: '#6366f1', marginBottom: 16 } } />
				<div style={ { fontSize: 20, fontWeight: 600, marginBottom: 8 } }>
					{ `${ total } image${ total !== 1 ? 's' : '' } found — ready to regenerate` }
				</div>
				{ firstFilename && (
					<div style={ { color: '#8c8c8c', marginBottom: 24, fontSize: 13 } }>
						Starting with { firstFilename }
					</div>
				) }
				{ error && (
					<Alert type="warning" message={ error } style={ { marginBottom: 24, textAlign: 'left' } } />
				) }
				<Space size="middle" wrap>
					<Button type="primary" size="large" onClick={ handleRegenNow }>
						Regenerate Now
					</Button>
					<Button size="large" onClick={ handleBackground }>
						In Background
					</Button>
					<Button type="link" size="large" onClick={ handleSkip }>
						Skip — I'll handle this manually
					</Button>
				</Space>
			</div>
		);
	}

	return (
		<div>
			<div style={ { marginBottom: 24 } }>
				<div style={ { fontSize: 14, color: '#595959', marginBottom: 8 } }>
					{ 'done' === phase
						? `Regeneration complete — ${ done } / ${ total } images`
						: `Regenerating — ${ currentFilename || '…' }` }
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
