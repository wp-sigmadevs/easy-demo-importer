import { Button, Input, Tabs, Empty, Tooltip, Tag } from 'antd';
import { LockOutlined, SearchOutlined } from '@ant-design/icons';
import { useNavigate } from 'react-router-dom';
import { useState, useEffect } from 'react';
import { useWizard } from '../WizardContext';
import DemoCard from '../../components/DemoCard';
import GridSkeleton from '../../components/GridSkeleton';
import useSharedDataStore from '../../utils/sharedDataStore';
import ReactDOM from 'react-dom';

/* global sdEdiAdminParams */

const DemoSelectStep = () => {
	const navigate = useNavigate();
	const { setSelectedDemo } = useWizard();
	const { importList, fetchImportList, loading } = useSharedDataStore();
	const [ search, setSearch ] = useState( '' );
	const [ activeTab, setActiveTab ] = useState( 'all' );

	useEffect( () => {
		fetchImportList( sdEdiAdminParams.restApiUrl + 'sd/edi/v1/demo/list' );
	}, [] );

	const demos      = importList?.data ?? {};
	const demoArray  = Object.entries( demos ).map( ( [ slug, data ] ) => ( { slug, ...data } ) );
	const categories = [ 'all', ...new Set( demoArray.flatMap( ( d ) => d.categories ?? [] ) ) ];

	const filtered = demoArray.filter( ( d ) => {
		const matchTab    = activeTab === 'all' || ( d.categories ?? [] ).includes( activeTab );
		const matchSearch = ! search || d.name?.toLowerCase().includes( search.toLowerCase() );
		return matchTab && matchSearch;
	} );

	const handleSelect = ( demo ) => {
		if ( demo.requires_met === false ) return;
		setSelectedDemo( demo );
		sessionStorage.setItem( 'sd_edi_selected_demo', JSON.stringify( demo ) );
		navigate( '/wizard/options' );
	};

	const back = document.getElementById( 'edi-wizard-back-slot' );

	return (
		<>
			<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 } }>
				<h2 style={ { fontSize: 18, fontWeight: 700, margin: 0 } }>Choose a Demo</h2>
				<Input
					prefix={ <SearchOutlined /> }
					placeholder="Search demos…"
					style={ { width: 220 } }
					value={ search }
					onChange={ ( e ) => setSearch( e.target.value ) }
					allowClear
				/>
			</div>

			<Tabs
				activeKey={ activeTab }
				onChange={ setActiveTab }
				items={ categories.map( ( c ) => ( { key: c, label: c === 'all' ? 'All' : c } ) ) }
				style={ { marginBottom: 16 } }
			/>

			{ loading
				? <GridSkeleton />
				: filtered.length === 0
					? <Empty description="No demos match your search." />
					: (
						<div style={ { display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(220px, 1fr))', gap: 16 } }>
							{ filtered.map( ( demo ) => {
								const locked = demo.requires_met === false;
								return (
									<Tooltip
										key={ demo.slug }
										title={ locked
											? `Requires: ${ ( demo.requires_missing ?? [] ).join( ', ' ) }`
											: '' }
									>
										<div
											style={ { opacity: locked ? 0.5 : 1, cursor: locked ? 'not-allowed' : 'pointer', position: 'relative' } }
											onClick={ () => handleSelect( demo ) }
										>
											{ locked && (
												<Tag
													icon={ <LockOutlined /> }
													color="default"
													style={ { position: 'absolute', top: 8, right: 8, zIndex: 2 } }
												>
													Requirements not met
												</Tag>
											) }
											<DemoCard data={ demo } disableClick={ locked } />
										</div>
									</Tooltip>
								);
							} ) }
						</div>
					)
			}

			{ back && ReactDOM.createPortal(
				<Button onClick={ () => navigate( '/wizard/plugins' ) }>Back</Button>,
				back
			) }
		</>
	);
};

export default DemoSelectStep;
