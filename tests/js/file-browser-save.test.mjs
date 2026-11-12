import assert from 'node:assert/strict';
import { createFileBrowserSave } from '../../resources/js/components/machines/file-browser-save.js';
import test from 'node:test';

// Exercise the same save routine used by the component without a running panel or daemon.

function editor() {
    const text = { value: 'version B' };
    const file = {
        value: {
            path: '/app/example.txt',
            bytes: new TextEncoder().encode('version A'),
            original: 'version A',
        },
    };
    const changed = {
        get value() {
            return (
                file.value?.original !== null &&
                text.value !== file.value?.original
            );
        },
    };
    const saving = { value: false };
    const fileError = { value: null };
    const savedSize = {
        get value() {
            return new TextEncoder().encode(text.value).length;
        },
    };
    let resolve;
    let reject;
    let written;
    const job = (_op, _path, content) => {
        written = Buffer.from(content, 'base64').toString('utf8');
        return new Promise((yes, no) => {
            resolve = yes;
            reject = no;
        });
    };
    const dependencies = {
        text,
        file,
        changed,
        saving,
        fileError,
        savedSize,
        props: { maxWrite: 10000 },
        needsUnlock: () => false,
        toBase64: (data) => Buffer.from(data).toString('base64'),
        job,
        toast: { success() {} },
        trans: (message) => message,
        baseName: (path) => path.split('/').pop(),
        bytes: (size) => String(size),
        dir: { value: null },
        list() {},
        handle: (error) => error.message,
    };
    const save = createFileBrowserSave(dependencies);
    return {
        text,
        file,
        changed,
        saving,
        fileError,
        save,
        complete: () => resolve({}),
        fail: () => reject(new Error('Write failed')),
        get written() {
            return written;
        },
    };
}

await test('edits made during a save remain dirty and can be saved again', async () => {
    const e = editor();
    const first = e.save();
    e.text.value = 'version C';
    e.complete();
    await first;
    assert.equal(e.written, 'version B');
    assert.equal(e.file.value.original, 'version B');
    assert.equal(new TextDecoder().decode(e.file.value.bytes), 'version B');
    assert.equal(e.text.value, 'version C');
    assert.equal(e.changed.value, true);
    assert.equal(e.saving.value, false);
    const second = e.save();
    e.complete();
    await second;
    assert.equal(e.written, 'version C');
    assert.equal(e.changed.value, false);
});

await test('a save with no later edits clears the dirty state', async () => {
    const e = editor();
    const pending = e.save();
    e.complete();
    await pending;
    assert.equal(e.file.value.original, 'version B');
    assert.equal(e.changed.value, false);
    assert.equal(e.saving.value, false);
});

await test('a completed save does not reopen a file that was closed', async () => {
    const e = editor();
    const pending = e.save();
    e.file.value = null;
    e.text.value = '';
    e.complete();
    await pending;
    assert.equal(e.file.value, null);
    assert.equal(e.text.value, '');
});

await test('a completed save does not overwrite another open file', async () => {
    const e = editor();
    const pending = e.save();
    const other = {
        path: '/app/other.txt',
        original: 'other',
        bytes: new Uint8Array(),
    };
    e.file.value = other;
    e.text.value = 'other edit';
    e.complete();
    await pending;
    assert.equal(e.file.value, other);
    assert.equal(e.text.value, 'other edit');
});

await test('reopening the same path is a new editor session', async () => {
    const e = editor();
    const pending = e.save();
    const reopened = { ...e.file.value };
    e.file.value = reopened;
    e.text.value = 'new session edit';
    e.complete();
    await pending;
    assert.equal(e.file.value, reopened);
    assert.equal(e.text.value, 'new session edit');
});

await test('a failed save keeps the original baseline and later edits', async () => {
    const e = editor();
    const pending = e.save();
    e.text.value = 'version C';
    e.fail();
    await pending;
    assert.equal(e.file.value.original, 'version A');
    assert.equal(e.text.value, 'version C');
    assert.equal(e.changed.value, true);
    assert.equal(e.fileError.value, 'Write failed');
    assert.equal(e.saving.value, false);
});
