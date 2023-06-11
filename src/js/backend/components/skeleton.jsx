import { Skeleton } from 'antd';

/**
 * Generates a grid skeleton component.
 *
 * @param {boolean} props - Indicates whether the skeleton is active.
 */
const gridSkeleton = (props) => (
	<>
		<Skeleton.Node block={true} active={props} />
		<div className="details edi-d-flex edi-align-items-center">
			<Skeleton active={props} paragraph={{ rows: 0 }} />
			<Skeleton.Button active={props} />
		</div>
	</>
);

export default gridSkeleton;
