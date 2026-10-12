import { afterEach, expect, test, vi } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { createExtensionTestHost, createTestServer } from '@pterodactyl/sdk/testing';
import { domainsQuery, type Domain, type DomainsResponse } from './api';
import { certificateSummary, daysLeft, domainError, labelError } from './format';
import AddDomainForm from './screens/AddDomainForm';
import DomainsScreen from './screens/DomainsScreen';

let host: ReturnType<typeof createExtensionTestHost> | undefined;

afterEach(() => {
    cleanup();
    host?.dispose();
});

test('subdomain labels follow DNS rules and skip reserved names', () => {
    expect(labelError('myserver')).toBeNull();
    expect(labelError('my-server-2')).toBeNull();
    expect(labelError('a'.repeat(63))).toBeNull();
    expect(labelError('')).toBe('Enter a name.');
    expect(labelError('a'.repeat(64))).toBe('At most 63 characters.');
    expect(labelError('-edge')).toMatch(/hyphen/);
    expect(labelError('edge-')).toMatch(/hyphen/);
    expect(labelError('Upper')).toMatch(/lower case/);
    expect(labelError('my_server')).toMatch(/lower case/);
    expect(labelError('www')).toMatch(/reserved/);
    expect(labelError('node12')).toMatch(/reserved/);
    expect(labelError('nodes-of-war')).toMatch(/reserved/);
    expect(labelError('nod')).toBeNull();
});

test('own domains are checked before they are sent', () => {
    expect(domainError('play.example.com')).toBeNull();
    expect(domainError('Play.Example.com.')).toBeNull();
    expect(domainError('bücher.example')).toBeNull();
    expect(domainError('')).toBe('Enter your domain.');
    expect(domainError('https://play.example.com')).toMatch(/without http/);
    expect(domainError('play.example.com:25565')).toMatch(/without http/);
    expect(domainError('localhost')).toMatch(/full domain/);
    expect(domainError('1.2.3.4')).toMatch(/IP address/);
    expect(domainError('bad..example.com')).toMatch(/not a valid/);
    expect(domainError('mc.games.example.net', ['games.example.net'])).toMatch(/belongs to this panel/);
});

test('certificate summaries count the days left', () => {
    const now = Date.parse('2026-10-12T00:00:00Z');
    expect(daysLeft('2026-11-11T12:00:00Z', now)).toBe(30);
    expect(certificateSummary({ ssl_status: 'active', cert_expires_at: '2026-11-11T12:00:00Z' }, now)).toMatch(/\(30 days\)$/);
    expect(certificateSummary({ ssl_status: 'active', cert_expires_at: '2026-10-01T00:00:00Z' }, now)).toMatch(/^Expired/);
    expect(certificateSummary({ ssl_status: 'none', cert_expires_at: null }, now)).toBeNull();
});

const base: Domain = {
    id: 1,
    kind: 'subdomain',
    name: 'mc.games.example.net',
    zone: 'games.example.net',
    allocation_id: 10,
    target: '93.184.215.14',
    port: 25565,
    srv_service: '_minecraft._tcp',
    dns_status: 'active',
    dns_error: null,
    dns_checked_at: '2026-10-12T00:00:00Z',
    delegation_ok: false,
    ssl_status: 'active',
    ssl_error: null,
    ssl_next_attempt_at: null,
    cert_issued_at: '2026-10-12T00:00:00Z',
    cert_expires_at: '2099-01-10T00:00:00Z',
    created_at: '2026-10-12T00:00:00Z',
    records: [],
    cert_folder: '/ssl/mc.games.example.net',
    renewable: false,
};

const response = (domains: Domain[], extra: Partial<DomainsResponse> = {}): DomainsResponse => ({
    domains,
    zones: ['games.example.net'],
    allocations: [
        { id: 10, address: '93.184.215.14:25565', is_default: true },
        { id: 11, address: '93.184.215.14:25566', is_default: false },
    ],
    max_domains: 3,
    can_manage: true,
    ssl_available: true,
    own_allowed: true,
    own_ssl_available: true,
    srv_service: '_minecraft._tcp',
    cert_path: '/ssl',
    ...extra,
});

function renderScreen(data: DomainsResponse, permissions?: string[]) {
    const server = createTestServer({ uuid: 'aaaaaaaa-0000-4000-8000-000000000002', permissions });
    host = createExtensionTestHost({ extensionId: 'domains', prefix: 'rxdm', server });
    host.queryClient.setQueryData(domainsQuery(server.attributes.uuid).queryKey, data);
    render(<DomainsScreen />, { wrapper: host.Wrapper });
}

test('the screen lists domains with their DNS and certificate status', async () => {
    renderScreen(
        response([
            base,
            {
                ...base,
                id: 2,
                kind: 'own',
                name: 'play.customer.org',
                zone: null,
                dns_status: 'mismatch',
                dns_error: 'play.customer.org has no A record yet.',
                ssl_status: 'none',
                cert_expires_at: null,
                cert_folder: null,
                renewable: true,
                records: [
                    { role: 'address', type: 'A', name: 'play.customer.org', value: '93.184.215.14', ttl: 300 },
                    { role: 'delegation', type: 'CNAME', name: '_acme-challenge.play.customer.org', value: 'v1234.acme.example.net', ttl: 300 },
                ],
            },
            { ...base, id: 3, name: 'eu.games.example.net', ssl_status: 'failed', ssl_error: 'The CA could not verify the domain.', cert_expires_at: null, ssl_next_attempt_at: '2099-01-01T00:00:00Z', renewable: true },
        ]),
    );

    expect(await screen.findByText('mc.games.example.net')).toBeTruthy();
    expect(screen.getByText('3 of 3 used')).toBeTruthy();
    // No room for another one.
    expect(screen.queryByText('Add domain')).toBeNull();

    expect(screen.getAllByText('DNS active')).toHaveLength(2);
    expect(screen.getByText('DNS not matching')).toBeTruthy();
    expect(screen.getByText('Certificate active')).toBeTruthy();
    expect(screen.getByText('Certificate failed')).toBeTruthy();
    expect(screen.getByText('No certificate')).toBeTruthy();
    expect(screen.getByText('The CA could not verify the domain.')).toBeTruthy();
    expect(screen.getByText(/\/ssl\/mc\.games\.example\.net\/fullchain\.pem/)).toBeTruthy();

    // The own domain shows the records to create, including the delegation CNAME.
    expect(screen.getByText('play.customer.org has no A record yet.')).toBeTruthy();
    expect(screen.getByText('_acme-challenge.play.customer.org')).toBeTruthy();
    expect(screen.getByText('v1234.acme.example.net')).toBeTruthy();
    expect(screen.getByText('Check DNS')).toBeTruthy();
    // A healthy certificate renews on schedule, so there is no button for it.
    expect(screen.queryByText('Renew now')).toBeNull();
    expect(screen.getByText('Try again')).toBeTruthy();
    expect(screen.getByText('Get certificate')).toBeTruthy();
});

test('a certificate inside the renewal window can be renewed by hand', async () => {
    renderScreen(response([{ ...base, renewable: true }]));

    expect(await screen.findByText('Renew now')).toBeTruthy();
});

test('read-only users see the list without actions', async () => {
    renderScreen(response([{ ...base, renewable: true }], { can_manage: false }), ['ext.domains.read']);

    expect(await screen.findByText('mc.games.example.net')).toBeTruthy();
    expect(screen.queryByText('Delete')).toBeNull();
    expect(screen.queryByText('Renew now')).toBeNull();
    expect(screen.queryByText('Add domain')).toBeNull();
});

test('own domains without a delegation zone say certificates are unavailable', async () => {
    renderScreen(response([{ ...base, kind: 'own', name: 'play.customer.org', ssl_status: 'none', cert_expires_at: null, dns_status: 'verified' }], { own_ssl_available: false }));

    expect(await screen.findByText('Certificates are only available for subdomains on this panel.')).toBeTruthy();
    expect(screen.queryByText('Get certificate')).toBeNull();
});

test('an empty server offers to add a domain', async () => {
    renderScreen(response([]));

    expect(await screen.findByText('No domains yet')).toBeTruthy();
    expect(screen.getByText('Add domain')).toBeTruthy();
});

test('the form validates before submitting and sends a clean subdomain', async () => {
    const onSubmit = vi.fn();
    const server = createTestServer();
    host = createExtensionTestHost({ extensionId: 'domains', prefix: 'rxdm', server });
    render(<AddDomainForm data={response([])} pending={false} onCancel={() => {}} onSubmit={onSubmit} />, { wrapper: host.Wrapper });

    fireEvent.click(await screen.findByText('Add domain'));
    expect(onSubmit).not.toHaveBeenCalled();
    expect(screen.getByRole('alert').textContent).toBe('Enter a name.');

    fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'www' } });
    expect(screen.getByRole('alert').textContent).toMatch(/reserved/);
    fireEvent.click(screen.getByText('Add domain'));
    expect(onSubmit).not.toHaveBeenCalled();

    fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'MyServer' } });
    expect(screen.getByText('myserver.games.example.net')).toBeTruthy();
    fireEvent.click(screen.getByText('Add domain'));
    expect(onSubmit).toHaveBeenCalledWith({
        kind: 'subdomain',
        label: 'myserver',
        zone: 'games.example.net',
        domain: null,
        allocation_id: 10,
        certificate: true,
    });
});

test('the form sends an own domain without asking for a certificate', async () => {
    const onSubmit = vi.fn();
    host = createExtensionTestHost({ extensionId: 'domains', prefix: 'rxdm', server: createTestServer() });
    render(<AddDomainForm data={response([])} pending={false} onCancel={() => {}} onSubmit={onSubmit} />, { wrapper: host.Wrapper });

    fireEvent.click(await screen.findByRole('radio', { name: /My own domain/ }));
    fireEvent.change(screen.getByLabelText('Your domain'), { target: { value: 'http://play.customer.org' } });
    fireEvent.click(screen.getByText('Add domain'));
    expect(onSubmit).not.toHaveBeenCalled();
    expect(screen.getByRole('alert').textContent).toMatch(/without http/);

    fireEvent.change(screen.getByLabelText('Your domain'), { target: { value: 'Play.Customer.org.' } });
    fireEvent.click(screen.getByText('Add domain'));
    expect(onSubmit).toHaveBeenCalledWith(expect.objectContaining({ kind: 'own', domain: 'play.customer.org', label: null, certificate: false }));
});
