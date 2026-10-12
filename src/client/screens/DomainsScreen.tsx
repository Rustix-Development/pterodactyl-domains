import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    Alert,
    Button,
    CopyOnClick,
    Dialog,
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
    httpErrorToHuman,
    Label,
    Select,
    ServerContentBlock,
    Spinner,
    toast,
    useCurrentServerRequired,
} from '@pterodactyl/sdk';
import {
    checkDomain,
    createDomain,
    deleteDomain,
    domainsQuery,
    isBusy,
    moveDomain,
    requestCertificate,
    type Domain,
    type DomainInput,
    type DomainsResponse,
} from '../api';
import { certificateSummary } from '../format';
import RecordList from '../components/RecordList';
import { DnsBadge, SslBadge } from '../components/StatusBadge';
import AddDomainForm, { allocationOptions } from './AddDomainForm';

function DomainCard({
    domain,
    data,
    onCheck,
    onCertificate,
    onMove,
    onDelete,
    busy,
}: {
    domain: Domain;
    data: DomainsResponse;
    onCheck: () => void;
    onCertificate: () => void;
    onMove: () => void;
    onDelete: () => void;
    busy: boolean;
}) {
    const allocation = data.allocations.find((a) => a.id === domain.allocation_id);
    const own = domain.kind === 'own';
    const sslPossible = data.ssl_available && (!own || data.own_ssl_available);
    const certificate = certificateSummary(domain);
    const needsRecords = own && (domain.dns_status !== 'verified' || (data.own_ssl_available && !domain.delegation_ok));

    return (
        <li className='rxdm:flex rxdm:min-w-0 rxdm:flex-col rxdm:gap-3 rxdm:rounded-md rxdm:border rxdm:border-border rxdm:bg-card rxdm:p-4'>
            <div className='rxdm:flex rxdm:flex-wrap rxdm:items-start rxdm:justify-between rxdm:gap-2'>
                <div className='rxdm:min-w-0'>
                    <CopyOnClick text={domain.name}>
                        <p className='rxdm:m-0 rxdm:cursor-pointer rxdm:break-all rxdm:font-mono rxdm:font-medium'>{domain.name}</p>
                    </CopyOnClick>
                    <p className='rxdm:m-0 rxdm:text-sm rxdm:text-muted-foreground'>
                        {own ? 'Own domain' : 'Subdomain'} → {domain.target ?? '?'}
                        {allocation ? ` (port ${allocation.address.split(':').pop()})` : ''}
                        {domain.srv_service ? ' · SRV' : ''}
                    </p>
                </div>
                <div className='rxdm:flex rxdm:flex-wrap rxdm:gap-1'>
                    <DnsBadge status={domain.dns_status} />
                    {sslPossible || domain.ssl_status !== 'none' ? <SslBadge status={domain.ssl_status} /> : null}
                </div>
            </div>

            {domain.dns_error &&
                (domain.dns_status === 'error' || domain.dns_status === 'mismatch' ? (
                    <Alert type='danger'>
                        <span className='rxdm:wrap-anywhere'>{domain.dns_error}</span>
                    </Alert>
                ) : (
                    // Only optional records are missing; the domain works.
                    <p className='rxdm:m-0 rxdm:text-sm rxdm:text-muted-foreground rxdm:wrap-anywhere'>{domain.dns_error}</p>
                ))}
            {domain.ssl_error && (
                <Alert type={domain.ssl_status === 'failed' ? 'danger' : 'warning'}>
                    <span className='rxdm:wrap-anywhere'>{domain.ssl_error}</span>
                    {domain.ssl_next_attempt_at && (
                        <span className='rxdm:block rxdm:text-xs'>
                            Tried again automatically after {new Date(domain.ssl_next_attempt_at).toLocaleString()}.
                        </span>
                    )}
                </Alert>
            )}

            {needsRecords && (
                <details open={domain.dns_status !== 'verified'} className='rxdm:min-w-0'>
                    <summary className='rxdm:cursor-pointer rxdm:text-sm rxdm:font-medium'>Records to create at your DNS host</summary>
                    <div className='rxdm:mt-3'>
                        <RecordList records={domain.records} />
                    </div>
                </details>
            )}
            {own && !data.own_ssl_available && data.ssl_available && (
                <p className='rxdm:m-0 rxdm:text-sm rxdm:text-muted-foreground'>
                    Certificates are only available for subdomains on this panel.
                </p>
            )}

            {domain.ssl_status === 'active' && certificate && (
                <p className='rxdm:m-0 rxdm:break-all rxdm:text-sm rxdm:text-muted-foreground'>
                    {certificate}
                    {domain.cert_folder && (
                        <>
                            {' · '}
                            <span className='rxdm:font-mono'>{domain.cert_folder}/fullchain.pem, privkey.pem</span>
                        </>
                    )}
                </p>
            )}

            {data.can_manage && (
                <div className='rxdm:mt-auto rxdm:flex rxdm:flex-wrap rxdm:gap-2'>
                    {own && (
                        <Button size='small' isSecondary disabled={busy} onClick={onCheck}>
                            Check DNS
                        </Button>
                    )}
                    {sslPossible && (domain.renewable || isBusy(domain)) && (
                        <Button size='small' isSecondary disabled={busy || isBusy(domain)} onClick={onCertificate}>
                            {domain.ssl_status === 'active' ? 'Renew now' : domain.ssl_status === 'failed' ? 'Try again' : 'Get certificate'}
                        </Button>
                    )}
                    {data.allocations.length > 1 && (
                        <Button size='small' isSecondary disabled={busy} onClick={onMove}>
                            Change port
                        </Button>
                    )}
                    <Button.Danger size='small' className='rxdm:ml-auto' disabled={domain.ssl_status === 'issuing'} onClick={onDelete}>
                        Delete
                    </Button.Danger>
                </div>
            )}
        </li>
    );
}

export default function DomainsScreen() {
    const server = useCurrentServerRequired();
    const uuid = server.attributes.uuid;
    const client = useQueryClient();
    const { data, isLoading } = useQuery(domainsQuery(uuid));
    const [adding, setAdding] = useState(false);
    const [moving, setMoving] = useState<{ domain: Domain; allocation: number } | null>(null);
    const [deleting, setDeleting] = useState<Domain | null>(null);

    const refresh = () => client.invalidateQueries({ queryKey: ['domains', uuid] });
    const onError = (error: unknown) => toast.error(httpErrorToHuman(error));

    const create = useMutation({
        mutationFn: (input: DomainInput) => createDomain(uuid, input),
        onSuccess: async (domain) => {
            toast.success(domain.kind === 'own' ? 'Domain added. Now create the records shown.' : `${domain.name} is ready.`);
            setAdding(false);
            await refresh();
        },
        onError,
    });
    const check = useMutation({
        mutationFn: (id: number) => checkDomain(uuid, id),
        onSuccess: async (domain) => {
            if (domain.dns_status === 'verified' && !domain.dns_error) toast.success('Everything is in place.');
            else if (domain.dns_status === 'active') toast.success('DNS records updated.');
            else toast.error('Not everything is in place yet. DNS changes can take a few minutes.');
            await refresh();
        },
        onError,
    });
    const certificate = useMutation({
        mutationFn: (id: number) => requestCertificate(uuid, id),
        onSuccess: async () => {
            toast.success('Certificate requested. It usually arrives within two minutes.');
            await refresh();
        },
        onError,
    });
    const move = useMutation({
        mutationFn: ({ id, allocation }: { id: number; allocation: number }) => moveDomain(uuid, id, allocation),
        onSuccess: async () => {
            setMoving(null);
            await refresh();
        },
        onError,
    });
    const remove = useMutation({
        mutationFn: (id: number) => deleteDomain(uuid, id),
        onSuccess: async () => {
            setDeleting(null);
            await refresh();
        },
        onError,
    });

    const domains = data?.domains ?? [];
    const offers = !!data && (data.zones.length > 0 || data.own_allowed);
    const canAdd = !!data && data.can_manage && offers && domains.length < data.max_domains;

    return (
        <ServerContentBlock title='Domains'>
            <div className='rxdm:mb-4 rxdm:flex rxdm:flex-wrap rxdm:items-center rxdm:justify-between rxdm:gap-3'>
                <div className='rxdm:min-w-0'>
                    <h2 className='rxdm:m-0 rxdm:text-lg rxdm:font-medium'>Domains</h2>
                    {data && (
                        <p className='rxdm:m-0 rxdm:text-sm rxdm:text-muted-foreground'>
                            {domains.length} of {data.max_domains} used
                        </p>
                    )}
                </div>
                {canAdd && <Button onClick={() => setAdding(true)}>Add domain</Button>}
            </div>

            {isLoading || !data ? (
                <Spinner centered />
            ) : domains.length === 0 ? (
                <Empty className='rxdm:border'>
                    <EmptyHeader>
                        <EmptyTitle>No domains yet</EmptyTitle>
                        <EmptyDescription>
                            {offers
                                ? 'Give this server an easy address instead of an IP and port, with a free certificate if you need one.'
                                : 'The host has not set up any domains yet.'}
                        </EmptyDescription>
                    </EmptyHeader>
                </Empty>
            ) : (
                <ul className='rxdm:m-0 rxdm:grid rxdm:list-none rxdm:gap-3 rxdm:p-0 rxdm:xl:grid-cols-2'>
                    {domains.map((domain) => (
                        <DomainCard
                            key={domain.id}
                            domain={domain}
                            data={data}
                            busy={check.isPending || certificate.isPending}
                            onCheck={() => check.mutate(domain.id)}
                            onCertificate={() => certificate.mutate(domain.id)}
                            onMove={() => setMoving({ domain, allocation: domain.allocation_id ?? data.allocations[0]!.id })}
                            onDelete={() => setDeleting(domain)}
                        />
                    ))}
                </ul>
            )}

            <Dialog open={adding} onClose={() => setAdding(false)} title='Add a domain'>
                {adding && data && (
                    <AddDomainForm data={data} pending={create.isPending} onCancel={() => setAdding(false)} onSubmit={(input) => create.mutate(input)} />
                )}
            </Dialog>

            <Dialog open={moving !== null} onClose={() => setMoving(null)} title={`Point ${moving?.domain.name ?? ''} to`}>
                {moving && data && (
                    <div className='rxdm:flex rxdm:flex-col rxdm:gap-4'>
                        <div>
                            <Label htmlFor='rxdm-move'>Allocation</Label>
                            <Select
                                id='rxdm-move'
                                value={moving.allocation}
                                options={allocationOptions(data.allocations)}
                                onChange={(v) => setMoving({ ...moving, allocation: Number(v) })}
                            />
                        </div>
                        {moving.domain.kind === 'own' && (
                            <p className='rxdm:m-0 rxdm:text-sm rxdm:text-muted-foreground'>
                                If the IP changes you will need to update the record at your DNS host.
                            </p>
                        )}
                        <Dialog.Footer>
                            <Button isSecondary onClick={() => setMoving(null)} disabled={move.isPending}>
                                Cancel
                            </Button>
                            <Button isLoading={move.isPending} onClick={() => move.mutate({ id: moving.domain.id, allocation: moving.allocation })}>
                                Save
                            </Button>
                        </Dialog.Footer>
                    </div>
                )}
            </Dialog>

            <Dialog.Confirm
                open={deleting !== null}
                onClose={() => setDeleting(null)}
                title={`Delete ${deleting?.name ?? ''}?`}
                confirm='Delete'
                pending={remove.isPending}
                onConfirmed={() => deleting && remove.mutate(deleting.id)}
            >
                {deleting?.kind === 'own'
                    ? 'The domain is removed from this server and its certificate files are deleted. Remove the records at your DNS host yourself.'
                    : 'Its DNS records and certificate files are deleted. Players using this address can no longer join with it.'}
            </Dialog.Confirm>
        </ServerContentBlock>
    );
}
