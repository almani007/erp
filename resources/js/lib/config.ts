import { useQuery } from '@tanstack/react-query';
import { getOne } from './api';

export const BUSINESS_TYPES = [
    { id: 'general', name: 'General / mixed (show everything)' },
    { id: 'grocery', name: 'Grocery & supermarket' },
    { id: 'garments', name: 'Garments & footwear' },
    { id: 'jewellery', name: 'Gold & jewellery' },
    { id: 'bartan', name: 'Bartan / crockery / utensils' },
    { id: 'wholesale', name: 'Wholesale & distribution' },
];

export interface Features {
    /** Wholesale price, minimum quantity and customer price tier. */
    wholesale: boolean;
    /** Selling in packs: carton, dozen, set. */
    packs: boolean;
    /** Size / colour variants of a style. */
    variants: boolean;
    /** Prices from a daily market rate: gold, silver, steel by weight. */
    rates: boolean;
}

const BY_TYPE: Record<string, Features> = {
    general: { wholesale: true, packs: true, variants: true, rates: true },
    grocery: { wholesale: true, packs: true, variants: false, rates: false },
    garments: { wholesale: true, packs: true, variants: true, rates: false },
    jewellery: { wholesale: false, packs: false, variants: false, rates: true },
    bartan: { wholesale: true, packs: true, variants: false, rates: true },
    wholesale: { wholesale: true, packs: true, variants: false, rates: false },
};

export interface AppConfig {
    business_type: string;
    loyalty: { earn_per_amount: string; point_value: string; enabled: boolean };
}

/**
 * What this business uses. The business type only decides which fields and
 * screens are shown — every feature keeps working on the server whatever it is.
 */
export function useAppConfig(): { config: AppConfig | undefined; features: Features; refetch: () => void } {
    const query = useQuery({ queryKey: ['app-config'], queryFn: () => getOne<AppConfig>('/app-config'), staleTime: 5 * 60_000 });

    return {
        config: query.data,
        features: BY_TYPE[query.data?.business_type ?? 'general'] ?? BY_TYPE.general,
        refetch: () => { query.refetch(); },
    };
}
