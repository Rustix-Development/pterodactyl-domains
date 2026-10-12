import type { DnsStatus, SslStatus } from '../api';
import { DNS_LABELS, SSL_LABELS } from '../format';

type Tone = 'good' | 'bad' | 'warn' | 'muted';

const TONES: Record<Tone, string> = {
    good: 'rxdm:bg-success rxdm:text-success-foreground',
    bad: 'rxdm:bg-destructive rxdm:text-destructive-foreground',
    warn: 'rxdm:bg-warning rxdm:text-warning-foreground',
    muted: 'rxdm:bg-muted rxdm:text-muted-foreground',
};

const DNS_TONE: Record<DnsStatus, Tone> = { pending: 'warn', active: 'good', verified: 'good', mismatch: 'bad', error: 'bad' };
const SSL_TONE: Record<SslStatus, Tone> = { none: 'muted', queued: 'warn', issuing: 'warn', active: 'good', failed: 'bad' };

export function Badge({ tone, children }: { tone: Tone; children: string }) {
    return (
        <span
            className={`rxdm:inline-block rxdm:whitespace-nowrap rxdm:rounded rxdm:px-2 rxdm:py-0.5 rxdm:text-xs rxdm:font-medium ${TONES[tone]}`}
        >
            {children}
        </span>
    );
}

export function DnsBadge({ status }: { status: DnsStatus }) {
    return <Badge tone={DNS_TONE[status]}>{DNS_LABELS[status]}</Badge>;
}

export function SslBadge({ status }: { status: SslStatus }) {
    return <Badge tone={SSL_TONE[status]}>{SSL_LABELS[status]}</Badge>;
}
