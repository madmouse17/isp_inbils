import { chromium } from '@playwright/test';

const baseURL = 'http://127.0.0.1:8000';
const roles = ['admin', 'manager', 'staff', 'technician', 'customer'];
const expectedByRole = {
  admin: ['Dashboard','Organization','Company','Users','Roles','Permissions','Customers','Locations','Employees','Vehicles','Documents','Number Sequences','Service','Bandwidth Profiles','Speed Profiles','SLA Tiers','Inventory','Categories','Units','Stocks','Stock Movements','Network Assets','SPK','Billing','Tunggakan','Ticketing','Evaluations','Reports','Komponen'],
  manager: ['Dashboard','Customers','Locations','Service','Bandwidth Profiles','Speed Profiles','SLA Tiers','Inventory','Categories','Units','Stocks','Stock Movements','Network Assets','SPK','Billing','Tunggakan','Ticketing','Evaluations','Reports','Komponen'],
  staff: ['Dashboard','Customers','Locations','Service','Bandwidth Profiles','Speed Profiles','SLA Tiers','Inventory','Categories','Units','Stocks','Stock Movements','Network Assets','SPK','Billing','Tunggakan','Ticketing','Evaluations','Reports','Komponen'],
  technician: ['Dashboard','Customers','Locations','Inventory','Categories','Units','Stocks','Stock Movements','Network Assets','SPK','Ticketing','Evaluations','Komponen'],
  customer: ['Dashboard','Ticketing','Komponen'],
};

const forbiddenByRole = {
  manager: ['Organization','Company','Users','Roles','Permissions','Employees','Vehicles','Documents','Number Sequences'],
  staff: ['Organization','Company','Users','Roles','Permissions','Employees','Vehicles','Documents','Number Sequences'],
  technician: ['Organization','Company','Users','Roles','Permissions','Employees','Vehicles','Documents','Number Sequences','Service','Billing','Tunggakan','Reports'],
  customer: ['Organization','Company','Users','Roles','Permissions','Customers','Locations','Employees','Vehicles','Documents','Number Sequences','Service','Inventory','Network Assets','SPK','Billing','Tunggakan','Evaluations','Reports'],
};

const out = [];
const issues = [];
const browser = await chromium.launch({ headless: true });

for (const role of roles) {
  const page = await browser.newPage({ baseURL });
  const consoleErrors = [];
  page.on('console', msg => { if (['error', 'warning'].includes(msg.type())) consoleErrors.push(`${msg.type()}: ${msg.text()}`); });
  page.on('pageerror', err => consoleErrors.push(`pageerror: ${err.message}`));
  await page.goto('/login');
  await page.getByLabel('Email').fill(`${role}@smoke.test`);
  await page.getByLabel('Password').fill('password123');
  await page.getByRole('button', { name: 'Log in' }).click();
  await page.waitForLoadState('networkidle');
  const loginUrl = page.url();
  if (!loginUrl.includes('/admin/')) {
    issues.push({ role, url: loginUrl, type: 'login', detail: 'login did not land on /admin' });
  }

  const menu = [];
  for (const label of expectedByRole.admin) {
    if (await page.getByRole('link', { name: label, exact: true }).count()) menu.push(label);
  }
  const missing = expectedByRole[role].filter(x => !menu.includes(x));
  const forbiddenVisible = (forbiddenByRole[role] || []).filter(x => menu.includes(x));
  if (missing.length) issues.push({ role, url: page.url(), type: 'menu-missing', detail: missing.join(', ') });
  if (forbiddenVisible.length) issues.push({ role, url: page.url(), type: 'forbidden-menu-visible', detail: forbiddenVisible.join(', ') });

  const pages = [];
  for (const label of menu) {
    const link = page.getByRole('link', { name: label, exact: true }).first();
    const href = await link.getAttribute('href');
    const beforeErrors = consoleErrors.length;
    await link.click();
    await page.waitForLoadState('networkidle');
    const status = page.url().includes('/login') ? 'redirect-login' : 'rendered';
    const bodyText = (await page.locator('body').innerText()).trim();
    const blank = bodyText.length < 20;
    const newErrors = consoleErrors.slice(beforeErrors);
    pages.push({ label, href, finalUrl: page.url(), status, blank, errors: newErrors });
    if (status !== 'rendered' || blank || newErrors.length) {
      issues.push({ role, url: page.url(), type: 'page-smoke', detail: `${label}: status=${status}, blank=${blank}, errors=${newErrors.join(' | ')}` });
    }
  }

  await page.goto('/admin/dashboard');
  await page.waitForLoadState('networkidle');
  const toggle = page.getByRole('button', { name: 'Toggle dark mode' });
  const bgBefore = await page.evaluate(() => getComputedStyle(document.querySelector('.flex.min-h-screen') || document.body).backgroundColor);
  let darkClass = false;
  let stored = null;
  let bgAfter = bgBefore;
  let persisted = false;
  let darkPass = false;
  if (await toggle.count()) {
    await toggle.click();
    await page.waitForTimeout(150);
    darkClass = await page.evaluate(() => document.documentElement.classList.contains('dark'));
    stored = await page.evaluate(() => localStorage.getItem('darkMode'));
    bgAfter = await page.evaluate(() => getComputedStyle(document.querySelector('.flex.min-h-screen') || document.body).backgroundColor);
    await page.reload();
    await page.waitForLoadState('networkidle');
    persisted = await page.evaluate(() => document.documentElement.classList.contains('dark') && localStorage.getItem('darkMode') === 'true');
    darkPass = darkClass && stored === 'true' && persisted && bgBefore !== bgAfter;
  }
  if (!darkPass) issues.push({ role, url: page.url(), type: 'dark-mode', detail: `darkClass=${darkClass}, stored=${stored}, persisted=${persisted}, bgBefore=${bgBefore}, bgAfter=${bgAfter}` });

  out.push({ role, menu, missing, forbiddenVisible, pages, dark: { pass: darkPass, bgBefore, bgAfter, stored, persisted }, consoleErrors });
  await page.close();
}
await browser.close();
console.log(JSON.stringify({ out, issues }, null, 2));
