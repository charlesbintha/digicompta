export async function api(path, options = {}) {
    const headers = {
        Accept: "application/json",
        "Content-Type": "application/json",
    };
    if (options.method && options.method !== "GET") {
        const csrf = await fetch("/api/v1/csrf", {
            headers: { Accept: "application/json" },
        });
        headers["X-CSRF-TOKEN"] = (await csrf.json()).token;
    }
    const response = await fetch(`/api/v1${path}`, {
        ...options,
        headers,
        body: options.body ? JSON.stringify(options.body) : undefined,
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
        const error = new Error(
            Object.values(data.errors || {}).flat()[0] ||
                data.message ||
                "Une erreur est survenue. Réessayez.",
        );
        error.status = response.status;
        throw error;
    }
    return data;
}
export const money = (value) =>
    `${new Intl.NumberFormat("fr-SN", { maximumFractionDigits: 0 }).format(Number(value || 0))} FCFA`;
export const shortMoney = (value) =>
    new Intl.NumberFormat("fr-SN", {
        notation: "compact",
        maximumFractionDigits: 1,
    }).format(value);
export const today = () =>
    new Date().toLocaleDateString("en-CA", { timeZone: "Africa/Dakar" });
export const date = (value) =>
    value
        ? new Date(
              `${String(value).slice(0, 10)}T12:00:00Z`,
          ).toLocaleDateString("fr-SN", {
              day: "2-digit",
              month: "short",
              year: "numeric",
          })
        : "—";
export function status(invoice) {
    if (invoice.status === "issued" && invoice.due_date < today())
        return "overdue";
    if (invoice.status === "issued" && invoice.paid > 0) return "partial";
    return invoice.status;
}
export const statuses = {
    draft: "Brouillon",
    pending: "À valider",
    issued: "En attente",
    partial: "Partiellement payée",
    paid: "Soldée",
    overdue: "En retard",
};
export const roles = {
    owner: "Propriétaire",
    accountant: "Comptable",
    sales: "Commercial",
    viewer: "Lecture seule",
};
