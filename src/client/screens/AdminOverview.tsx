import { useMutation, useQuery } from '@tanstack/react-query';
import { Alert, Button, httpErrorToHuman, PageContentBlock, Table, toast } from '@pterodactyl/sdk';
import { adminOverviewQuery, testProvider } from '../api';
import { certificateSummary } from '../format';
import { DnsBadge, SslBadge } from '../components/StatusBadge';

/** Every domain on the panel with its DNS and certificate state, for hosts. */
export default function AdminOverview() {
    const { data, isFetching } = useQuery(adminOverviewQuery());
    const test = useMutation({ mutationFn: testProvider, onError: (error) => toast.error(httpErrorToHuman(error)) });

    return (
        <PageContentBlock title='Domains'>
            <section className='rxdm:mb-6 rxdm:flex rxdm:flex-col rxdm:gap-3 rxdm:rounded-md rxdm:border rxdm:border-border rxdm:bg-card rxdm:p-4'>
                <div className='rxdm:flex rxdm:flex-wrap rxdm:items-center rxdm:justify-between rxdm:gap-3'>
                    <div className='rxdm:min-w-0'>
                        <h2 className='rxdm:m-0 rxdm:text-lg rxdm:font-medium'>DNS provider{data ? `: ${data.provider}` : ''}</h2>
                        {data && (
                            <p className='rxdm:m-0 rxdm:break-all rxdm:text-sm rxdm:text-muted-foreground'>
                                {data.zones.length > 0 ? `Subdomains under ${data.zones.join(', ')}` : 'No domains for subdomains set'}
                                {' · '}
                                {data.delegation_zone ? `Own domains delegate to ${data.delegation_zone}` : 'No delegation zone, so no certificates for own domains'}
                            </p>
                        )}
                    </div>
                    <Button isSecondary isLoading={test.isPending} onClick={() => test.mutate()}>
                        Test API token
                    </Button>
                </div>
                {test.data && (
                    <Alert type={test.data.ok ? 'success' : 'danger'}>
                        <span className='rxdm:block rxdm:break-words'>{test.data.message}</span>
                        {test.data.checks.length > 0 && (
                            <ul className='rxdm:m-0 rxdm:mt-2 rxdm:list-none rxdm:p-0 rxdm:text-sm'>
                                {test.data.checks.map((check) => (
                                    <li key={check.role + check.name} className='rxdm:break-all'>
                                        {check.ok ? '✓' : '✗'} {check.name} ({check.role === 'zone' ? 'subdomains' : 'delegation'})
                                        {check.ok ? '' : ': not in any zone the token can edit'}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Alert>
                )}
            </section>

            <h2 className='rxdm:mt-0 rxdm:mb-3 rxdm:text-lg rxdm:font-medium'>All domains</h2>
            <Table
                rows={data?.domains ?? []}
                keyOf={(row) => String(row.id)}
                isFetching={isFetching}
                emptyState='No server has a domain yet.'
                columns={[
                    {
                        id: 'name',
                        label: 'Domain',
                        cell: (row) => (
                            <span className='rxdm:block rxdm:min-w-56 rxdm:max-w-xs rxdm:wrap-anywhere rxdm:font-mono rxdm:text-sm'>
                                {row.name}
                                <span className='rxdm:block rxdm:font-sans rxdm:text-xs rxdm:text-muted-foreground'>
                                    {row.kind === 'own' ? 'Own domain' : 'Subdomain'} → {row.target ?? '?'}
                                </span>
                            </span>
                        ),
                    },
                    { id: 'server', label: 'Server', cell: (row) => row.server?.name ?? 'Deleted server' },
                    { id: 'dns', label: 'DNS', cell: (row) => <DnsBadge status={row.dns_status} /> },
                    { id: 'ssl', label: 'Certificate', cell: (row) => <SslBadge status={row.ssl_status} /> },
                    {
                        id: 'expires',
                        label: 'Expires',
                        cell: (row) => (
                            <span className='rxdm:block rxdm:min-w-40 rxdm:max-w-sm rxdm:text-sm'>
                                {row.cert_expires_at ? certificateSummary(row) : '—'}
                                {(row.ssl_error ?? row.dns_error) && (
                                    <span
                                        className='rxdm:line-clamp-2 rxdm:text-xs rxdm:text-muted-foreground rxdm:wrap-anywhere'
                                        title={row.ssl_error ?? row.dns_error ?? ''}
                                    >
                                        {row.ssl_error ?? row.dns_error}
                                    </span>
                                )}
                            </span>
                        ),
                    },
                ]}
            />
        </PageContentBlock>
    );
}
