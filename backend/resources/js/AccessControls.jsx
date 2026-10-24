import React, { useState } from "react";
import {
    ShieldCheck,
    Plus,
    LockKeyhole,
    Pencil,
    Copy,
    ArrowDown,
    ArrowUp,
    Trash2,
    Check,
    LoaderCircle,
    GitBranch,
    Users,
} from "lucide-react";
import { api } from "./api";
import { Modal, Field } from "./Forms";

const groups = {
    banking: "Rapprochements bancaires",
    refunds: "Remboursements clients",
    accounting: "Comptabilité",
    quotes: "Devis",
    purchases: "Achats",
    credits: "Avoirs",
    stock: "Stocks",
    communications: "Emails",
    subscription: "Abonnements",
    support: "Support",
    dashboard: "Tableau de bord",
    contacts: "Clients & fournisseurs",
    products: "Catalogue",
    invoices: "Facturation",
    payments: "Règlements",
    expenses: "Dépenses",
    team: "Collaborateurs",
    audit: "Journal",
    settings: "Paramètres",
    roles: "Rôles",
    workflows: "Validations",
};
export function assignableRoles(access, session) {
    return (access.roles || []).filter(
        (r) =>
            r.key !== "owner" &&
            r.permissions.every((p) => session.permissions.includes(p)),
    );
}
export function AccessPanel({ access, team, session, can, save }) {
    const [tab, setTab] = useState("members"),
        [editor, setEditor] = useState(null);
    const nameFor = (key) =>
        access.roles.find((r) => r.key === key)?.name || "Rôle indisponible";
    const manageable = (r) =>
        can("roles.manage") &&
        !r.builtin &&
        r.key !== session.role &&
        r.permissions.every((p) => session.permissions.includes(p));
    return (
        <>
            <div className="access-tabs" aria-label="Gestion des accès">
                <button
                    className={tab === "members" ? "selected" : ""}
                    onClick={() => setTab("members")}
                >
                    <Users size={17} /> Collaborateurs{" "}
                    <span>{team.length}</span>
                </button>
                <button
                    className={tab === "roles" ? "selected" : ""}
                    onClick={() => setTab("roles")}
                >
                    <ShieldCheck size={17} /> Rôles & permissions{" "}
                    <span>{access.roles.length}</span>
                </button>
            </div>
            {tab === "members" ? (
                <section className="panel">
                    <div className="table-scroll">
                        <table>
                            <thead>
                                <tr>
                                    <th>Membre</th>
                                    <th>Rôle</th>
                                    <th>Accès</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {team.map((m) => (
                                    <tr key={m.id}>
                                        <td>
                                            <strong>{m.name}</strong>
                                            <span className="cell-secondary">
                                                {m.email}
                                            </span>
                                        </td>
                                        <td>
                                            <span className="tag">
                                                {nameFor(m.role)}
                                            </span>
                                        </td>
                                        <td>
                                            <span
                                                className={`badge ${m.is_active ? "paid" : "draft"}`}
                                            >
                                                <i />
                                                {m.is_active
                                                    ? "Actif"
                                                    : "Désactivé"}
                                            </span>
                                        </td>
                                        <td>
                                            {m.role === "owner" ? (
                                                <span className="locked-label">
                                                    <LockKeyhole size={13} />{" "}
                                                    Accès protégé
                                                </span>
                                            ) : can("team.write") &&
                                              m.user_id !== session.user.id &&
                                              access.roles
                                                  .find((r) => r.key === m.role)
                                                  ?.permissions.every((p) =>
                                                      session.permissions.includes(
                                                          p,
                                                      ),
                                                  ) ? (
                                                <button
                                                    className="small-button"
                                                    onClick={() =>
                                                        setEditor({
                                                            type: "member",
                                                            member: m,
                                                        })
                                                    }
                                                >
                                                    <Pencil size={14} />{" "}
                                                    Modifier l’accès
                                                </button>
                                            ) : (
                                                <span className="helper">
                                                    {m.user_id ===
                                                    session.user.id
                                                        ? "Votre compte"
                                                        : "Consultation"}
                                                </span>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <div className="access-note">
                        Une désactivation bloque immédiatement les prochaines
                        requêtes du collaborateur. Le compte et son historique
                        sont conservés.
                    </div>
                </section>
            ) : (
                <>
                    <div className="access-toolbar">
                        <p>
                            Les rôles standards restent disponibles. Créez vos
                            propres combinaisons de droits.
                        </p>
                        {can("roles.manage") && (
                            <button
                                className="button primary"
                                onClick={() => setEditor({ type: "role" })}
                            >
                                <Plus size={17} /> Créer un rôle
                            </button>
                        )}
                    </div>
                    <div className="role-grid">
                        {access.roles.map((r) => (
                            <article className="panel role-card" key={r.key}>
                                <div className="role-card-heading">
                                    <div className="section-icon">
                                        <ShieldCheck size={21} />
                                    </div>
                                    <span className="tag">
                                        {r.builtin
                                            ? "Standard"
                                            : "Personnalisé"}
                                    </span>
                                </div>
                                <h2>{r.name}</h2>
                                <p>
                                    {r.permissions.length} permission
                                    {r.permissions.length > 1 ? "s" : ""} ·{" "}
                                    {
                                        team.filter(
                                            (m) =>
                                                m.role === r.key && m.is_active,
                                        ).length
                                    }{" "}
                                    membre(s) actif(s)
                                </p>
                                <div className="role-tags">
                                    {Object.keys(groups)
                                        .filter((g) =>
                                            r.permissions.some((p) =>
                                                p.startsWith(`${g}.`),
                                            ),
                                        )
                                        .map((g) => (
                                            <span key={g}>{groups[g]}</span>
                                        ))}
                                    {!r.permissions.length && (
                                        <span>Aucun module autorisé</span>
                                    )}
                                </div>
                                <div className="role-actions">
                                    <button
                                        className="text-button"
                                        onClick={() =>
                                            setEditor({
                                                type: "role",
                                                role: r,
                                                readOnly: !manageable(r),
                                            })
                                        }
                                    >
                                        {manageable(r)
                                            ? "Modifier les permissions"
                                            : "Voir les permissions"}
                                    </button>
                                    {can("roles.manage") &&
                                        r.permissions.every((p) =>
                                            session.permissions.includes(p),
                                        ) && (
                                            <button
                                                className="icon-button"
                                                title={`Dupliquer ${r.name}`}
                                                aria-label={`Dupliquer ${r.name}`}
                                                onClick={() =>
                                                    setEditor({
                                                        type: "role",
                                                        copy: r,
                                                    })
                                                }
                                            >
                                                <Copy size={16} />
                                            </button>
                                        )}
                                </div>
                            </article>
                        ))}
                    </div>
                </>
            )}
            {editor?.type === "role" && (
                <RoleEditor
                    access={access}
                    session={session}
                    {...editor}
                    close={() => setEditor(null)}
                    save={save}
                />
            )}
            {editor?.type === "member" && (
                <MemberEditor
                    member={editor.member}
                    access={access}
                    session={session}
                    close={() => setEditor(null)}
                    save={save}
                />
            )}
        </>
    );
}
function RoleEditor({
    access,
    session,
    role,
    copy,
    readOnly = false,
    close,
    save,
}) {
    const [name, setName] = useState(
            role?.name || (copy ? `${copy.name} — copie` : ""),
        ),
        [permissions, setPermissions] = useState(
            role?.permissions || copy?.permissions || [],
        ),
        [error, setError] = useState(""),
        [busy, setBusy] = useState(false);
    function toggle(key, checked) {
        let next = new Set(permissions);
        if (checked) {
            const add = (p) => {
                next.add(p);
                (access.dependencies[p] || []).forEach(add);
            };
            add(key);
        } else {
            next.delete(key);
            let changed = true;
            while (changed) {
                changed = false;
                for (const p of next) {
                    if (
                        (access.dependencies[p] || []).some((d) => !next.has(d))
                    ) {
                        next.delete(p);
                        changed = true;
                    }
                }
            }
        }
        setPermissions([...next]);
    }
    return (
        <Modal
            wide
            title={
                readOnly ? name : role ? "Modifier le rôle" : "Créer un rôle"
            }
            subtitle="Les droits de consultation sont aussi appliqués côté serveur."
            close={close}
        >
            <form
                className="record-form"
                onSubmit={async (e) => {
                    e.preventDefault();
                    setBusy(true);
                    setError("");
                    try {
                        await api(role ? `/roles/${role.id}` : "/roles", {
                            method: role ? "PUT" : "POST",
                            body: { name, permissions },
                        });
                        await save();
                        close();
                    } catch (e) {
                        setError(e.message);
                    } finally {
                        setBusy(false);
                    }
                }}
            >
                <Field
                    label="Nom du rôle"
                    required
                    maxLength={100}
                    value={name}
                    disabled={readOnly}
                    onChange={(e) => setName(e.target.value)}
                />
                <div className="info-banner">
                    {readOnly
                        ? "Ce rôle est consultable ici. Les rôles standards et votre propre rôle ne sont pas modifiables."
                        : "Les droits nécessaires sont cochés automatiquement. Décocher une consultation retire les actions qui en dépendent."}
                </div>
                <div className="permission-matrix">
                    {Object.entries(groups).map(([prefix, label]) => (
                        <fieldset key={prefix}>
                            <legend>{label}</legend>
                            {Object.entries(access.catalog)
                                .filter(([key]) => key.startsWith(`${prefix}.`))
                                .map(([key, description]) => (
                                    <label className="check-field" key={key}>
                                        <input
                                            type="checkbox"
                                            checked={permissions.includes(key)}
                                            disabled={
                                                readOnly ||
                                                !session.permissions.includes(
                                                    key,
                                                )
                                            }
                                            onChange={(e) =>
                                                toggle(key, e.target.checked)
                                            }
                                        />
                                        <span>{description}</span>
                                    </label>
                                ))}
                        </fieldset>
                    ))}
                </div>
                {error && (
                    <p className="error-box" role="alert">
                        {error}
                    </p>
                )}
                <div className="modal-actions">
                    <button
                        type="button"
                        className="button secondary"
                        onClick={close}
                    >
                        Fermer
                    </button>
                    {!readOnly && (
                        <button className="button primary" disabled={busy}>
                            {busy ? (
                                <LoaderCircle className="spin" size={16} />
                            ) : (
                                <Check size={16} />
                            )}{" "}
                            Enregistrer le rôle
                        </button>
                    )}
                </div>
            </form>
        </Modal>
    );
}
function MemberEditor({ member, access, session, close, save }) {
    const [role, setRole] = useState(member.role),
        [active, setActive] = useState(Boolean(member.is_active)),
        [error, setError] = useState(""),
        [busy, setBusy] = useState(false);
    return (
        <Modal
            title={`Accès de ${member.name}`}
            subtitle={member.email}
            close={close}
        >
            <form
                className="record-form"
                onSubmit={async (e) => {
                    e.preventDefault();
                    setBusy(true);
                    setError("");
                    try {
                        await api(`/members/${member.id}`, {
                            method: "PUT",
                            body: { role, is_active: active },
                        });
                        await save();
                        close();
                    } catch (e) {
                        setError(e.message);
                    } finally {
                        setBusy(false);
                    }
                }}
            >
                <Field label="Rôle">
                    <select
                        value={role}
                        onChange={(e) => setRole(e.target.value)}
                    >
                        {assignableRoles(access, session).map((r) => (
                            <option value={r.key} key={r.key}>
                                {r.name}
                            </option>
                        ))}
                    </select>
                </Field>
                <label className="check-field">
                    <input
                        type="checkbox"
                        checked={active}
                        onChange={(e) => setActive(e.target.checked)}
                    />
                    <span>Autoriser l’accès à l’entreprise</span>
                </label>
                {!active && (
                    <div className="info-banner">
                        Le collaborateur ne pourra plus accéder à cet espace, y
                        compris depuis une session déjà ouverte.
                    </div>
                )}
                <p className="helper">
                    Si cette personne intervient dans un circuit de validation,
                    prévoyez un autre collaborateur actif avec le même rôle.
                </p>
                {error && (
                    <p className="error-box" role="alert">
                        {error}
                    </p>
                )}
                <div className="modal-actions">
                    <button
                        type="button"
                        className="button secondary"
                        onClick={close}
                    >
                        Annuler
                    </button>
                    <button className="button primary" disabled={busy}>
                        Enregistrer l’accès
                    </button>
                </div>
            </form>
        </Modal>
    );
}
export function WorkflowEditor({ access, save }) {
    const [form, setForm] = useState(access.workflow),
        [error, setError] = useState(""),
        [busy, setBusy] = useState(false);
    if (!form)
        return <div className="panel access-note">Chargement du circuit…</div>;
    const options = access.roles.filter((r) =>
        r.permissions.includes("invoices.approve"),
    );
    const update = (key, value) => setForm((f) => ({ ...f, [key]: value }));
    const stepUpdate = (index, key, value) =>
        update(
            "steps",
            form.steps.map((s, i) =>
                i === index ? { ...s, [key]: value } : s,
            ),
        );
    const move = (index, offset) => {
        const steps = [...form.steps];
        [steps[index], steps[index + offset]] = [
            steps[index + offset],
            steps[index],
        ];
        update("steps", steps);
    };
    return (
        <div className="workflow-layout">
            <form
                className="panel workflow-form"
                onSubmit={async (e) => {
                    e.preventDefault();
                    setBusy(true);
                    setError("");
                    try {
                        await api("/invoice-workflow", {
                            method: "PUT",
                            body: form,
                        });
                        await save();
                    } catch (e) {
                        setError(e.message);
                    } finally {
                        setBusy(false);
                    }
                }}
            >
                <div className="workflow-intro">
                    <div className="section-icon">
                        <GitBranch />
                    </div>
                    <h2>Votre circuit de validation</h2>
                    <p>
                        Un contrôle progressif avant l’émission d’une facture.
                    </p>
                </div>
                <label className="check-field switch-row">
                    <input
                        type="checkbox"
                        checked={form.enabled}
                        onChange={(e) => update("enabled", e.target.checked)}
                    />
                    <span>
                        <strong>Activer le circuit personnalisé</strong>
                        <small>
                            Sans activation : une validation par un utilisateur
                            autorisé.
                        </small>
                    </span>
                </label>
                <fieldset disabled={!form.enabled} className="workflow-fields">
                    <Field
                        label="Appliquer ces étapes à partir de ce montant TTC (FCFA)"
                        type="number"
                        min="0"
                        max="2000000000000"
                        step="1"
                        required
                        value={form.threshold}
                        onChange={(e) => update("threshold", e.target.value)}
                    />
                    <p className="helper">
                        Sous le seuil, une seule validation est requise. Les
                        règles de séparation ci-dessous restent appliquées
                        lorsque le circuit est actif.
                    </p>
                    <div className="workflow-steps">
                        {form.steps.map((s, index) => (
                            <div className="workflow-step" key={index}>
                                <span className="step-number">{index + 1}</span>
                                <div className="step-fields">
                                    <Field
                                        label={`Nom de l’étape ${index + 1}`}
                                        required
                                        maxLength={90}
                                        value={s.name}
                                        onChange={(e) =>
                                            stepUpdate(
                                                index,
                                                "name",
                                                e.target.value,
                                            )
                                        }
                                    />
                                    <Field
                                        label={`Rôle validateur de l’étape ${index + 1}`}
                                    >
                                        <select
                                            required
                                            value={s.role}
                                            onChange={(e) =>
                                                stepUpdate(
                                                    index,
                                                    "role",
                                                    e.target.value,
                                                )
                                            }
                                        >
                                            {options.map((r) => (
                                                <option
                                                    value={r.key}
                                                    key={r.key}
                                                >
                                                    {r.name}
                                                </option>
                                            ))}
                                        </select>
                                    </Field>
                                </div>
                                <div className="step-actions">
                                    <button
                                        type="button"
                                        className="icon-button"
                                        disabled={index === 0}
                                        aria-label={`Monter l’étape ${index + 1}`}
                                        onClick={() => move(index, -1)}
                                    >
                                        <ArrowUp size={15} />
                                    </button>
                                    <button
                                        type="button"
                                        className="icon-button"
                                        disabled={
                                            index === form.steps.length - 1
                                        }
                                        aria-label={`Descendre l’étape ${index + 1}`}
                                        onClick={() => move(index, 1)}
                                    >
                                        <ArrowDown size={15} />
                                    </button>
                                    <button
                                        type="button"
                                        className="icon-button"
                                        disabled={form.steps.length === 1}
                                        aria-label={`Supprimer l’étape ${index + 1}`}
                                        onClick={() =>
                                            update(
                                                "steps",
                                                form.steps.filter(
                                                    (_, i) => i !== index,
                                                ),
                                            )
                                        }
                                    >
                                        <Trash2 size={15} />
                                    </button>
                                </div>
                            </div>
                        ))}
                    </div>
                    <button
                        type="button"
                        className="text-button"
                        disabled={form.steps.length >= 5}
                        onClick={() =>
                            update("steps", [
                                ...form.steps,
                                {
                                    name: `Validation ${form.steps.length + 1}`,
                                    role: "owner",
                                },
                            ])
                        }
                    >
                        <Plus size={16} /> Ajouter une étape
                    </button>
                    <div className="separation-rules">
                        <label className="check-field">
                            <input
                                type="checkbox"
                                checked={form.prevent_self_approval}
                                onChange={(e) =>
                                    update(
                                        "prevent_self_approval",
                                        e.target.checked,
                                    )
                                }
                            />
                            <span>
                                Interdire au créateur de valider sa propre
                                facture
                            </span>
                        </label>
                        <label className="check-field">
                            <input
                                type="checkbox"
                                checked={form.distinct_approvers}
                                onChange={(e) =>
                                    update(
                                        "distinct_approvers",
                                        e.target.checked,
                                    )
                                }
                            />
                            <span>
                                Exiger une personne différente à chaque étape
                            </span>
                        </label>
                    </div>
                </fieldset>
                {error && (
                    <p className="error-box" role="alert">
                        {error}
                    </p>
                )}
                <div className="modal-actions">
                    <button className="button primary" disabled={busy}>
                        {busy ? (
                            <LoaderCircle className="spin" size={16} />
                        ) : (
                            <Check size={16} />
                        )}{" "}
                        Enregistrer le circuit
                    </button>
                </div>
            </form>
            <aside className="panel workflow-summary">
                <span className="eyebrow">COMMENT ÇA MARCHE</span>
                <h2>
                    Des règles claires,
                    <br />à chaque étape.
                </h2>
                <ol>
                    <li>
                        <strong>Soumission</strong>
                        <p>
                            Le circuit est fixé selon le montant TTC à cet
                            instant.
                        </p>
                    </li>
                    <li>
                        <strong>Validation progressive</strong>
                        <p>
                            Chaque personne doit avoir le rôle attendu et le
                            droit de validation. Le propriétaire suit lui aussi
                            ces règles.
                        </p>
                    </li>
                    <li>
                        <strong>Émission</strong>
                        <p>
                            La facture reçoit son numéro après la dernière
                            validation.
                        </p>
                    </li>
                </ol>
                <div className="info-banner">
                    Les factures déjà en attente conservent leur circuit. Un
                    rejet motivé les renvoie en brouillon ; une nouvelle
                    soumission démarre un nouveau cycle.
                </div>
                <p className="helper">
                    Assignez les rôles à des validateurs actifs avant la
                    soumission. Si aucun circuit réalisable n’existe, la facture
                    reste en brouillon.
                </p>
            </aside>
        </div>
    );
}
export function RejectInvoiceDialog({ invoice, close, save }) {
    const [comment, setComment] = useState(""),
        [error, setError] = useState(""),
        [busy, setBusy] = useState(false);
    return (
        <Modal
            title="Renvoyer la facture en brouillon"
            subtitle="Le motif sera conservé dans l’historique des validations."
            close={close}
        >
            <form
                className="record-form"
                onSubmit={async (e) => {
                    e.preventDefault();
                    setBusy(true);
                    setError("");
                    try {
                        await api(`/invoices/${invoice.id}/transition`, {
                            method: "POST",
                            body: {
                                action: "reject",
                                round: invoice.approval_round,
                                step: invoice.approval_step,
                                comment,
                            },
                        });
                        await save();
                        close();
                    } catch (e) {
                        setError(e.message);
                    } finally {
                        setBusy(false);
                    }
                }}
            >
                <Field label="Motif du retour">
                    <textarea
                        required
                        minLength={3}
                        maxLength={1000}
                        rows={4}
                        value={comment}
                        onChange={(e) => setComment(e.target.value)}
                    />
                </Field>
                {error && (
                    <p className="error-box" role="alert">
                        {error}
                    </p>
                )}
                <div className="modal-actions">
                    <button
                        type="button"
                        className="button secondary"
                        onClick={close}
                    >
                        Annuler
                    </button>
                    <button className="button primary" disabled={busy}>
                        Renvoyer en brouillon
                    </button>
                </div>
            </form>
        </Modal>
    );
}
