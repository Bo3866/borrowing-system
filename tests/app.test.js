const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');

function loadAppDom() {
  const indexHtml = fs.readFileSync(path.resolve(__dirname, '..', 'index.html'), 'utf8');
  let appJs = fs.readFileSync(path.resolve(__dirname, '..', 'app.js'), 'utf8');

  // Inline the script so JSDOM executes it in the window context
  const htmlWithScript = indexHtml.replace('<script src="app.js"></script>', `<script>${appJs}</script>`);

  const dom = new JSDOM(htmlWithScript, { runScripts: 'dangerously', resources: 'usable' });
  return dom;
}

describe('Borrowing System - core functions', () => {
  let dom;
  let window;

  beforeEach(() => {
    dom = loadAppDom();
    window = dom.window;
  });

  afterEach(() => {
    dom.window.close();
  });

  test('getResourceTypeLabel returns correct label', () => {
    const labelSpace = window.getResourceTypeLabel('space');
    const labelEquip = window.getResourceTypeLabel('equipment');
    expect(labelSpace).toBe('空間');
    expect(labelEquip).toBe('器材');
  });

  test('checkTimeConflict detects overlapping application', () => {
    // There is an existing application app001 for res001: 2024-05-15T09:00 - 2024-05-15T12:00
    const start = new Date('2024-05-15T10:00');
    const end = new Date('2024-05-15T11:00');
    const conflict = window.checkTimeConflict('res001', start, end);
    expect(conflict).toBeTruthy();
    expect(conflict.id).toBe('app001');
  });

  test('filterResourcesData respects type and search filters', () => {
    // setup DOM filter values
    const select = window.document.getElementById('resourceType');
    const search = window.document.getElementById('searchKeyword');

    select.value = 'equipment';
    search.value = '投影機';

    const results = window.filterResourcesData();
    expect(results.length).toBeGreaterThan(0);
    expect(results.every(r => r.type === 'equipment')).toBe(true);
    expect(results.some(r => r.name.includes('投影機') || r.description.includes('投影機'))).toBe(true);
  });
});
