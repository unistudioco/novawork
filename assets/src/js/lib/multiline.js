/**
 * Message fields (chat, comments, editing in place) are textareas that grow
 * with their text, up to a few lines, then scroll. Enter sends; Shift+Enter
 * starts a new line (owner request, 2026-11-22).
 *
 * Enter does nothing here while an input method is composing (Japanese,
 * Chinese…), or when an earlier handler already took the key: the @ and /
 * suggestion list picks with Enter, and it is attached first.
 */

/**
 * Size a field to its text (CSS caps the height and scrolls past it).
 * Call it after changing the value from code; typing fits by itself.
 *
 * @param {HTMLTextAreaElement} field The field.
 */
export function fit( field ) {
	if ( ! field || field.tagName !== 'TEXTAREA' ) {
		return;
	}
	field.style.height = 'auto';
	field.style.height = field.scrollHeight + 'px';
}

/**
 * Whether a keydown is a plain Enter that should send.
 *
 * @param {KeyboardEvent} e The event.
 */
export const isSendKey = ( e ) => e.key === 'Enter' && ! e.shiftKey && ! e.isComposing && e.keyCode !== 229 && ! e.defaultPrevented;

/**
 * Make a textarea grow with its text and, with `onEnter`, send on Enter.
 *
 * @param {HTMLTextAreaElement} field   The field.
 * @param {Function}            [onEnter] Called on a plain Enter.
 */
export function multiline( field, onEnter ) {
	field.addEventListener( 'input', () => fit( field ) );
	if ( onEnter ) {
		field.addEventListener( 'keydown', ( e ) => {
			if ( isSendKey( e ) ) {
				e.preventDefault();
				onEnter( e );
			}
		} );
	}
	fit( field );
}
