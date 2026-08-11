import assert from 'node:assert/strict';
import { groupPermissions, setGroupPermissions, setPermission } from './permissionGroups.ts';

const permissions = [
    { id: 1, name: 'billing.view', group: 'billing' },
    { id: 2, name: 'spk.view', group: 'spk' },
    { id: 3, name: 'spk.create', group: 'spk' },
];

assert.deepEqual(Object.keys(groupPermissions(permissions)), ['spk', 'billing']);
assert.deepEqual(setGroupPermissions([], permissions.slice(1), true), ['spk.view', 'spk.create']);
assert.deepEqual(setGroupPermissions(['spk.view', 'billing.view'], permissions.slice(1), false), [
    'billing.view',
]);
assert.deepEqual(setPermission(['spk.view'], 'spk.create', true), ['spk.view', 'spk.create']);
assert.deepEqual(setPermission(['spk.view'], 'spk.view', false), []);

console.log('permissionGroups.selfcheck.mjs: all assertions passed');
