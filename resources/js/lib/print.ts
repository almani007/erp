import { money } from './format';

/** Escapes text for the printed HTML. */
export const esc = (value: unknown) =>
    String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c] as string));

export interface PrintParty { name: string; code?: string | null; phone?: string | null; address?: string | null; tax_no?: string | null }
export interface PrintLine { description: string; sku?: string; quantity: string | number; unit_price: string | number; discount?: string | number; tax?: string | number; total: string | number }

/**
 * A4 business document (quotation, sales order, invoice, statement) in a new
 * window, ready for the browser's print / save-as-PDF.
 */
export function printDocument(opts: {
    title: string;
    number: string;
    company: { legal_name: string; tax_registration_no?: string | null } | null | undefined;
    branch?: string | null;
    party: PrintParty;
    meta: [string, string | null | undefined][];
    lines?: PrintLine[];
    table?: { head: string[]; rows: (string | number)[][]; align?: ('left' | 'right')[] };
    totals: [string, string | number, boolean?][];
    notes?: string | null;
    terms?: string | null;
    currency?: string;
}) {
    const w = window.open('', '_blank', 'width=900,height=1000');
    if (!w) return;

    const head = opts.table?.head ?? ['#', 'Item', 'Qty', 'Rate', 'Discount', 'Tax', 'Amount'];
    const align = opts.table?.align ?? ['left', 'left', 'right', 'right', 'right', 'right', 'right'];
    const rows = opts.table?.rows ?? (opts.lines ?? []).map((l, i) => [
        i + 1, `${esc(l.description)}${l.sku ? `<div class="muted">${esc(l.sku)}</div>` : ''}`,
        Number(l.quantity).toLocaleString('en-PK', { maximumFractionDigits: 3 }), money(l.unit_price), money(l.discount ?? 0), money(l.tax ?? 0), money(l.total),
    ]);

    w.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>${esc(opts.title)} ${esc(opts.number)}</title><style>
        @page { size: A4; margin: 14mm; }
        * { box-sizing: border-box; }
        body { font: 12px/1.45 "Segoe UI", system-ui, sans-serif; color: #1f2937; margin: 0; padding: 24px; }
        .top { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 3px solid #1e3a8a; padding-bottom: 12px; }
        .brand { font-size: 20px; font-weight: 700; color: #1e3a8a; }
        .doc { text-align: right; } .doc h1 { margin: 0; font-size: 22px; letter-spacing: .5px; text-transform: uppercase; color: #111827; }
        .muted { color: #6b7280; font-size: 11px; }
        .grid { display: grid; grid-template-columns: 1.3fr 1fr; gap: 24px; margin: 18px 0; }
        .box h3 { margin: 0 0 4px; font-size: 11px; text-transform: uppercase; color: #6b7280; letter-spacing: .5px; }
        .meta td { padding: 2px 0; } .meta td:first-child { color: #6b7280; padding-right: 16px; }
        table.lines { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.lines th { background: #1e3a8a; color: #fff; font-weight: 600; padding: 7px 8px; font-size: 11px; }
        table.lines td { padding: 7px 8px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        table.lines tr:nth-child(even) td { background: #f8fafc; }
        .totals { margin-left: auto; width: 320px; margin-top: 12px; border-collapse: collapse; }
        .totals td { padding: 5px 8px; } .totals td:last-child { text-align: right; }
        .totals tr.grand td { border-top: 2px solid #1e3a8a; font-size: 15px; font-weight: 700; }
        .notes { margin-top: 22px; white-space: pre-wrap; } .sign { display: flex; justify-content: space-between; margin-top: 60px; }
        .sign div { border-top: 1px solid #9ca3af; width: 200px; text-align: center; padding-top: 4px; color: #6b7280; }
        footer { margin-top: 30px; text-align: center; color: #9ca3af; font-size: 10px; }
    </style></head><body>
    <div class="top">
        <div><div class="brand">${esc(opts.company?.legal_name ?? '')}</div>
            <div class="muted">${opts.branch ? esc(opts.branch) : ''}${opts.company?.tax_registration_no ? ` · NTN/STRN ${esc(opts.company.tax_registration_no)}` : ''}</div></div>
        <div class="doc"><h1>${esc(opts.title)}</h1><div style="font-size:14px;font-weight:600">${esc(opts.number)}</div></div>
    </div>
    <div class="grid">
        <div class="box"><h3>${opts.title.toLowerCase().includes('statement') ? 'Account' : 'Bill to'}</h3>
            <div style="font-size:14px;font-weight:600">${esc(opts.party.name)}</div>
            ${opts.party.code ? `<div class="muted">${esc(opts.party.code)}</div>` : ''}
            ${opts.party.address ? `<div>${esc(opts.party.address)}</div>` : ''}
            ${opts.party.phone ? `<div>${esc(opts.party.phone)}</div>` : ''}
            ${opts.party.tax_no ? `<div>Tax no. ${esc(opts.party.tax_no)}</div>` : ''}</div>
        <div class="box"><table class="meta">${opts.meta.filter(([, v]) => v).map(([k, v]) => `<tr><td>${esc(k)}</td><td><b>${esc(v)}</b></td></tr>`).join('')}</table></div>
    </div>
    <table class="lines"><thead><tr>${head.map((h, i) => `<th style="text-align:${align[i] ?? 'left'}">${esc(h)}</th>`).join('')}</tr></thead>
        <tbody>${rows.map((r) => `<tr>${r.map((c, i) => `<td style="text-align:${align[i] ?? 'left'}">${c}</td>`).join('')}</tr>`).join('')}</tbody></table>
    <table class="totals">${opts.totals.map(([k, v, grand]) => `<tr class="${grand ? 'grand' : ''}"><td>${esc(k)}</td><td>${typeof v === 'number' || /^-?[\d.]+$/.test(String(v)) ? `${opts.currency ?? ''} ${money(v)}` : esc(v)}</td></tr>`).join('')}</table>
    ${opts.notes ? `<div class="notes"><b>Notes</b><br>${esc(opts.notes)}</div>` : ''}
    ${opts.terms ? `<div class="notes"><b>Terms &amp; conditions</b><br>${esc(opts.terms)}</div>` : ''}
    <div class="sign"><div>Prepared by</div><div>Authorised signature</div><div>Received by</div></div>
    <footer>Printed ${new Date().toLocaleString('en-PK')}</footer>
    <script>window.onload = () => window.print()</script>
    </body></html>`);
    w.document.close();
}

/** Free-form printout (labels, cards) in a new window. `body` must already be escaped. */
export function printHtml(title: string, body: string) {
    const w = window.open('', '_blank', 'width=900,height=1000');
    if (!w) return;
    w.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>${esc(title)}</title>
        <style>@page { size: A4; margin: 12mm; } body { font: 12px/1.45 "Segoe UI", system-ui, sans-serif; color: #1f2937; margin: 0; padding: 16px; }</style>
        </head><body>${body}<script>window.onload = () => setTimeout(() => window.print(), 300);</script></body></html>`);
    w.document.close();
}
