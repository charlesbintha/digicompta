import React, { useEffect, useState } from "react";
import {
    Plus,
    RefreshCw,
    Download,
    Mail,
    Bell,
    Check,
    Send,
    ArrowRight,
    Pencil,
    Archive,
    BookOpen,
} from "lucide-react";
import { api, money, date, today } from "./api";
import { Field, Modal } from "./Forms";

const labels = {
    draft: "Brouillon",
    reconciled: "Rapproché",
    accepted: "Accepté",
    declined: "Refusé",
    converted: "Converti",
    approved: "Validé",
    pending: "En attente",
    paid: "Soldé",
    cancelled: "Annulé",
    open: "Ouvert",
    in_progress: "En cours",
    resolved: "Résolu",
    closed: "Fermé",
    normal: "Normale",
    high: "Haute",
    critical: "Critique",
    bug: "Bug",
    question: "Question",
    billing: "Facturation",
    queued: "En file",
    sending: "Envoi en cours",
    sent: "Envoyé",
    failed: "Échec",
    rejected: "Rejetée",
};
const stamp = (v) =>
    v
        ? new Date(
              v.replace(" ", "T") +
                  (v.includes("Z") || v.includes("+") ? "" : "Z"),
          ).toLocaleString("fr-SN", {
              timeZone: "Africa/Dakar",
              dateStyle: "short",
              timeStyle: "short",
          })
        : "—";
export function Tag({ value }) {
    return (
        <span className={`badge ${value}`}>
            <i />
            {labels[value] || value}
        </span>
    );
}
export function DataTable({ headers, children, empty = false }) {
    return empty ? (
        <div className="empty">
            <BookOpen size={26} />
            <h3>Aucun élément à afficher</h3>
            <p>Les opérations enregistrées apparaîtront ici.</p>
        </div>
    ) : (
        <div className="table-scroll">
            <table>
                <thead>
                    <tr>
                        {headers.map((h, i) => (
                            <th key={i}>{h}</th>
                        ))}
                    </tr>
                </thead>
                <tbody>{children}</tbody>
            </table>
        </div>
    );
}
export function Panel({ title, note, actions, children }) {
    return (
        <section className="panel module-panel">
            <div className="panel-heading">
                <div>
                    <h2>{title}</h2>
                    {note && <p>{note}</p>}
                </div>
                <div className="module-actions">{actions}</div>
            </div>
            {children}
        </section>
    );
}
export function useModule(path) {
    const [data, setData] = useState(null),
        [error, setError] = useState(""),
        [busy, setBusy] = useState(false);
    async function reload() {
        try {
            setData(await api(path));
            setError("");
        } catch (e) {
            setError(e.message);
        }
    }
    useEffect(() => {
        setData(null);
        reload();
    }, [path]);
    async function act(url, body = {}, method = "POST") {
        setBusy(true);
        setError("");
        try {
            const r = await api(url, { method, body });
            await reload();
            return r;
        } catch (e) {
            setError(e.message);
            throw e;
        } finally {
            setBusy(false);
        }
    }
    return { data, error, busy, reload, act, setError };
}
export function Feedback({ m }) {
    return (
        <>
            {m.error && (
                <div className="error-box" role="alert">
                    {m.error}
                </div>
            )}
            {!m.data && !m.error && <p className="helper">Chargement…</p>}
        </>
    );
}
export const button = (text, click, disabled = false) => (
    <button
        className="button secondary small"
        onClick={click}
        disabled={disabled}
    >
        {text}
    </button>
);
export function FormDialog({
    title,
    note,
    initial,
    fields = [],
    url,
    method = "POST",
    close,
    saved,
    children,
    transform = (v) => v,
    submitLabel = "Enregistrer",
}) {
    const [v, setV] = useState(initial),
        [error, setError] = useState(""),
        [busy, setBusy] = useState(false);
    const set = (k, value) => setV((old) => ({ ...old, [k]: value }));
    async function submit(e) {
        e.preventDefault();
        setBusy(true);
        setError("");
        try {
            await api(typeof url === "function" ? url(v) : url, {
                method,
                body: transform(v),
            });
            await saved?.();
            close();
        } catch (e) {
            setError(e.message);
        } finally {
            setBusy(false);
        }
    }
    return (
        <Modal title={title} subtitle={note} close={close} wide={!!children}>
            <form onSubmit={submit}>
                <div className="form-body">
                    {error && (
                        <div className="error-box" role="alert">
                            {error}
                        </div>
                    )}
                    <div className="form-grid">
                        {fields.map((f) =>
                            f.type === "checkbox" ? (
                                <label className="check-field" key={f.key}>
                                    <input
                                        type="checkbox"
                                        checked={!!v[f.key]}
                                        onChange={(e) =>
                                            set(f.key, e.target.checked)
                                        }
                                    />
                                    {f.label}
                                </label>
                            ) : (
                                <Field key={f.key} label={f.label}>
                                    {f.options ? (
                                        <select
                                            value={v[f.key] ?? ""}
                                            required={
                                                f.required !== false &&
                                                ![
                                                    "assigned_to",
                                                    "plan_id",
                                                ].includes(f.key)
                                            }
                                            onChange={(e) =>
                                                set(f.key, e.target.value)
                                            }
                                        >
                                            {f.options.map((o) => (
                                                <option
                                                    key={
                                                        Array.isArray(o)
                                                            ? o[0]
                                                            : o
                                                    }
                                                    value={
                                                        Array.isArray(o)
                                                            ? o[0]
                                                            : o
                                                    }
                                                >
                                                    {Array.isArray(o)
                                                        ? o[1]
                                                        : o}
                                                </option>
                                            ))}
                                        </select>
                                    ) : f.type === "textarea" ? (
                                        <textarea
                                            value={v[f.key] ?? ""}
                                            required={
                                                f.required !== false &&
                                                ![
                                                    "assigned_to",
                                                    "plan_id",
                                                ].includes(f.key)
                                            }
                                            maxLength={f.maxLength || 10000}
                                            onChange={(e) =>
                                                set(f.key, e.target.value)
                                            }
                                            rows={4}
                                        />
                                    ) : (
                                        <input
                                            type={f.type || "text"}
                                            value={v[f.key] ?? ""}
                                            required={
                                                f.required !== false &&
                                                ![
                                                    "assigned_to",
                                                    "plan_id",
                                                ].includes(f.key)
                                            }
                                            min={f.min}
                                            max={f.max}
                                            step={
                                                f.type === "number"
                                                    ? 1
                                                    : undefined
                                            }
                                            maxLength={f.maxLength || 255}
                                            autoComplete={
                                                f.type === "password"
                                                    ? "new-password"
                                                    : undefined
                                            }
                                            onChange={(e) =>
                                                set(f.key, e.target.value)
                                            }
                                        />
                                    )}
                                </Field>
                            ),
                        )}
                    </div>
                    {children?.(v, set)}
                </div>
                <div className="modal-footer">
                    <button
                        type="button"
                        className="button secondary"
                        onClick={close}
                    >
                        Annuler
                    </button>
                    <button className="button" disabled={busy}>
                        {busy ? "Enregistrement…" : submitLabel}
                    </button>
                </div>
            </form>
        </Modal>
    );
}
export const field = (key, label, type = "text", extra = {}) => ({
    key,
    label,
    type,
    ...extra,
});
export const select = (key, label, options) => ({ key, label, options });
export const payFields = [
    field("amount", "Montant XOF", "number", { min: 1 }),
    field("paid_at", "Date du règlement", "date"),
    select("method", "Moyen de paiement", [
        "Virement",
        "Espèces",
        "Wave",
        "Orange Money",
        "Chèque",
    ]),
];
function InvoiceLines({ v, set }) {
    return (
        <div className="line-editor">
            <h3>Articles</h3>
            {v.items.map((item, i) => (
                <div className="module-line" key={i}>
                    {[
                        ["description", "Description", "text"],
                        ["quantity", "Quantité", "number"],
                        ["unit_price", "Prix unitaire XOF", "number"],
                    ].map(([k, label, type]) => (
                        <Field key={k} label={label}>
                            <input
                                type={type}
                                min={k === "quantity" ? 1 : 0}
                                step={1}
                                required
                                value={item[k]}
                                onChange={(e) =>
                                    set(
                                        "items",
                                        v.items.map((l, j) =>
                                            j === i
                                                ? { ...l, [k]: e.target.value }
                                                : l,
                                        ),
                                    )
                                }
                            />
                        </Field>
                    ))}
                    <button
                        type="button"
                        className="icon-button"
                        aria-label={`Retirer la ligne ${i + 1}`}
                        disabled={v.items.length === 1}
                        onClick={() =>
                            set(
                                "items",
                                v.items.filter((_, j) => j !== i),
                            )
                        }
                    >
                        ×
                    </button>
                </div>
            ))}
            <button
                type="button"
                className="button secondary small"
                onClick={() =>
                    set("items", [
                        ...v.items,
                        { description: "", quantity: 1, unit_price: 0 },
                    ])
                }
            >
                <Plus size={16} />
                Ajouter une ligne
            </button>
        </div>
    );
}

export function CommercePanel({ page, data: business, can, saved, company }) {
    const m = useModule("/commerce"),
        [form, setForm] = useState(null),
        [detail, setDetail] = useState(null);
    const d = m.data;
    const done = async () => {
        await m.reload();
        await saved();
    };
    const run = (path, body) =>
        m
            .act(path, body)
            .then(saved)
            .catch(() => {});
    const close = () => setForm(null);
    const contact = (id) =>
        business.contacts.find((c) => c.id === id)?.name || `Contact #${id}`;
    const product = (id) =>
        business.products.find((p) => p.id === id)?.name || `Produit #${id}`;
    function quote() {
        setForm({
            title: "Nouveau devis",
            url: "/quotes",
            initial: {
                contact_id: "",
                valid_until: today(),
                tax_basis_points: 0,
                notes: "",
                items: [{ description: "", quantity: 1, unit_price: 0 }],
            },
            fields: [
                select("contact_id", "Client", [
                    ["", "Sélectionnez un client"],
                    ...business.contacts
                        .filter((c) => c.type === "client" && !c.archived)
                        .map((c) => [c.id, c.name]),
                ]),
                field("valid_until", "Valable jusqu’au", "date"),
                field(
                    "tax_basis_points",
                    "Taxe en points de base (1800 = 18 %)",
                    "number",
                    { min: 0, max: 10000 },
                ),
                field("notes", "Conditions / notes", "textarea", {
                    required: false,
                }),
            ],
            children: (v, set) => <InvoiceLines v={v} set={set} />,
        });
    }
    function purchase() {
        setForm({
            title: "Facture fournisseur",
            url: "/purchases",
            initial: {
                supplier_id: "",
                reference: "",
                description: "",
                issue_date: today(),
                due_date: today(),
                subtotal: 0,
                tax_amount: 0,
                account_code: "601",
                product_id: "",
                quantity: "",
            },
            fields: [
                select("supplier_id", "Fournisseur", [
                    ["", "Sélectionnez un fournisseur"],
                    ...business.contacts
                        .filter((c) => c.type === "supplier" && !c.archived)
                        .map((c) => [c.id, c.name]),
                ]),
                field("reference", "Référence fournisseur"),
                field("description", "Libellé"),
                field("issue_date", "Date de facture", "date"),
                field("due_date", "Échéance", "date"),
                field("subtotal", "Montant HT XOF", "number", { min: 1 }),
                field("tax_amount", "Taxe déductible XOF", "number", {
                    min: 0,
                }),
                field("account_code", "Compte de charge"),
                {
                    ...select(
                        "product_id",
                        "Produit réceptionné (facultatif)",
                        [
                            ["", "Aucune entrée de stock"],
                            ...business.products
                                .filter(
                                    (p) => p.type === "product" && !p.archived,
                                )
                                .map((p) => [p.id, p.name]),
                        ],
                    ),
                    required: false,
                },
                field("quantity", "Quantité réceptionnée", "number", {
                    min: 1,
                    required: false,
                }),
            ],
        });
    }
    function stock() {
        setForm({
            title: "Mouvement de stock",
            note: "Entrée : quantité positive. Sortie : quantité négative. Les factures liées au catalogue déstockent à la validation ; cet écran sert aux ajustements manuels.",
            url: "/stock",
            initial: { product_id: "", quantity: 1, reason: "" },
            fields: [
                select("product_id", "Produit", [
                    ["", "Sélectionnez un produit"],
                    ...business.products
                        .filter((p) => p.type === "product" && !p.archived)
                        .map((p) => [p.id, p.name]),
                ]),
                field("quantity", "Quantité (+ entrée / − sortie)", "number"),
                field("reason", "Motif"),
            ],
        });
    }
    function credit() {
        setForm({
            title: "Émettre un avoir",
            note: "Réduit le montant facturé. Pour une facture encaissée, l’avoir ouvre un crédit à rembourser séparément. Cette écriture est définitive.",
            url: (v) => `/invoices/${v.invoice_id}/credits`,
            initial: {
                invoice_id: "",
                total: 1,
                reason: "",
                issued_at: today(),
                include_paid: true,
            },
            fields: [
                select("invoice_id", "Facture", [
                    ["", "Sélectionnez une facture"],
                    ...business.invoices
                        .filter(
                            (i) =>
                                ["issued", "paid"].includes(i.status) &&
                                i.credited < i.total,
                        )
                        .map((i) => [
                            i.id,
                            `${i.number} — montant restant à créditer ${money(i.total - i.credited)}`,
                        ]),
                ]),
                field("total", "Montant TTC de l’avoir XOF", "number", {
                    min: 1,
                }),
                field("reason", "Motif de l’avoir"),
                field("issued_at", "Date de l’avoir", "date"),
            ],
            submitLabel: "Émettre l’avoir",
        });
    }
    return (
        <div className="module-stack">
            <Feedback m={m} />
            {d && (
                <>
                    {page === "quotes" && (
                        <Panel
                            title={`${d.quotes.length} devis`}
                            note="Enregistrez la décision de votre client, puis convertissez le devis accepté en facture brouillon."
                            actions={
                                can("quotes.write") &&
                                button("Nouveau devis", quote)
                            }
                        >
                            <DataTable
                                headers={[
                                    "Référence / client",
                                    "Validité",
                                    "Total TTC",
                                    "Statut",
                                    "Actions",
                                ]}
                                empty={!d.quotes.length}
                            >
                                {d.quotes.map((q) => (
                                    <tr key={q.id}>
                                        <td>
                                            <strong>DEV-{q.id}</strong>
                                            <small>
                                                {contact(q.contact_id)}
                                            </small>
                                        </td>
                                        <td>{date(q.valid_until)}</td>
                                        <td className="amount">
                                            {money(q.total)}
                                        </td>
                                        <td>
                                            <Tag value={q.status} />
                                        </td>
                                        <td>
                                            <div className="module-actions">
                                                {button("Voir", () =>
                                                    setDetail(q),
                                                )}
                                                {can("quotes.write") &&
                                                    q.status === "draft" && (
                                                        <>
                                                            {button(
                                                                "Accepté",
                                                                () =>
                                                                    run(
                                                                        `/quotes/${q.id}/action`,
                                                                        {
                                                                            action: "accept",
                                                                        },
                                                                    ),
                                                                m.busy,
                                                            )}
                                                            {button(
                                                                "Refusé",
                                                                () =>
                                                                    run(
                                                                        `/quotes/${q.id}/action`,
                                                                        {
                                                                            action: "decline",
                                                                        },
                                                                    ),
                                                                m.busy,
                                                            )}
                                                        </>
                                                    )}
                                                {can("quotes.write") &&
                                                    can("invoices.write") &&
                                                    q.status === "accepted" &&
                                                    button(
                                                        "Créer la facture",
                                                        () =>
                                                            run(
                                                                `/quotes/${q.id}/action`,
                                                                {
                                                                    action: "convert",
                                                                },
                                                            ),
                                                        m.busy,
                                                    )}
                                                {q.invoice_id && (
                                                    <small>
                                                        Facture #{q.invoice_id}
                                                    </small>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </DataTable>
                        </Panel>
                    )}
                    {page === "purchases" && (
                        <Panel
                            title="Factures fournisseurs"
                            note="Saisie → validation comptable → règlements. Les dépenses déjà payées se saisissent dans Dépenses."
                            actions={
                                can("purchases.write") &&
                                button("Nouvel achat", purchase)
                            }
                        >
                            <DataTable
                                headers={[
                                    "Référence / fournisseur",
                                    "Échéance",
                                    "Total TTC",
                                    "Solde",
                                    "Statut",
                                    "Actions",
                                ]}
                                empty={!d.purchases.length}
                            >
                                {d.purchases.map((p) => (
                                    <tr key={p.id}>
                                        <td>
                                            <strong>{p.reference}</strong>
                                            <small>
                                                {contact(p.supplier_id)} ·{" "}
                                                {p.description}
                                            </small>
                                        </td>
                                        <td>{date(p.due_date)}</td>
                                        <td>{money(p.total)}</td>
                                        <td className="amount">
                                            {money(p.total - p.paid)}
                                        </td>
                                        <td>
                                            <Tag value={p.status} />
                                        </td>
                                        <td>
                                            <div className="module-actions">
                                                {can("purchases.approve") &&
                                                    p.status === "draft" &&
                                                    button(
                                                        "Valider",
                                                        () =>
                                                            run(
                                                                `/purchases/${p.id}/approve`,
                                                            ),
                                                        m.busy,
                                                    )}
                                                {can("purchases.pay") &&
                                                    p.status === "approved" &&
                                                    button("Régler", () =>
                                                        setForm({
                                                            title: `Règlement ${p.reference}`,
                                                            url: `/purchases/${p.id}/payments`,
                                                            initial: {
                                                                amount:
                                                                    p.total -
                                                                    p.paid,
                                                                paid_at:
                                                                    today(),
                                                                method: "Virement",
                                                                request_id:
                                                                    crypto.randomUUID(),
                                                            },
                                                            fields: payFields,
                                                        }),
                                                    )}
                                                {button("Historique", () =>
                                                    setDetail({
                                                        purchase: p,
                                                        payments:
                                                            d.purchase_payments.filter(
                                                                (x) =>
                                                                    x.purchase_id ===
                                                                    p.id,
                                                            ),
                                                    }),
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </DataTable>
                        </Panel>
                    )}
                    {page === "credits" && (
                        <Panel
                            title="Avoirs clients"
                            note="Les avoirs diminuent le solde client et corrigent automatiquement la comptabilité."
                            actions={
                                can("credits.write") &&
                                button("Nouvel avoir", credit)
                            }
                        >
                            <DataTable
                                headers={[
                                    "Avoir / facture",
                                    "Date",
                                    "Motif",
                                    "HT",
                                    "Taxe",
                                    "TTC",
                                ]}
                                empty={!d.credits.length}
                            >
                                {d.credits.map((c) => (
                                    <tr key={c.id}>
                                        <td>
                                            <strong>AV-{c.id}</strong>
                                            <small>
                                                {
                                                    business.invoices.find(
                                                        (i) =>
                                                            i.id ===
                                                            c.invoice_id,
                                                    )?.number
                                                }
                                            </small>
                                        </td>
                                        <td>{date(c.issued_at)}</td>
                                        <td>{c.reason}</td>
                                        <td>{money(c.subtotal)}</td>
                                        <td>{money(c.tax_amount)}</td>
                                        <td className="amount">
                                            {money(c.total)}
                                        </td>
                                    </tr>
                                ))}
                            </DataTable>
                        </Panel>
                    )}
                    {page === "stock" && (
                        <>
                            <Panel
                                title="Stock disponible"
                                note="Quantités issues des ventes, réceptions fournisseurs, retours et ajustements."
                                actions={
                                    can("stock.write") &&
                                    button("Nouveau mouvement", stock)
                                }
                            >
                                <DataTable
                                    headers={[
                                        "Produit",
                                        "Référence",
                                        "Quantité disponible",
                                    ]}
                                    empty={
                                        !business.products.some(
                                            (p) => p.type === "product",
                                        )
                                    }
                                >
                                    {business.products
                                        .filter((p) => p.type === "product")
                                        .map((p) => (
                                            <tr key={p.id}>
                                                <td>
                                                    {p.name}
                                                    {p.archived && " (archivé)"}
                                                </td>
                                                <td>{p.sku || "—"}</td>
                                                <td className="amount">
                                                    {d.movements
                                                        .filter(
                                                            (x) =>
                                                                x.product_id ===
                                                                p.id,
                                                        )
                                                        .reduce(
                                                            (s, x) =>
                                                                s + x.quantity,
                                                            0,
                                                        )}
                                                </td>
                                            </tr>
                                        ))}
                                </DataTable>
                            </Panel>
                            <Panel title="Historique des mouvements">
                                <DataTable
                                    headers={[
                                        "Date",
                                        "Produit",
                                        "Quantité",
                                        "Motif",
                                    ]}
                                    empty={!d.movements.length}
                                >
                                    {d.movements.map((s) => (
                                        <tr key={s.id}>
                                            <td>{stamp(s.created_at)}</td>
                                            <td>{product(s.product_id)}</td>
                                            <td>
                                                {s.quantity > 0 ? "+" : ""}
                                                {s.quantity}
                                            </td>
                                            <td>{s.reason}</td>
                                        </tr>
                                    ))}
                                </DataTable>
                            </Panel>
                        </>
                    )}
                </>
            )}
            {form && <FormDialog {...form} close={close} saved={done} />}{" "}
            {detail && (
                <Modal
                    title={
                        detail.purchase
                            ? `Achat ${detail.purchase.reference}`
                            : `Devis DEV-${detail.id}`
                    }
                    close={() => setDetail(null)}
                    wide
                >
                    <div className="form-body printable-document">
                        {detail.purchase ? (
                            <DataTable
                                headers={["Date", "Moyen", "Montant"]}
                                empty={!detail.payments.length}
                            >
                                {detail.payments.map((p) => (
                                    <tr key={p.id}>
                                        <td>{date(p.paid_at)}</td>
                                        <td>{p.method}</td>
                                        <td>{money(p.amount)}</td>
                                    </tr>
                                ))}
                            </DataTable>
                        ) : (
                            <>
                                <h3>{company.name}</h3>
                                <p>
                                    {contact(detail.contact_id)} · Valable
                                    jusqu’au {date(detail.valid_until)}
                                </p>
                                <DataTable
                                    headers={[
                                        "Description",
                                        "Quantité",
                                        "Prix unitaire",
                                        "Total",
                                    ]}
                                >
                                    {detail.items.map((i, n) => (
                                        <tr key={n}>
                                            <td>{i.description}</td>
                                            <td>{i.quantity}</td>
                                            <td>{money(i.unit_price)}</td>
                                            <td>
                                                {money(
                                                    i.quantity * i.unit_price,
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </DataTable>
                                <p>
                                    HT {money(detail.subtotal)} · Taxe{" "}
                                    {money(detail.tax_amount)}
                                </p>
                                <h3>Total {money(detail.total)}</h3>
                                <p>{detail.notes}</p>
                            </>
                        )}
                    </div>
                    <div className="modal-footer no-print">
                        <button
                            className="button secondary"
                            onClick={() => window.print()}
                        >
                            Imprimer / PDF
                        </button>
                    </div>
                </Modal>
            )}
        </div>
    );
}

const mapLabels = {
    customer: "Clients",
    supplier: "Fournisseurs",
    sales: "Ventes",
    tax_collected: "Taxe collectée",
    tax_deductible: "Taxe déductible",
    bank: "Banque / mobile money",
    cash: "Caisse",
    expense: "Charge par défaut",
};
export function AccountingPanel({ can, saved }) {
    const [range, setRange] = useState({ from: "", to: "" }),
        [tab, setTab] = useState("journal"),
        [account, setAccount] = useState(""),
        [form, setForm] = useState(null);
    const m = useModule("/accounting?" + new URLSearchParams(range)),
        d = m.data;
    const done = async () => {
        await m.reload();
        await saved();
    };
    function entry() {
        setForm({
            title: "Nouvelle écriture",
            note: "Les débits et les crédits doivent être égaux. Une écriture validée se corrige par extourne.",
            url: "/accounting/entries",
            initial: {
                label: "",
                entry_date: today(),
                journal: "OD",
                lines: [
                    { account_code: "521", debit: 0, credit: 0 },
                    { account_code: "411", debit: 0, credit: 0 },
                ],
            },
            fields: [
                field("label", "Libellé"),
                field("entry_date", "Date comptable", "date"),
                select("journal", "Journal", ["OD", "VT", "AC", "BQ", "CA"]),
            ],
            children: (v, set) => (
                <div className="line-editor">
                    {v.lines.map((l, i) => (
                        <div className="module-line" key={i}>
                            <Field label="Compte">
                                <select
                                    value={l.account_code}
                                    onChange={(e) =>
                                        set(
                                            "lines",
                                            v.lines.map((x, j) =>
                                                j === i
                                                    ? {
                                                          ...x,
                                                          account_code:
                                                              e.target.value,
                                                      }
                                                    : x,
                                            ),
                                        )
                                    }
                                >
                                    {d.accounts.map((a) => (
                                        <option key={a.code} value={a.code}>
                                            {a.code} · {a.name}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                            {["debit", "credit"].map((k) => (
                                <Field
                                    label={
                                        k === "debit"
                                            ? "Débit XOF"
                                            : "Crédit XOF"
                                    }
                                    key={k}
                                >
                                    <input
                                        type="number"
                                        min={0}
                                        step={1}
                                        required
                                        value={l[k]}
                                        onChange={(e) =>
                                            set(
                                                "lines",
                                                v.lines.map((x, j) =>
                                                    j === i
                                                        ? {
                                                              ...x,
                                                              [k]: Number(
                                                                  e.target
                                                                      .value,
                                                              ),
                                                          }
                                                        : x,
                                                ),
                                            )
                                        }
                                    />
                                </Field>
                            ))}
                            <button
                                type="button"
                                className="icon-button"
                                aria-label={`Retirer ligne ${i + 1}`}
                                disabled={v.lines.length === 2}
                                onClick={() =>
                                    set(
                                        "lines",
                                        v.lines.filter((_, j) => j !== i),
                                    )
                                }
                            >
                                ×
                            </button>
                        </div>
                    ))}
                    <p className="helper">
                        Écart :{" "}
                        {money(
                            v.lines.reduce(
                                (s, l) =>
                                    s + Number(l.debit) - Number(l.credit),
                                0,
                            ),
                        )}
                    </p>
                    <button
                        type="button"
                        className="button secondary small"
                        onClick={() =>
                            set("lines", [
                                ...v.lines,
                                { account_code: "521", debit: 0, credit: 0 },
                            ])
                        }
                    >
                        Ajouter une ligne
                    </button>
                </div>
            ),
        });
    }
    const totalDebit = d?.accounts.reduce((s, a) => s + a.debit, 0) || 0,
        totalCredit = d?.accounts.reduce((s, a) => s + a.credit, 0) || 0;
    return (
        <div className="module-stack">
            <Feedback m={m} />
            {d && (
                <>
                    <div className="module-summary">
                        <div>
                            <span>Débits de la période</span>
                            <strong>{money(totalDebit)}</strong>
                        </div>
                        <div>
                            <span>Crédits de la période</span>
                            <strong>{money(totalCredit)}</strong>
                        </div>
                        <div>
                            <span>Clôture définitive</span>
                            <strong>{date(d.closed_through)}</strong>
                        </div>
                    </div>
                    <Panel
                        title="Comptabilité générale"
                        note="Plan de comptes paramétrable. Les factures, achats et règlements validés alimentent automatiquement les journaux."
                        actions={
                            <>
                                <a
                                    className="button secondary small"
                                    href="/api/v1/accounting/export"
                                >
                                    <Download size={16} />
                                    Exporter tout
                                </a>
                                {can("accounting.write") &&
                                    button("Nouvelle écriture", entry)}
                            </>
                        }
                    >
                        <div className="module-toolbar">
                            <div className="module-tabs">
                                {[
                                    ["journal", "Journaux"],
                                    ["balance", "Balance"],
                                    ["ledger", "Grand livre"],
                                    ["accounts", "Comptes"],
                                ].map(([k, l]) => (
                                    <button
                                        key={k}
                                        className={tab === k ? "active" : ""}
                                        onClick={() => setTab(k)}
                                    >
                                        {l}
                                    </button>
                                ))}
                            </div>
                            <div className="module-actions">
                                <Field label="Du">
                                    <input
                                        type="date"
                                        value={range.from}
                                        onChange={(e) =>
                                            setRange({
                                                ...range,
                                                from: e.target.value,
                                            })
                                        }
                                    />
                                </Field>
                                <Field label="Au">
                                    <input
                                        type="date"
                                        value={range.to}
                                        onChange={(e) =>
                                            setRange({
                                                ...range,
                                                to: e.target.value,
                                            })
                                        }
                                    />
                                </Field>
                            </div>
                        </div>
                        {tab === "journal" && (
                            <DataTable
                                headers={[
                                    "Date / journal",
                                    "Libellé",
                                    "Écriture",
                                    "Débit",
                                    "Crédit",
                                    "Correction",
                                ]}
                                empty={!d.entries.length}
                            >
                                {d.entries.map((e) => (
                                    <tr key={e.id}>
                                        <td>
                                            {date(e.entry_date)}
                                            <small>
                                                {e.journal} · #{e.id}
                                            </small>
                                        </td>
                                        <td>
                                            {e.label}
                                            <small>{e.source_key}</small>
                                        </td>
                                        <td>
                                            {e.lines.map((l) => (
                                                <small key={l.id}>
                                                    {l.account_code}
                                                </small>
                                            ))}
                                        </td>
                                        <td>
                                            {e.lines.map((l) => (
                                                <small key={l.id}>
                                                    {money(l.debit)}
                                                </small>
                                            ))}
                                        </td>
                                        <td>
                                            {e.lines.map((l) => (
                                                <small key={l.id}>
                                                    {money(l.credit)}
                                                </small>
                                            ))}
                                        </td>
                                        <td>
                                            {can("accounting.write") &&
                                                e.source_key.startsWith(
                                                    "manual:",
                                                ) &&
                                                !d.entries.some(
                                                    (x) =>
                                                        x.reversal_of === e.id,
                                                ) &&
                                                button("Extourner", () =>
                                                    setForm({
                                                        title: `Extourner l’écriture #${e.id}`,
                                                        url: `/accounting/entries/${e.id}/reverse`,
                                                        initial: {
                                                            entry_date: today(),
                                                            reason: "",
                                                        },
                                                        fields: [
                                                            field(
                                                                "entry_date",
                                                                "Date de correction",
                                                                "date",
                                                            ),
                                                            field(
                                                                "reason",
                                                                "Motif",
                                                            ),
                                                        ],
                                                    }),
                                                )}
                                        </td>
                                    </tr>
                                ))}
                            </DataTable>
                        )}
                        {tab === "balance" && (
                            <DataTable
                                headers={[
                                    "Compte",
                                    "Intitulé",
                                    "Débit",
                                    "Crédit",
                                    "Solde débiteur",
                                    "Solde créditeur",
                                ]}
                            >
                                {d.accounts.map((a) => (
                                    <tr key={a.code}>
                                        <td>{a.code}</td>
                                        <td>{a.name}</td>
                                        <td>{money(a.debit)}</td>
                                        <td>{money(a.credit)}</td>
                                        <td>
                                            {money(
                                                Math.max(0, a.debit - a.credit),
                                            )}
                                        </td>
                                        <td>
                                            {money(
                                                Math.max(0, a.credit - a.debit),
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </DataTable>
                        )}
                        {tab === "ledger" && (
                            <>
                                <div className="module-toolbar">
                                    <Field label="Compte du grand livre">
                                        <select
                                            value={account}
                                            onChange={(e) =>
                                                setAccount(e.target.value)
                                            }
                                        >
                                            <option value="">
                                                Tous les comptes
                                            </option>
                                            {d.accounts.map((a) => (
                                                <option
                                                    key={a.code}
                                                    value={a.code}
                                                >
                                                    {a.code} · {a.name}
                                                </option>
                                            ))}
                                        </select>
                                    </Field>
                                </div>
                                <DataTable
                                    headers={[
                                        "Date",
                                        "Compte",
                                        "Libellé",
                                        "Débit",
                                        "Crédit",
                                    ]}
                                    empty={!d.entries.length}
                                >
                                    {d.entries.flatMap((e) =>
                                        e.lines
                                            .filter(
                                                (l) =>
                                                    !account ||
                                                    l.account_code === account,
                                            )
                                            .map((l) => (
                                                <tr key={l.id}>
                                                    <td>
                                                        {date(e.entry_date)}
                                                    </td>
                                                    <td>{l.account_code}</td>
                                                    <td>{e.label}</td>
                                                    <td>{money(l.debit)}</td>
                                                    <td>{money(l.credit)}</td>
                                                </tr>
                                            )),
                                    )}
                                </DataTable>
                            </>
                        )}
                        {tab === "accounts" && (
                            <>
                                <DataTable headers={["Compte", "Intitulé"]}>
                                    {d.accounts.map((a) => (
                                        <tr key={a.code}>
                                            <td>{a.code}</td>
                                            <td>{a.name}</td>
                                        </tr>
                                    ))}
                                </DataTable>
                                <div className="module-toolbar">
                                    {can("accounting.write") &&
                                        button("Ajouter un compte", () =>
                                            setForm({
                                                title: "Nouveau compte",
                                                url: "/accounting/accounts",
                                                initial: { code: "", name: "" },
                                                fields: [
                                                    field(
                                                        "code",
                                                        "Numéro de compte",
                                                    ),
                                                    field("name", "Intitulé"),
                                                ],
                                            }),
                                        )}
                                    {can("accounting.close") &&
                                        button("Correspondances", () =>
                                            setForm({
                                                title: "Correspondances comptables",
                                                note: "À configurer avant la première écriture. Les correspondances seront ensuite figées pour préserver la cohérence des comptes.",
                                                url: "/accounting/settings",
                                                method: "PUT",
                                                initial: d.mapping,
                                                fields: Object.keys(
                                                    d.mapping,
                                                ).map((k) =>
                                                    select(
                                                        k,
                                                        mapLabels[k],
                                                        d.accounts.map((a) => [
                                                            a.code,
                                                            `${a.code} · ${a.name}`,
                                                        ]),
                                                    ),
                                                ),
                                                transform: (v) => ({
                                                    mapping: v,
                                                }),
                                            }),
                                        )}
                                </div>
                            </>
                        )}
                    </Panel>
                    <Panel
                        title="Contrôles de période"
                        note="Importez les opérations antérieures à l’activation de la comptabilité. La clôture interdit ensuite toute écriture datée dans la période clôturée."
                        actions={
                            <>
                                {can("accounting.write") &&
                                    button(
                                        "Comptabiliser l’historique",
                                        () =>
                                            m
                                                .act("/accounting/sync")
                                                .catch(() => {}),
                                        m.busy,
                                    )}
                                {can("accounting.close") &&
                                    button("Clôturer une période", () =>
                                        setForm({
                                            title: "Clôture définitive",
                                            note: "Cette action est irréversible : aucune nouvelle écriture ne pourra être datée au plus tard à la date choisie. Finalisez les brouillons et vérifiez la balance avant de confirmer.",
                                            url: "/accounting/close",
                                            initial: { through: today() },
                                            fields: [
                                                field(
                                                    "through",
                                                    "Clôturer jusqu’au",
                                                    "date",
                                                ),
                                            ],
                                            submitLabel: "Confirmer la clôture",
                                        }),
                                    )}
                            </>
                        }
                    />
                </>
            )}
            {form && (
                <FormDialog
                    {...form}
                    saved={done}
                    close={() => setForm(null)}
                />
            )}
        </div>
    );
}

export function SubscriptionPanel({ can, saved }) {
    const m = useModule("/subscription"),
        [form, setForm] = useState(null),
        d = m.data;
    const [paymentMessage, setPaymentMessage] = useState("");
    async function wave(order, check = false) {
        setPaymentMessage("");
        try {
            const result = await m.act(
                `/subscription/orders/${order.id}/wave${check ? "/refresh" : ""}`,
            );
            if (result.status === "paid") {
                setPaymentMessage(
                    "Paiement confirmé. Votre abonnement a été mis à jour.",
                );
                saved?.();
            } else if (result.wave_state === "expired") {
                setPaymentMessage(
                    "Ce paiement Wave a expiré. Contactez le support pour renouveler la demande.",
                );
            } else if (!check && result.url) {
                window.location.assign(result.url);
            } else {
                setPaymentMessage(
                    "Wave n’a pas encore confirmé le paiement. Vous pouvez vérifier à nouveau.",
                );
            }
        } catch (_) {
            /* Feedback displays the API error. */
        }
    }
    return (
        <div className="module-stack">
            <Feedback m={m} />
            {paymentMessage && (
                <p className="helper" role="status">
                    {paymentMessage}
                </p>
            )}
            {new URLSearchParams(window.location.search).has("wave_order") && (
                <p className="helper">
                    De retour de Wave ? Cliquez sur « Vérifier le paiement »
                    pour actualiser votre abonnement.
                </p>
            )}
            {d && (
                <>
                    <div className="module-summary">
                        <div>
                            <span>Offre actuelle</span>
                            <strong>{d.company.plan}</strong>
                            <small>
                                {d.active
                                    ? "Accès actif"
                                    : "Accès en lecture seule ou suspendu"}
                            </small>
                        </div>
                        <div>
                            <span>Collaborateurs actifs</span>
                            <strong>
                                {d.usage.users} /{" "}
                                {d.current_plan?.max_users ?? "∞"}
                            </strong>
                        </div>
                        <div>
                            <span>Factures ce mois</span>
                            <strong>
                                {d.usage.invoices} /{" "}
                                {d.current_plan?.monthly_invoices ?? "∞"}
                            </strong>
                        </div>
                    </div>
                    <p className="helper">
                        {d.company.subscription_status === "trial"
                            ? `Essai jusqu’au ${date(d.company.trial_ends_at)}`
                            : `Fin d’abonnement : ${date(d.company.subscription_ends_at)}`}
                        .{" "}
                        {d.wave_enabled
                            ? "Réglez votre abonnement avec Wave. Chaque renouvellement nécessite votre validation dans Wave."
                            : "Le paiement Wave est en cours de configuration. La validation manuelle du règlement reste disponible auprès du support."}
                    </p>
                    <div className="offer-grid">
                        {d.plans.map((p) => (
                            <section className="panel offer-card" key={p.id}>
                                <span className="eyebrow">
                                    OFFRE ENTREPRISE
                                </span>
                                <h2>{p.name}</h2>
                                <strong className="offer-price">
                                    {money(p.monthly_price)}
                                </strong>
                                <p>pour 30 jours · Sénégal</p>
                                <ul>
                                    <li>
                                        {p.max_users ?? "Nombre illimité de"}{" "}
                                        collaborateurs
                                    </li>
                                    <li>
                                        {p.monthly_invoices ??
                                            "Nombre illimité de"}{" "}
                                        factures par mois
                                    </li>
                                    <li>
                                        Première réponse support :{" "}
                                        {p.sla_response_hours} h
                                    </li>
                                    <li>
                                        Objectif de résolution :{" "}
                                        {p.sla_resolution_hours} h
                                    </li>
                                    <li>
                                        Modules :{" "}
                                        {p.modules
                                            .map(
                                                (x) =>
                                                    ({
                                                        accounting:
                                                            "comptabilité",
                                                        commerce: "commerce",
                                                        communications: "email",
                                                        support: "support",
                                                    })[x],
                                            )
                                            .join(", ") ||
                                            "socle de facturation"}
                                    </li>
                                </ul>
                                {can("subscription.write") && (
                                    <button
                                        className="button"
                                        disabled={d.orders.some(
                                            (o) => o.status === "pending",
                                        )}
                                        onClick={() =>
                                            setForm({
                                                title: `Demander l’offre ${p.name}`,
                                                note: `Montant avant promotion : ${money(p.monthly_price)}. Aucun débit automatique. La plateforme vérifiera votre règlement avant d’activer 30 jours d’accès.`,
                                                url: "/subscription/orders",
                                                initial: {
                                                    plan_id: p.id,
                                                    code: "",
                                                },
                                                fields: [
                                                    field(
                                                        "code",
                                                        "Code promotionnel (facultatif)",
                                                        "text",
                                                        { required: false },
                                                    ),
                                                ],
                                                submitLabel:
                                                    "Envoyer la demande",
                                            })
                                        }
                                    >
                                        Choisir cette offre
                                        <ArrowRight size={16} />
                                    </button>
                                )}
                            </section>
                        ))}
                    </div>
                    {!d.plans.length && (
                        <p className="helper">
                            Les offres sont en cours de configuration par la
                            plateforme. Le support reste disponible.
                        </p>
                    )}
                    <Panel title="Demandes d’abonnement">
                        <DataTable
                            headers={[
                                "Demande",
                                "Offre",
                                "Tarif",
                                "Réduction",
                                "À régler",
                                "Statut",
                                "Paiement",
                            ]}
                            empty={!d.orders.length}
                        >
                            {d.orders.map((o) => (
                                <tr key={o.id}>
                                    <td>
                                        #{o.id}
                                        <small>{date(o.created_at)}</small>
                                    </td>
                                    <td>{o.plan_name}</td>
                                    <td>{money(o.subtotal)}</td>
                                    <td>{money(o.discount)}</td>
                                    <td className="amount">{money(o.total)}</td>
                                    <td>
                                        <Tag value={o.status} />
                                    </td>
                                    <td>
                                        {o.status === "pending" &&
                                            o.total > 0 &&
                                            can("subscription.write") &&
                                            d.wave_enabled && (
                                                <div className="row-actions">
                                                    {o.wave_state !==
                                                        "expired" && (
                                                        <button
                                                            className="button secondary small"
                                                            disabled={m.busy}
                                                            onClick={() =>
                                                                wave(o)
                                                            }
                                                        >
                                                            {o.wave_reference
                                                                ? "Reprendre avec Wave"
                                                                : "Payer avec Wave"}
                                                        </button>
                                                    )}
                                                    {o.wave_reference && (
                                                        <button
                                                            className="button secondary small"
                                                            disabled={m.busy}
                                                            onClick={() =>
                                                                wave(o, true)
                                                            }
                                                        >
                                                            Vérifier le paiement
                                                        </button>
                                                    )}
                                                </div>
                                            )}
                                        {o.wave_state === "expired" && (
                                            <small>
                                                Paiement expiré · contactez le
                                                support
                                            </small>
                                        )}
                                        {o.payment_reference && (
                                            <small>{o.payment_reference}</small>
                                        )}
                                        {o.total === 0 &&
                                            o.status === "pending" && (
                                                <small>
                                                    Validation par la plateforme
                                                </small>
                                            )}
                                    </td>
                                </tr>
                            ))}
                        </DataTable>
                    </Panel>
                </>
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

export function SupportPanel({ can, staff = false }) {
    const path = staff ? "/admin/support" : "/support",
        m = useModule(path),
        [form, setForm] = useState(null),
        [selected, setSelected] = useState(null),
        d = m.data;
    const ticket = d?.tickets.find((t) => t.id === selected);
    return (
        <div className="module-stack">
            <Feedback m={m} />
            {d && (
                <>
                    <div className="module-summary">
                        <div>
                            <span>Tickets ouverts</span>
                            <strong>
                                {
                                    d.tickets.filter((t) =>
                                        ["open", "in_progress"].includes(
                                            t.status,
                                        ),
                                    ).length
                                }
                            </strong>
                        </div>
                        <div>
                            <span>Délais dépassés</span>
                            <strong>
                                {
                                    d.tickets.filter(
                                        (t) =>
                                            !["closed", "resolved"].includes(
                                                t.status,
                                            ) &&
                                            (t.response_breached ||
                                                t.resolution_breached),
                                    ).length
                                }
                            </strong>
                        </div>
                        <div>
                            <span>Tickets résolus</span>
                            <strong>
                                {
                                    d.tickets.filter((t) =>
                                        ["resolved", "closed"].includes(
                                            t.status,
                                        ),
                                    ).length
                                }
                            </strong>
                        </div>
                    </div>
                    <Panel
                        title={
                            staff
                                ? "Centre de support plateforme"
                                : "Vos demandes de support"
                        }
                        note="Délais calculés en heures continues (24 h/24), selon l’offre à l’ouverture. Priorité haute : délais divisés par 2 ; critique : par 4."
                        actions={
                            !staff &&
                            can("support.write") &&
                            button("Nouveau signalement", () =>
                                setForm({
                                    title: "Contacter le support",
                                    url: "/support",
                                    initial: {
                                        subject: "",
                                        body: "",
                                        category: "bug",
                                        priority: "normal",
                                    },
                                    fields: [
                                        field("subject", "Objet"),
                                        select("category", "Catégorie", [
                                            ["bug", "Signaler un bug"],
                                            ["question", "Question"],
                                            [
                                                "billing",
                                                "Abonnement / facturation",
                                            ],
                                        ]),
                                        select("priority", "Priorité", [
                                            ["normal", "Normale"],
                                            ["high", "Haute"],
                                            ["critical", "Critique"],
                                        ]),
                                        field(
                                            "body",
                                            "Description et étapes de reproduction",
                                            "textarea",
                                        ),
                                    ],
                                    submitLabel: "Créer le ticket",
                                }),
                            )
                        }
                    >
                        <DataTable
                            headers={[
                                "Ticket",
                                "Priorité",
                                "Première réponse attendue",
                                "Résolution attendue",
                                "Statut",
                                "",
                            ]}
                            empty={!d.tickets.length}
                        >
                            {d.tickets.map((t) => (
                                <tr key={t.id}>
                                    <td>
                                        <strong>
                                            #{t.id} · {t.subject}
                                        </strong>
                                        <small>
                                            {staff
                                                ? t.company_name
                                                : labels[t.category]}
                                            {t.assignee_name &&
                                                ` · ${t.assignee_name}`}
                                        </small>
                                    </td>
                                    <td>
                                        <Tag value={t.priority} />
                                    </td>
                                    <td
                                        className={
                                            t.response_breached &&
                                            !t.resolved_at
                                                ? "late-text"
                                                : ""
                                        }
                                    >
                                        {stamp(t.response_due_at)}
                                        <small>
                                            {t.responded_at
                                                ? "Réponse reçue " +
                                                  stamp(t.responded_at)
                                                : "En attente de réponse"}
                                        </small>
                                    </td>
                                    <td
                                        className={
                                            t.resolution_breached
                                                ? "late-text"
                                                : ""
                                        }
                                    >
                                        {stamp(t.resolution_due_at)}
                                    </td>
                                    <td>
                                        <Tag value={t.status} />
                                    </td>
                                    <td>
                                        {button("Ouvrir", () =>
                                            setSelected(t.id),
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </DataTable>
                    </Panel>
                </>
            )}
            {ticket && (
                <Panel
                    title={`#${ticket.id} · ${ticket.subject}`}
                    actions={
                        <>
                            {staff &&
                                button("Affecter / changer le statut", () =>
                                    setForm({
                                        title: "Traitement du ticket",
                                        url: `/admin/support/${ticket.id}`,
                                        method: "PUT",
                                        initial: {
                                            status: ticket.status,
                                            assigned_to:
                                                ticket.assigned_to ?? "",
                                        },
                                        fields: [
                                            select(
                                                "status",
                                                "Statut",
                                                [
                                                    "open",
                                                    "in_progress",
                                                    "resolved",
                                                    "closed",
                                                ].map((k) => [k, labels[k]]),
                                            ),
                                            select(
                                                "assigned_to",
                                                "Responsable",
                                                [
                                                    ["", "Non affecté"],
                                                    ...d.staff.map((s) => [
                                                        s.id,
                                                        s.name,
                                                    ]),
                                                ],
                                            ),
                                        ],
                                        transform: (v) => ({
                                            ...v,
                                            assigned_to: v.assigned_to || null,
                                        }),
                                    }),
                                )}
                            {button("Fermer la conversation", () =>
                                setSelected(null),
                            )}
                        </>
                    }
                >
                    <div className="ticket-thread">
                        {ticket.replies.map((r) => (
                            <article
                                className={r.is_staff ? "staff-reply" : ""}
                                key={r.id}
                            >
                                <strong>
                                    {r.author} {r.is_staff && "· Support"}
                                </strong>
                                <small>{stamp(r.created_at)}</small>
                                <p>{r.body}</p>
                            </article>
                        ))}
                        {ticket.status !== "closed" &&
                            (staff || can("support.write")) &&
                            button("Répondre", () =>
                                setForm({
                                    title: `Répondre au ticket #${ticket.id}`,
                                    url: `${path}/${ticket.id}/replies`,
                                    initial: { body: "" },
                                    fields: [
                                        field(
                                            "body",
                                            "Votre réponse",
                                            "textarea",
                                        ),
                                    ],
                                    submitLabel: "Envoyer la réponse",
                                }),
                            )}
                    </div>
                </Panel>
            )}
            {form && (
                <FormDialog
                    {...form}
                    saved={m.reload}
                    close={() => setForm(null)}
                />
            )}
        </div>
    );
}

export function CommunicationsPanel({ can, data: business }) {
    const m = useModule("/communications"),
        [form, setForm] = useState(null),
        [notice, setNotice] = useState(""),
        d = m.data;
    function smtp() {
        setForm({
            title: "Serveur SMTP de votre entreprise",
            note: "Connexion chiffrée obligatoire. Le mot de passe est stocké chiffré et n’est jamais renvoyé au navigateur. Laissez-le vide pour conserver le secret existant.",
            url: "/communications/smtp",
            method: "PUT",
            initial: {
                host: d.smtp?.host || "",
                port: d.smtp?.port || 587,
                encryption: d.smtp?.encryption || "tls",
                username: d.smtp?.username || "",
                password: "",
                from_email: d.smtp?.from_email || "",
                from_name: d.smtp?.from_name || "",
                enabled: d.smtp?.enabled || false,
            },
            fields: [
                field("host", "Hôte SMTP public"),
                select("port", "Port", [587, 465]),
                select("encryption", "Chiffrement", [
                    ["tls", "STARTTLS (587)"],
                    ["ssl", "TLS direct (465)"],
                ]),
                field("username", "Identifiant SMTP"),
                field("password", "Mot de passe SMTP", "password", {
                    required: !d.smtp?.has_password,
                }),
                field("from_email", "Adresse d’expédition", "email"),
                field("from_name", "Nom d’expéditeur"),
                field("enabled", "Activer les envois", "checkbox"),
            ],
        });
    }
    return (
        <div className="module-stack">
            <Feedback m={m} />
            {notice && (
                <div className="success-box" role="status">
                    {notice}
                </div>
            )}
            {d && (
                <>
                    <Panel
                        title="Emails de votre entreprise"
                        note="Les factures sont envoyées au format texte depuis votre adresse professionnelle. L’impression PDF reste accessible depuis chaque facture."
                        actions={
                            <>
                                {can("communications.manage") &&
                                    button("Configurer le SMTP", smtp)}
                                {can("communications.write") &&
                                    button("Envoyer une facture", () =>
                                        setForm({
                                            title: "Envoyer une facture par email",
                                            note: "Le destinataire est l’adresse client figée dans la facture. Vérifiez-la avant l’envoi.",
                                            url: "/communications/send",
                                            initial: { invoice_id: "" },
                                            fields: [
                                                select(
                                                    "invoice_id",
                                                    "Facture",
                                                    [
                                                        [
                                                            "",
                                                            "Sélectionnez une facture",
                                                        ],
                                                        ...business.invoices
                                                            .filter((i) =>
                                                                [
                                                                    "issued",
                                                                    "paid",
                                                                ].includes(
                                                                    i.status,
                                                                ),
                                                            )
                                                            .map((i) => [
                                                                i.id,
                                                                `${i.number} · ${i.customer_snapshot.email || "Sans adresse email"}`,
                                                            ]),
                                                    ],
                                                ),
                                            ],
                                            submitLabel:
                                                "Mettre en file d’envoi",
                                        }),
                                    )}
                            </>
                        }
                    >
                        <div className="module-toolbar">
                            <p>
                                {can("communications.manage")
                                    ? d.smtp
                                        ? `${d.smtp.enabled ? "Activé" : "Désactivé"} · ${d.smtp.host}:${d.smtp.port} · ${d.smtp.from_email}`
                                        : "Aucun serveur SMTP configuré."
                                    : "Votre administrateur gère la configuration SMTP."}
                            </p>
                            {can("communications.write") &&
                                button(
                                    "Envoyer un test à mon adresse",
                                    () =>
                                        m
                                            .act("/communications/send", {
                                                test: true,
                                            })
                                            .then(() =>
                                                setNotice(
                                                    "Message de test mis en file. Actualisez l’historique pour vérifier sa livraison.",
                                                ),
                                            )
                                            .catch(() => {}),
                                    m.busy,
                                )}
                        </div>
                    </Panel>
                    <Panel
                        title="Historique des envois"
                        note="Un état « Envoyé » signifie que le serveur SMTP a accepté le message. Vérifiez sa réception avant de relancer un échec."
                        actions={button("Actualiser", m.reload)}
                    >
                        <DataTable
                            headers={[
                                "Date",
                                "Destinataire",
                                "Objet",
                                "État",
                                "",
                            ]}
                            empty={!d.outbox.length}
                        >
                            {d.outbox.map((o) => (
                                <tr key={o.id}>
                                    <td>{stamp(o.created_at)}</td>
                                    <td>{o.recipient}</td>
                                    <td>
                                        {o.subject}
                                        <small>{o.error}</small>
                                    </td>
                                    <td>
                                        <Tag value={o.status} />
                                    </td>
                                    <td>
                                        {o.status === "failed" &&
                                            can("communications.write") &&
                                            button(
                                                "Relancer",
                                                () =>
                                                    m
                                                        .act(
                                                            `/communications/${o.id}/retry`,
                                                        )
                                                        .catch(() => {}),
                                                m.busy,
                                            )}
                                    </td>
                                </tr>
                            ))}
                        </DataTable>
                    </Panel>
                </>
            )}
            {form && (
                <FormDialog
                    {...form}
                    saved={m.reload}
                    close={() => setForm(null)}
                />
            )}
        </div>
    );
}

export function NotificationsPanel({ navigate }) {
    const m = useModule("/notifications"),
        d = m.data;
    async function enable() {
        try {
            if (!("serviceWorker" in navigator) || !("PushManager" in window)) {
                throw new Error(
                    "Ce navigateur ne prend pas en charge les notifications push.",
                );
            }
            const permission = await Notification.requestPermission();
            if (permission !== "granted") {
                throw new Error(
                    "Les notifications ne sont pas autorisées dans le navigateur.",
                );
            }
            const registration =
                await navigator.serviceWorker.register("/sw.js");
            await navigator.serviceWorker.ready;
            const raw = d.push_public_key.replace(/-/g, "+").replace(/_/g, "/");
            const bytes = Uint8Array.from(
                atob(raw + "=".repeat((4 - (raw.length % 4)) % 4)),
                (c) => c.charCodeAt(0),
            );
            const subscription =
                (await registration.pushManager.getSubscription()) ||
                (await registration.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: bytes,
                }));
            const json = subscription.toJSON();
            await m.act("/push", { endpoint: json.endpoint, keys: json.keys });
        } catch (e) {
            m.setError(e.message);
        }
    }
    return (
        <div className="module-stack">
            <Feedback m={m} />
            {d && (
                <>
                    <Panel
                        title="Centre de notifications"
                        note="Validations, abonnements et réponses du support. Les notifications push affichent uniquement un avis d’activité."
                        actions={
                            <>
                                {button("Actualiser", m.reload)}
                                {button(
                                    "Tout marquer comme lu",
                                    () =>
                                        m
                                            .act("/notifications/read")
                                            .catch(() => {}),
                                    m.busy,
                                )}
                                {d.push_enabled
                                    ? button(
                                          "Désactiver le push",
                                          () =>
                                              m
                                                  .act("/push", {}, "DELETE")
                                                  .catch(() => {}),
                                          m.busy,
                                      )
                                    : button(
                                          "Activer le push",
                                          enable,
                                          !d.push_public_key,
                                      )}
                            </>
                        }
                    >
                        {!d.push_public_key && (
                            <p className="module-toolbar helper">
                                Les clés du service push doivent être
                                configurées par la plateforme.
                            </p>
                        )}
                        <div className="notification-list">
                            {d.notifications.length ? (
                                d.notifications.map((n) => (
                                    <article
                                        className={!n.read_at ? "unread" : ""}
                                        key={n.id}
                                    >
                                        <Bell size={19} />
                                        <div>
                                            <strong>{n.title}</strong>
                                            <p>{n.body}</p>
                                            <small>{stamp(n.created_at)}</small>
                                        </div>
                                        {n.target &&
                                            button("Consulter", () =>
                                                navigate(n.target),
                                            )}
                                    </article>
                                ))
                            ) : (
                                <div className="empty">
                                    <Bell />
                                    <p>Vous êtes à jour.</p>
                                </div>
                            )}
                        </div>
                    </Panel>
                </>
            )}
        </div>
    );
}

export function PlatformPanel({ saved }) {
    const m = useModule("/admin/platform"),
        [tab, setTab] = useState("companies"),
        [form, setForm] = useState(null),
        d = m.data;
    function plan(p) {
        setForm({
            title: p ? "Modifier l’offre" : "Nouvelle offre",
            url: p ? `/admin/plans/${p.id}` : "/admin/plans",
            method: p ? "PUT" : "POST",
            initial: p
                ? {
                      ...p,
                      max_users: p.max_users ?? "",
                      monthly_invoices: p.monthly_invoices ?? "",
                  }
                : {
                      name: "",
                      monthly_price: 0,
                      max_users: "",
                      monthly_invoices: "",
                      sla_response_hours: 24,
                      sla_resolution_hours: 72,
                      active: false,
                      modules: [
                          "accounting",
                          "commerce",
                          "communications",
                          "support",
                      ],
                  },
            fields: [
                field("name", "Nom de l’offre"),
                field("monthly_price", "Prix XOF / 30 jours", "number", {
                    min: 0,
                }),
                field(
                    "max_users",
                    "Collaborateurs maximum (vide = illimité)",
                    "number",
                    { required: false, min: 1 },
                ),
                field(
                    "monthly_invoices",
                    "Factures / mois (vide = illimité)",
                    "number",
                    { required: false, min: 1 },
                ),
                field(
                    "sla_response_hours",
                    "Délai de réponse (heures)",
                    "number",
                    { min: 1 },
                ),
                field(
                    "sla_resolution_hours",
                    "Délai de résolution (heures)",
                    "number",
                    { min: 1 },
                ),
                field(
                    "active",
                    "Proposer cette offre aux entreprises",
                    "checkbox",
                ),
            ],
            children: (v, set) => (
                <div className="module-checks">
                    {[
                        ["accounting", "Comptabilité"],
                        ["commerce", "Devis, achats, avoirs, stocks"],
                        ["communications", "Emails SMTP"],
                    ].map(([key, label]) => (
                        <label className="check-field" key={key}>
                            <input
                                type="checkbox"
                                checked={v.modules.includes(key)}
                                onChange={(e) =>
                                    set(
                                        "modules",
                                        e.target.checked
                                            ? [...v.modules, key]
                                            : v.modules.filter(
                                                  (x) => x !== key,
                                              ),
                                    )
                                }
                            />
                            {label}
                        </label>
                    ))}
                    <p className="helper">
                        Le support et les abonnements restent toujours
                        accessibles.
                    </p>
                </div>
            ),
            transform: (v) => ({
                ...v,
                max_users: v.max_users || null,
                monthly_invoices: v.monthly_invoices || null,
            }),
        });
    }
    function promo(p) {
        setForm({
            title: p ? "Modifier la promotion" : "Nouvelle promotion",
            url: p ? `/admin/promotions/${p.id}` : "/admin/promotions",
            method: p ? "PUT" : "POST",
            initial: p
                ? {
                      ...p,
                      plan_id: p.plan_id ?? "",
                      max_uses: p.max_uses ?? "",
                      starts_at: p.starts_at?.slice(0, 16) || "",
                      ends_at: p.ends_at?.slice(0, 16) || "",
                  }
                : {
                      code: "",
                      type: "percent",
                      value: 10,
                      plan_id: "",
                      max_uses: "",
                      starts_at: "",
                      ends_at: "",
                      active: true,
                  },
            fields: [
                field("code", "Code promotionnel"),
                select("type", "Réduction", [
                    ["percent", "Pourcentage"],
                    ["fixed", "Montant XOF"],
                ]),
                field("value", "Valeur", "number", { min: 1 }),
                select("plan_id", "Offre concernée", [
                    ["", "Toutes les offres"],
                    ...d.plans.map((p) => [p.id, p.name]),
                ]),
                field("max_uses", "Nombre maximal d’utilisations", "number", {
                    required: false,
                    min: 1,
                }),
                field("starts_at", "Début (Dakar)", "datetime-local", {
                    required: false,
                }),
                field("ends_at", "Fin (Dakar)", "datetime-local", {
                    required: false,
                }),
                field("active", "Activer ce code", "checkbox"),
            ],
            transform: (v) => ({
                ...v,
                plan_id: v.plan_id || null,
                max_uses: v.max_uses || null,
                starts_at: v.starts_at || null,
                ends_at: v.ends_at || null,
            }),
        });
    }
    return (
        <div className="module-stack">
            <div className="module-tabs">
                {[
                    ["companies", "Entreprises"],
                    ["plans", "Offres"],
                    ["promotions", "Promotions"],
                    ["orders", "Règlements"],
                    ["support", "Support & SLA"],
                    ["audit", "Journal global"],
                ].map(([key, label]) => (
                    <button
                        key={key}
                        className={tab === key ? "active" : ""}
                        onClick={() => setTab(key)}
                    >
                        {label}
                    </button>
                ))}
            </div>
            <Feedback m={m} />
            {tab === "support" ? (
                <SupportPanel staff can={() => true} />
            ) : (
                d && (
                    <>
                        {tab === "companies" && (
                            <Panel title={`${d.companies.length} entreprises`}>
                                <DataTable
                                    headers={[
                                        "Entreprise",
                                        "Offre",
                                        "État",
                                        "Échéance",
                                        "",
                                    ]}
                                    empty={!d.companies.length}
                                >
                                    {d.companies.map((c) => (
                                        <tr key={c.id}>
                                            <td>
                                                <strong>{c.name}</strong>
                                                <small>
                                                    {c.ninea ||
                                                        "NINEA non renseigné"}
                                                </small>
                                            </td>
                                            <td>{c.plan}</td>
                                            <td>
                                                {
                                                    {
                                                        trial: "Essai",
                                                        active: "Actif",
                                                        suspended: "Suspendu",
                                                    }[c.subscription_status]
                                                }
                                            </td>
                                            <td>
                                                {date(
                                                    c.subscription_status ===
                                                        "trial"
                                                        ? c.trial_ends_at
                                                        : c.subscription_ends_at,
                                                )}
                                            </td>
                                            <td>
                                                {button("Gérer", () =>
                                                    setForm({
                                                        title: c.name,
                                                        url: `/admin/companies/${c.id}`,
                                                        method: "PUT",
                                                        initial: {
                                                            plan: c.plan,
                                                            subscription_status:
                                                                c.subscription_status,
                                                        },
                                                        fields: [
                                                            select(
                                                                "plan",
                                                                "Offre",
                                                                d.plans.map(
                                                                    (p) =>
                                                                        p.name,
                                                                ),
                                                            ),
                                                            select(
                                                                "subscription_status",
                                                                "Statut",
                                                                [
                                                                    [
                                                                        "trial",
                                                                        "Essai",
                                                                    ],
                                                                    [
                                                                        "active",
                                                                        "Actif",
                                                                    ],
                                                                    [
                                                                        "suspended",
                                                                        "Suspendu",
                                                                    ],
                                                                ],
                                                            ),
                                                        ],
                                                    }),
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </DataTable>
                            </Panel>
                        )}
                        {tab === "plans" && (
                            <Panel
                                title="Catalogue des offres"
                                note="Configurez les prix, quotas et modules avant de rendre une offre disponible."
                                actions={button("Nouvelle offre", () =>
                                    plan(null),
                                )}
                            >
                                <DataTable
                                    headers={[
                                        "Offre",
                                        "Prix / 30 j",
                                        "Quotas",
                                        "SLA réponse / résolution",
                                        "Publication",
                                        "",
                                    ]}
                                >
                                    {d.plans.map((p) => (
                                        <tr key={p.id}>
                                            <td>{p.name}</td>
                                            <td>{money(p.monthly_price)}</td>
                                            <td>
                                                {p.max_users ?? "∞"} membres ·{" "}
                                                {p.monthly_invoices ?? "∞"}{" "}
                                                factures
                                            </td>
                                            <td>
                                                {p.sla_response_hours} h /{" "}
                                                {p.sla_resolution_hours} h
                                            </td>
                                            <td>
                                                {p.active
                                                    ? "Disponible"
                                                    : "Masquée"}
                                            </td>
                                            <td>
                                                {button("Modifier", () =>
                                                    plan(p),
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </DataTable>
                            </Panel>
                        )}
                        {tab === "promotions" && (
                            <Panel
                                title="Codes promotionnels"
                                note="Une utilisation par entreprise. Une demande en attente réserve une utilisation ; son annulation la libère."
                                actions={button("Nouvelle promotion", () =>
                                    promo(null),
                                )}
                            >
                                <DataTable
                                    headers={[
                                        "Code",
                                        "Réduction",
                                        "Utilisations",
                                        "Validité",
                                        "État",
                                        "",
                                    ]}
                                    empty={!d.promotions.length}
                                >
                                    {d.promotions.map((p) => (
                                        <tr key={p.id}>
                                            <td>
                                                <strong>{p.code}</strong>
                                            </td>
                                            <td>
                                                {p.type === "percent"
                                                    ? `${p.value} %`
                                                    : money(p.value)}
                                            </td>
                                            <td>
                                                {p.uses} / {p.max_uses ?? "∞"}
                                            </td>
                                            <td>
                                                {date(p.starts_at)} →{" "}
                                                {date(p.ends_at)}
                                            </td>
                                            <td>
                                                {p.active
                                                    ? "Actif"
                                                    : "Désactivé"}
                                            </td>
                                            <td>
                                                {button("Modifier", () =>
                                                    promo(p),
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </DataTable>
                            </Panel>
                        )}
                        {tab === "orders" && (
                            <Panel
                                title="Règlements des abonnements"
                                note="Confirmez uniquement après réception et vérification du paiement. La confirmation active 30 jours. Aucun prélèvement n’est effectué par l’application."
                            >
                                <DataTable
                                    headers={[
                                        "Demande",
                                        "Entreprise / offre",
                                        "Total",
                                        "Statut",
                                        "Référence",
                                        "Actions",
                                    ]}
                                    empty={!d.orders.length}
                                >
                                    {d.orders.map((o) => (
                                        <tr key={o.id}>
                                            <td>
                                                #{o.id}
                                                <small>
                                                    {date(o.created_at)}
                                                </small>
                                            </td>
                                            <td>
                                                {o.company_name}
                                                <small>{o.plan_name}</small>
                                            </td>
                                            <td className="amount">
                                                {money(o.total)}
                                            </td>
                                            <td>
                                                <Tag value={o.status} />
                                            </td>
                                            <td>
                                                {o.payment_reference || "—"}
                                            </td>
                                            <td>
                                                {o.status === "pending" && (
                                                    <div className="module-actions">
                                                        {button(
                                                            "Confirmer le paiement",
                                                            () =>
                                                                setForm({
                                                                    title: `Confirmer ${money(o.total)} reçus`,
                                                                    note: `Entreprise : ${o.company_name}. Vérifiez le paiement avant validation. Pour une offre gratuite, indiquez « Gratuit ».`,
                                                                    url: `/admin/orders/${o.id}/decision`,
                                                                    initial: {
                                                                        action: "confirm",
                                                                        payment_reference:
                                                                            "",
                                                                    },
                                                                    fields: [
                                                                        field(
                                                                            "payment_reference",
                                                                            "Référence du paiement vérifié",
                                                                        ),
                                                                    ],
                                                                    submitLabel:
                                                                        "Confirmer et activer",
                                                                }),
                                                        )}
                                                        {button("Annuler", () =>
                                                            setForm({
                                                                title: "Annuler cette demande",
                                                                note: "La promotion réservée sera libérée. Aucun remboursement automatique.",
                                                                url: `/admin/orders/${o.id}/decision`,
                                                                initial: {
                                                                    action: "cancel",
                                                                },
                                                                submitLabel:
                                                                    "Confirmer l’annulation",
                                                            }),
                                                        )}
                                                    </div>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </DataTable>
                            </Panel>
                        )}
                        {tab === "audit" && (
                            <Panel
                                title="Journal de la plateforme"
                                note="200 dernières actions, avec entreprise et auteur."
                            >
                                <DataTable
                                    headers={[
                                        "Date",
                                        "Entreprise",
                                        "Auteur",
                                        "Action",
                                        "Entité",
                                    ]}
                                    empty={!d.audit.length}
                                >
                                    {d.audit.map((a) => (
                                        <tr key={a.id}>
                                            <td>{stamp(a.created_at)}</td>
                                            <td>{a.company_name}</td>
                                            <td>{a.actor}</td>
                                            <td>{a.action}</td>
                                            <td>
                                                {a.entity_type} #{a.entity_id}
                                            </td>
                                        </tr>
                                    ))}
                                </DataTable>
                            </Panel>
                        )}
                    </>
                )
            )}
            {form && (
                <FormDialog
                    {...form}
                    saved={async () => {
                        await m.reload();
                        await saved();
                    }}
                    close={() => setForm(null)}
                />
            )}
        </div>
    );
}

export function EditRecord({ record, kind, close, saved }) {
    const isProduct = kind === "products";
    return (
        <FormDialog
            title={isProduct ? "Modifier le produit" : "Modifier le contact"}
            url={`/records/${kind}/${record.id}`}
            method="PUT"
            initial={record}
            fields={[
                field("name", "Nom"),
                ...(isProduct
                    ? [
                          field("sku", "Référence", "text", {
                              required: false,
                          }),
                          select("type", "Type", [
                              ["product", "Produit"],
                              ["service", "Service"],
                          ]),
                          field("price", "Prix XOF", "number", { min: 0 }),
                      ]
                    : [
                          field("email", "Email", "email", { required: false }),
                          field("phone", "Téléphone", "text", {
                              required: false,
                          }),
                          field("address", "Adresse", "text", {
                              required: false,
                          }),
                      ]),
                field(
                    "archived",
                    "Archiver cette fiche (historique conservé)",
                    "checkbox",
                ),
            ]}
            saved={saved}
            close={close}
        />
    );
}
export function ExpenseActions({ expense, can, saved }) {
    const [error, setError] = useState(""),
        [busy, setBusy] = useState(false);
    async function decide(action) {
        setBusy(true);
        try {
            await api(`/expenses/${expense.id}/decision`, {
                method: "POST",
                body: { action },
            });
            await saved();
        } catch (e) {
            setError(e.message);
        } finally {
            setBusy(false);
        }
    }
    return (
        <>
            <Tag value={expense.status} />
            {expense.status === "pending" && can("expenses.approve") && (
                <div className="module-actions">
                    {button("Valider", () => decide("approve"), busy)}
                    {button("Rejeter", () => decide("reject"), busy)}
                </div>
            )}
            {error && <small role="alert">{error}</small>}
        </>
    );
}
