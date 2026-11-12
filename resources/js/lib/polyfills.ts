if (typeof Object.hasOwn !== 'function') {
    Object.defineProperty(Object, 'hasOwn', {
        value: (object: object, key: PropertyKey): boolean =>
            Object.prototype.hasOwnProperty.call(Object(object), key),
        configurable: true,
        writable: true,
    });
}
