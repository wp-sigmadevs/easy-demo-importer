import { Skeleton } from 'antd';
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
