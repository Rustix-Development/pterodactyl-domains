import { useState, type FormEvent, type ReactNode } from 'react';
import { Button, Dialog, Input, Label, Select, Switch } from '@pterodactyl/sdk';
import type { Allocation, DomainInput, DomainKind, DomainsResponse } from '../api';
import { domainError, labelError } from '../format';

interface Props {
    data: Pick<DomainsResponse, 'zones' | 'allocations' | 'own_allowed' | 'ssl_available' | 'srv_service'>;
    pending: boolean;
    onCancel: () => void;
    onSubmit: (input: DomainInput) => void;
}

function Field({ id, label, hint, error, children }: { id: string; label: string; hint?: string; error?: string | null; children: ReactNode }) {
    return (
        <div className='rxdm:min-w-0'>
            <Label htmlFor={id}>{label}</Label>
            {children}
            {error ? (
                <p role='alert' className='rxdm:mt-1 rxdm:text-xs rxdm:text-destructive'>
                    {error}
                </p>
            ) : (
                hint && <p className='rxdm:mt-1 rxdm:text-xs rxdm:text-muted-foreground'>{hint}</p>
            )}
        </div>
    );
}

export const allocationOptions = (allocations: Allocation[]) =>
    allocations.map((a) => ({ value: a.id, label: a.is_default ? `${a.address} (primary)` : a.address }));

export default function AddDomainForm({ data, pending, onCancel, onSubmit }: Props) {
    const kinds: DomainKind[] = [...(data.zones.length > 0 ? ['subdomain' as const] : []), ...(data.own_allowed ? ['own' as const] : [])];
    const [kind, setKind] = useState<DomainKind>(kinds[0] ?? 'subdomain');
    const [label, setLabel] = useState('');
    const [zone, setZone] = useState(data.zones[0] ?? '');
    const [domain, setDomain] = useState('');
    const [allocation, setAllocation] = useState<number | null>(data.allocations[0]?.id ?? null);
    const [certificate, setCertificate] = useState(data.ssl_available);
    const [submitted, setSubmitted] = useState(false);

    const cleanLabel = label.trim().toLowerCase();
    const error = kind === 'subdomain' ? labelError(cleanLabel) : domainError(domain, data.zones);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        setSubmitted(true);
        if (error) return;

        onSubmit({
            kind,
            label: kind === 'subdomain' ? cleanLabel : null,
            zone: kind === 'subdomain' ? zone : null,
            domain: kind === 'own' ? domain.trim().toLowerCase().replace(/\.$/, '') : null,
            allocation_id: allocation,
            certificate: kind === 'subdomain' && certificate,
        });
    };

    return (
        <form onSubmit={submit} noValidate className='rxdm:flex rxdm:flex-col rxdm:gap-4'>
            {kinds.length > 1 && (
                <div role='radiogroup' aria-label='Kind of domain' className='rxdm:grid rxdm:grid-cols-2 rxdm:gap-2'>
                    {kinds.map((value) => (
                        <button
                            key={value}
                            type='button'
                            role='radio'
                            aria-checked={kind === value}
                            onClick={() => setKind(value)}
                            className={`rxdm:rounded-md rxdm:border rxdm:p-3 rxdm:text-left rxdm:text-sm ${
                                kind === value ? 'rxdm:border-primary rxdm:bg-card' : 'rxdm:border-border rxdm:bg-transparent'
                            }`}
                        >
                            <span className='rxdm:block rxdm:font-medium'>{value === 'subdomain' ? 'Free subdomain' : 'My own domain'}</span>
                            <span className='rxdm:block rxdm:text-xs rxdm:text-muted-foreground'>
                                {value === 'subdomain' ? 'Set up for you in seconds.' : 'From any registrar; you add the records.'}
                            </span>
                        </button>
                    ))}
                </div>
            )}

            {kind === 'subdomain' ? (
                <div className='rxdm:grid rxdm:gap-4 rxdm:sm:grid-cols-2'>
                    <Field id='rxdm-label' label='Name' error={submitted ? error : null} hint='Letters, digits and hyphens.'>
                        <Input
                            id='rxdm-label'
                            value={label}
                            maxLength={63}
                            autoComplete='off'
                            placeholder='myserver'
                            aria-invalid={submitted && !!error}
                            onChange={(e) => setLabel(e.target.value)}
                        />
                    </Field>
                    <Field id='rxdm-zone' label='Domain'>
                        <Select
                            id='rxdm-zone'
                            value={zone}
                            options={data.zones.map((z) => ({ value: z, label: z }))}
                            onChange={(v) => setZone(String(v))}
                        />
                    </Field>
                    <p className='rxdm:m-0 rxdm:break-all rxdm:text-sm rxdm:text-muted-foreground rxdm:sm:col-span-2'>
                        Address: <span className='rxdm:font-mono rxdm:text-foreground'>{(cleanLabel || 'name') + '.' + zone}</span>
                    </p>
                </div>
            ) : (
                <Field
                    id='rxdm-domain'
                    label='Your domain'
                    error={submitted ? error : null}
                    hint='A domain or subdomain you control, e.g. play.example.com. You will get the records to create at your DNS host.'
                >
                    <Input
                        id='rxdm-domain'
                        value={domain}
                        maxLength={253}
                        autoComplete='off'
                        placeholder='play.example.com'
                        aria-invalid={submitted && !!error}
                        onChange={(e) => setDomain(e.target.value)}
                    />
                </Field>
            )}

            {data.allocations.length > 1 && (
                <Field id='rxdm-allocation' label='Points to'>
                    <Select
                        id='rxdm-allocation'
                        value={allocation}
                        options={allocationOptions(data.allocations)}
                        onChange={(v) => setAllocation(Number(v))}
                    />
                </Field>
            )}

            {data.srv_service && (
                <p className='rxdm:m-0 rxdm:text-sm rxdm:text-muted-foreground'>
                    {kind === 'subdomain'
                        ? `An SRV record (${data.srv_service}) is added too, so players can join without the port.`
                        : `You also get an SRV record (${data.srv_service}) to add, so players can join without the port.`}
                </p>
            )}

            {kind === 'subdomain' && data.ssl_available && (
                <Switch
                    checked={certificate}
                    onChange={setCertificate}
                    label='Get a certificate'
                    description='A free Let’s Encrypt certificate, written to the server’s files and renewed automatically.'
                />
            )}

            <Dialog.Footer>
                <Button isSecondary onClick={onCancel} disabled={pending}>
                    Cancel
                </Button>
                <Button type='submit' isLoading={pending}>
                    Add domain
                </Button>
            </Dialog.Footer>
        </form>
    );
}
