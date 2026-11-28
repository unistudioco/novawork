/**
 * Browser tests never reach the internet: every page may load only the dev
 * site (and localhost). Any other request is aborted, and the test that made
 * it fails, naming the address (offline-guard.js). Specs import
 * { test, expect } from here, not from @playwright/test. (The site's own
 * outgoing requests are blocked by bin/offline.sh and the
 * tests/fixtures/test-offline.php mu-plugin; teardown.js fails on those.)
 */
import { test as base, expect } from '@playwright/test';
import { isLocal, offlineFixtures } from './offline-guard.js';

export const test = base.extend( offlineFixtures( expect ) );

export { expect, isLocal };
