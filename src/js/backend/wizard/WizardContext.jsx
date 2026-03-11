import { createContext, useContext, useState, useCallback } from 'react';

const WizardContext = createContext( null );

const DEFAULT_OPTIONS = {
	content:        true,
	media:          true,
	customizer:     true,
	widgets:        true,
	menus:          true,
	pluginSettings: true,
	regenImages:    true,
	resetDb:        false,
};

/**
 * WizardProvider — wraps all /wizard/* routes.
 * Holds state that lives for the duration of one import wizard session.
 */
export const WizardProvider = ( { children } ) => {
	const [ selectedDemo,  setSelectedDemo  ] = useState( null );
	const [ importOptions, setImportOptions ] = useState( DEFAULT_OPTIONS );
	const [ dryRunStats,   setDryRunStats   ] = useState( null );
	const [ importProgress, setImportProgress ] = useState( { done: 0, total: 0, currentTitle: '' } );
	const [ direction, setDirection ] = useState( 'forward' );

	const updateOption = useCallback( ( key, value ) => {
		setImportOptions( ( prev ) => ( { ...prev, [ key ]: value } ) );
	}, [] );

	const resetWizard = useCallback( () => {
		setSelectedDemo( null );
		setImportOptions( DEFAULT_OPTIONS );
		setDryRunStats( null );
		setImportProgress( { done: 0, total: 0, currentTitle: '' } );
		setDirection( 'forward' );
	}, [] );

	return (
		<WizardContext.Provider value={ {
			selectedDemo,  setSelectedDemo,
			importOptions, updateOption,
			dryRunStats,   setDryRunStats,
			importProgress, setImportProgress,
			direction,     setDirection,
			resetWizard,
		} }>
			{ children }
		</WizardContext.Provider>
	);
};

/**
 * useWizard — consume wizard context from any step component.
 *
 * @throws {Error} If used outside WizardProvider.
 */
export const useWizard = () => {
	const ctx = useContext( WizardContext );

	if ( ! ctx ) {
		throw new Error( 'useWizard must be used inside WizardProvider' );
	}

	return ctx;
};
