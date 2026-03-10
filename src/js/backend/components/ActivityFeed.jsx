import { useEffect, useRef, useState } from 'react';
import { Empty, Tag } from 'antd';
import {
	InfoCircleOutlined, CheckCircleOutlined,
	WarningOutlined, CloseCircleOutlined,
	LoadingOutlined,
} from '@ant-design/icons';
import { Api } from '../utils/Api';

const LEVEL_CONFIG = {
	info:    { icon: <InfoCircleOutlined />,    color: '#1677ff', bg: '#e6f4ff' },
	success: { icon: <CheckCircleOutlined />,   color: '#52c41a', bg: '#f6ffed' },
	warning: { icon: <WarningOutlined />,       color: '#faad14', bg: '#fffbe6' },
	error:   { icon: <CloseCircleOutlined />,   color: '#ff4d4f', bg: '#fff2f0' },
};

/**
 * ActivityFeed — polls /sd/edi/v1/import-log while active is true.
 *
 * @param {object} props
 * @param {string} props.sessionId  Active import session UUID.
 * @param {boolean} props.active    Set to false to stop polling.
 */
const ActivityFeed = ( { sessionId, active = true } ) => {
	const [ entries, setEntries ] = useState( [] );
	const scrollRef = useRef( null );
	const sinceRef  = useRef( '' );

	useEffect( () => {
		if ( ! sessionId ) return;

		const poll = () => {
			Api.get( `/sd/edi/v1/import-log`, {
				params: { session_id: sessionId, since: sinceRef.current },
			} )
			.then( ( res ) => {
				const rows = Array.isArray( res.data ) ? res.data : [];
				if ( rows.length ) {
					sinceRef.current = rows[ rows.length - 1 ].timestamp;
					setEntries( ( prev ) => [ ...prev, ...rows ] );
				}
			} )
			.catch( () => {} );
		};

		poll();

		if ( ! active ) return;

		const interval = setInterval( poll, 2000 );
		return () => clearInterval( interval );
	}, [ sessionId, active ] );

	// Auto-scroll to bottom on new entries.
	useEffect( () => {
		if ( scrollRef.current ) {
			scrollRef.current.scrollTop = scrollRef.current.scrollHeight;
		}
	}, [ entries ] );

	return (
		<div style={ {
			border: '1px solid #f0f0f0', borderRadius: 8,
			background: '#fafafa', overflow: 'hidden',
		} }>
			<div style={ {
				padding: '10px 16px', borderBottom: '1px solid #f0f0f0',
				display: 'flex', alignItems: 'center', gap: 8,
				fontSize: 13, fontWeight: 600, color: '#595959',
			} }>
				{ active && <LoadingOutlined style={ { color: '#6366f1' } } /> }
				Activity Log
			</div>

			<div
				ref={ scrollRef }
				style={ { maxHeight: 280, overflowY: 'auto', padding: entries.length ? '12px 16px' : 0 } }
			>
				{ entries.length === 0
					? <Empty image={ Empty.PRESENTED_IMAGE_SIMPLE } description="Waiting for activity…" style={ { margin: '20px 0' } } />
					: entries.map( ( e ) => {
						const cfg  = LEVEL_CONFIG[ e.level ] ?? LEVEL_CONFIG.info;
						const time = new Date( e.timestamp ).toLocaleTimeString( 'en-US', {
							hour: '2-digit', minute: '2-digit', second: '2-digit',
						} );
						return (
							<div key={ e.id } style={ {
								display: 'flex', alignItems: 'flex-start', gap: 10,
								marginBottom: 8, padding: '8px 10px', borderRadius: 6,
								background: cfg.bg, border: `1px solid ${ cfg.color }22`,
							} }>
								<span style={ { color: cfg.color, fontSize: 14, marginTop: 1 } }>
									{ cfg.icon }
								</span>
								<span style={ { flex: 1, fontSize: 13, color: '#262626', lineHeight: 1.5 } }>
									{ e.message }
								</span>
								<span style={ { fontSize: 11, color: '#bfbfbf', whiteSpace: 'nowrap' } }>
									{ time }
								</span>
							</div>
						);
					} )
				}
			</div>
		</div>
	);
};

export default ActivityFeed;
