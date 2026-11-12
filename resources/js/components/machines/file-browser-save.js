export function createFileBrowserSave({
    text,
    file,
    changed,
    saving,
    fileError,
    savedSize,
    props,
    needsUnlock,
    toBase64,
    job,
    toast,
    trans,
    baseName,
    bytes,
    dir,
    list,
    handle,
}) {
    return async function save() {
        const open = file.value;
        if (!open || !changed.value || saving.value) {
            return;
        }
        if (savedSize.value > props.maxWrite) {
            fileError.value = trans(
                'Files over :bytes can’t be saved from the panel yet.',
                { bytes: String(bytes(props.maxWrite)) },
            );

            return;
        }
        if (needsUnlock(() => save())) {
            return;
        }

        saving.value = true;
        fileError.value = null;
        const submitted = text.value;
        const data = new TextEncoder().encode(submitted);

        try {
            const result = await job('write', open.path, toBase64(data));
            if (file.value === open) {
                file.value = {
                    path: open.path,
                    bytes: data,
                    original: submitted,
                };
            }
            toast.success(
                result.backup
                    ? trans(
                          'Saved. The old version is kept beside it as :baseName.',
                          { baseName: String(baseName(result.backup)) },
                      )
                    : trans('Saved.'),
            );
            if (dir.value) {
                void list(dir.value);
            }
        } catch (error) {
            fileError.value = handle(error, () => save());
        } finally {
            saving.value = false;
        }
    };
}
