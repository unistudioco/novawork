/**
 * The WordPress.org plugin page's icon and banner, into .wordpress-org/
 * (SVN assets/): icon-128x128.png, icon-256x256.png, banner-772x250.png,
 * banner-1544x500.png.
 *
 * The icon is the app's mark (the flag on blue, as in the header). The
 * banner puts the name and one line beside the workspace on a computer
 * (the board, a crop of screenshot-2.png) and on a phone (screenshot-11.png), so run
 * bin/screenshots.mjs first. Run with Fontshare reachable, so Satoshi and
 * Supreme show (otherwise it says so and uses a fallback):
 *
 *   node bin/wporg-assets.mjs
 *
 * Uses the installed Chrome; set NOVAWORK_CHROME to a browser binary to use
 * another one.
 */
import { chromium } from '@playwright/test';
import { readFileSync } from 'node:fs';

const OUT = '.wordpress-org/';
const FONTS = 'https://api.fontshare.com/v2/css?f%5B%5D=satoshi@500,700&f%5B%5D=supreme@400,500&display=swap';
const BLUE = '#2065f5';
// Phosphor flag-banner-fold (fill), as App\Branding::flag().
const FLAG = 'M131.79,69.65l-43.63,96A4,4,0,0,1,84.52,168H28.23a8.2,8.2,0,0,1-6.58-3.13,8,8,0,0,1,.43-10.25L57.19,116,22.08,77.38a8,8,0,0,1-.43-10.26A8.22,8.22,0,0,1,28.23,64h99.92A4,4,0,0,1,131.79,69.65ZM237.56,42.24A8.3,8.3,0,0,0,231.77,40H168a8,8,0,0,0-7.28,4.69l-42.57,93.65a4,4,0,0,0,3.64,5.66h57.79l-34.86,76.69a8,8,0,1,0,14.56,6.62l80-176A8,8,0,0,0,237.56,42.24Z';
const flag = ( size ) => `<svg width="${ size }" height="${ size }" viewBox="0 0 256 256"><path fill="#fff" d="${ FLAG }"/></svg>`;
const png = ( file ) => 'data:image/png;base64,' + readFileSync( OUT + file ).toString( 'base64' );
const shot = png( 'screenshot-2.png' );
const phone = png( 'screenshot-11.png' );

const HEAD = `<link rel="stylesheet" href="${ FONTS }"><style>
html,body{margin:0}
body{font-family:Supreme,Inter,ui-sans-serif,system-ui,sans-serif;-webkit-font-smoothing:antialiased}
.mark{display:grid;place-items:center;background:${ BLUE }}
</style>`;

// Icon: edge to edge (the directory rounds it), the flag at the header mark's share (20/32).
const ICON = `${ HEAD }<div class="mark" id="art" style="width:128px;height:128px">${ flag( 80 ) }</div>`;

// Banner, laid out at 772x250 and rendered at 1x and 2x.
const BANNER = `${ HEAD }<style>
#art{position:relative;width:772px;height:250px;overflow:hidden;background:#f7f5f2}
.copy{position:absolute;left:48px;top:0;bottom:0;width:300px;display:flex;flex-direction:column;justify-content:center}
.brand{display:flex;align-items:center;gap:9px}
.brand .mark{width:28px;height:28px;border-radius:7px}
.name{font:700 21px/1 Satoshi,Inter,sans-serif;letter-spacing:-.01em;color:#1d1919}
.line{margin:20px 0 0;font:700 24px/1.2 Satoshi,Inter,sans-serif;letter-spacing:-.015em;color:#1d1919;white-space:nowrap}
.line em{font-style:normal;color:${ BLUE }}
.sub{margin:10px 0 0;font:400 13.5px/1.5 Supreme,Inter,sans-serif;color:#6f6964;white-space:nowrap}
.frame{position:absolute;left:500px;top:30px;width:420px;height:260px;border-radius:14px;overflow:hidden;background:#fff;border:1px solid #e6e1db;box-shadow:0 24px 60px -24px rgba(29,25,25,.28)}
.frame img{display:block;width:520px;margin:-6px 0 0 -22px}
.phone{position:absolute;left:392px;top:40px;width:124px;height:268px;box-sizing:border-box;border:4px solid #1d1919;border-radius:24px;overflow:hidden;background:#f7f5f2;padding-top:16px;box-shadow:0 22px 50px -18px rgba(29,25,25,.38)}
.phone img{display:block;width:100%}
.phone .island{position:absolute;left:50%;top:6px;width:34px;height:10px;margin-left:-17px;border-radius:6px;background:#1d1919}
</style><div id="art"><div class="copy"><div class="brand"><div class="mark">${ flag( 17 ) }</div><div class="name">NovaWork</div></div>
<p class="line">Less noise. <em>More done.</em></p>
<p class="sub">10x your team's productivity with focus,<br>a board, a roadmap, decisions and chat,<br>right inside WordPress. Free, on any device.</p></div>
<div class="frame"><img src="${ shot }" alt=""></div>
<div class="phone"><img src="${ phone }" alt=""><span class="island"></span></div></div>`;

const browser = await chromium.launch( process.env.NOVAWORK_CHROME ? { executablePath: process.env.NOVAWORK_CHROME } : { channel: 'chrome' } );

async function render( html, scale, file, words = false ) {
	const page = await browser.newPage( { deviceScaleFactor: scale, viewport: { width: 900, height: 400 } } );
	await page.setContent( html, { waitUntil: 'networkidle' } ).catch( () => page.setContent( html ) );
	await page.evaluate( () => document.fonts.ready );
	// check() says yes when no Satoshi face exists at all, so look for loaded faces.
	const real = await page.evaluate( () => [ 'Satoshi', 'Supreme' ].every( ( name ) => [ ...document.fonts ].some( ( f ) => f.family.replace( /"/g, '' ) === name && f.status === 'loaded' ) ) );
	await page.locator( '#art' ).screenshot( { path: OUT + file } );
	await page.close();
	console.log( `wrote ${ OUT }${ file }${ real || ! words ? '' : '  (Fontshare unreachable: fallback font; run again where it is reachable)' }` );
}

await render( ICON, 2, 'icon-256x256.png' );
await render( ICON, 1, 'icon-128x128.png' );
await render( BANNER, 2, 'banner-1544x500.png', true );
await render( BANNER, 1, 'banner-772x250.png', true );
await browser.close();
