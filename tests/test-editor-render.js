// Render the real list component with a minimal createElement tree (no WP install).
const fs = require('fs');
const vm = require('vm');
const assert = require('assert');
let source = fs.readFileSync(__dirname + '/../assets/js/editor.js', 'utf8');
source = source.replace("\tregisterPlugin( 'rendar-prepublish-checks', { render: PrepublishChecks } );", "\twindow.renderCheckList = CheckList;");
const el = (type, props, ...children) => ({ type, props: props || {}, children });
const wp = {
  plugins: { registerPlugin() {} },
  editor: { PluginDocumentSettingPanel() {}, PluginPrePublishPanel() {} },
  element: { createElement: el, Fragment: 'fragment', useState() {}, useEffect() {}, useRef() {} },
  data: { useSelect() {} }, coreData: { useEntityProp() {} }, apiFetch() {},
  i18n: { __: s => s, sprintf() {} },
  components: { Button() {}, Spinner() {}, TextareaControl() {}, Notice() {} }
};
const sandbox = { window: { wp, rendarPrepublish: {} } };
vm.runInNewContext(source, sandbox);
const report = { checks: [
  { id: 'acf', status: 'unavailable', severity: 'error', label: 'ACF', message: 'ACF absent', offenders: [] },
  { id: 'skip', status: 'not_applicable', severity: 'error', label: 'Skip', message: 'Skipped', offenders: [] },
  { id: 'category-ancestors', status: 'fail', severity: 'warning', label: 'Ancestors', message: 'Missing', offenders: [] }
] };
const tree = sandbox.window.renderCheckList({ report });
const items = tree.children[0];
const rows = item => item.children[0];
const byLabel = label => items.find(item => rows(item)[0].children[1].children[0] === label);
const unavailable = rows(byLabel('ACF'))[0].children[0];
assert.strictEqual(unavailable.props.className, 'rendar-pc__mark is-unavailable');
assert.strictEqual(unavailable.props['aria-label'], 'Could not be checked');
assert.strictEqual(unavailable.children[0], '?');
assert.strictEqual(rows(byLabel('Skip'))[0].children[0].props.className, 'rendar-pc__mark is-skipped');
assert.strictEqual(rows(byLabel('Ancestors')).length, 2, 'no category-specific fix button');

// Defense in depth: a malformed response must not render fields which React
// cannot use as children/keys. PHP rejects these before a real response leaves
// the server; this proves the editor also drops them if a stale cache or proxy
// hands it bad data anyway.
const malformed = sandbox.window.renderCheckList({ report: { checks: [
  {
    id: 'image-alt-text', status: 'fail', severity: 'error', label: 'Alt text', message: 'Missing',
    offenders: [
      { key: [ 'bad-key' ], label: 'Valid label' },
      { key: 'valid-key', label: [ 'bad-label' ] },
      { key: 'null-url', label: 'Null URL', edit_url: null },
      { key: 'good-key', label: 'Safe label', edit_url: '', id: 12, src: 'https://example.test/x.jpg' }
    ]
  }
] } });
const malformedRows = rows(malformed.children[0][0]);
assert.strictEqual(malformedRows.length, 3, 'valid offender list still renders');
const renderedOffenders = malformedRows[2].children[0];
assert.strictEqual(renderedOffenders.length, 1, 'array key/label offender is dropped before rendering');
assert.deepStrictEqual(renderedOffenders.map(row => row.props.offender.key), ['good-key']);
assert.strictEqual(renderedOffenders[0].props.offender.id, 12);
assert.strictEqual(renderedOffenders[0].props.offender.src, 'https://example.test/x.jpg');
assert.strictEqual(renderedOffenders[0].props.offender.edit_url, '');
console.log('editor render: OK');
