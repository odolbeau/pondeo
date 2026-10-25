const MAX_AGE = 60 * 60 * 24 * 365;

// A preference the server reads (see the saved state redirect): a cookie, not
// localStorage. An empty value forgets it.
export function savePreference(name: string, query: URLSearchParams): void {
    const attributes = `Path=/; SameSite=Lax${'https:' === window.location.protocol ? '; Secure' : ''}`;
    document.cookie = 0 === [...query].length ? `${name}=; Max-Age=0; ${attributes}` : `${name}=${encodeURIComponent(query.toString())}; Max-Age=${MAX_AGE}; ${attributes}`;
}
