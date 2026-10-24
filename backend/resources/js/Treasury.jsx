import React, { useState } from "react";
import { api, money, date, today } from "./api";
import { Field } from "./Forms";
import {
    Panel,
    DataTable,
    FormDialog,
    Feedback,
    useModule,
    field,
    select,
    button,
    payFields,
} from "./Modules";

export function RefundPanel({ data, can, saved }) {
    const m = useModule("/refunds"),
        [form, setForm] = useState(null);
    const due = data.invoices.filter((i) => i.refund_due > 0);
    return (
        <div className="module-stack">
            <Feedback m={m} />
            <Panel
                title="Crédits clients à rembourser"
                note="Enregistrez ici un remboursement déjà effectué. Aucun transfert bancaire ou mobile money n’est déclenché."
            >
                <DataTable
                    headers={[
                        "Facture / client",
                        "Avoirs",
                        "Déjà remboursé",
                        "À rembourser",
                        "",
                    ]}
                    empty={!due.length}
                >
                    {due.map((i) => (
                        <tr key={i.id}>
                            <td>
                                {i.number}
                                <small>{i.customer_snapshot.name}</small>
                            </td>
                            <td>{money(i.credited)}</td>
                            <td>{money(i.refunded)}</td>
                            <td>{money(i.refund_due)}</td>
                            <td>
                                {can("refunds.write") &&
                                    button("Enregistrer le remboursement", () =>
                                        setForm({
                                            title: `Remboursement ${i.number}`,
                                            note: "Vérifiez la référence du paiement effectué au client avant de confirmer.",
                                            url: `/invoices/${i.id}/refunds`,
                                            initial: {
                                                amount: i.refund_due,
                                                paid_at: today(),
                                                method: "Virement",
                                                reference: "",
                                                request_id: crypto.randomUUID(),
                                            },
                                            fields: [
                                                ...payFields,
                                                field(
                                                    "reference",
                                                    "Référence du remboursement effectué",
                                                ),
                                            ],
                                        }),
                                    )}
                            </td>
                        </tr>
                    ))}
                </DataTable>
            </Panel>
            {m.data && (
                <Panel title="Historique des remboursements">
                    <DataTable
                        headers={[
                            "Date",
                            "Facture",
                            "Montant",
                            "Moyen",
                            "Référence",
                        ]}
                        empty={!m.data.refunds.length}
                    >
                        {m.data.refunds.map((r) => (
                            <tr key={r.id}>
                                <td>{date(r.paid_at)}</td>
                                <td>
                                    {data.invoices.find(
                                        (i) => i.id === r.invoice_id,
                                    )?.number || `#${r.invoice_id}`}
                                </td>
                                <td>{money(r.amount)}</td>
                                <td>{r.method}</td>
                                <td>{r.reference}</td>
                            </tr>
                        ))}
                    </DataTable>
                </Panel>
            )}
            {form && (
                <FormDialog
                    {...form}
                    close={() => setForm(null)}
                    saved={async () => {
                        await m.reload();
                        await saved();
                    }}
                />
            )}
        </div>
    );
}

export function DraftPanel({ data, can, saved }) {
    const [form, setForm] = useState(null);
    const drafts = data.invoices.filter((i) => i.status === "draft");
    return (
        <Panel
            title="Brouillons et archives"
            note="L’archivage retire un brouillon des factures actives sans supprimer son historique. Il peut être restauré."
        >
            <DataTable
                headers={["Brouillon", "Client", "Montant", "État", ""]}
                empty={!drafts.length}
            >
                {drafts.map((i) => (
                    <tr key={i.id}>
                        <td>
                            #{i.id}
                            <small>{date(i.issue_date)}</small>
                        </td>
                        <td>{i.customer_snapshot.name}</td>
                        <td>{money(i.total)}</td>
                        <td>{i.archived ? "Archivé" : "Actif"}</td>
                        <td>
                            {can("invoices.write") &&
                                button(
                                    i.archived ? "Restaurer" : "Archiver",
                                    () =>
                                        setForm({
                                            title: i.archived
                                                ? "Restaurer le brouillon"
                                                : "Archiver le brouillon",
                                            url: `/invoices/${i.id}/archive`,
                                            method: "PUT",
                                            initial: { archived: !i.archived },
                                            note: `Brouillon #${i.id} — ${i.customer_snapshot.name}. Son historique est conservé.`,
                                            submitLabel: i.archived
                                                ? "Restaurer"
                                                : "Archiver",
                                        }),
                                )}
                        </td>
                    </tr>
                ))}
            </DataTable>
            {form && (
                <FormDialog
                    {...form}
                    close={() => setForm(null)}
                    saved={saved}
                />
            )}
        </Panel>
    );
}

export function ReturnsPanel({ data, can, saved }) {
    const m = useModule("/commerce"),
        [form, setForm] = useState(null);
    const movements = m.data?.movements || [];
    const sold = movements
        .filter((x) => x.source_key?.startsWith("invoice:"))
        .map((x) => ({
            ...x,
            remaining: -movements
                .filter(
                    (y) =>
                        y.invoice_id === x.invoice_id &&
                        y.product_id === x.product_id,
                )
                .reduce((sum, y) => sum + y.quantity, 0),
        }));
    return (
        <div className="module-stack">
            <Feedback m={m} />
            <Panel
                title="Retours de marchandises"
                note="La validation d’une vente déstocke les produits liés au catalogue. Enregistrez un retour uniquement après réception physique. L’avoir financier s’émet séparément."
            >
                <DataTable
                    headers={["Facture", "Produit", "Quantité retournable", ""]}
                    empty={!sold.length}
                >
                    {sold.map((s) => (
                        <tr key={s.id}>
                            <td>
                                {data.invoices.find(
                                    (i) => i.id === s.invoice_id,
                                )?.number || s.invoice_id}
                            </td>
                            <td>
                                {data.products.find(
                                    (p) => p.id === s.product_id,
                                )?.name || s.product_id}
                            </td>
                            <td>{s.remaining}</td>
                            <td>
                                {can("stock.write") &&
                                    s.remaining > 0 &&
                                    button("Réceptionner un retour", () =>
                                        setForm({
                                            title: "Retour de marchandise",
                                            url: `/invoices/${s.invoice_id}/returns`,
                                            initial: {
                                                product_id: s.product_id,
                                                quantity: s.remaining,
                                                reason: "Retour client",
                                                request_id: crypto.randomUUID(),
                                            },
                                            fields: [
                                                field(
                                                    "quantity",
                                                    "Quantité reçue",
                                                    "number",
                                                    {
                                                        min: 1,
                                                        max: s.remaining,
                                                    },
                                                ),
                                                field(
                                                    "reason",
                                                    "Motif du retour",
                                                ),
                                            ],
                                        }),
                                    )}
                            </td>
                        </tr>
                    ))}
                </DataTable>
            </Panel>
            {form && (
                <FormDialog
                    {...form}
                    close={() => setForm(null)}
                    saved={async () => {
                        await m.reload();
                        await saved();
                    }}
                />
            )}
        </div>
    );
}

export function BankPanel({ can }) {
    const m = useModule("/banking"),
        [form, setForm] = useState(null),
        [selected, setSelected] = useState(null);
    const d = m.data,
        statement = d?.statements.find((s) => s.id === selected);
    function create() {
        setForm({
            title: "Importer un relevé bancaire",
            note: "Montants entiers XOF. Une ligne par mouvement : date;libellé;montant signé (positif = entrée, négatif = sortie). Les lignes doivent être comprises dans la période.",
            url: "/banking",
            initial: {
                reference: "",
                account_code: d.accounts[0]?.code || "",
                from: today(),
                to: today(),
                opening_balance: 0,
                closing_balance: 0,
                csv: "",
            },
            fields: [
                field("reference", "Référence du relevé"),
                select(
                    "account_code",
                    "Compte bancaire",
                    d.accounts.map((a) => [a.code, `${a.code} · ${a.name}`]),
                ),
                field("from", "Début", "date"),
                field("to", "Fin", "date"),
                field("opening_balance", "Solde initial XOF", "number"),
                field("closing_balance", "Solde final XOF", "number"),
                field(
                    "csv",
                    "Mouvements : AAAA-MM-JJ;libellé;montant",
                    "textarea",
                    { required: false, maxLength: 500000 },
                ),
            ],
            transform: (v) => {
                const lines = v.csv
                    .split(/\r?\n/)
                    .filter((l) => l.trim())
                    .map((l, i) => {
                        const parts = l.split(";");
                        if (
                            parts.length !== 3 ||
                            !/^\d{4}-\d{2}-\d{2}$/.test(parts[0].trim()) ||
                            !/^-?\d+$/.test(parts[2].trim())
                        )
                            throw new Error(
                                `Ligne ${i + 1} : utilisez date;libellé;montant entier signé, sans séparateur de milliers.`,
                            );
                        return {
                            booked_at: parts[0].trim(),
                            description: parts[1].trim(),
                            amount: Number(parts[2].trim()),
                        };
                    });
                const { csv, ...rest } = v;
                return { ...rest, lines };
            },
        });
    }
    return (
        <div className="module-stack">
            <Feedback m={m} />
            {d && (
                <>
                    <Panel
                        title="Rapprochements bancaires"
                        note="Importez votre relevé puis associez chaque mouvement à une écriture du même compte. La finalisation exige une concordance des lignes et des soldes."
                        actions={
                            can("banking.write") &&
                            button("Importer un relevé", create)
                        }
                    >
                        <DataTable
                            headers={[
                                "Référence / compte",
                                "Période",
                                "Solde final banque",
                                "Solde final comptable",
                                "État",
                                "",
                            ]}
                            empty={!d.statements.length}
                        >
                            {d.statements.map((s) => (
                                <tr key={s.id}>
                                    <td>
                                        {s.reference}
                                        <small>{s.account_code}</small>
                                    </td>
                                    <td>
                                        {date(s.from)} → {date(s.to)}
                                    </td>
                                    <td>{money(s.closing_balance)}</td>
                                    <td>{money(s.ledger_closing)}</td>
                                    <td>
                                        {s.status === "reconciled"
                                            ? "Rapproché"
                                            : s.status === "cancelled"
                                              ? "Annulé"
                                              : "En cours"}
                                    </td>
                                    <td>
                                        {button("Ouvrir", () =>
                                            setSelected(s.id),
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </DataTable>
                    </Panel>
                    {statement && (
                        <Panel
                            title={`Relevé ${statement.reference}`}
                            note={`Solde initial banque : ${money(statement.opening_balance)} · Comptabilité : ${money(statement.ledger_opening)}. Les écritures manquantes se saisissent dans Comptabilité.`}
                            actions={
                                can("banking.write") &&
                                statement.status === "draft" &&
                                button("Finaliser le rapprochement", () =>
                                    setForm({
                                        title: "Finaliser le rapprochement",
                                        note: "Les associations deviennent définitives. Les nouvelles écritures bancaires antérieures ou égales à la fin de cette période seront bloquées.",
                                        url: `/banking/${statement.id}/reconcile`,
                                        initial: {},
                                        submitLabel: "Finaliser",
                                    }),
                                )
                            }
                        >
                            <DataTable
                                headers={[
                                    "Date",
                                    "Mouvement bancaire",
                                    "Montant signé",
                                    "Écriture associée",
                                    "",
                                ]}
                                empty={!statement.lines.length}
                            >
                                {statement.lines.map((l) => (
                                    <tr key={l.id}>
                                        <td>{date(l.booked_at)}</td>
                                        <td>{l.description}</td>
                                        <td>{money(l.amount)}</td>
                                        <td>
                                            {l.journal_line_id
                                                ? `Écriture / ligne #${l.journal_line_id}`
                                                : "Non rapproché"}
                                        </td>
                                        <td>
                                            {can("banking.write") &&
                                                statement.status === "draft" &&
                                                (l.journal_line_id
                                                    ? button(
                                                          "Dissocier",
                                                          () =>
                                                              m
                                                                  .act(
                                                                      `/banking/${statement.id}/lines/${l.id}`,
                                                                      {
                                                                          journal_line_id:
                                                                              null,
                                                                      },
                                                                      "PUT",
                                                                  )
                                                                  .catch(
                                                                      () => {},
                                                                  ),
                                                          m.busy,
                                                      )
                                                    : button("Associer", () =>
                                                          setForm({
                                                              title: "Associer une écriture",
                                                              url: `/banking/${statement.id}/lines/${l.id}`,
                                                              method: "PUT",
                                                              initial: {
                                                                  journal_line_id:
                                                                      "",
                                                              },
                                                              fields: [
                                                                  select(
                                                                      "journal_line_id",
                                                                      `Mouvement ${money(l.amount)}`,
                                                                      [
                                                                          [
                                                                              "",
                                                                              "Sélectionnez une écriture",
                                                                          ],
                                                                          ...statement.candidates
                                                                              .filter(
                                                                                  (
                                                                                      c,
                                                                                  ) =>
                                                                                      c.debit -
                                                                                          c.credit ===
                                                                                      l.amount,
                                                                              )
                                                                              .map(
                                                                                  (
                                                                                      c,
                                                                                  ) => [
                                                                                      c.id,
                                                                                      `${date(c.entry_date)} · ${c.label} · ${money(c.debit - c.credit)}`,
                                                                                  ],
                                                                              ),
                                                                      ],
                                                                  ),
                                                              ],
                                                          }),
                                                      ))}
                                        </td>
                                    </tr>
                                ))}
                            </DataTable>
                            <div className="module-toolbar">
                                <p>
                                    {statement.candidates.length} écriture(s)
                                    bancaire(s) non associée(s) dans cette
                                    période.
                                </p>
                                {button("Actualiser", m.reload)}
                                {can("banking.write") &&
                                    statement.status === "draft" &&
                                    button("Annuler ce relevé", () =>
                                        setForm({
                                            title: "Annuler le relevé",
                                            note: "Le relevé sera conservé dans l’historique, ses associations seront libérées. Vous pourrez importer un nouveau relevé sur la même période avec une nouvelle référence.",
                                            url: `/banking/${statement.id}/cancel`,
                                            initial: {},
                                            submitLabel: "Annuler le relevé",
                                        }),
                                    )}
                            </div>
                        </Panel>
                    )}
                </>
            )}
            {form && (
                <FormDialog
                    {...form}
                    close={() => setForm(null)}
                    saved={m.reload}
                />
            )}
        </div>
    );
}

export function ReportsPanel() {
    const [period, setPeriod] = useState({
        from: today().slice(0, 4) + "-01-01",
        to: today(),
    });
    const m = useModule("/accounting?" + new URLSearchParams(period)),
        d = m.data;
    const charges =
        d?.accounts
            .filter((a) => a.code.startsWith("6"))
            .reduce((s, a) => s + a.debit - a.credit, 0) || 0;
    const products =
        d?.accounts
            .filter((a) => a.code.startsWith("7"))
            .reduce((s, a) => s + a.credit - a.debit, 0) || 0;
    const others =
        d?.accounts
            .filter((a) => a.code.startsWith("8"))
            .reduce((s, a) => s + a.credit - a.debit, 0) || 0;
    function download() {
        const rows = [
            [
                "Compte",
                "Intitulé",
                "Débit XOF",
                "Crédit XOF",
                "Solde débiteur XOF",
            ],
            ...d.accounts.map((a) => [
                a.code,
                a.name,
                a.debit,
                a.credit,
                a.debit - a.credit,
            ]),
        ];
        const csv =
            "\ufeff" +
            rows
                .map((row) =>
                    row
                        .map(
                            (v) =>
                                '"' +
                                String(v)
                                    .replace(/^[=+@-]/, "'$&")
                                    .replaceAll('"', '""') +
                                '"',
                        )
                        .join(";"),
                )
                .join("\r\n");
        const url = URL.createObjectURL(
            new Blob([csv], { type: "text/csv;charset=utf-8" }),
        );
        const a = document.createElement("a");
        a.href = url;
        a.download = `balance-${period.from}-${period.to}.csv`;
        a.click();
        URL.revokeObjectURL(url);
    }
    return (
        <div className="module-stack">
            <Feedback m={m} />
            <div className="module-toolbar">
                <Field label="Du">
                    <input
                        type="date"
                        value={period.from}
                        onChange={(e) =>
                            setPeriod({ ...period, from: e.target.value })
                        }
                    />
                </Field>
                <Field label="Au">
                    <input
                        type="date"
                        value={period.to}
                        onChange={(e) =>
                            setPeriod({ ...period, to: e.target.value })
                        }
                    />
                </Field>
                {d && button("Exporter les soldes de la période", download)}
            </div>
            {d && (
                <>
                    <div className="module-summary">
                        <div>
                            <span>Produits — comptes de classe 7</span>
                            <strong>{money(products)}</strong>
                        </div>
                        <div>
                            <span>Charges — comptes de classe 6</span>
                            <strong>{money(charges)}</strong>
                        </div>
                        <div>
                            <span>Résultat des comptes 6 et 7</span>
                            <strong>{money(products - charges)}</strong>
                        </div>
                    </div>
                    <Panel
                        title="Soldes par classe de comptes"
                        note="Synthèse de gestion calculée à partir des écritures comptabilisées sur la période. Les comptes de classe 8 sont présentés séparément ; ce document ne constitue pas une déclaration fiscale."
                    >
                        <DataTable
                            headers={[
                                "Classe",
                                "Débit",
                                "Crédit",
                                "Solde débiteur net",
                            ]}
                        >
                            {Array.from({ length: 8 }, (_, i) =>
                                String(i + 1),
                            ).map((prefix) => {
                                const accounts = d.accounts.filter((a) =>
                                    a.code.startsWith(prefix),
                                );
                                const debit = accounts.reduce(
                                        (s, a) => s + a.debit,
                                        0,
                                    ),
                                    credit = accounts.reduce(
                                        (s, a) => s + a.credit,
                                        0,
                                    );
                                return (
                                    <tr key={prefix}>
                                        <td>Classe {prefix}</td>
                                        <td>{money(debit)}</td>
                                        <td>{money(credit)}</td>
                                        <td>{money(debit - credit)}</td>
                                    </tr>
                                );
                            })}
                        </DataTable>
                        <p className="module-toolbar">
                            Solde créditeur net des comptes de classe 8 :{" "}
                            {money(others)}.
                        </p>
                    </Panel>
                </>
            )}
        </div>
    );
}
