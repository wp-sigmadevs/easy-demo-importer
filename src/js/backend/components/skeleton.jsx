import { Skeleton } from 'antd';

/**
 * Generates a grid skeleton component.
 *
 * @param {boolean} props - Indicates whether the skeleton is active.
 */
const gridSkeleton = (props) => (
	<>
		<Skeleton.Node
			block={true}
			active={props}
			style={{ width: '100%', height: 200 }}
		>
			<p></p>
		</Skeleton.Node>
		<Skeleton
			style={{
				padding: '10px',
			}}
			active={props}
		/>
	</>
);

export default gridSkeleton;
