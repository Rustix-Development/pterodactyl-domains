import { CopyOnClick } from '@pterodactyl/sdk';
import type { DnsRecord } from '../api';

const ROLE: Record<DnsRecord['role'], string> = {
    address: 'Points the domain at the server',
    srv: 'Lets players join without the port (optional)',
    delegation: 'Lets the panel get certificates for you',
};

function Value({ label, text }: { label: string; text: string }) {
    return (
        <div className='rxdm:min-w-0'>
            <dt className='rxdm:text-xs rxdm:uppercase rxdm:tracking-wide rxdm:text-muted-foreground'>{label}</dt>
            <dd className='rxdm:m-0'>
                <CopyOnClick text={text}>
                    <code className='rxdm:block rxdm:cursor-pointer rxdm:break-all rxdm:rounded rxdm:bg-muted rxdm:px-2 rxdm:py-1 rxdm:font-mono rxdm:text-xs'>
                        {text}
                    </code>
                </CopyOnClick>
            </dd>
        </div>
    );
}

/** The records to create at the user's DNS host, each value copyable. */
export default function RecordList({ records }: { records: DnsRecord[] }) {
    return (
        <ul className='rxdm:m-0 rxdm:flex rxdm:list-none rxdm:flex-col rxdm:gap-3 rxdm:p-0'>
            {records.map((record) => (
                <li key={record.role} className='rxdm:rounded-md rxdm:border rxdm:border-border rxdm:p-3'>
                    <p className='rxdm:m-0 rxdm:mb-2 rxdm:text-sm'>
                        <span className='rxdm:mr-2 rxdm:rounded rxdm:bg-muted rxdm:px-1.5 rxdm:py-0.5 rxdm:font-mono rxdm:text-xs'>
                            {record.type}
                        </span>
                        <span className='rxdm:text-muted-foreground'>{ROLE[record.role]}</span>
                    </p>
                    <dl className='rxdm:m-0 rxdm:grid rxdm:gap-2 rxdm:sm:grid-cols-2'>
                        <Value label='Name' text={record.name} />
                        <Value label={record.type === 'SRV' ? 'Value (priority weight port target)' : 'Value'} text={record.value} />
                    </dl>
                </li>
            ))}
        </ul>
    );
}
