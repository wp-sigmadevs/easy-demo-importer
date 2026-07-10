import React, { useEffect, useRef, useState } from 'react';

/**
 * Determinate/indeterminate progress bar for an active import phase card.
 *
 * The track fill uses the same brand gradient as the top step line, with a
 * moving diagonal stripe overlay. Until a numeric percentage is available the
 * bar is indeterminate (a narrow fill sweeps across); once a number arrives it
 * becomes determinate. The displayed percentage is tweened toward each new
 * target with requestAnimationFrame, so it counts up (…58, 59, 60) instead of
 * snapping — the server only reports progress once per batch window, and this
 * smooths the gap between those discrete points.
 *
 * @param {Object}  props         - Component properties.
 * @param {?number} props.percent - Progress percentage (0-100), or null while indeterminate.
 */
export const ImportBar = ({ percent = null }) => {
	const [display, setDisplay] = useState(percent ?? 0);
	const displayRef = useRef(display);
	const rafRef = useRef(0);

	displayRef.current = display;

	/**
	 * Tween the shown number from its current value to the latest target.
	 */
	useEffect(() => {
		if (percent === null) {
			return undefined;
		}

		const from = displayRef.current;
		const to = percent;

		if (from === to) {
			return undefined;
		}

		const duration = 500;
		let startTime = null;

		const tick = (now) => {
			if (startTime === null) {
				startTime = now;
			}

			const progress = Math.min(1, (now - startTime) / duration);

			setDisplay(Math.round(from + (to - from) * progress));

			if (progress < 1) {
				rafRef.current = requestAnimationFrame(tick);
			}
		};

		rafRef.current = requestAnimationFrame(tick);

		return () => cancelAnimationFrame(rafRef.current);
	}, [percent]);

	const isIndeterminate = percent === null;

	return (
		<div className="sd-edi-import-progress">
			<div
				className={`sd-edi-import-bar${
					isIndeterminate ? ' is-indeterminate' : ''
				}`}
			>
				<div
					className="sd-edi-import-bar-fill"
					style={
						isIndeterminate ? undefined : { width: `${display}%` }
					}
				/>
			</div>
			{!isIndeterminate && (
				<span className="sd-edi-import-percent">{display}%</span>
			)}
		</div>
	);
};
