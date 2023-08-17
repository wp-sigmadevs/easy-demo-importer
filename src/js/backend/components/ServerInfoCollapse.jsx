import React from 'react';
import { Collapse, Table, Popover, Alert } from 'antd';
import { InfoCircleOutlined } from '@ant-design/icons';

const ServerInfoCollapse = ({ serverInfo }) => {
	const { Panel } = Collapse;

	return (
		<Collapse defaultActiveKey={[Object.keys(serverInfo)[0]]}>
			{Object.keys(serverInfo).map((key) => {
				const serverItem = {
					...serverInfo[key],
					id: key,
				};

				return (
					<Panel key={serverItem.id} header={serverItem.label}>
						<Table
							dataSource={Object.keys(serverItem.fields).map(
								(fieldKey) => ({
									...serverItem.fields[fieldKey],
									key: fieldKey,
								})
							)}
							columns={[
								{
									dataIndex: 'label',
									key: 'label',
								},
								{
									dataIndex: 'value',
									key: 'value',
									render: (text, record) => {
										if (record.error) {
											return (
												<span className="error-value">
													<span>{text}</span>
													<Popover
														content={
															<Alert
																message="Error"
																description={
																	record.error
																}
																type="error"
																showIcon
															/>
														}
													>
														<InfoCircleOutlined
															style={{
																marginLeft:
																	'4px',
															}}
														/>
													</Popover>
												</span>
											);
										}
										return text;
									},
								},
							]}
							pagination={false}
							rowClassName={(record, index) =>
								index % 2 === 0 ? 'even-row' : 'odd-row'
							}
						/>
					</Panel>
				);
			})}
		</Collapse>
	);
};

export default ServerInfoCollapse;
