/**
 * Alpine.effect with a guard. Alpine runs effects from one shared queue, and
 * an error thrown inside one stops that queue for good: nothing on the page
 * updates any more (a closing search stays on screen, buttons stop
 * reacting). A view that fails to draw logs the error and the app goes on.
 */
import Alpine from 'alpinejs';

export const safeEffect = ( fn ) =>
	Alpine.effect( () => {
		try {
			fn();
		} catch ( e ) {
			// eslint-disable-next-line no-console
			console.error( 'NovaWork:', e );
		}
	} );
