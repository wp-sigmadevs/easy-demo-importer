/**
 * Laravel Mix Configuration File.
 */

const mix = require('laravel-mix');
const fs = require('fs-extra');
const path = require('path');
const cliColor = require('cli-color');
const emojic = require('emojic');
const archiver = require('archiver');
const min = mix.inProduction() ? '.min' : '';

const package_path = path.resolve(__dirname);
const package_slug = path.basename(path.resolve(package_path));
const temDirectory = package_path + '/dist';

mix.options({
	terser: {
		extractComments: false,
	},
	processCssUrls: false,
});

// mix.webpackConfig({
// 	stats: {
// 		children: true,
// 	},
// });

if (process.env.npm_config_package) {
	mix.then(function () {
		const copyTo = path.resolve(`${temDirectory}/${package_slug}`);

		// Select All file then paste on list
		let includes = [
			'inc',
			'assets',
			'languages',
			'lib',
			'samples',
			'vendor',
			'views',
			'composer.json',
			'index.php',
			// GPLv3 requires the licence text to travel with the distribution;
			// the plugin header and readme.txt only reference it.
			'LICENSE',
			'readme.txt',
			// WordPress runs uninstall.php on delete and it takes precedence
			// over the registered uninstall hook, whose callback is an empty
			// stub. Omitting it here shipped a plugin that cleaned up nothing.
			'uninstall.php',
			`${package_slug}.php`,
		];

		// Dev-only vendor subdirectories to exclude from the zip.
		const vendorExcludes = [
			path.join(package_path, 'vendor', 'bin'),
			path.join(package_path, 'vendor', 'phpstan'),
			path.join(package_path, 'vendor', 'rector'),
		];

		const vendorFilter = (src) =>
			!vendorExcludes.some((excluded) => src.startsWith(excluded));

		fs.ensureDir(copyTo, function (err) {
			if (err) return console.error(err);
			includes.map((include) => {
				const options =
					include === 'vendor' ? { filter: vendorFilter } : {};
				fs.copy(
					`${package_path}/${include}`,
					`${copyTo}/${include}`,
					options,
					function (err) {
						if (err) return console.error(err);
						console.log(
							cliColor.white(
								`=> ${emojic.smiley}  ${include} copied...`
							)
						);
					}
				);
			});

			console.log(
				cliColor.white(
					`=> ${emojic.whiteCheckMark}  Build directory created`
				)
			);
		});
	});

	return;
}

if (
	!process.env.npm_config_block &&
	!process.env.npm_config_package &&
	(process.env.NODE_ENV === 'development' ||
		process.env.NODE_ENV === 'production')
) {
	/*
	 * The translation template is NOT generated here. `wp-pot` dropped the
	 * WordPress.org-standard headers (X-Domain, Last-Translator, Language-Team,
	 * PO-Revision-Date) and missed the plugin-header metadata strings, so every
	 * build silently degraded the committed POT. Run `npm run translate`
	 * (wp i18n make-pot) instead.
	 */

	/**
	 * JS
	 */
	mix.js('src/js/backend.js', 'assets/js/backend.min.js').react();

	/**
	 * CSS
	 */
	if (!mix.inProduction()) {
		mix.sass(
			'src/scss/backend.scss',
			'assets/css/backend.min.css'
		).sourceMaps(true, 'source-map');
		mix.sass(
			'src/scss/backend-rtl.scss',
			'assets/css/rtl/backend-rtl.min.css'
		).sourceMaps(true, 'source-map');
	} else {
		mix.sass('src/scss/backend.scss', 'assets/css/backend.min.css');
		mix.sass(
			'src/scss/backend-rtl.scss',
			'assets/css/rtl/backend-rtl.min.css'
		);
	}

	/*
	 * The RTL chain below consumes this run's own outputs as inputs: postCss()
	 * reads assets/css/backend.min.css, which mix.sass() above produces, and
	 * combine() reads compiled-rtl.css, which postCss() produces. Webpack
	 * resolves those inputs before the producing steps have written them, so on a
	 * tree without a previous build both reads fail - and the build still exits 0,
	 * reporting success while producing no artifact.
	 *
	 * Both asset directories are gitignored, so this is the state of every fresh
	 * clone: `npm run dev` fails with "Can't resolve .../backend.min.css" until
	 * some earlier build happens to have left the files behind. Seeding empty
	 * placeholders lets the first build resolve them; the real content is written
	 * over the top during the same run.
	 *
	 * This also replaces the manual `touch assets/css/rtl/compiled-rtl.css` the
	 * release steps used to require before every production build.
	 */
	fs.ensureDirSync(path.resolve(__dirname, 'assets/css/rtl'));

	['assets/css/backend.min.css', 'assets/css/rtl/compiled-rtl.css'].forEach(
		(seed) => {
			const target = path.resolve(__dirname, seed);

			if (!fs.existsSync(target)) {
				fs.writeFileSync(target, '');
			}
		}
	);

	mix.postCss(
		'assets/css/backend.min.css',
		'assets/css/rtl/compiled-rtl.css',
		[require('rtlcss')]
	);
	mix.combine(
		[
			'assets/css/rtl/compiled-rtl.css',
			'assets/css/rtl/backend-rtl.min.css',
		],
		'assets/css/backend-rtl.min.css'
	);
}
if (process.env.npm_config_zip) {
	async function getVersion() {
		let data;
		try {
			data = await fs.readFile(
				package_path + `/${package_slug}.php`,
				'utf-8'
			);
		} catch (err) {
			console.error(err);
		}
		const lines = data.split(/\r?\n/);
		let version = '';
		for (let i = 0; i < lines.length; i++) {
			if (
				lines[i].includes('* Version:') ||
				lines[i].includes('*Version:')
			) {
				version = lines[i]
					.replace('* Version:', '')
					.replace('*Version:', '')
					.trim();
				break;
			}
		}
		return version;
	}

	const version_get = getVersion();
	version_get.then(function (version) {
		const destinationPath = `${temDirectory}/${package_slug}.${version}.zip`;
		const output = fs.createWriteStream(destinationPath);
		const archive = archiver('zip', { zlib: { level: 9 } });
		output.on('close', function () {
			console.log(archive.pointer() + ' total bytes');
			console.log(
				'Archive has been finalized and the output file descriptor has closed.'
			);
			fs.removeSync(`${temDirectory}/${package_slug}`);
		});
		output.on('end', function () {
			console.log('Data has been drained');
		});
		archive.on('error', function (err) {
			throw err;
		});

		archive.pipe(output);
		archive.directory(`${temDirectory}/${package_slug}`, package_slug);
		archive.finalize();
	});
}
