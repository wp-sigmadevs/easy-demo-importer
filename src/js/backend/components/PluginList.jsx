import React from 'react';
import { List } from 'antd';
import { getPluginText, getStatusIcon } from '../utils/helpers';

/**
 * Component for displaying a list of plugins.
 *
 * @param {Object} props         - Component properties.
 * @param {Array}  props.plugins - The array of plugins to display.
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
