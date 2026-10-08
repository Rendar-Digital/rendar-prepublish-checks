// Isolated assertions for the editor issue watcher. No browser or outbound REST.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../assets/js/editor.js'), 'utf8');
const win = {
  rendarPrepublish: {}, rendarPrepublishTestHooks: {},
  wp: {
    plugins: { registerPlugin() {} }, editor: {},
    element: { createElement() {}, useState() {}, useEffect() {}, useRef() {} },
    data: { useSelect() {} }, coreData: { useEntityProp() {} }, apiFetch() {},
    i18n: { __(s) { return s; }, sprintf(s, ...args) { let n = 0; return s.replace(/%s/g, () => String(args[n++])); }, _n() {} },
    components: {}
  }
};
vm.runInNewContext(source, { window: win, Promise, JSON, Math, Date });
const { createIssueWatcher, issueMessage } = win.rendarPrepublishTestHooks;
const issue = { post_status: 'pending', active: true, issue: {
  source: 'backstop', time: '2026-09-25', intended_status: 'future',
  scheduled_display: 'January 15, 2030', checks: ['image'], messages: ['Image: Missing']
} };
function harness(responses, initial) {
  const calls = { fetch: 0, refresh: 0, notices: [], issues: [] };
  let status = initial;
  const watcher = createIssueWatcher({
    fetchIssue() { calls.fetch++; return Promise.resolve(responses.shift()); },
    getSavedStatus() { return status; },
    refreshPost() { calls.refresh++; status = 'pending'; return Promise.resolve(); },
    notify(s) { calls.notices.push(s); }, onIssue(r) { calls.issues.push(r); }
  });
  return { watcher, calls };
}
(async () => {
  let { watcher, calls } = harness([issue, issue], 'future');
  await watcher.check('saved'); await watcher.check('saved');
  assert.equal(calls.refresh, 1);
  assert.equal(calls.notices.length, 1);
  assert.match(calls.notices[0], /NOT scheduled/);
  assert.match(calls.notices[0], /Image: Missing/);
  assert.equal(calls.issues[0], issue);
  ({ watcher, calls } = harness([issue], 'pending'));
  await watcher.check('mount');
  assert.equal(calls.notices.length, 0);
  ({ watcher, calls } = harness([{post_status:'future', active:false, issue:null}], 'future'));
  await watcher.check('saved');
  assert.equal(calls.notices.length, 0);
  const cron = { ...issue, issue: { ...issue.issue, source: 'cron' } };
  ({ watcher, calls } = harness([cron], 'pending'));
  await watcher.check('saved');
  assert.equal(calls.notices.length, 0);
  assert.match(issueMessage(cron), /scheduled for January 15, 2030/);
  ({ watcher, calls } = harness([{post_status:'future',active:false,issue:null}, issue], 'future'));
  const first = watcher.check('mount'); watcher.check('saved'); watcher.check('saved');
  await first; await new Promise(resolve => setTimeout(resolve, 0));
  assert.equal(calls.fetch, 2);
  assert.equal(calls.notices.length, 1);
  await createIssueWatcher({fetchIssue: () => Promise.reject(Error('offline')), getSavedStatus: () => 'future', refreshPost() {}, notify() {}, onIssue() {}}).check('saved');
  console.log('issue watcher: saved/mount/cron/overlap/offline assertions passed');
})().catch(e => { console.error(e); process.exitCode = 1; });
