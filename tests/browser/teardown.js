// After the whole browser run: delete the test items a stopped or failed
// test left behind, then the team activity rows the tests made (tasks and
// decisions they created and deleted still left feed entries).
import { cleanActivity, cleanLeftovers } from './helpers.js';

export default function teardown() {
	cleanLeftovers();
	cleanActivity();
}
