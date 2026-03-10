import { Steps } from 'antd';
import { Outlet, useNavigate, useLocation } from 'react-router-dom';
import { useEffect, useRef } from 'react';
import { WizardProvider, useWizard } from './WizardContext';
import './wizard.css';

/* global sdEdiAdminParams */

const STEPS = [
	{ key: 'welcome',      title: 'Welcome'      },
	{ key: 'requirements', title: 'Requirements' },
	{ key: 'plugins',      title: 'Plugins'      },
	{ key: 'demos',        title: 'Select Demo'  },
	{ key: 'options',      title: 'Options'      },
	{ key: 'confirm',      title: 'Confirm'      },
	{ key: 'importing',    title: 'Importing'    },
	{ key: 'regen',        title: 'Images'       },
	{ key: 'complete',     title: 'Done'         },
];

/**
 * Inner layout — needs WizardProvider to already be in the tree.
 */
const WizardShell = () => {
	const navigate    = useNavigate();
	const location    = useLocation();
	const { direction, setDirection } = useWizard();
	const prevStepRef = useRef( -1 );

	const currentKey  = location.pathname.split( '/' ).pop();
	const currentIdx  = STEPS.findIndex( ( s ) => s.key === currentKey );
	const stepCounter = currentIdx >= 0 ? `Step ${ currentIdx + 1 } of ${ STEPS.length }` : '';

	// Set slide direction on navigation.
	useEffect( () => {
		if ( prevStepRef.current >= 0 ) {
			setDirection( currentIdx >= prevStepRef.current ? 'forward' : 'backward' );
		}
		prevStepRef.current = currentIdx;
	}, [ currentIdx, setDirection ] );

	const antSteps = STEPS.map( ( s, i ) => ( {
		title:  s.title,
		status: i < currentIdx ? 'finish' : i === currentIdx ? 'process' : 'wait',
	} ) );

	const handleBack = () => {
		if ( currentIdx > 0 ) {
			navigate( `/wizard/${ STEPS[ currentIdx - 1 ].key }` );
		} else {
			navigate( '/' );
		}
	};

	return (
		<div className="edi-wizard-shell">
			<div className="edi-wizard-header">
				<div className="edi-wizard-meta">
					<h1>{ sdEdiAdminParams.pluginName || 'Demo Importer' }</h1>
					{ stepCounter && (
						<span className="edi-wizard-counter">{ stepCounter }</span>
					) }
				</div>
				<Steps
					current={ currentIdx }
					items={ antSteps }
					size="small"
					labelPlacement="vertical"
				/>
			</div>

			<div
				className={ `edi-wizard-body edi-wizard-step-enter-${ direction }` }
				key={ currentKey }
			>
				<Outlet />
			</div>

			<div className="edi-wizard-footer">
				<span
					id="edi-wizard-back-slot"
					style={ { display: 'contents' } }
				/>
				<span
					id="edi-wizard-next-slot"
					style={ { display: 'contents' } }
				/>
			</div>
		</div>
	);
};

/**
 * WizardLayout — route element that provides WizardContext and the shell.
 * Used as the parent route for all /wizard/* routes.
 */
const WizardLayout = () => (
	<WizardProvider>
		<WizardShell />
	</WizardProvider>
);

export default WizardLayout;
