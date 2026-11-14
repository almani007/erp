import { create } from 'zustand';
import { persist } from 'zustand/middleware';

export interface SessionUser {
    id: string;
    name: string;
    email: string;
    tenant_id: string;
    all_branches: boolean;
    /** Platform operator: manages tenants and business templates. */
    is_platform_admin?: boolean;
}

export interface Branch {
    id: string;
    company_id: string;
    code: string;
    name: string;
}

interface SessionState {
    token: string | null;
    user: SessionUser | null;
    roles: string[];
    permissions: string[];
    branches: Branch[];
    /** null = all branches the user may see */
    activeBranchId: string | null;
    signIn: (payload: { token: string; user: SessionUser; roles: string[]; permissions: string[] }) => void;
    signOut: () => void;
    setBranches: (branches: Branch[]) => void;
    setActiveBranch: (id: string | null) => void;
}

export const useSession = create<SessionState>()(
    persist(
        (set) => ({
            token: null,
            user: null,
            roles: [],
            permissions: [],
            branches: [],
            activeBranchId: null,
            signIn: ({ token, user, roles, permissions }) => set({ token, user, roles, permissions }),
            signOut: () => set({ token: null, user: null, roles: [], permissions: [], branches: [], activeBranchId: null }),
            setBranches: (branches) =>
                set((state) => ({
                    branches,
                    activeBranchId: branches.some((b) => b.id === state.activeBranchId) ? state.activeBranchId : null,
                })),
            setActiveBranch: (activeBranchId) => set({ activeBranchId }),
        }),
        { name: 'erp-session' },
    ),
);

/** UI hint only — the server checks every request. */
export function can(permission: string): boolean {
    const { permissions } = useSession.getState();
    return permissions.includes('*') || permissions.includes(permission);
}

export function useCan(permission: string): boolean {
    return useSession((s) => s.permissions.includes('*') || s.permissions.includes(permission));
}

/** Company of the active branch, or of the first branch when none is selected. */
export function useCompanyId(): string | null {
    return useSession((s) => {
        const branch = s.branches.find((b) => b.id === s.activeBranchId) ?? s.branches[0];
        return branch?.company_id ?? null;
    });
}
