import { useQuery } from '@tanstack/react-query';
import { getOne } from './api';

export interface Features {
    /** Wholesale price, minimum quantity and customer price tier. */
    wholesale: boolean;
    /** Selling in packs: carton, dozen, set. */
    packs: boolean;
    /** Size / colour variants of a style. */
    variants: boolean;
    /** Prices from a daily market rate: gold, silver, steel by weight. */
    rates: boolean;
    /** Batch numbers and expiry dates, sold first-expiry-first-out. */
    expiry: boolean;
    /** Serial / IMEI per unit sold, with warranty. */
    serials: boolean;
}

export const FEATURE_LABELS: { key: keyof Features; label: string }[] = [
    { key: 'wholesale', label: 'Wholesale prices, minimum quantities and wholesale customers' },
    { key: 'packs', label: 'Selling in packs — carton, dozen, set' },
    { key: 'variants', label: 'Size / colour variants of a style' },
    { key: 'rates', label: 'Prices from a daily market rate — gold, silver, steel by weight' },
    { key: 'expiry', label: 'Batch numbers and expiry dates — medicines, food, cosmetics' },
    { key: 'serials', label: 'Serial / IMEI numbers and warranty — phones, electronics, tools' },
];

const all = (on: boolean): Features => ({ wholesale: on, packs: on, variants: on, rates: on, expiry: on, serials: on });

/** Starting points. Every switch can still be turned on or off individually. */
export const BUSINESS_TYPES: { id: string; name: string; features: Features }[] = [
    { id: 'general', name: 'General / mixed (everything on)', features: all(true) },
    { id: 'grocery', name: 'Grocery & supermarket', features: { ...all(false), wholesale: true, packs: true, expiry: true } },
    { id: 'pharmacy', name: 'Pharmacy & medical store', features: { ...all(false), packs: true, expiry: true } },
    { id: 'garments', name: 'Garments, footwear & fabric', features: { ...all(false), wholesale: true, packs: true, variants: true } },
    { id: 'jewellery', name: 'Gold & jewellery', features: { ...all(false), rates: true, serials: true } },
    { id: 'bartan', name: 'Bartan, crockery & utensils', features: { ...all(false), wholesale: true, packs: true, rates: true } },
    { id: 'electronics', name: 'Electronics & mobile phones', features: { ...all(false), wholesale: true, serials: true } },
    { id: 'hardware', name: 'Hardware, sanitary & building material', features: { ...all(false), wholesale: true, packs: true, rates: true } },
    { id: 'cosmetics', name: 'Cosmetics & general store', features: { ...all(false), wholesale: true, variants: true, expiry: true } },
    { id: 'wholesale', name: 'Wholesale & distribution', features: { ...all(false), wholesale: true, packs: true, expiry: true } },
    { id: 'services', name: 'Services (salon, clinic, workshop, consultancy)', features: all(false) },
    { id: 'restaurant', name: 'Restaurant, café & bakery', features: { ...all(false), expiry: true } },
    { id: 'manufacturing', name: 'Small manufacturing & workshop', features: { ...all(false), wholesale: true, packs: true } },
    { id: 'rental', name: 'Rental & hire', features: { ...all(false), serials: true } },
];

export type ModuleName = 'sales' | 'catalog' | 'inventory' | 'purchasing' | 'finance' | 'reporting' | 'records' | 'production' | 'people' | 'assets';

export interface AppConfig {
    business_type: string;
    /** Individual switches that override the business type's defaults. */
    features: Partial<Features>;
    loyalty: { earn_per_amount: string; point_value: string; enabled: boolean };
    /** What the plan includes, set by the platform operator. */
    entitlements?: { modules: Partial<Record<ModuleName, boolean>>; features: Partial<Features> };
    company?: { id: string; legal_name: string; tax_registration_no: string | null; base_currency: string } | null;
    print?: { invoice_terms?: string | null; quotation_terms?: string | null };
}

export function presetFeatures(type: string | undefined): Features {
    return (BUSINESS_TYPES.find((t) => t.id === type) ?? BUSINESS_TYPES[0]).features;
}

/**
 * What this business uses. These switches only decide which fields and
 * screens are shown — the server behaves the same whatever they are.
 */
export function useAppConfig(): { config: AppConfig | undefined; features: Features; moduleOn: (m: ModuleName) => boolean; refetch: () => void } {
    const query = useQuery({ queryKey: ['app-config'], queryFn: () => getOne<AppConfig>('/app-config'), staleTime: 5 * 60_000 });

    return {
        config: query.data,
        // A feature the plan excludes stays off whatever the business switched on.
        features: { ...presetFeatures(query.data?.business_type), ...(query.data?.features ?? {}), ...(query.data?.entitlements?.features ?? {}) },
        moduleOn: (m) => query.data?.entitlements?.modules?.[m] !== false,
        refetch: () => { query.refetch(); },
    };
}
