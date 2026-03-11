import { Button, Tabs, Checkbox, Input, Spin, Alert, Badge } from 'antd';
import { SearchOutlined, LoadingOutlined } from '@ant-design/icons';
import { useNavigate } from 'react-router-dom';
import React, { useState, useEffect, useCallback } from 'react';
import ReactDOM from 'react-dom';
import Axios from 'axios';
import { useWizard } from '../WizardContext';
import { Api } from '../../utils/Api';

/* global sdEdiAdminParams */

const SelectItemsStep = () => {
	const navigate = useNavigate();
	const { selectedDemo, importOptions, selectedIds, setSelectedIds } = useWizard();
	const [ footer,    setFooter    ] = useState( null );
	const [ back,      setBack      ] = useState( null );
	const [ types,     setTypes     ] = useState( [] );
	const [ items,     setItems     ] = useState( {} );    // { post_type: [{id, title}] }
	const [ checked,   setChecked   ] = useState( {} );    // { post_type: Set<int> }
	const [ search,    setSearch    ] = useState( {} );    // { post_type: string }
	const [ loading,   setLoading   ] = useState( {} );    // { post_type: bool }
	const [ fetched,   setFetched   ] = useState( {} );    // { post_type: bool }
	const [ error,     setError     ] = useState( null );
	const [ activeTab, setActiveTab ] = useState( null );
	const [ preparing, setPreparing ] = useState( false );

	useEffect( () => {
		const nextEl = document.getElementById( 'edi-wizard-next-slot' );
		const backEl = document.getElementById( 'edi-wizard-back-slot' );
		if ( nextEl ) setFooter( nextEl );
		if ( backEl ) setBack( backEl );
	}, [] );

	// Fetch all items on mount.
	useEffect( () => {
		if ( ! selectedDemo ) {
			navigate( '/wizard/demos' ); return;
		}

		const fetchItems = () => {
			Api.get( `/sd/edi/v1/demo-items?demo=${ selectedDemo.slug }` )
				.then( ( res ) => {
					const allItems  = res.data.items || [];
					const postTypes = ( res.data.types || [] ).filter(
						( t ) => ! [ 'attachment', 'nav_menu_item' ].includes( t )
					);

					// Group items by type and initialize checked state (everything selected by default).
					const groupedItems = {};
					const checkedMap   = {};
					const fetchedMap   = {};

					postTypes.forEach( ( type ) => {
						const typeItems = allItems.filter( ( i ) => i.post_type === type );
						groupedItems[ type ] = typeItems;
						checkedMap[ type ]   = new Set( typeItems.map( ( i ) => i.id ) );
						fetchedMap[ type ]   = true;
					} );

					setTypes( postTypes );
					setItems( groupedItems );
					setChecked( checkedMap );
					setFetched( fetchedMap );

					if ( postTypes.length > 0 ) {
						setActiveTab( postTypes[ 0 ] );
					}
					setPreparing( false );
				} )
				.catch( ( err ) => {
					if ( err.response?.data?.code === 'demo_not_downloaded' ) {
						setPreparing( true );

						const params = new FormData();
						params.append( 'action', 'sd_edi_download_demo_files' );
						params.append( 'demo', selectedDemo.slug );
						params.append( 'sd_edi_nonce', sdEdiAdminParams.sd_edi_nonce );

						Axios.post( sdEdiAdminParams.ajaxUrl, params )
							.then( () => fetchItems() )
							.catch( () => {
								setPreparing( false );
								setError( 'Could not download demo files. Check your internet connection.' );
							} );
					} else {
						setError( 'Could not load demo items. Check that the demo has been downloaded.' );
					}
				} );
		};

		fetchItems();
	}, [ selectedDemo ] ); // eslint-disable-line react-hooks/exhaustive-deps

	// Tab switching only updates UI state now.
	const loadTab = useCallback( ( postType ) => {
		setActiveTab( postType );
	}, [] );

	const handleNext = () => {
		const allIds = [];
		// Use items state to get IDs for all post types, but only if they were fetched/checked
		Object.keys( items ).forEach( ( postType ) => {
			const sel = checked[ postType ];
			if ( sel ) {
				sel.forEach( ( id ) => allIds.push( id ) );
			}
		} );

		setSelectedIds( allIds );
		navigate( '/wizard/confirm' );
	};

	const toggleItem = ( postType, id ) => {
		setChecked( ( prev ) => {
			const s = new Set( prev[ postType ] || [] );
			s.has( id ) ? s.delete( id ) : s.add( id );
			return { ...prev, [ postType ]: s };
		} );
	};

	const selectAll  = ( postType ) => setChecked( ( prev ) => ( { ...prev, [ postType ]: new Set( ( items[ postType ] || [] ).map( ( i ) => i.id ) ) } ) );
	const deselectAll = ( postType ) => setChecked( ( prev ) => ( { ...prev, [ postType ]: new Set() } ) );

	const tabItems = types.map( ( type ) => {
		const typeItems = items[ type ] || [];
		const q         = ( search[ type ] || '' ).toLowerCase();
		const filtered  = q ? typeItems.filter( ( i ) => i.title.toLowerCase().includes( q ) ) : typeItems;
		const sel       = checked[ type ] || new Set();
		const selCount  = typeItems.filter( ( i ) => sel.has( i.id ) ).length;

		return {
			key:   type,
			label: <span>{ type } <Badge count={ selCount } size="small" color="#1677ff" /></span>,
			children: (
				<div style={ { padding: '12px 0' } }>
					<div style={ { display: 'flex', gap: 8, marginBottom: 12, alignItems: 'center' } }>
						<Input
							placeholder={ `Search ${ type }…` }
							prefix={ <SearchOutlined /> }
							value={ search[ type ] || '' }
							onChange={ ( e ) => setSearch( ( p ) => ( { ...p, [ type ]: e.target.value } ) ) }
							style={ { flex: 1 } }
							allowClear
						/>
						<Button size="small" onClick={ () => selectAll( type ) }>All</Button>
						<Button size="small" onClick={ () => deselectAll( type ) }>None</Button>
					</div>

					{ loading[ type ] ? (
						<div style={ { textAlign: 'center', padding: 32 } }><Spin /></div>
					) : (
						<div style={ { maxHeight: 340, overflowY: 'auto', display: 'flex', flexDirection: 'column', gap: 4 } }>
							{ filtered.map( ( item ) => (
								<div
									key={ item.id }
									style={ {
										display: 'flex', alignItems: 'center', gap: 8,
										padding: '6px 10px', borderRadius: 6, cursor: 'pointer',
										background: sel.has( item.id ) ? '#f0f5ff' : '#fafafa',
										border: `1px solid ${ sel.has( item.id ) ? '#adc6ff' : '#f0f0f0' }`,
									} }
									onClick={ () => toggleItem( type, item.id ) }
								>
									<Checkbox checked={ sel.has( item.id ) } onChange={ () => toggleItem( type, item.id ) } />
									<span style={ { fontSize: 13 } }>{ item.title }</span>
								</div>
							) ) }
							{ filtered.length === 0 && (
								<div style={ { textAlign: 'center', color: '#8c8c8c', padding: 24 } }>No items match.</div>
							) }
						</div>
					) }

					<div style={ { marginTop: 10, color: '#8c8c8c', fontSize: 12 } }>
						{ selCount } of { typeItems.length } { type } selected
					</div>
				</div>
			),
		};
	} );

	return (
		<>
			<h2 style={ { fontSize: 18, fontWeight: 700, marginBottom: 6 } }>Select Items to Import</h2>
			<p style={ { color: '#8c8c8c', marginBottom: 20, fontSize: 14 } }>
				Choose which posts, pages, and other content to include. All items are selected by default.
			</p>

			{ error && <Alert type="error" message={ error } style={ { marginBottom: 16 } } /> }

			{ ( types.length === 0 || preparing ) && ! error ? (
				<div style={ { textAlign: 'center', padding: 40 } }>
					<Spin
						indicator={ <LoadingOutlined style={ { fontSize: 24 } } spin /> }
						tip={ preparing ? 'Preparing demo content (downloading)…' : 'Loading items…' }
					/>
				</div>
			) : (
				<Tabs
					activeKey={ activeTab }
					onChange={ ( key ) => { setActiveTab( key ); loadTab( key ); } }
					items={ tabItems }
				/>
			) }

			{ back && ReactDOM.createPortal(
				<Button onClick={ () => navigate( '/wizard/options' ) }>Back</Button>,
				back
			) }

			{ footer && ReactDOM.createPortal(
				<Button type="primary" onClick={ handleNext } disabled={ types.length === 0 }>
					Continue
				</Button>,
				footer
			) }
		</>
	);
};

export default SelectItemsStep;
