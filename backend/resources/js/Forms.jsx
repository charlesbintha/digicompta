import React, { useEffect, useRef, useState } from "react";
import { createPortal } from "react-dom";
import { Plus, X, Trash2, Check, LoaderCircle } from "lucide-react";
import { api, money, today, date } from "./api";

export function Field({ label, children, ...props }) {
    return (
        <label className="field">
            <span>{label}</span>
            {children || <input {...props} />}
        </label>
    );
}
export function Modal({ title, subtitle, children, close, wide = false }) {
    const ref = useRef();
    useEffect(() => {
        const dialog = ref.current;
        dialog.showModal();
        return () => dialog.close();
    }, []);
    return createPortal(
        <dialog
            ref={ref}
            className={wide ? "modal wide" : "modal"}
            onCancel={close}
            aria-labelledby="modal-title"
        >
            <div className="modal-head">
                <div>
                    <h2 id="modal-title">{title}</h2>
                    {subtitle && <p>{subtitle}</p>}
                </div>
                <button
                    className="icon-button"
                    onClick={close}
                    aria-label="Fermer"
                >
                    <X size={21} />
                </button>
            </div>
            {children}
        </dialog>,
        document.body,
    );
}
export function RecordForm({
    kind,
    contacts,
    products,
    invoice,
    company,
    roleOptions = [],
    save,
    close,
}) {
    const defaults = {
        contact: {
            name: "",
            email: "",
            phone: "",
            address: "",
            type: "client",
        },
        supplier: {
            name: "",
            email: "",
            phone: "",
            address: "",
            type: "supplier",
        },
        product: { name: "", sku: "", price: "", type: "service" },
        expense: {
            description: "",
            category: "Fonctionnement",
            amount: "",
            paid_at: today(),
        },
        payment: {
            amount: invoice
                ? Math.max(
                      0,
                      invoice.total -
                          invoice.paid -
                          (invoice.credited || 0) +
                          (invoice.refunded || 0),
                  )
                : "",
            paid_at: today(),
            method: "Virement",
            request_id: crypto.randomUUID(),
        },
        member: {
            name: "",
            email: "",
            password: "",
            role: roleOptions.some((r) => r.key === "sales")
                ? "sales"
                : roleOptions[0]?.key || "",
        },
        settings: {
            name: company.name,
            address: company.address || "",
            ninea: company.ninea || "",
            rccm: company.rccm || "",
        },
        invoice: invoice
            ? {
                  contact_id: invoice.contact_id,
                  issue_date: invoice.issue_date,
                  due_date: invoice.due_date,
                  tax_basis_points: invoice.tax_basis_points,
                  notes: invoice.notes || "",
                  items: invoice.items.map(
                      ({ description, quantity, unit_price, product_id }) => ({
                          product_id: product_id || null,
                          description,
                          quantity,
                          unit_price,
                      }),
                  ),
              }
            : {
                  contact_id: "",
                  issue_date: today(),
                  due_date: today(),
                  tax_basis_points: 0,
                  notes: "",
                  items: [{ description: "", quantity: 1, unit_price: 0 }],
              },
    };
    const [form, setForm] = useState(defaults[kind]);
    const [error, setError] = useState("");
    const [busy, setBusy] = useState(false);
    const update = (key, value) => setForm((f) => ({ ...f, [key]: value }));
    const props = (key) => ({
        value: form[key],
        onChange: (e) => update(key, e.target.value),
    });
    const itemUpdate = (index, key, value) =>
        setForm((f) => ({
            ...f,
            items: f.items.map((item, i) =>
                i === index ? { ...item, [key]: value } : item,
            ),
        }));
    const titles = {
        contact: "Nouveau client",
        supplier: "Nouveau fournisseur",
        product: "Nouveau produit ou service",
        expense: "Enregistrer une dépense",
        invoice: invoice ? "Modifier le brouillon" : "Nouvelle facture",
        payment: "Enregistrer un règlement",
        member: "Ajouter un collaborateur",
        settings: "Informations de l’entreprise",
    };
    async function submit(e) {
        e.preventDefault();
        setBusy(true);
        setError("");
        try {
            const paths = {
                contact: "/contacts",
                supplier: "/contacts",
                product: "/products",
                expense: "/expenses",
                invoice: invoice ? `/invoices/${invoice.id}` : "/invoices",
                payment: `/invoices/${invoice?.id}/payments`,
                member: "/members",
                settings: "/settings",
            };
            await api(paths[kind], {
                method:
                    kind === "settings" || (kind === "invoice" && invoice)
                        ? "PUT"
                        : "POST",
                body: form,
            });
            await save();
            close();
        } catch (e) {
            setError(e.message);
        } finally {
            setBusy(false);
        }
    }
    const subtotal =
        form.items?.reduce(
            (sum, i) => sum + Number(i.quantity) * Number(i.unit_price),
            0,
        ) || 0;
    return (
        <Modal
            title={titles[kind]}
            subtitle={
                kind === "invoice"
                    ? "Préparez votre facture en XOF. Elle sera enregistrée en brouillon."
                    : "Les informations sont enregistrées dans votre espace entreprise."
            }
            wide={kind === "invoice"}
            close={close}
        >
            <form onSubmit={submit} className="record-form">
                {(kind === "contact" || kind === "supplier") && (
                    <>
                        <Field
                            label="Nom / raison sociale"
                            required
                            maxLength={150}
                            {...props("name")}
                        />
                        <div className="form-grid">
                            <Field
                                label="Adresse email"
                                type="email"
                                {...props("email")}
                            />
                            <Field
                                label="Téléphone"
                                type="tel"
                                placeholder="+221 77 000 00 00"
                                {...props("phone")}
                            />
                        </div>
                        <Field label="Adresse" {...props("address")} />
                    </>
                )}
                {kind === "product" && (
                    <>
                        <Field
                            label="Désignation"
                            required
                            {...props("name")}
                        />
                        <div className="form-grid">
                            <Field label="Type">
                                <select {...props("type")}>
                                    <option value="service">Service</option>
                                    <option value="product">Produit</option>
                                </select>
                            </Field>
                            <Field
                                label="Référence (facultatif)"
                                {...props("sku")}
                            />
                        </div>
                        <Field
                            label="Prix unitaire hors taxe (FCFA)"
                            required
                            type="number"
                            min="0"
                            max="1000000000"
                            step="1"
                            {...props("price")}
                        />
                    </>
                )}
                {kind === "expense" && (
                    <>
                        <Field
                            label="Libellé"
                            required
                            {...props("description")}
                        />
                        <Field label="Catégorie">
                            <select {...props("category")}>
                                {[
                                    "Fonctionnement",
                                    "Achats de marchandises",
                                    "Loyer",
                                    "Transport",
                                    "Salaires",
                                    "Autres",
                                ].map((v) => (
                                    <option key={v}>{v}</option>
                                ))}
                            </select>
                        </Field>
                        <div className="form-grid">
                            <Field
                                label="Montant payé (FCFA)"
                                type="number"
                                min="1"
                                max="1000000000"
                                step="1"
                                required
                                {...props("amount")}
                            />
                            <Field
                                label="Date de paiement"
                                type="date"
                                max={today()}
                                required
                                {...props("paid_at")}
                            />
                        </div>
                    </>
                )}
                {kind === "payment" && (
                    <>
                        <div className="info-banner">
                            {invoice.number} · Solde restant :{" "}
                            <strong>
                                {money(
                                    invoice.total -
                                        invoice.paid -
                                        (invoice.credited || 0) +
                                        (invoice.refunded || 0),
                                )}
                            </strong>
                        </div>
                        <Field
                            label="Montant reçu (FCFA)"
                            type="number"
                            min="1"
                            max={
                                invoice.total -
                                invoice.paid -
                                (invoice.credited || 0) +
                                (invoice.refunded || 0)
                            }
                            required
                            step="1"
                            {...props("amount")}
                        />
                        <div className="form-grid">
                            <Field
                                label="Date du paiement"
                                type="date"
                                min={invoice.issue_date}
                                max={today()}
                                required
                                {...props("paid_at")}
                            />
                            <Field label="Moyen de paiement">
                                <select {...props("method")}>
                                    {[
                                        "Virement",
                                        "Espèces",
                                        "Wave",
                                        "Orange Money",
                                        "Chèque",
                                    ].map((v) => (
                                        <option key={v}>{v}</option>
                                    ))}
                                </select>
                            </Field>
                        </div>
                        <p className="helper">
                            Enregistrement manuel d’un paiement déjà reçu. Aucun
                            prélèvement n’est effectué.
                        </p>
                    </>
                )}
                {kind === "member" && (
                    <>
                        <Field
                            label="Nom complet"
                            required
                            {...props("name")}
                        />
                        <Field
                            label="Adresse email"
                            type="email"
                            required
                            {...props("email")}
                        />
                        <Field
                            label="Mot de passe (12 caractères minimum)"
                            type="password"
                            autoComplete="new-password"
                            minLength={12}
                            required
                            {...props("password")}
                        />
                        <Field label="Rôle">
                            <select required {...props("role")}>
                                {roleOptions.map((r) => (
                                    <option key={r.key} value={r.key}>
                                        {r.name}
                                    </option>
                                ))}
                            </select>
                        </Field>
                        <p className="helper">
                            Transmettez les identifiants à votre collaborateur
                            par un canal sécurisé. L’invitation par email sera
                            ajoutée ultérieurement.
                        </p>
                    </>
                )}
                {kind === "settings" && (
                    <>
                        <Field
                            label="Raison sociale"
                            required
                            {...props("name")}
                        />
                        <Field label="Adresse" {...props("address")} />
                        <div className="form-grid">
                            <Field label="NINEA" {...props("ninea")} />
                            <Field label="RCCM" {...props("rccm")} />
                        </div>
                        <div className="info-banner">
                            Sénégal · Devise XOF · Fuseau horaire Africa/Dakar
                        </div>
                    </>
                )}
                {kind === "invoice" && (
                    <>
                        <Field label="Client">
                            <select required {...props("contact_id")}>
                                <option value="">Sélectionner un client</option>
                                {contacts
                                    .filter(
                                        (c) =>
                                            c.type === "client" && !c.archived,
                                    )
                                    .map((c) => (
                                        <option key={c.id} value={c.id}>
                                            {c.name}
                                        </option>
                                    ))}
                            </select>
                        </Field>
                        {!contacts.some((c) => c.type === "client") && (
                            <p className="helper">
                                Ajoutez d’abord un client depuis le module
                                Clients.
                            </p>
                        )}
                        <div className="form-grid">
                            <Field
                                label="Date de facture"
                                type="date"
                                required
                                {...props("issue_date")}
                            />
                            <Field
                                label="Échéance"
                                type="date"
                                min={form.issue_date}
                                required
                                {...props("due_date")}
                            />
                        </div>
                        <div className="line-heading">Produits et services</div>
                        {form.items.map((item, index) => (
                            <div className="invoice-line" key={index}>
                                <div>
                                    <Field
                                        label={`Désignation ${index + 1}`}
                                        required
                                        value={item.description}
                                        onChange={(e) =>
                                            itemUpdate(
                                                index,
                                                "description",
                                                e.target.value,
                                            )
                                        }
                                    />
                                    {products.length > 0 && (
                                        <select
                                            aria-label={`Choisir un produit pour la ligne ${index + 1}`}
                                            value={item.product_id || ""}
                                            onChange={(e) => {
                                                const p = products.find(
                                                    (p) =>
                                                        p.id ===
                                                        Number(e.target.value),
                                                );
                                                if (!p) {
                                                    itemUpdate(
                                                        index,
                                                        "product_id",
                                                        null,
                                                    );
                                                    return;
                                                }
                                                if (p)
                                                    setForm((f) => ({
                                                        ...f,
                                                        items: f.items.map(
                                                            (item, i) =>
                                                                i === index
                                                                    ? {
                                                                          ...item,
                                                                          product_id:
                                                                              p.id,
                                                                          description:
                                                                              p.name,
                                                                          unit_price:
                                                                              p.price,
                                                                      }
                                                                    : item,
                                                        ),
                                                    }));
                                            }}
                                        >
                                            <option value="">
                                                Ligne libre (sans mouvement de
                                                stock)
                                            </option>
                                            {products
                                                .filter((p) => !p.archived)
                                                .map((p) => (
                                                    <option
                                                        key={p.id}
                                                        value={p.id}
                                                    >
                                                        {p.name}
                                                    </option>
                                                ))}
                                        </select>
                                    )}
                                </div>
                                <Field
                                    label="Qté"
                                    type="number"
                                    min="1"
                                    max="10000"
                                    step="1"
                                    required
                                    value={item.quantity}
                                    onChange={(e) =>
                                        itemUpdate(
                                            index,
                                            "quantity",
                                            e.target.value,
                                        )
                                    }
                                />
                                <Field
                                    label="Prix HT (FCFA)"
                                    type="number"
                                    min="0"
                                    max="1000000000"
                                    step="1"
                                    required
                                    value={item.unit_price}
                                    onChange={(e) =>
                                        itemUpdate(
                                            index,
                                            "unit_price",
                                            e.target.value,
                                        )
                                    }
                                />
                                <button
                                    type="button"
                                    className="icon-button"
                                    disabled={form.items.length === 1}
                                    aria-label={`Supprimer la ligne ${index + 1}`}
                                    onClick={() =>
                                        update(
                                            "items",
                                            form.items.filter(
                                                (_, i) => i !== index,
                                            ),
                                        )
                                    }
                                >
                                    <Trash2 size={17} />
                                </button>
                            </div>
                        ))}
                        <button
                            className="text-button"
                            type="button"
                            onClick={() =>
                                update("items", [
                                    ...form.items,
                                    {
                                        description: "",
                                        quantity: 1,
                                        unit_price: 0,
                                    },
                                ])
                            }
                        >
                            <Plus size={16} /> Ajouter une ligne
                        </button>
                        <div className="form-grid">
                            <Field
                                label="Taux de taxe (%) — à renseigner"
                                type="number"
                                min="0"
                                max="100"
                                step="0.01"
                                value={form.tax_basis_points / 100}
                                onChange={(e) =>
                                    update(
                                        "tax_basis_points",
                                        Math.round(
                                            Number(e.target.value) * 100,
                                        ),
                                    )
                                }
                            />
                            <div className="invoice-total">
                                <small>Total TTC</small>
                                <strong>
                                    {money(
                                        subtotal +
                                            Math.floor(
                                                (subtotal *
                                                    form.tax_basis_points +
                                                    5000) /
                                                    10000,
                                            ),
                                    )}
                                </strong>
                            </div>
                        </div>
                        <Field label="Notes et conditions de paiement">
                            <textarea
                                rows={3}
                                maxLength={2000}
                                {...props("notes")}
                            />
                        </Field>
                    </>
                )}
                {error && (
                    <div role="alert" className="error-box">
                        {error}
                    </div>
                )}
                <div className="modal-actions">
                    <button
                        type="button"
                        className="button secondary"
                        onClick={close}
                        disabled={busy}
                    >
                        Annuler
                    </button>
                    <button className="button primary" disabled={busy}>
                        {busy ? (
                            <LoaderCircle className="spin" size={17} />
                        ) : (
                            <Check size={17} />
                        )}{" "}
                        {kind === "invoice"
                            ? "Enregistrer le brouillon"
                            : "Enregistrer"}
                    </button>
                </div>
            </form>
        </Modal>
    );
}
export function InvoiceDetail({ invoice, close }) {
    const client = invoice.customer_snapshot;
    const company = invoice.seller_snapshot;
    return (
        <Modal
            wide
            title={invoice.number || `Brouillon #${invoice.id}`}
            subtitle={`Créée le ${date(invoice.issue_date)}`}
            close={close}
        >
            <div className="invoice-document">
                <div className="document-top">
                    <div>
                        <h2>{company.name}</h2>
                        <p>{company.address}</p>
                        {company.ninea && <p>NINEA : {company.ninea}</p>}
                        {company.rccm && <p>RCCM : {company.rccm}</p>}
                    </div>
                    <div>
                        <span className="eyebrow">FACTURÉ À</span>
                        <h3>{client.name}</h3>
                        <p>{client.address}</p>
                        <p>{client.email}</p>
                    </div>
                </div>
                <div className="info-banner">
                    Échéance : {date(invoice.due_date)} · Devise : XOF
                </div>
                <div className="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Désignation</th>
                                <th>Qté</th>
                                <th>Prix HT</th>
                                <th>Total HT</th>
                            </tr>
                        </thead>
                        <tbody>
                            {invoice.items.map((i) => (
                                <tr key={i.id}>
                                    <td>{i.description}</td>
                                    <td>{i.quantity}</td>
                                    <td>{money(i.unit_price)}</td>
                                    <td>{money(i.total)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <div className="document-totals">
                    <p>
                        Sous-total <strong>{money(invoice.subtotal)}</strong>
                    </p>
                    <p>
                        Taxe ({invoice.tax_basis_points / 100} %){" "}
                        <strong>{money(invoice.tax_amount)}</strong>
                    </p>
                    <p>
                        Total TTC <strong>{money(invoice.total)}</strong>
                    </p>
                    <p>
                        Déjà réglé <strong>{money(invoice.paid)}</strong>
                    </p>
                    {!!invoice.credited && (
                        <p>
                            Avoirs émis{" "}
                            <strong>{money(invoice.credited)}</strong>
                        </p>
                    )}
                    {!!invoice.refunded && (
                        <p>
                            Déjà remboursé{" "}
                            <strong>{money(invoice.refunded)}</strong>
                        </p>
                    )}
                    {!!invoice.refund_due && (
                        <p>
                            À rembourser au client{" "}
                            <strong>{money(invoice.refund_due)}</strong>
                        </p>
                    )}
                    <p>
                        Solde client (négatif = crédit){" "}
                        <strong>
                            {money(
                                invoice.total -
                                    invoice.paid -
                                    (invoice.credited || 0) +
                                    (invoice.refunded || 0),
                            )}
                        </strong>
                    </p>
                </div>
                <p className="document-notes">{invoice.notes}</p>
            </div>
            <section className="approval-history no-print">
                <h3>Suivi de validation</h3>
                {invoice.status === "pending" && (
                    <div className="info-banner">
                        Étape {invoice.approval_step + 1}/
                        {invoice.approval_snapshot?.steps.length || 1} :{" "}
                        {invoice.approval_snapshot?.steps[invoice.approval_step]
                            ?.name || "Validation"}{" "}
                        ·{" "}
                        {invoice.approval_snapshot?.steps[invoice.approval_step]
                            ?.role_name || "Validateur autorisé"}
                    </div>
                )}
                {(invoice.approvals || []).length ? (
                    invoice.approvals.map((a) => (
                        <div className="approval-entry" key={a.id}>
                            <span
                                className={`badge ${a.action === "approve" ? "paid" : "overdue"}`}
                            >
                                {a.action === "approve"
                                    ? "Validée"
                                    : "Retournée"}
                            </span>
                            <div>
                                <strong>
                                    Cycle {a.round} · {a.step_name}
                                </strong>
                                <p>
                                    {a.actor} · {date(a.created_at)}
                                </p>
                                {a.comment && (
                                    <blockquote>{a.comment}</blockquote>
                                )}
                            </div>
                        </div>
                    ))
                ) : (
                    <p className="helper">
                        Aucune décision enregistrée dans l’historique de
                        validation.
                    </p>
                )}
            </section>
            <div className="modal-actions">
                <button className="button secondary" onClick={close}>
                    Fermer
                </button>
                <button
                    className="button primary"
                    onClick={() => window.print()}
                >
                    Imprimer / PDF
                </button>
            </div>
        </Modal>
    );
}
