import React, { useEffect, useState } from 'react';

const params = window.sdEdiNetworkParams || {};
const i18n = params.i18n || {};

export default function Dashboard() {
	const [sites, setSites] = useState([]);
	const [loading, setLoading] = useState(true);
	const [err, setErr] = useState('');

	useEffect(() => {
		fetch(params.restUrl + 'network/status', {
			headers: { 'X-WP-Nonce': params.restNonce },
		})
			.then(async (r) => {
				const data = await r.json().catch(() => ({}));
				if (!r.ok) {
					throw new Error(
						(data && data.message) ||
							`HTTP ${r.status} ${r.statusText || ''}`.trim()
					);
				}
				return data;
			})
			.then((d) => setSites(d.sites || []))
			.catch((e) => setErr(String(e.message || e)))
			.finally(() => setLoading(false));
	}, []);

	if (loading) return <p>{i18n.loading || 'Loading…'}</p>;
	if (err) return <p className="error">{err}</p>;

	return (
		<table className="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th>Blog ID</th>
					<th>Domain</th>
					<th>{i18n.lastImport}</th>
					<th>Demo</th>
					<th>Has Table</th>
					<th>Action</th>
				</tr>
			</thead>
			<tbody>
				{sites.map((s) => (
					<tr key={s.blog_id}>
						<td>{s.blog_id}</td>
						<td>
							<a href={s.site_url} target="_blank" rel="noreferrer">
								{s.domain}
							</a>
						</td>
						<td>{s.last_import || i18n.noImport}</td>
						<td>{s.demo || '—'}</td>
						<td>{s.has_table ? '✓' : '—'}</td>
						<td>
							<a
								href={
									s.site_url +
									'/wp-admin/themes.php?page=sd-easy-demo-importer'
								}
								target="_blank"
								rel="noreferrer"
								className="button"
							>
								{i18n.openInSubsite}
							</a>
						</td>
					</tr>
				))}
			</tbody>
		</table>
	);
}
