// After the whole browser run: remove the team activity rows the tests made
// (tasks and decisions they created and deleted still left feed entries).
import { cleanActivity } from './helpers.js';

export default function teardown() {
	cleanActivity();
}
