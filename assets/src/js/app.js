/**
 * NovaWork app entry.
 *
 * Alpine owns state; the app shell (Phase 3) registers its components here
 * before Alpine starts.
 */
import Alpine from 'alpinejs';
import '../css/app.css';

window.Alpine = Alpine;
Alpine.start();
