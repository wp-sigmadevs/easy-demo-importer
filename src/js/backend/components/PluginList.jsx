import React from 'react';
import { List } from 'antd';
import { getPluginText, getStatusIcon } from '../utils/helpers';

/**
 * Component to display a list of plugins.
 *
 * @param {Array} plugins - The array of plugins to display.
 */
const PluginList = ({ plugins }) => {
	if (plugins.length === 0) {
		return false;
	}

	return (
		<List
			className="edi-fade-in"
			dataSource={plugins}
			renderItem={(plugin) => (
				<List.Item>
					<List.Item.Meta
						avatar={getStatusIcon(plugin.status)}
						title={plugin.name}
						description={getPluginText(plugin.status)}
					/>
				</List.Item>
			)}
		/>
	);
};

export default PluginList;
