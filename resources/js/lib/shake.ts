export function shake(field: HTMLElement | null | undefined): void {
    if (!field) return;
    field.classList.remove('so-shake');
    void field.offsetWidth;
    field.classList.add('so-shake');
    field.focus();
}
