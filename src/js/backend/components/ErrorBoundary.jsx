import React, { Component } from 'react';
import ErrorMessage from './ErrorMessage';

/**
 * Error Boundary component to catch React errors.
 *
 * @since 1.2.0
 */
class ErrorBoundary extends Component {
	constructor(props) {
		super(props);
		this.state = { hasError: false, error: null };
	}

	static getDerivedStateFromError(error) {
		return { hasError: true, error };
	}

	componentDidCatch(error, errorInfo) {
		console.error('Easy Demo Importer - React Error:', error, errorInfo);
	}

	render() {
		if (this.state.hasError) {
			return (
				<div className="edi-error-boundary">
					<ErrorMessage
						message={
							this.props.message ||
							'Something went wrong while rendering this component.'
						}
					/>
					{process.env.NODE_ENV === 'development' && (
						<pre
							style={{
								padding: '10px',
								background: '#fff1f0',
								border: '1px solid #ffa39e',
								marginTop: '10px',
							}}
						>
							{this.state.error && this.state.error.toString()}
						</pre>
					)}
				</div>
			);
		}

		return this.props.children;
	}
}

export default ErrorBoundary;
