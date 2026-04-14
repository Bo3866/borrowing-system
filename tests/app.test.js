import { describe, it, expect, vi } from 'vitest';
import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const rootDir = resolve(process.cwd());
const indexHtml = readFileSync(resolve(rootDir, 'index.html'), 'utf8');
const appScript = readFileSync(resolve(rootDir, 'app.js'), 'utf8');

function createAppWindow() {
  const dom = new JSDOM(indexHtml, {
    url: 'http://localhost/',
    runScripts: 'outside-only',
    pretendToBeVisual: true
  });

  const { window } = dom;
  window.alert = vi.fn();
  window.confirm = vi.fn(() => true);
  window.prompt = vi.fn(() => null);
  window.scrollTo = vi.fn();

  window.eval(appScript);
  window.document.dispatchEvent(new window.Event('DOMContentLoaded'));

  return window;
}

describe('borrowing-system app unit tests', () => {
  it('navigateTo should block student role from admin page', () => {
    const window = createAppWindow();

    window.navigateTo('admin');

    expect(window.alert).toHaveBeenCalledWith('您沒有權限訪問此頁面');
    expect(window.document.getElementById('dashboard').classList.contains('active')).toBe(true);
    expect(window.document.getElementById('admin').classList.contains('active')).toBe(false);
  });

  it('filterResourcesData should filter by type and keyword', () => {
    const window = createAppWindow();

    window.document.getElementById('resourceType').value = 'equipment';
    window.document.getElementById('searchKeyword').value = '投影';

    const result = window.filterResourcesData();

    expect(result).toHaveLength(1);
    expect(result[0].id).toBe('res003');
  });

  it('checkTimeConflict should return overlapping approved application', () => {
    const window = createAppWindow();

    const conflict = window.checkTimeConflict(
      'res001',
      new window.Date('2024-05-15T10:00'),
      new window.Date('2024-05-15T11:00')
    );

    expect(conflict).toBeTruthy();
    expect(conflict.id).toBe('app001');
  });

  it('checkTimeConflict should ignore rejected application', () => {
    const window = createAppWindow();

    const conflict = window.checkTimeConflict(
      'res003',
      new window.Date('2024-05-18T10:30'),
      new window.Date('2024-05-18T11:00')
    );

    expect(conflict).toBeUndefined();
  });

  it('submitBorrowApplication should validate empty applicant name', () => {
    const window = createAppWindow();

    const form = window.document.getElementById('borrowForm');
    form.dataset.resourceId = 'res001';

    window.document.getElementById('modalResourceName').value = '教室A101';
    window.document.getElementById('applicantName').value = '';
    window.document.getElementById('applicantPhone').value = '0912345678';
    window.document.getElementById('startDateTime').value = '2026-04-15T10:00';
    window.document.getElementById('endDateTime').value = '2026-04-15T11:00';
    window.document.getElementById('purpose').value = '測試用途';

    const event = { preventDefault: vi.fn() };
    window.submitBorrowApplication(event);

    expect(event.preventDefault).toHaveBeenCalled();
    expect(window.alert).toHaveBeenCalledWith('錯誤：請填寫申請人姓名');
  });

  it('renderResources should show empty state when no resource matches filters', () => {
    const window = createAppWindow();

    window.document.getElementById('searchKeyword').value = '不存在的關鍵字';
    window.renderResources();

    const resourcesList = window.document.getElementById('resourcesList');
    expect(resourcesList.textContent).toContain('沒有找到符合條件的資源');
  });
});
