import { Column } from 'devextreme-react/data-grid';
import CrudGrid from '../components/CrudGrid';
import Page from '../components/Page';

export default function AuditLogPage() {
    return (
        <Page title="Audit log" subtitle="Append-only record of material actions (latest 500)">
            <CrudGrid resource="/audit-logs">
                <Column dataField="occurred_at" caption="When" dataType="datetime" width={190} />
                <Column dataField="actor.name" caption="User" width={200} />
                <Column dataField="action" width={280} />
                <Column dataField="subject_type" caption="Record type" width={170} />
                <Column dataField="reason" />
                <Column caption="Change" calculateCellValue={(row: any) => (row.after ? JSON.stringify(row.after) : '')} />
                <Column dataField="ip" caption="IP" width={130} />
            </CrudGrid>
        </Page>
    );
}
