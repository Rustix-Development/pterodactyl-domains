import type { DnsStatus, Domain, SslStatus } from './api';

export const DNS_LABELS: Record<DnsStatus, string> = {
    pending: 'Waiting for DNS',
    active: 'DNS active',
    verified: 'DNS verified',
    mismatch: 'DNS not matching',
    error: 'DNS error',
};

export const SSL_LABELS: Record<SslStatus, string> = {
    none: 'No certificate',
    queued: 'Certificate queued',
    issuing: 'Issuing certificate',
    active: 'Certificate active',
    failed: 'Certificate failed',
};

/** Shown in the form as a hint; the panel checks its own, longer list too. */
export const RESERVED = ['www', 'mail', 'panel', 'node*', 'ns*', 'mx*', 'smtp', 'ftp', 'admin', 'api'];

const LABEL = /^(?!-)[a-z0-9-]{1,63}(?<!-)$/;

const matches = (pattern: string, value: string) =>
    new RegExp('^' + pattern.replace(/[.+^${}()|[\]\\]/g, '\\$&').replace(/\*/g, '.*').replace(/\?/g, '.') + '$').test(value);

/** Why a subdomain label cannot be used, or null when it looks fine. */
export function labelError(label: string): string | null {
    if (label === '') return 'Enter a name.';
    if (label.length > 63) return 'At most 63 characters.';
    if (!LABEL.test(label)) return 'Use lower case letters, digits and hyphens, not starting or ending with a hyphen.';
    if (RESERVED.some((pattern) => matches(pattern, label))) return `“${label}” is reserved.`;

    return null;
}

/** Why an own domain cannot be used, or null when it looks fine. */
export function domainError(value: string, zones: string[] = []): string | null {
    const name = value.trim().toLowerCase().replace(/\.$/, '');
    if (name === '') return 'Enter your domain.';
    if (/^[a-z]+:\/\//.test(name) || name.includes('/') || name.includes(':'))
        return 'Enter just the domain, like play.example.com, without http:// or a port.';
    if (!name.includes('.')) return 'Enter a full domain, like play.example.com.';
    // International names are converted to punycode by the panel.
    const ascii = /^[\x20-\x7e]*$/.test(name);
    if (ascii && !name.split('.').every((part) => LABEL.test(part))) return 'That is not a valid domain.';
    if (ascii && /^\d+$/.test(name.split('.').pop() ?? '')) return 'Enter a domain, not an IP address.';
    if (zones.some((zone) => name === zone || name.endsWith('.' + zone)))
        return 'That domain belongs to this panel. Use the subdomain option instead.';

    return null;
}

/** Whole days until the given time, negative once it has passed. */
export function daysLeft(iso: string, now: number = Date.now()): number {
    return Math.floor((new Date(iso).getTime() - now) / 86_400_000);
}

/** A compact date for tables: "Oct 10, 2026". */
export function day(iso: string | null): string {
    if (!iso) return '—';

    return new Date(iso).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
}

/** A one-line summary of the certificate for lists. */
export function certificateSummary(domain: Pick<Domain, 'ssl_status' | 'cert_expires_at'>, now: number = Date.now()): string | null {
    if (!domain.cert_expires_at) return null;
    const left = daysLeft(domain.cert_expires_at, now);

    return left < 0 ? `Expired ${day(domain.cert_expires_at)}` : `Valid until ${day(domain.cert_expires_at)} (${left} days)`;
}
