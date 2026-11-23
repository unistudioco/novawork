/**
 * The member's last activity on this device (a key, a click, a touch, a
 * scroll, or the Idle Detection API saying they are back).
 *
 * views/idle.js keeps it current; sync.js sends it with each poll, so the
 * server knows the latest activity on any of the member's devices and every
 * device's "Still working?" rule can use it (owner report, 2026-11-23: the
 * phone asked, and paused the timer, while the laptop was in use).
 */
let last = Date.now();

/** Local time (ms) of the last activity here. */
export const lastActive = () => last;

/**
 * Note activity.
 *
 * @param {number} [at] Local time; now by default.
 */
export function markActive( at = Date.now() ) {
	last = at;
}
