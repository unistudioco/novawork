/**
 * Renders the installable app's icon layers (assets/app-icons/, 1.1.0).
 *
 * - shape.png, flag.png: black layers, 512 px. App\WebApp colours them with
 *   the workspace's accent (GD) so the app icon matches the header's mark:
 *   the accent as a rounded square with the flag on it.
 * - icon-*.png: the default blue icons, used when the server has no GD.
 *
 * Run after changing the mark: node bin/app-icons.mjs
 */
import { chromium } from 'playwright';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const OUT = join( dirname( fileURLToPath( import.meta.url ) ), '..', 'assets', 'app-icons' );
// Phosphor flag-banner-fold (fill), as App\Branding::flag().
const FLAG = 'M131.79,69.65l-43.63,96A4,4,0,0,1,84.52,168H28.23a8.2,8.2,0,0,1-6.58-3.13,8,8,0,0,1,.43-10.25L57.19,116,22.08,77.38a8,8,0,0,1-.43-10.26A8.22,8.22,0,0,1,28.23,64h99.92A4,4,0,0,1,131.79,69.65ZM237.56,42.24A8.3,8.3,0,0,0,231.77,40H168a8,8,0,0,0-7.28,4.69l-42.57,93.65a4,4,0,0,0,3.64,5.66h57.79l-34.86,76.69a8,8,0,1,0,14.56,6.62l80-176A8,8,0,0,0,237.56,42.24Z';
const BLUE = '#2065f5';

/**
 * One icon as SVG.
 *
 * @param {object} o
 * @param {number} o.size     Pixels.
 * @param {string} o.bg       Background colour, or 'none'.
 * @param {number} o.radius   Corner radius as a share of the size (0 = full bleed).
 * @param {string} o.fg       Flag colour, or 'none'.
 * @param {number} o.flag     Flag box as a share of the size (the header's mark: 20/32).
 */
function svg( { size, bg, radius, fg, flag } ) {
	const box = size * flag;
	const at = ( size - box ) / 2;
	return `<svg xmlns="http://www.w3.org/2000/svg" width="${ size }" height="${ size }" viewBox="0 0 ${ size } ${ size }">`
		+ ( bg === 'none' ? '' : `<rect width="${ size }" height="${ size }" rx="${ size * radius }" fill="${ bg }"/>` )
		+ ( fg === 'none' ? '' : `<svg x="${ at }" y="${ at }" width="${ box }" height="${ box }" viewBox="0 0 256 256"><path fill="${ fg }" d="${ FLAG }"/></svg>` )
		+ '</svg>';
}

const MARK = 20 / 32; // The flag inside the header's 32 px mark.
const RADIUS = 8 / 32; // Its corners.
const files = {
	'shape.png': { size: 512, bg: '#000', radius: RADIUS, fg: 'none', flag: MARK },
	'flag.png': { size: 512, bg: 'none', radius: 0, fg: '#000', flag: MARK },
	'icon-192.png': { size: 192, bg: BLUE, radius: RADIUS, fg: '#fff', flag: MARK },
	'icon-512.png': { size: 512, bg: BLUE, radius: RADIUS, fg: '#fff', flag: MARK },
	// Android crops maskable icons to its own shape: full bleed, the flag inside the safe circle.
	'icon-maskable-512.png': { size: 512, bg: BLUE, radius: 0, fg: '#fff', flag: MARK * 0.8 },
	// iOS rounds the corners itself and shows transparency as black: full bleed.
	'icon-apple-180.png': { size: 180, bg: BLUE, radius: 0, fg: '#fff', flag: MARK },
};

const browser = await chromium.launch( process.env.NOVAWORK_CHROME ? { executablePath: process.env.NOVAWORK_CHROME } : { channel: 'chrome' } );
const page = await browser.newPage();
for ( const [ name, o ] of Object.entries( files ) ) {
	await page.setViewportSize( { width: o.size, height: o.size } );
	await page.setContent( `<style>html,body{margin:0;background:transparent}svg{display:block}</style>${ svg( o ) }` );
	await page.locator( 'body > svg' ).screenshot( { path: join( OUT, name ), omitBackground: true } );
	console.log( 'wrote assets/app-icons/' + name );
}
await browser.close();
