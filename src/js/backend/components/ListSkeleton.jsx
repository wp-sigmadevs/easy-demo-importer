import { Skeleton } from 'antd';

/**
 * Generates a grid skeleton component.
 *
 * @param {boolean} props - Indicates whether the skeleton is active.
 */
const ListSkeleton = (props) => (
	<>
		<div className="list-skeleton details">
			<Skeleton.Button active={props} />
			<Skeleton active={props} />
		</div>
	</>
);

export default ListSkeleton;
