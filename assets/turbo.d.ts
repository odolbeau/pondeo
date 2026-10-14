// @hotwired/turbo ships no typings: only what the map uses to push a history entry.
declare module '@hotwired/turbo' {
    export const session: { history: { push(location: URL, restorationIdentifier?: string): void } };
}
