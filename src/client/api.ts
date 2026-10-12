import { queryOptions } from '@tanstack/react-query';
import { http } from '@pterodactyl/sdk';

export type DomainKind = 'subdomain' | 'own';
export type DnsStatus = 'pending' | 'active' | 'verified' | 'mismatch' | 'error';
export type SslStatus = 'none' | 'queued' | 'issuing' | 'active' | 'failed';

export interface DnsRecord {
    role: 'address' | 'srv' | 'delegation';
    type: string;
    name: string;
    value: string;
    ttl: number;
}

export interface Domain {
    id: number;
    kind: DomainKind;
    name: string;
    zone: string | null;
    allocation_id: number | null;
    target: string | null;
    port: number | null;
    srv_service: string | null;
    dns_status: DnsStatus;
    dns_error: string | null;
    dns_checked_at: string | null;
    delegation_ok: boolean;
    ssl_status: SslStatus;
    ssl_error: string | null;
    ssl_next_attempt_at: string | null;
    cert_issued_at: string | null;
    cert_expires_at: string | null;
    created_at: string | null;
    records: DnsRecord[];
    cert_folder: string | null;
    /** Whether a certificate can be requested now (none yet, failed, or due for renewal). */
    renewable: boolean;
}

export interface Allocation {
    id: number;
    address: string;
    is_default: boolean;
}

export interface DomainsResponse {
    domains: Domain[];
    zones: string[];
    allocations: Allocation[];
    max_domains: number;
    can_manage: boolean;
    ssl_available: boolean;
    own_allowed: boolean;
    own_ssl_available: boolean;
    srv_service: string | null;
    cert_path: string;
}

export interface DomainInput {
    kind: DomainKind;
    label: string | null;
    zone: string | null;
    domain: string | null;
    allocation_id: number | null;
    certificate: boolean;
}

export interface AdminOverview {
    domains: (Omit<Domain, 'records' | 'cert_folder' | 'renewable'> & {
        server: { id: number; name: string; uuidShort: string } | null;
    })[];
    provider: string;
    zones: string[];
    delegation_zone: string | null;
    acme_directory: string;
}

export interface ProviderTest {
    ok: boolean;
    message: string;
    checks: { name: string; role: 'zone' | 'delegation'; ok: boolean }[];
}

const BUSY: SslStatus[] = ['queued', 'issuing'];

export const isBusy = (domain: Pick<Domain, 'ssl_status'>): boolean => BUSY.includes(domain.ssl_status);

const base = (uuid: string) => `/api/client/servers/${uuid}/extensions/domains`;

export const domainsQuery = (uuid: string) =>
    queryOptions({
        queryKey: ['domains', uuid],
        queryFn: async () => (await http.get<DomainsResponse>(`${base(uuid)}/domains`)).data,
        // Poll while a certificate is on its way so the status updates on its own.
        refetchInterval: (query) => (query.state.data?.domains.some(isBusy) ? 10_000 : false),
    });

export const adminOverviewQuery = () =>
    queryOptions({
        queryKey: ['domains', 'admin'],
        queryFn: async () => (await http.get<AdminOverview>('/api/admin/extensions/domains/overview')).data,
    });

export async function createDomain(uuid: string, input: DomainInput): Promise<Domain> {
    return (await http.post<{ data: Domain }>(`${base(uuid)}/domains`, input)).data.data;
}

export async function moveDomain(uuid: string, id: number, allocationId: number): Promise<Domain> {
    return (await http.put<{ data: Domain }>(`${base(uuid)}/domains/${id}`, { allocation_id: allocationId })).data.data;
}

export async function deleteDomain(uuid: string, id: number): Promise<void> {
    await http.delete(`${base(uuid)}/domains/${id}`);
}

export async function checkDomain(uuid: string, id: number): Promise<Domain> {
    return (await http.post<{ data: Domain }>(`${base(uuid)}/domains/${id}/check`)).data.data;
}

export async function requestCertificate(uuid: string, id: number): Promise<Domain> {
    return (await http.post<{ data: Domain }>(`${base(uuid)}/domains/${id}/certificate`)).data.data;
}

export async function testProvider(): Promise<ProviderTest> {
    return (await http.post<ProviderTest>('/api/admin/extensions/domains/test')).data;
}
