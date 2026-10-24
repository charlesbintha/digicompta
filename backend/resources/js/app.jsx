import React, { useEffect, useState } from "react";
import { createRoot } from "react-dom/client";
import {
    LayoutDashboard,
    Users,
    Truck,
    Package,
    FileText,
    ShoppingBag,
    BookOpen,
    Settings,
    ShieldCheck,
    ArrowUpRight,
    ArrowDownLeft,
    ArrowRight,
    Plus,
    Search,
    LogOut,
    Menu,
    X,
    Clock,
    CheckCheck,
    CircleHelp,
    Activity,
    Building2,
    Layers,
    LoaderCircle,
    Check,
    CreditCard,
    Eye,
    Send,
    Ban,
    Pencil,
} from "lucide-react";
import {
    api,
    money,
    shortMoney,
    today,
    date,
    status,
    statuses,
    roles,
} from "./api";
import { RecordForm, InvoiceDetail, Field } from "./Forms";
import "../css/app.css";
import {
    BankPanel,
    RefundPanel,
    DraftPanel,
    ReturnsPanel,
    ReportsPanel,
} from "./Treasury";
import {
    CommercePanel,
    AccountingPanel,
    SubscriptionPanel,
    SupportPanel,
    CommunicationsPanel,
    NotificationsPanel,
    PlatformPanel,
    EditRecord,
    ExpenseActions,
} from "./Modules";
import {
    AccessPanel,
    WorkflowEditor,
    RejectInvoiceDialog,
    assignableRoles,
} from "./AccessControls";

const nav = [
    ["dashboard", "Vue d’ensemble", LayoutDashboard],
    ["invoices", "Factures & ventes", FileText],
    ["quotes", "Devis", FileText],
    ["drafts", "Brouillons & archives", FileText],
    ["credits", "Avoirs", FileText],
    ["refunds", "Remboursements", ArrowDownLeft],
    ["clients", "Clients", Users],
    ["suppliers", "Fournisseurs", Truck],
    ["products", "Produits & services", Package],
    ["stock", "Stocks", Package],
    ["returns", "Retours produits", Package],
    ["purchases", "Achats fournisseurs", Truck],
    ["expenses", "Dépenses", ShoppingBag],
    ["accounting", "Comptabilité", BookOpen],
    ["banking", "Banque & rapprochements", CreditCard],
    ["reports", "États de gestion", BookOpen],
];
const secondaryNav = [
    ["subscription", "Abonnement", CreditCard],
    ["communications", "Emails", Send],
    ["support", "Support & bugs", CircleHelp],
    ["team", "Équipe & accès", ShieldCheck],
    ["activity", "Journal d’activité", Activity],
    ["settings", "Paramètres", Settings],
    ["workflows", "Validations", CheckCheck],
];
const pagePermissions = {
    banking: "banking.read",
    refunds: "refunds.read",
    drafts: "invoices.read",
    returns: "stock.read",
    reports: "accounting.read",
    quotes: "quotes.read",
    purchases: "purchases.read",
    credits: "invoices.read",
    stock: "stock.read",
    accounting: "accounting.read",
    subscription: "subscription.read",
    communications: "communications.read",
    support: "support.read",
    dashboard: "dashboard.read",
    invoices: "invoices.read",
    clients: "contacts.read",
    suppliers: "contacts.read",
    products: "products.read",
    expenses: "expenses.read",
    team: "team.read",
    activity: "audit.read",
    settings: "settings.read",
    workflows: "workflows.manage",
};
const emptyAccess = {
    roles: [],
    catalog: {},
    dependencies: {},
    workflow: null,
};
const emptyData = {
    contacts: [],
    products: [],
    invoices: [],
    expenses: [],
    payments: [],
    purchase_payments: [],
    audit: [],
    team: [],
};
function Brand() {
    return (
        <div className="brand">
            <div className="brand-mark">
                <Layers size={23} />
            </div>
            <span>
                digi<span className="brand-light">compta</span>
                <span className="brand-dot">.</span>
            </span>
        </div>
    );
}
function Badge({ value }) {
    return (
        <span className={`badge ${value}`}>
            <i />
            {statuses[value] || value}
        </span>
    );
}
function Empty({
    title = "Aucun élément pour le moment",
    description = "Ajoutez votre premier élément pour commencer.",
}) {
    return (
        <div className="empty">
            <FileText size={30} />
            <h3>{title}</h3>
            <p>{description}</p>
        </div>
    );
}
function Auth({ onLogin }) {
    const [register, setRegister] = useState(false),
        [form, setForm] = useState({
            name: "",
            company: "",
            email: "",
            password: "",
            password_confirmation: "",
        }),
        [error, setError] = useState(""),
        [busy, setBusy] = useState(false);
    async function submit(e) {
        e.preventDefault();
        setBusy(true);
        setError("");
        try {
            onLogin(
                await api(register ? "/register" : "/login", {
                    method: "POST",
                    body: form,
                }),
            );
        } catch (e) {
            setError(e.message);
        } finally {
            setBusy(false);
        }
    }
    return (
        <div className="auth-page">
            <section className="auth-story">
                <Brand />
                <div className="auth-copy">
                    <span className="eyebrow">
                        PENSÉ POUR LES ENTREPRISES DU SÉNÉGAL
                    </span>
                    <h1>
                        Votre business.
                        <br />
                        Une vue claire.
                        <br />
                        <em>L’esprit tranquille.</em>
                    </h1>
                    <p>
                        Des premières factures aux prochains projets,
                        <br />
                        gardez le contrôle de votre activité.
                    </p>
                    <div className="auth-benefits">
                        <span>
                            <CheckCheck /> Facturation en FCFA
                        </span>
                        <span>
                            <ShieldCheck /> Un espace pour votre équipe
                        </span>
                    </div>
                </div>
                <div className="auth-footer">
                    Dakar, Sénégal <span>Simple. Local. À vos côtés.</span>
                </div>
            </section>
            <section className="auth-form-section">
                <div className="auth-form">
                    <span className="eyebrow">BIENVENUE SUR DIGI-COMPTA</span>
                    <h2>
                        {register
                            ? "Créons votre espace."
                            : "Heureux de vous retrouver."}
                    </h2>
                    <p>
                        {register
                            ? "Votre entreprise mérite une gestion plus simple."
                            : "Connectez-vous pour retrouver votre entreprise."}
                    </p>
                    <form onSubmit={submit}>
                        {register && (
                            <>
                                <Field
                                    label="Votre nom"
                                    required
                                    value={form.name}
                                    onChange={(e) =>
                                        setForm({
                                            ...form,
                                            name: e.target.value,
                                        })
                                    }
                                />
                                <Field
                                    label="Nom de l’entreprise"
                                    required
                                    value={form.company}
                                    onChange={(e) =>
                                        setForm({
                                            ...form,
                                            company: e.target.value,
                                        })
                                    }
                                />
                            </>
                        )}
                        <Field
                            label="Adresse email"
                            type="email"
                            autoComplete="username"
                            required
                            value={form.email}
                            onChange={(e) =>
                                setForm({ ...form, email: e.target.value })
                            }
                        />
                        <Field
                            label="Mot de passe"
                            type="password"
                            autoComplete={
                                register ? "new-password" : "current-password"
                            }
                            minLength={register ? 12 : undefined}
                            required
                            value={form.password}
                            onChange={(e) =>
                                setForm({ ...form, password: e.target.value })
                            }
                        />
                        {register && (
                            <Field
                                label="Confirmer le mot de passe"
                                type="password"
                                autoComplete="new-password"
                                required
                                value={form.password_confirmation}
                                onChange={(e) =>
                                    setForm({
                                        ...form,
                                        password_confirmation: e.target.value,
                                    })
                                }
                            />
                        )}{" "}
                        {error && (
                            <p className="error-box" role="alert">
                                {error}
                            </p>
                        )}
                        <button
                            className="button primary auth-submit"
                            disabled={busy}
                        >
                            {busy ? (
                                <LoaderCircle className="spin" size={18} />
                            ) : (
                                <ArrowRight size={18} />
                            )}{" "}
                            {register ? "Créer mon entreprise" : "Se connecter"}
                        </button>
                    </form>
                    <p className="auth-switch">
                        {register ? "Déjà un compte ?" : "Vous démarrez ?"}{" "}
                        <button
                            className="text-button"
                            onClick={() => {
                                setRegister(!register);
                                setError("");
                            }}
                        >
                            {register ? "Se connecter" : "Créer mon entreprise"}
                        </button>
                    </p>
                    <p className="auth-country">
                        Sénégal <span>•</span> XOF / FCFA <span>•</span>{" "}
                        Français
                    </p>
                </div>
            </section>
        </div>
    );
}
function InvoiceTable({
    invoices,
    open,
    act,
    can,
    compact = false,
    busy = false,
}) {
    return invoices.length ? (
        <div className="table-scroll">
            <table>
                <thead>
                    <tr>
                        <th>Facture / Client</th>
                        <th>Échéance</th>
                        <th>Montant TTC</th>
                        <th>Statut</th>
                        <th>
                            <span className="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {invoices.map((i) => (
                        <tr key={i.id}>
                            <td>
                                <button
                                    className="table-link"
                                    onClick={() =>
                                        open({ kind: "detail", invoice: i })
                                    }
                                >
                                    {i.number ||
                                        `BROUILLON-${String(i.id).padStart(4, "0")}`}
                                </button>
                                <span className="cell-secondary">
                                    {i.customer_snapshot.name}
                                </span>
                            </td>
                            <td>{date(i.due_date)}</td>
                            <td className="amount">
                                {money(i.total)}
                                {i.paid > 0 && i.status !== "paid" && (
                                    <span className="cell-secondary">
                                        Solde :{" "}
                                        {money(
                                            Math.max(
                                                0,
                                                i.total -
                                                    i.paid -
                                                    (i.credited || 0) +
                                                    (i.refunded || 0),
                                            ),
                                        )}
                                    </span>
                                )}
                            </td>
                            <td>
                                <Badge value={status(i)} />
                                {i.status === "pending" && (
                                    <span className="cell-secondary">
                                        Étape {i.approval_step + 1}/
                                        {i.approval_snapshot?.steps.length || 1}{" "}
                                        ·{" "}
                                        {i.approval_snapshot?.steps[
                                            i.approval_step
                                        ]?.name || "Validation"}
                                    </span>
                                )}
                            </td>
                            <td>
                                <div className="row-actions">
                                    <button
                                        className="icon-button"
                                        title="Voir la facture"
                                        aria-label={`Voir la facture ${i.number || i.id}`}
                                        onClick={() =>
                                            open({ kind: "detail", invoice: i })
                                        }
                                    >
                                        <Eye size={17} />
                                    </button>
                                    {!compact && (
                                        <>
                                            {i.allowed_actions?.includes(
                                                "edit",
                                            ) && (
                                                <button
                                                    className="small-button"
                                                    onClick={() =>
                                                        open({
                                                            kind: "invoice",
                                                            invoice: i,
                                                        })
                                                    }
                                                >
                                                    <Pencil size={14} />{" "}
                                                    Modifier
                                                </button>
                                            )}
                                            {i.status === "draft" &&
                                                i.allowed_actions?.includes(
                                                    "submit",
                                                ) && (
                                                    <button
                                                        className="small-button"
                                                        disabled={busy}
                                                        onClick={() =>
                                                            act(i, "submit")
                                                        }
                                                    >
                                                        <Send size={14} />{" "}
                                                        Soumettre
                                                    </button>
                                                )}
                                            {i.status === "pending" &&
                                                i.allowed_actions?.includes(
                                                    "approve",
                                                ) && (
                                                    <>
                                                        <button
                                                            className="small-button"
                                                            disabled={busy}
                                                            onClick={() =>
                                                                act(
                                                                    i,
                                                                    "approve",
                                                                )
                                                            }
                                                        >
                                                            <Check size={14} />{" "}
                                                            Valider
                                                        </button>
                                                        <button
                                                            className="icon-button"
                                                            disabled={busy}
                                                            title="Retour en brouillon"
                                                            aria-label="Retour en brouillon"
                                                            onClick={() =>
                                                                act(i, "reject")
                                                            }
                                                        >
                                                            <Ban size={16} />
                                                        </button>
                                                    </>
                                                )}
                                            {i.status === "issued" &&
                                                can("payments.write") && (
                                                    <button
                                                        className="small-button"
                                                        onClick={() =>
                                                            open({
                                                                kind: "payment",
                                                                invoice: i,
                                                            })
                                                        }
                                                    >
                                                        <CreditCard size={14} />{" "}
                                                        Régler
                                                    </button>
                                                )}
                                        </>
                                    )}
                                </div>
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    ) : (
        <Empty
            title="Vos factures apparaîtront ici"
            description="Créez une facture pour suivre vos ventes et vos règlements."
        />
    );
}
function Dashboard({ data, session, open, navigate, can }) {
    const [period, setPeriod] = useState("month");
    const current = today().slice(0, 7);
    const inPeriod = (d) => period === "all" || String(d).startsWith(current);
    const revenue = data.invoices
        .filter(
            (i) =>
                ["issued", "paid"].includes(i.status) && inPeriod(i.issue_date),
        )
        .reduce((s, i) => s + Number(i.total) - Number(i.credited || 0), 0);
    const received = data.payments
        .filter((p) => inPeriod(p.paid_at))
        .reduce((s, p) => s + Number(p.amount), 0);
    const spent = [
        ...data.expenses.filter((p) => p.status === "approved"),
        ...(data.refunds || []).map((p) => ({ ...p, status: "approved" })),
        ...(data.purchase_payments || []).map((p) => ({
            ...p,
            status: "approved",
        })),
    ]
        .filter((p) => p.status === "approved" && inPeriod(p.paid_at))
        .reduce((s, p) => s + Number(p.amount), 0);
    const unpaid = data.invoices
        .filter((i) => i.status === "issued")
        .reduce(
            (s, i) =>
                s +
                Math.max(
                    0,
                    Number(i.total) -
                        Number(i.paid) -
                        Number(i.credited || 0) +
                        Number(i.refunded || 0),
                ),
            0,
        );
    const pending = data.invoices.filter((i) => i.status === "pending");
    const overdue = data.invoices.filter((i) => status(i) === "overdue");
    const months = Array.from({ length: 6 }, (_, i) => {
        const d = new Date(`${today()}T12:00:00Z`);
        d.setUTCDate(1);
        d.setUTCMonth(d.getUTCMonth() - 5 + i);
        const key = d.toISOString().slice(0, 7);
        return {
            key,
            label: d.toLocaleDateString("fr-SN", { month: "short" }),
            received: data.payments
                .filter((p) => p.paid_at.startsWith(key))
                .reduce((s, p) => s + Number(p.amount), 0),
            spent: [
                ...data.expenses.filter((p) => p.status === "approved"),
                ...(data.refunds || []).map((p) => ({
                    ...p,
                    status: "approved",
                })),
                ...(data.purchase_payments || []).map((p) => ({
                    ...p,
                    status: "approved",
                })),
            ]
                .filter(
                    (p) => p.status === "approved" && p.paid_at.startsWith(key),
                )
                .reduce((s, p) => s + Number(p.amount), 0),
        };
    });
    const max = Math.max(...months.flatMap((m) => [m.received, m.spent]), 1);
    const metrics = [
        [
            "Ventes facturées",
            revenue,
            FileText,
            "Factures validées, TTC net d’avoirs",
            "green",
        ],
        [
            "Encaissements",
            received,
            ArrowDownLeft,
            "Paiements enregistrés",
            "blue",
        ],
        ["À encaisser", unpaid, Clock, "Solde global des factures", "orange"],
        [
            "Décaissements",
            spent,
            ArrowUpRight,
            "Dépenses, règlements fournisseurs et remboursements",
            "purple",
        ],
    ];
    return (
        <>
            <div className="page-heading">
                <div>
                    <div className="eyebrow">
                        VOTRE ENTREPRISE EN UN COUP D’ŒIL
                    </div>
                    <h1>
                        Bonjour, {session.user.name.split(" ")[0]}{" "}
                        <span className="hello-dot" />
                    </h1>
                    <p>Voici où en est votre activité. À vous de jouer.</p>
                </div>
                <div className="heading-actions">
                    <select
                        aria-label="Période des indicateurs"
                        value={period}
                        onChange={(e) => setPeriod(e.target.value)}
                    >
                        <option value="month">Ce mois-ci</option>
                        <option value="all">Toute la période</option>
                    </select>
                    {can("invoices.write") && (
                        <button
                            className="button primary"
                            onClick={() => open({ kind: "invoice" })}
                        >
                            <Plus size={18} /> Nouvelle facture
                        </button>
                    )}
                </div>
            </div>
            <div className="metrics">
                {metrics.map(([label, value, Icon, caption, color]) => (
                    <article className="metric" key={label}>
                        <div className="metric-top">
                            <span>{label}</span>
                            <div className={`metric-icon ${color}`}>
                                <Icon size={18} />
                            </div>
                        </div>
                        <strong>
                            {money(value).replace(" FCFA", "")}{" "}
                            <small>FCFA</small>
                        </strong>
                        <div className="metric-caption">
                            <span className={`tiny-dot ${color}`} />
                            {caption}
                        </div>
                    </article>
                ))}
            </div>
            <div className="dashboard-middle">
                <section className="panel chart-panel">
                    <div className="panel-heading">
                        <div>
                            <h2>Votre trésorerie en mouvement</h2>
                            <p>Encaissements et dépenses · 6 derniers mois</p>
                        </div>
                        <div className="legend">
                            <span>
                                <i className="green-bg" />
                                Entrées
                            </span>
                            <span>
                                <i className="sand-bg" />
                                Sorties
                            </span>
                        </div>
                    </div>
                    <div
                        className="chart"
                        role="img"
                        aria-label={`Flux mensuels. ${months.map((m) => `${m.label} : entrées ${money(m.received)}, sorties ${money(m.spent)}`).join(". ")}`}
                    >
                        <div className="chart-labels">
                            {[1, 0.75, 0.5, 0.25, 0].map((v) => (
                                <span key={v}>{shortMoney(max * v)}</span>
                            ))}
                        </div>
                        <div className="chart-plot">
                            <div className="grid-lines">
                                {[0, 1, 2, 3, 4].map((v) => (
                                    <i key={v} />
                                ))}
                            </div>
                            <div className="chart-columns">
                                {months.map((m) => (
                                    <div className="chart-month" key={m.key}>
                                        <div className="bars">
                                            <div
                                                className="bar income"
                                                title={money(m.received)}
                                                style={{
                                                    height: `${(m.received / max) * 100}%`,
                                                }}
                                            />
                                            <div
                                                className="bar expense"
                                                title={money(m.spent)}
                                                style={{
                                                    height: `${(m.spent / max) * 100}%`,
                                                }}
                                            />
                                        </div>
                                        <span>{m.label}</span>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>
                </section>
                <section className="attention-panel">
                    <div className="attention-top">
                        <span className="eyebrow">LE BON RÉFLEXE</span>
                        <div className="attention-icon">
                            <CheckCheck size={28} />
                        </div>
                    </div>
                    <h2>Un œil sur l’essentiel.</h2>
                    <p>Gardez une longueur d’avance sur vos échéances.</p>
                    <button onClick={() => navigate("invoices", "pending")}>
                        <span>
                            <span className="attention-count">
                                {pending.length}
                            </span>{" "}
                            facture{pending.length > 1 ? "s" : ""} à valider
                        </span>
                        <ArrowUpRight size={18} />
                    </button>
                    <button onClick={() => navigate("invoices", "overdue")}>
                        <span>
                            <span className="attention-count">
                                {overdue.length}
                            </span>{" "}
                            facture{overdue.length > 1 ? "s" : ""} en retard
                        </span>
                        <ArrowUpRight size={18} />
                    </button>
                    <div className="attention-foot">
                        <ShieldCheck size={14} /> Chaque action est journalisée.
                    </div>
                </section>
            </div>
            <section className="panel">
                <div className="panel-heading">
                    <div>
                        <h2>Dernières factures</h2>
                        <p>Vos ventes les plus récentes, au même endroit.</p>
                    </div>
                    <button
                        className="text-button"
                        onClick={() => navigate("invoices")}
                    >
                        Toutes les factures <ArrowRight size={16} />
                    </button>
                </div>
                <InvoiceTable
                    invoices={data.invoices.slice(0, 5)}
                    open={open}
                    can={can}
                    compact
                />
            </section>
            <div className="dashboard-footer">
                <span>
                    <span className="tiny-dot green" /> Données de votre espace
                    entreprise
                </span>
                <span>Fait pour entreprendre, au Sénégal.</span>
            </div>
        </>
    );
}
function App() {
    const [session, setSession] = useState(null),
        [loading, setLoading] = useState(true),
        [data, setData] = useState(emptyData),
        [page, setPage] = useState(
            new URLSearchParams(window.location.search).has("wave_order")
                ? "subscription"
                : "dashboard",
        ),
        [modal, setModal] = useState(null),
        [search, setSearch] = useState(""),
        [filter, setFilter] = useState("all"),
        [error, setError] = useState(""),
        [success, setSuccess] = useState(""),
        [mobile, setMobile] = useState(false),
        [busy, setBusy] = useState(false);
    const [access, setAccess] = useState(emptyAccess);
    const [smallScreen, setSmallScreen] = useState(
        () => window.matchMedia("(max-width: 700px)").matches,
    );
    useEffect(() => {
        const media = window.matchMedia("(max-width: 700px)");
        const change = () => setSmallScreen(media.matches);
        media.addEventListener("change", change);
        return () => media.removeEventListener("change", change);
    }, []);
    const expired =
        session &&
        ((session.company.subscription_status === "trial" &&
            session.company.trial_ends_at &&
            new Date(
                session.company.trial_ends_at.replace(" ", "T") +
                    (/Z|[+]\d\d:\d\d$/.test(session.company.trial_ends_at)
                        ? ""
                        : "Z"),
            ) < new Date()) ||
            (session.company.subscription_status === "active" &&
                session.company.subscription_ends_at &&
                new Date(
                    session.company.subscription_ends_at.replace(" ", "T") +
                        (/Z|[+]\d\d:\d\d$/.test(
                            session.company.subscription_ends_at,
                        )
                            ? ""
                            : "Z"),
                ) < new Date()));
    const can = (p) =>
        session?.permissions.includes(p) &&
        (!expired ||
            p?.endsWith(".read") ||
            p?.startsWith("support.") ||
            p?.startsWith("subscription."));
    useEffect(() => {
        api("/me")
            .then(setSession)
            .catch((e) => {
                if (e.status !== 401) setError(e.message);
            })
            .finally(() => setLoading(false));
    }, []);
    useEffect(() => {
        if (session) refresh().catch((e) => setError(e.message));
    }, [session?.user.id]);
    useEffect(() => {
        if (
            session &&
            pagePermissions[page] &&
            !session.permissions.includes(pagePermissions[page])
        ) {
            setPage(
                Object.keys(pagePermissions).find((p) =>
                    session.permissions.includes(pagePermissions[p]),
                ) || "home",
            );
        }
    }, [session?.permissions.join(","), page]);
    useEffect(() => {
        if (!success) return;
        const id = setTimeout(() => setSuccess(""), 4000);
        return () => clearTimeout(id);
    }, [success]);
    async function refresh(current = session) {
        if (current?.company.subscription_status === "suspended") {
            setData(emptyData);
            setAccess(emptyAccess);
            setPage("subscription");
            return;
        }
        const result = await api("/business");
        setData(result);
        if (
            current?.permissions.some((p) =>
                ["team.read", "workflows.manage"].includes(p),
            )
        )
            setAccess(await api("/access"));
        else setAccess(emptyAccess);
    }
    function navigate(next, selected = "all") {
        setPage(next);
        setFilter(selected);
        setSearch("");
        setMobile(false);
        setError("");
    }
    async function saved() {
        const current = await api("/me");
        setSession(current);
        await refresh(current);
        setSuccess("Enregistrement effectué.");
    }
    async function transition(invoice, action) {
        if (action === "reject") {
            setModal({ kind: "reject", invoice });
            return;
        }
        setBusy(true);
        setError("");
        try {
            await api(`/invoices/${invoice.id}/transition`, {
                method: "POST",
                body: {
                    action,
                    round: invoice.approval_round,
                    step: invoice.approval_step,
                },
            });
            await refresh();
            setSuccess("Statut de la facture mis à jour.");
        } catch (e) {
            setError(e.message);
        } finally {
            setBusy(false);
        }
    }
    async function logout() {
        try {
            await api("/logout", { method: "POST" });
            setSession(null);
            setData(emptyData);
            setAccess(emptyAccess);
            setPage("dashboard");
            setError("");
        } catch (e) {
            setError(e.message);
        }
    }
    if (loading)
        return (
            <div className="loading-screen">
                <Brand />
                <LoaderCircle className="spin" />
                <p>Ouverture de votre espace…</p>
            </div>
        );
    if (!session)
        return (
            <>
                {error && (
                    <div className="global-error" role="alert">
                        {error}
                    </div>
                )}
                <Auth
                    onLogin={(s) => {
                        setError("");
                        setSession(s);
                    }}
                />
            </>
        );
    const headings = {
        banking: [
            "Banque & rapprochements",
            "Rapprochez vos relevés des opérations comptabilisées.",
        ],
        refunds: [
            "Remboursements clients",
            "Suivez les crédits et les montants reversés.",
        ],
        drafts: [
            "Brouillons & archives",
            "Conservez votre historique sans encombrer les factures actives.",
        ],
        returns: [
            "Retours produits",
            "Enregistrez les marchandises retournées par vos clients.",
        ],
        reports: [
            "États de gestion",
            "Analysez les produits, charges et soldes comptables.",
        ],
        quotes: ["Devis", "Préparez vos offres et transformez-les en ventes."],
        purchases: [
            "Achats fournisseurs",
            "Suivez vos engagements et vos règlements fournisseurs.",
        ],
        credits: [
            "Avoirs clients",
            "Corrigez les montants facturés avec une trace comptable.",
        ],
        stock: [
            "Gestion des stocks",
            "Enregistrez les entrées et sorties de vos produits.",
        ],
        accounting: [
            "Comptabilité",
            "Vos opérations, vos comptes, vos écritures.",
        ],
        subscription: [
            "Votre abonnement",
            "Choisissez l’offre adaptée à votre entreprise.",
        ],
        communications: [
            "Emails professionnels",
            "Échangez avec vos clients depuis votre propre adresse.",
        ],
        support: [
            "Support & signalements",
            "Un suivi clair de vos questions et incidents.",
        ],
        notifications: [
            "Notifications",
            "Retrouvez l’activité qui vous concerne.",
        ],
        home: [
            "Bienvenue dans votre espace",
            "Votre propriétaire peut vous attribuer les modules utiles à votre activité.",
        ],
        workflows: [
            "Circuits de validation",
            "Définissez qui valide vos factures, et dans quel ordre.",
        ],
        invoices: [
            "Factures & ventes",
            "De la première facture au dernier règlement.",
        ],
        clients: [
            "Vos clients",
            "Cultivez vos relations, retrouvez chaque contact.",
        ],
        products: [
            "Produits & services",
            "Votre offre, organisée et prête à facturer.",
        ],
        suppliers: [
            "Vos fournisseurs",
            "Les partenaires qui font avancer votre entreprise.",
        ],
        expenses: [
            "Dépenses",
            "Enregistrez les dépenses payées, puis validez leur comptabilisation.",
        ],
        team: [
            "Équipe & accès",
            "À chacun son rôle, à chacun ses permissions.",
        ],
        activity: [
            "Journal d’activité",
            "Un historique des actions effectuées dans votre entreprise.",
        ],
        settings: ["Paramètres", "Un espace à l’image de votre entreprise."],
        roadmap: [
            "Les prochaines étapes",
            "Les modules qui viendront compléter votre gestion.",
        ],
        admin: [
            "Administration plateforme",
            "Gérez les entreprises et le statut de leurs abonnements.",
        ],
    };
    const add = {
        invoices: ["invoice", "Nouvelle facture", "invoices.write"],
        clients: ["contact", "Nouveau client", "contacts.write"],
        suppliers: ["supplier", "Nouveau fournisseur", "contacts.write"],
        products: ["product", "Ajouter un produit", "products.write"],
        expenses: ["expense", "Nouvelle dépense", "expenses.write"],
        team: ["member", "Ajouter un membre", "team.write"],
    };
    const match = (text) =>
        String(text).toLowerCase().includes(search.toLowerCase());
    const contacts = data.contacts.filter(
        (c) =>
            c.type === (page === "suppliers" ? "supplier" : "client") &&
            match(`${c.name} ${c.email} ${c.phone}`),
    );
    const invoices = data.invoices.filter(
        (i) =>
            !i.archived &&
            (filter === "all" || status(i) === filter) &&
            match(`${i.number || ""} ${i.customer_snapshot.name}`),
    );
    return (
        <div className="app-shell">
            {mobile && (
                <button
                    className="sidebar-overlay"
                    aria-label="Fermer le menu"
                    onClick={() => setMobile(false)}
                />
            )}
            <aside
                id="main-navigation"
                inert={smallScreen && !mobile}
                className={`sidebar ${mobile ? "open" : ""}`}
            >
                <Brand />
                <div className="workspace-switch">
                    <div className="workspace-icon">
                        {session.company.name.slice(0, 2).toUpperCase()}
                    </div>
                    <div>
                        <strong>{session.company.name}</strong>
                        <small>Espace entreprise</small>
                    </div>
                    <Building2 size={15} />
                </div>
                <span className="nav-label">VOTRE ACTIVITÉ</span>
                <nav>
                    {nav
                        .filter(([id]) => can(pagePermissions[id]))
                        .map(([id, label, Icon]) => (
                            <button
                                key={id}
                                className={page === id ? "active" : ""}
                                onClick={() => navigate(id)}
                            >
                                <Icon size={19} />
                                <span>{label}</span>
                                {id === "invoices" &&
                                    data.invoices.filter(
                                        (i) => i.status === "pending",
                                    ).length > 0 && (
                                        <span className="nav-count">
                                            {
                                                data.invoices.filter(
                                                    (i) =>
                                                        i.status === "pending",
                                                ).length
                                            }
                                        </span>
                                    )}
                            </button>
                        ))}
                </nav>
                <span className="nav-label management-label">VOTRE ESPACE</span>
                <nav>
                    {secondaryNav
                        .filter(([id]) => can(pagePermissions[id]))
                        .map(([id, label, Icon]) => (
                            <button
                                key={id}
                                className={page === id ? "active" : ""}
                                onClick={() => navigate(id)}
                            >
                                <Icon size={19} />
                                {label}
                            </button>
                        ))}
                    {session.user.is_super_admin && (
                        <button
                            className={page === "admin" ? "active" : ""}
                            onClick={() => navigate("admin")}
                        >
                            <Building2 size={19} /> Super administration
                        </button>
                    )}
                </nav>
                <div className="sidebar-bottom">
                    <div className="plan-box">
                        <span>
                            <span className="tiny-dot green" /> Offre{" "}
                            {session.company.plan}
                        </span>
                        <strong>
                            {session.company.subscription_status === "trial"
                                ? "Votre espace prend vie."
                                : "Tout pour avancer."}
                        </strong>
                        <p>
                            {session.company.subscription_status === "trial"
                                ? `Essai jusqu’au ${date(session.company.trial_ends_at)}`
                                : "Abonnement actif"}
                        </p>
                        <button onClick={() => navigate("subscription")}>
                            Mon abonnement <ArrowUpRight size={15} />
                        </button>
                    </div>
                    <button
                        className="profile"
                        onClick={logout}
                        title="Se déconnecter"
                    >
                        <span className="avatar">
                            {session.user.name
                                .split(" ")
                                .map((n) => n[0])
                                .slice(0, 2)
                                .join("")}
                        </span>
                        <span>
                            <strong>{session.user.name}</strong>
                            <small>
                                {session.role_name || roles[session.role]}
                            </small>
                        </span>
                        <LogOut size={17} />
                    </button>
                </div>
            </aside>
            <div className="main-shell">
                <header className="topbar">
                    <div className="breadcrumb">
                        <button
                            className="icon-button mobile-menu"
                            aria-label="Ouvrir le menu"
                            aria-expanded={mobile}
                            aria-controls="main-navigation"
                            onClick={() => setMobile(true)}
                        >
                            <Menu size={21} />
                        </button>
                        <span>Mon entreprise</span>
                        <span className="breadcrumb-slash">/</span>
                        <strong>
                            {page === "dashboard"
                                ? "Vue d’ensemble"
                                : headings[page]?.[0]}
                        </strong>
                    </div>
                    <div className="topbar-right">
                        <span className="country-chip">
                            <i /> Sénégal <span>·</span> XOF
                        </span>
                        <button
                            className="icon-button"
                            aria-label="Ouvrir les notifications"
                            onClick={() => navigate("notifications")}
                        >
                            <CircleHelp size={19} />
                        </button>
                    </div>
                </header>
                <main>
                    {expired && (
                        <div className="info-banner">
                            Votre période d’accès a expiré. Vos données restent
                            consultables.{" "}
                            <button
                                className="button secondary small"
                                onClick={() => navigate("subscription")}
                            >
                                Gérer l’abonnement
                            </button>
                        </div>
                    )}
                    {session.user.email === "demo@digicompta.test" && (
                        <div className="demo-notice">
                            <span className="tiny-dot orange" /> Espace de
                            démonstration · Les entreprises et opérations
                            présentées sont fictives.
                        </div>
                    )}
                    {error && (
                        <div className="error-box" role="alert">
                            {error}
                            <button
                                className="icon-button"
                                onClick={() => setError("")}
                                aria-label="Fermer le message"
                            >
                                <X size={16} />
                            </button>
                        </div>
                    )}
                    {success && (
                        <div className="toast" role="status">
                            <Check size={17} />
                            {success}
                        </div>
                    )}
                    {page === "dashboard" ? (
                        <Dashboard
                            data={data}
                            session={session}
                            open={setModal}
                            navigate={navigate}
                            can={can}
                        />
                    ) : (
                        <>
                            <div className="page-heading">
                                <div>
                                    <div className="eyebrow">
                                        {page === "admin"
                                            ? "DIGI-COMPTA PLATEFORME"
                                            : "VOTRE ESPACE DE GESTION"}
                                    </div>
                                    <h1>{headings[page]?.[0]}</h1>
                                    <p>{headings[page]?.[1]}</p>
                                </div>
                                {add[page] && can(add[page][2]) && (
                                    <button
                                        className="button primary"
                                        onClick={() =>
                                            setModal({ kind: add[page][0] })
                                        }
                                    >
                                        <Plus size={18} />
                                        {add[page][1]}
                                    </button>
                                )}
                            </div>
                            {[
                                "invoices",
                                "clients",
                                "suppliers",
                                "products",
                                "expenses",
                            ].includes(page) && (
                                <section className="panel">
                                    <div className="table-toolbar">
                                        {page === "invoices" ? (
                                            <div className="filter-tabs">
                                                {[
                                                    ["all", "Toutes"],
                                                    ["pending", "À valider"],
                                                    ["paid", "Payées"],
                                                    ["overdue", "En retard"],
                                                ].map(([key, label]) => (
                                                    <button
                                                        key={key}
                                                        className={
                                                            filter === key
                                                                ? "selected"
                                                                : ""
                                                        }
                                                        onClick={() =>
                                                            setFilter(key)
                                                        }
                                                    >
                                                        {label}
                                                    </button>
                                                ))}
                                            </div>
                                        ) : (
                                            <h2>
                                                {page === "products"
                                                    ? "Votre catalogue"
                                                    : page === "expenses"
                                                      ? "Dépenses enregistrées"
                                                      : "Répertoire"}
                                            </h2>
                                        )}
                                        <label className="search">
                                            <Search size={17} />
                                            <input
                                                aria-label="Rechercher"
                                                placeholder="Rechercher…"
                                                value={search}
                                                onChange={(e) =>
                                                    setSearch(e.target.value)
                                                }
                                            />
                                        </label>
                                    </div>
                                    {page === "invoices" && (
                                        <InvoiceTable
                                            invoices={invoices}
                                            open={setModal}
                                            act={transition}
                                            can={can}
                                            busy={busy}
                                        />
                                    )}
                                    {(page === "clients" ||
                                        page === "suppliers") &&
                                        (contacts.length ? (
                                            <div className="table-scroll">
                                                <table>
                                                    <thead>
                                                        <tr>
                                                            <th>Nom</th>
                                                            <th>Email</th>
                                                            <th>Téléphone</th>
                                                            <th>Adresse</th>
                                                            <th>
                                                                État / actions
                                                            </th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        {contacts.map((c) => (
                                                            <tr key={c.id}>
                                                                <td>
                                                                    <div className="contact-name">
                                                                        <span className="avatar subtle">
                                                                            {c.name
                                                                                .slice(
                                                                                    0,
                                                                                    2,
                                                                                )
                                                                                .toUpperCase()}
                                                                        </span>
                                                                        <strong>
                                                                            {
                                                                                c.name
                                                                            }
                                                                        </strong>
                                                                    </div>
                                                                </td>
                                                                <td>
                                                                    {c.email ||
                                                                        "—"}
                                                                </td>
                                                                <td>
                                                                    {c.phone ||
                                                                        "—"}
                                                                </td>
                                                                <td>
                                                                    {c.address ||
                                                                        "—"}
                                                                </td>
                                                                <td>
                                                                    {c.archived && (
                                                                        <span className="tag">
                                                                            Archivé
                                                                        </span>
                                                                    )}
                                                                    {can(
                                                                        "contacts.write",
                                                                    ) && (
                                                                        <button
                                                                            className="button secondary small"
                                                                            onClick={() =>
                                                                                setModal(
                                                                                    {
                                                                                        kind: "edit-record",
                                                                                        record: c,
                                                                                        recordKind:
                                                                                            "contacts",
                                                                                    },
                                                                                )
                                                                            }
                                                                        >
                                                                            Modifier
                                                                        </button>
                                                                    )}
                                                                </td>{" "}
                                                            </tr>
                                                        ))}
                                                    </tbody>
                                                </table>
                                            </div>
                                        ) : (
                                            <Empty />
                                        ))}
                                    {page === "products" &&
                                        (data.products.filter((p) =>
                                            match(`${p.name} ${p.sku}`),
                                        ).length ? (
                                            <div className="table-scroll">
                                                <table>
                                                    <thead>
                                                        <tr>
                                                            <th>Désignation</th>
                                                            <th>Référence</th>
                                                            <th>Type</th>
                                                            <th>
                                                                Prix unitaire HT
                                                            </th>
                                                            <th>
                                                                État / actions
                                                            </th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        {data.products
                                                            .filter((p) =>
                                                                match(
                                                                    `${p.name} ${p.sku}`,
                                                                ),
                                                            )
                                                            .map((p) => (
                                                                <tr key={p.id}>
                                                                    <td>
                                                                        <strong>
                                                                            {
                                                                                p.name
                                                                            }
                                                                        </strong>
                                                                    </td>
                                                                    <td>
                                                                        {p.sku ||
                                                                            "—"}
                                                                    </td>
                                                                    <td>
                                                                        <span className="tag">
                                                                            {p.type ===
                                                                            "service"
                                                                                ? "Service"
                                                                                : "Produit"}
                                                                        </span>
                                                                    </td>
                                                                    <td className="amount">
                                                                        {money(
                                                                            p.price,
                                                                        )}
                                                                    </td>
                                                                    <td>
                                                                        {p.archived && (
                                                                            <span className="tag">
                                                                                Archivé
                                                                            </span>
                                                                        )}
                                                                        {can(
                                                                            "products.write",
                                                                        ) && (
                                                                            <button
                                                                                className="button secondary small"
                                                                                onClick={() =>
                                                                                    setModal(
                                                                                        {
                                                                                            kind: "edit-record",
                                                                                            record: p,
                                                                                            recordKind:
                                                                                                "products",
                                                                                        },
                                                                                    )
                                                                                }
                                                                            >
                                                                                Modifier
                                                                            </button>
                                                                        )}
                                                                    </td>{" "}
                                                                </tr>
                                                            ))}
                                                    </tbody>
                                                </table>
                                            </div>
                                        ) : (
                                            <Empty />
                                        ))}
                                    {page === "expenses" &&
                                        (data.expenses.filter((p) =>
                                            match(
                                                `${p.description} ${p.category}`,
                                            ),
                                        ).length ? (
                                            <div className="table-scroll">
                                                <table>
                                                    <thead>
                                                        <tr>
                                                            <th>Libellé</th>
                                                            <th>Catégorie</th>
                                                            <th>
                                                                Date du paiement
                                                            </th>
                                                            <th>Montant</th>
                                                            <th>Validation</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        {data.expenses
                                                            .filter((p) =>
                                                                match(
                                                                    `${p.description} ${p.category}`,
                                                                ),
                                                            )
                                                            .map((p) => (
                                                                <tr key={p.id}>
                                                                    <td>
                                                                        <strong>
                                                                            {
                                                                                p.description
                                                                            }
                                                                        </strong>
                                                                    </td>
                                                                    <td>
                                                                        {
                                                                            p.category
                                                                        }
                                                                    </td>
                                                                    <td>
                                                                        {date(
                                                                            p.paid_at,
                                                                        )}
                                                                    </td>
                                                                    <td className="amount">
                                                                        {money(
                                                                            p.amount,
                                                                        )}
                                                                    </td>
                                                                    <td>
                                                                        <ExpenseActions
                                                                            expense={
                                                                                p
                                                                            }
                                                                            can={
                                                                                can
                                                                            }
                                                                            saved={
                                                                                saved
                                                                            }
                                                                        />
                                                                    </td>{" "}
                                                                </tr>
                                                            ))}
                                                    </tbody>
                                                </table>
                                            </div>
                                        ) : (
                                            <Empty />
                                        ))}
                                </section>
                            )}
                            {page === "team" && (
                                <AccessPanel
                                    access={access}
                                    team={data.team}
                                    session={session}
                                    can={can}
                                    save={saved}
                                />
                            )}
                            {page === "workflows" && access.workflow && (
                                <WorkflowEditor
                                    key={JSON.stringify(access.workflow)}
                                    access={access}
                                    save={saved}
                                />
                            )}
                            {page === "home" && (
                                <section className="panel">
                                    <Empty
                                        title="Votre compte est actif"
                                        description="Aucun module ne vous a encore été attribué. Contactez le propriétaire de votre entreprise."
                                    />
                                </section>
                            )}
                            {page === "activity" && (
                                <section className="panel">
                                    {data.audit.length ? (
                                        <div className="activity-list">
                                            {data.audit.map((a) => (
                                                <div
                                                    className="activity-row"
                                                    key={a.id}
                                                >
                                                    <div className="activity-icon">
                                                        <Activity size={18} />
                                                    </div>
                                                    <div>
                                                        <strong>
                                                            {a.action}
                                                        </strong>
                                                        <p>
                                                            {a.actor} ·{" "}
                                                            {a.entity_type} #
                                                            {a.entity_id}
                                                        </p>
                                                    </div>
                                                    <time>
                                                        {date(a.created_at)}
                                                    </time>
                                                </div>
                                            ))}
                                        </div>
                                    ) : (
                                        <Empty description="Les actions de votre équipe seront affichées ici." />
                                    )}
                                </section>
                            )}
                            {page === "settings" && (
                                <div className="settings-grid">
                                    <section className="panel settings-card">
                                        <div className="section-icon">
                                            <Building2 />
                                        </div>
                                        <h2>{session.company.name}</h2>
                                        <p>
                                            {session.company.address ||
                                                "Adresse à renseigner"}
                                        </p>
                                        <dl>
                                            <div>
                                                <dt>Pays</dt>
                                                <dd>Sénégal</dd>
                                            </div>
                                            <div>
                                                <dt>Devise</dt>
                                                <dd>XOF / FCFA</dd>
                                            </div>
                                            <div>
                                                <dt>NINEA</dt>
                                                <dd>
                                                    {session.company.ninea ||
                                                        "Non renseigné"}
                                                </dd>
                                            </div>
                                            <div>
                                                <dt>RCCM</dt>
                                                <dd>
                                                    {session.company.rccm ||
                                                        "Non renseigné"}
                                                </dd>
                                            </div>
                                        </dl>
                                        {can("settings.write") && (
                                            <button
                                                className="button secondary"
                                                onClick={() =>
                                                    setModal({
                                                        kind: "settings",
                                                    })
                                                }
                                            >
                                                Modifier les informations
                                            </button>
                                        )}
                                    </section>
                                    <section className="panel settings-card">
                                        <div className="section-icon">
                                            <CreditCard />
                                        </div>
                                        <h2>Offre {session.company.plan}</h2>
                                        <p>
                                            {session.company
                                                .subscription_status === "trial"
                                                ? "Période d’essai"
                                                : "Abonnement actif"}
                                        </p>
                                        <dl>
                                            <div>
                                                <dt>Statut</dt>
                                                <dd>
                                                    {session.company
                                                        .subscription_status ===
                                                    "trial"
                                                        ? "Essai"
                                                        : "Actif"}
                                                </dd>
                                            </div>
                                            {session.company
                                                .subscription_status ===
                                                "trial" && (
                                                <div>
                                                    <dt>Fin de l’essai</dt>
                                                    <dd>
                                                        {date(
                                                            session.company
                                                                .trial_ends_at,
                                                        )}
                                                    </dd>
                                                </div>
                                            )}
                                            <div>
                                                <dt>Espace</dt>
                                                <dd>{session.company.name}</dd>
                                            </div>
                                        </dl>
                                        <p className="helper">
                                            Retrouvez les offres, promotions et
                                            demandes de renouvellement dans la
                                            rubrique Abonnement.
                                        </p>
                                    </section>
                                    <section className="panel settings-card">
                                        <div className="section-icon">
                                            <Send />
                                        </div>
                                        <h2>Email & notifications</h2>
                                        <span className="tag">Disponible</span>
                                        <p>
                                            SMTP propre à votre entreprise,
                                            relances par email et notifications
                                            push.
                                        </p>
                                        <p className="helper">
                                            Configurez votre adresse
                                            professionnelle dans la rubrique
                                            Emails. Activez les notifications
                                            push dans le centre de
                                            notifications.
                                        </p>
                                    </section>
                                </div>
                            )}
                            {[
                                "quotes",
                                "purchases",
                                "credits",
                                "stock",
                            ].includes(page) && (
                                <CommercePanel
                                    key={page}
                                    page={page}
                                    data={data}
                                    can={can}
                                    saved={saved}
                                    company={session.company}
                                />
                            )}
                            {page === "banking" && <BankPanel can={can} />}
                            {page === "refunds" && (
                                <RefundPanel
                                    data={data}
                                    can={can}
                                    saved={saved}
                                />
                            )}
                            {page === "drafts" && (
                                <DraftPanel
                                    data={data}
                                    can={can}
                                    saved={saved}
                                />
                            )}
                            {page === "returns" && (
                                <ReturnsPanel
                                    data={data}
                                    can={can}
                                    saved={saved}
                                />
                            )}
                            {page === "reports" && <ReportsPanel />}
                            {page === "accounting" && (
                                <AccountingPanel can={can} saved={saved} />
                            )}
                            {page === "subscription" && (
                                <SubscriptionPanel can={can} saved={saved} />
                            )}
                            {page === "support" && <SupportPanel can={can} />}
                            {page === "communications" && (
                                <CommunicationsPanel can={can} data={data} />
                            )}
                            {page === "notifications" && (
                                <NotificationsPanel navigate={navigate} />
                            )}
                            {page === "admin" && (
                                <PlatformPanel saved={saved} />
                            )}
                        </>
                    )}
                </main>
            </div>
            {modal &&
                (modal.kind === "edit-record" ? (
                    <EditRecord
                        record={modal.record}
                        kind={modal.recordKind}
                        close={() => setModal(null)}
                        saved={saved}
                    />
                ) : modal.kind === "detail" ? (
                    <InvoiceDetail
                        invoice={modal.invoice}
                        close={() => setModal(null)}
                    />
                ) : modal.kind === "reject" ? (
                    <RejectInvoiceDialog
                        invoice={modal.invoice}
                        close={() => setModal(null)}
                        save={saved}
                    />
                ) : (
                    <RecordForm
                        key={modal.kind}
                        {...modal}
                        contacts={data.contacts}
                        products={data.products}
                        company={session.company}
                        roleOptions={assignableRoles(access, session)}
                        close={() => setModal(null)}
                        save={saved}
                    />
                ))}
        </div>
    );
}
createRoot(document.getElementById("root")).render(<App />);
