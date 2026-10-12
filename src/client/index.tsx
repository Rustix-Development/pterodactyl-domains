import './styles.css';
import { definePterodactylExtension } from '@pterodactyl/sdk';

export default definePterodactylExtension({
    setup({ screens }) {
        screens.register('domains', () => import('./screens/DomainsScreen'));
        screens.register('overview', () => import('./screens/AdminOverview'));
    },
});
